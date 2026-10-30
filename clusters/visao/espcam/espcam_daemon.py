#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
JARVIS CASA - ESP32-CAM SINGLE RESPONSIBLE DAEMON (Active/Standby High Availability).

Regras de Negocio:
 1. SO EXISTE UM DAEMON ATIVO NA CASA por vez (os demais ficam em Standby / Backup).
 2. O daemon avisa as cameras e puxa/recebe seus frames.
 3. A camera exige TOKEN FISICO (obtido apenas pela Serial via 'gravafirmware').
 4. O daemon carrega os tokens do cofre ~/.casa/camera_tokens.json e da porta HTTP 8088 (/token).
 5. O daemon envia os frames capturados via HTTPS para a API do site CASA (upload_snapshot).
 6. O site disponibiliza a imagem via get_snapshot (somente sessao/chave de API autenticada).
 7. Toda chamada ao site leva a chave de API em X-API-Key (variavel de ambiente CASA_API_KEY).
"""

import argparse
import concurrent.futures
import http.server
import json
import os
import socketserver
import subprocess
import sys
import threading
import time
import urllib.request
from pathlib import Path

try:
    import requests
except ImportError:
    requests = None

# Configuracoes de Rede e Nuvem
CASA_SERVER = os.getenv("CASA_SERVER_URL", "https://maurinsoft.com.br/casa")
CASA_API_KEY = os.getenv("CASA_API_KEY", "").strip()
SUBNET_PREFIX = "192.168.2."
BASE_DIR = Path(__file__).resolve().parent
PROJECT_DIR = BASE_DIR.parents[2]
TOKEN_VAULT_FILE = Path(os.path.expanduser("~/.casa/camera_tokens.json"))
DAEMON_LOCK_FILE = Path(os.path.expanduser("~/.casa/espcam_daemon.lock"))
CONTROL_PORT = 8088

# Estado Global do Daemon Unico
is_leader = True
camera_tokens = {}  # {device_id: {"token": ..., "ip": ...}}
known_cameras = {}  # {device_id: {"ip": ..., "last_seen": ...}}
HOUSE_WIFIS_FILE = Path(os.path.expanduser("~/.casa/house_wifis.json"))
house_wifis = []
last_wifi_survey_time = 0
last_wifi_sync_time = 0


def log_lcars(message: str, level: str = "INFO"):
    timestamp = time.strftime("%H:%M:%S")
    stardate = f"{time.strftime('%Y')}.{int(time.time() % 1000):03d}"
    print(f"[{timestamp}] [STARDATE {stardate}] [LCARS-{level}] {message}", flush=True)


def casa_headers(extra: dict = None) -> dict:
    """Cabecalhos para a API do site: identifica o daemon e autentica com a chave de API."""
    headers = {"User-Agent": "CASA-VisionDaemon/1.0"}
    if CASA_API_KEY:
        headers["X-API-Key"] = CASA_API_KEY
    if extra:
        headers.update(extra)
    return headers


def normalize_tokens(data: dict) -> dict:
    """Cofre aceita {chave: "token"} (formato do gravafirmware/site) ou {chave: {"token": ...}}."""
    out = {}
    for k, v in data.items():
        if isinstance(v, str) and v:
            out[k] = {"token": v, "updated_at": time.time()}
        elif isinstance(v, dict) and v.get("token"):
            out[k] = v
    return out


def find_token(device_id: str, ip: str = None):
    """Token da camera pelo device_id exato ou, na falta, pelo IP registrado."""
    entry = camera_tokens.get(device_id)
    if not entry and ip:
        entry = camera_tokens.get(ip)
    return entry.get("token") if entry else None


def sync_tokens_from_site():
    global camera_tokens
    try:
        url = f"{CASA_SERVER.rstrip('/')}/api/v1/camera.php?acao=get_tokens"
        req = urllib.request.Request(url, headers=casa_headers())
        with urllib.request.urlopen(req, timeout=6) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            tokens = data.get("tokens", {})
            if isinstance(tokens, dict) and tokens:
                camera_tokens.update(normalize_tokens(tokens))
                save_token_vault()
                log_lcars(f"Tokens sincronizados com sucesso do site ({CASA_SERVER}): {len(tokens)} chave(s) ativas.", "SYNC")
    except Exception as e:
        log_lcars(f"Tentativa de sincronizar tokens do site: {e}", "DEBUG")


def load_token_vault():
    global camera_tokens
    sync_tokens_from_site()
    if TOKEN_VAULT_FILE.exists():
        try:
            data = json.loads(TOKEN_VAULT_FILE.read_text(encoding="utf-8"))
            if isinstance(data, dict):
                camera_tokens.update(normalize_tokens(data))
                log_lcars(f"Cofre de chaves carregado: {len(camera_tokens)} token(s) de câmera registrado(s).", "VAULT")
        except Exception as e:
            log_lcars(f"Falha ao ler cofre de chaves: {e}", "WARN")


def save_token_vault():
    try:
        TOKEN_VAULT_FILE.parent.mkdir(parents=True, exist_ok=True)
        TOKEN_VAULT_FILE.write_text(json.dumps(camera_tokens, indent=2, ensure_ascii=False), encoding="utf-8")
    except Exception as e:
        log_lcars(f"Erro ao persistir cofre de tokens: {e}", "ERRO")


# -------------------------------------------------------------
# SERVIDOR DE CONTROLE LOCAL (Recebe tokens do gravafirmware)
# -------------------------------------------------------------
class ControlHandler(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path == "/token":
            length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(length).decode("utf-8")
            try:
                data = json.loads(body)
                did = data.get("device_id")
                tok = data.get("token")
                if did and tok:
                    camera_tokens[did] = data
                    save_token_vault()
                    log_lcars(f"NOVO TOKEN RECEBIDO VIA GRAVAFIRMWARE: [{did}] -> {tok[:6]}...{tok[-4:]}", "SECURITY")
                    self.send_response(200)
                    self.send_header("Content-Type", "application/json")
                    self.end_headers()
                    self.wfile.write(b'{"ok":true,"mensagem":"Token registrado com sucesso no daemon ativo"}')
                    return
            except Exception as e:
                log_lcars(f"Payload de token invalido: {e}", "ERRO")

        self.send_response(400)
        self.end_headers()
        self.wfile.write(b'{"ok":false,"erro":"Requisicao invalida"}')

    def do_GET(self):
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        resp = {
            "status": "online",
            "role": "active_leader" if is_leader else "standby",
            "registered_tokens": list(camera_tokens.keys()),
            "active_cameras": known_cameras
        }
        self.wfile.write(json.dumps(resp).encode("utf-8"))

    def log_message(self, format, *args):
        pass  # Silencia logs padroes do http.server


def start_control_server():
    global is_leader
    try:
        server = socketserver.TCPServer(("127.0.0.1", CONTROL_PORT), ControlHandler)
        log_lcars(f"Servidor de controle IPC ativo em http://127.0.0.1:{CONTROL_PORT}", "IPC")
        threading.Thread(target=server.serve_forever, daemon=True).start()
    except Exception as e:
        is_leader = False
        log_lcars(f"Porta {CONTROL_PORT} já em uso (outro daemon ativo). Entrando em modo STANDBY: {e}", "WARN")


# -------------------------------------------------------------
# DESCOBERTA E OPERAÇÃO DAS CÂMERAS COM TOKEN
# -------------------------------------------------------------
def check_camera_node(ip: str, timeout: float = 0.4):
    """Testa se o IP responde como ESP32-CAM."""
    try:
        r = requests.get(f"http://{ip}/status", timeout=timeout)
        if r.status_code == 200:
            data = r.json()
            if data.get("type") == "esp32cam" or "device_id" in data:
                did = data.get("device_id")
                return (ip, did, data)
    except Exception:
        pass
    return None


def scan_network_cameras(subnet: str = SUBNET_PREFIX):
    candidates = set([f"{subnet}{i}" for i in range(1, 255)])
    # Adiciona tambem sub-rede 192.168.0.x se a padrao for 192.168.2.x
    candidates.update([f"192.168.0.{i}" for i in range(1, 255)])
    # Adiciona IPs conhecidos do cofre de tokens
    for k, v in camera_tokens.items():
        if isinstance(v, dict) and "ip" in v and v["ip"]:
            candidates.add(v["ip"])
        elif isinstance(k, str) and re.match(r'^\d+\.\d+\.\d+\.\d+$', k):
            candidates.add(k)
    candidates = list(candidates)
    found = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=40) as ex:
        results = ex.map(check_camera_node, candidates)
        for res in results:
            if res:
                found.append(res)
    return found


def fetch_camera_snapshot(ip: str, token: str) -> bytes:
    """Requisita o frame à câmera apresentando o token obrigatório."""
    headers = {"X-Camera-Token": token}
    # Tenta via header
    try:
        r = requests.get(f"http://{ip}/capture", headers=headers, timeout=3.0)
        if r.status_code == 200 and len(r.content) > 200:
            return r.content
        # Fallback via query param se o firmware suportar
        r2 = requests.get(f"http://{ip}/capture?token={token}", timeout=3.0)
        if r2.status_code == 200 and len(r2.content) > 200:
            return r2.content
    except Exception as e:
        log_lcars(f"Falha ao capturar de {ip}: {e}", "WARN")
    return None



def sync_house_wifis_from_site():
    global house_wifis
    try:
        url = f"{CASA_SERVER.rstrip('/')}/api/v1/camera.php?acao=get_house_wifis"
        req = urllib.request.Request(url, headers=casa_headers())
        with urllib.request.urlopen(req, timeout=6) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            wifis = data.get("wifis", [])
            if isinstance(wifis, list) and wifis:
                house_wifis = wifis
                try:
                    HOUSE_WIFIS_FILE.parent.mkdir(parents=True, exist_ok=True)
                    HOUSE_WIFIS_FILE.write_text(json.dumps(house_wifis, indent=2, ensure_ascii=False), encoding="utf-8")
                except Exception:
                    pass
                log_lcars(f"Redes Wi-Fi da casa sincronizadas do site: {len(house_wifis)} rede(s) ativa(s).", "WIFI_SYNC")
    except Exception as e:
        log_lcars(f"Tentativa de sincronizar Wi-Fi da casa do site: {e}", "DEBUG")
        if HOUSE_WIFIS_FILE.exists():
            try:
                house_wifis = json.loads(HOUSE_WIFIS_FILE.read_text(encoding="utf-8"))
            except Exception:
                pass


def push_wifis_to_camera(ip: str, token: str):
    """Envia a lista de redes Wi-Fi da casa para a ESP32-CAM."""
    if not house_wifis:
        return
    url = f"http://{ip}/config/wifis"
    headers = {"X-Camera-Token": token, "Content-Type": "application/json"}
    payload = json.dumps({"wifis": house_wifis})
    try:
        if requests:
            r = requests.post(url, headers=headers, data=payload, timeout=3.0)
            if r.status_code == 200:
                log_lcars(f"Redes Wi-Fi da casa ({len(house_wifis)}) repassadas para cÃ¢mera em {ip}.", "WIFI_PUSH")
        else:
            req = urllib.request.Request(url, data=payload.encode("utf-8"), headers=headers, method="POST")
            with urllib.request.urlopen(req, timeout=3.0) as resp:
                pass
    except Exception as e:
        log_lcars(f"Falha ao repassar redes Wi-Fi para {ip}: {e}", "DEBUG")


def collect_and_report_wifi_survey(device_id: str, ip: str, token: str):
    """Coleta o survey de sinal das redes Wi-Fi da camera e repassa para a CASA gravar a cada minuto."""
    url = f"http://{ip}/wifi_survey"
    headers = {"X-Camera-Token": token}
    survey_data = None
    try:
        if requests:
            r = requests.get(url, headers=headers, timeout=4.0)
            if r.status_code == 200:
                survey_data = r.json()
        else:
            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=4.0) as resp:
                survey_data = json.loads(resp.read().decode("utf-8"))
    except Exception as e:
        log_lcars(f"Falha ao coletar survey Wi-Fi de [{device_id}] em {ip}: {e}", "DEBUG")
        return

    if not survey_data:
        return

    report_url = f"{CASA_SERVER.rstrip('/')}/api/v1/camera.php?acao=report_wifi_scan"
    payload = {
        "device_id": device_id,
        "ip": ip,
        "wifi_conectado": survey_data.get("connected_ssid", ""),
        "rssi_conectado": survey_data.get("connected_rssi", 0),
        "redes_detectadas": survey_data.get("wifis", [])
    }
    try:
        if requests:
            res = requests.post(report_url, json=payload, headers=casa_headers(), timeout=5.0)
            if res.status_code == 200:
                log_lcars(f"Telemetria de forca Wi-Fi de [{device_id}] gravada no CASA (Ativo: {payload['wifi_conectado']} {payload['rssi_conectado']} dBm, Redes: {len(payload['redes_detectadas'])}).", "WIFI_SURVEY")
            else:
                log_lcars(f"Relatorio de Wi-Fi recusado pelo CASA: HTTP {res.status_code}", "WARN")
        else:
            post_data = json.dumps(payload).encode("utf-8")
            req = urllib.request.Request(report_url, data=post_data, headers=casa_headers({"Content-Type": "application/json"}))
            with urllib.request.urlopen(req, timeout=5.0) as resp:
                pass
    except Exception as e:
        log_lcars(f"Erro ao enviar relatorio de Wi-Fi para CASA: {e}", "WARN")

def upload_snapshot_to_casa(device_id: str, ip: str, img_bytes: bytes):
    """Envia o frame capturado para o site central CASA (maurinsoft.com.br)."""
    url = f"{CASA_SERVER.rstrip('/')}/api/v1/camera.php?acao=upload_snapshot"
    try:
        headers = casa_headers({
            "Content-Type": "image/jpeg",
            "X-Device-Id": device_id,
        })
        params = {
            "acao": "upload_snapshot",
            "device_id": device_id,
            "ip": ip,
            "nome": f"ESP32-CAM ({device_id})"
        }
        res = requests.post(url, params=params, data=img_bytes, headers=headers, timeout=6.0)
        if res.status_code == 200:
            log_lcars(f"Frame de [{device_id}] enviado com sucesso à Nuvem CASA ({len(img_bytes)} bytes)!", "UPLOAD_OK")
        else:
            log_lcars(f"Upload para nuvem retornou HTTP {res.status_code}: {res.text[:120]}", "WARN")
    except Exception as e:
        log_lcars(f"Erro ao enviar snapshot à nuvem CASA: {e}", "WARN")


def run_daemon_loop(interval_sec: float = 8.0):
    global is_leader, last_wifi_survey_time
    if not CASA_API_KEY:
        log_lcars("CASA_API_KEY nao definida: o site vai recusar tokens, redes Wi-Fi e uploads (HTTP 401).", "AVISO")
    log_lcars("=== COMPUTADOR CENTRAL LCARS - DAEMON ÚNICO DE CÂMERAS ATIVADO ===", "START")
    load_token_vault()
    sync_house_wifis_from_site()
    start_control_server()

    # Diretorio local de gravacao temporaria
    snap_dir = BASE_DIR / "snapshots"
    snap_dir.mkdir(exist_ok=True)

    while True:
        try:
            if not is_leader:
                time.sleep(interval_sec)
                continue

            # 1. Recarrega tokens se arquivo mudou
            load_token_vault()

            # 2. Localiza câmeras na rede
            cameras = scan_network_cameras()
            for ip, did, data in cameras:
                known_cameras[did] = {"ip": ip, "last_seen": time.time(), "data": data}

                # Verifica se temos o token para este dispositivo
                token = find_token(did, ip)
                if not token:
                    log_lcars(f"Câmera detectada em {ip} [{did}], mas NENHUM TOKEN registrado no cofre. Use o 'gravafirmware' para obter a chave pela Serial.", "AVISO_TOKEN")
                    continue

                log_lcars(f"Câmera autorizada [{did}] em {ip}. Solicitando captura com chave de segurança...", "CAPTURE")
                frame_data = fetch_camera_snapshot(ip, token)
                if frame_data:
                    # Salva localmente
                    frame_path = snap_dir / f"{did}.jpg"
                    frame_path.write_bytes(frame_data)

                    # Envia para a Nuvem CASA
                    upload_snapshot_to_casa(did, ip, frame_data)

            # 3. Coleta e repassa survey de sinal Wi-Fi a cada 1 minuto (60s)
            now = time.time()
            if now - last_wifi_survey_time >= 60.0:
                last_wifi_survey_time = now
                sync_house_wifis_from_site()
                for did, info in known_cameras.items():
                    c_ip = info.get("ip")
                    t = find_token(did, c_ip)
                    if t and c_ip:
                        # Repassa redes cadastradas da casa para a camera
                        push_wifis_to_camera(c_ip, t)
                        # Coleta medicao de sinal e grava na nuvem CASA
                        collect_and_report_wifi_survey(did, c_ip, t)

        except Exception as e:
            log_lcars(f"Anomalia no ciclo do daemon: {e}", "ERRO")

        time.sleep(interval_sec)


def main():
    parser = argparse.ArgumentParser(description="JARVIS ESP32-CAM Single Responsible Daemon")
    parser.add_argument("--interval", type=float, default=8.0, help="Intervalo de ciclo em segundos")
    args = parser.parse_args()
    run_daemon_loop(args.interval)


if __name__ == "__main__":
    main()
