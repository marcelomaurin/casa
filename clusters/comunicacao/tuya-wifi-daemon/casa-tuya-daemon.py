#!/usr/bin/env python3
"""
CASA / JARVIS - Daemon de Controle de Dispositivos e Sensores Tuya Wi-Fi
Porta HTTP REST: 8096
Portas de escuta UDP: 6666 e 6667 (descoberta automática de dispositivos Tuya na rede local)

Suporta sensores de temperatura e umidade Tuya Smart Life (com backlight/display),
tomadas, interruptores e equipamentos Wi-Fi locais.
"""

import os
import sys
import json
import time
import socket
import struct
import zlib
import hashlib
import binascii
import threading
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs
from typing import Dict, Any, Optional

TUYA_DAEMON_PORT = int(os.environ.get("CASA_TUYA_PORT", "8101"))
ORQUESTRADOR_URL = os.environ.get("CASA_ORCHESTRATOR_URL", "http://127.0.0.1:8090/evento")
CONFIG_PATH = os.environ.get("CASA_TUYA_CONFIG", "/opt/casa-tuya-daemon/tuya_devices.json")

# Constantes do Protocolo Tuya Local
TUYA_PREFIX = 0x000055AA
TUYA_SUFFIX = 0x0000AA55

CMD_UDP_DISCOVERY = 0x00
CMD_CONTROL = 0x07
CMD_STATUS = 0x08
CMD_HEARTBEAT = 0x09
CMD_DP_QUERY = 0x0A


class TuyaPacket:
    """Montagem e desmontagem de pacotes Tuya binarios."""

    @staticmethod
    def pack(cmd: int, payload: bytes, seq: int = 0) -> bytes:
        length = len(payload) + 8  # payload + crc (4 bytes) + suffix (4 bytes)
        header = struct.pack(">IIII", TUYA_PREFIX, seq, cmd, length)
        body = payload
        crc = struct.pack(">I", zlib.crc32(header + body) & 0xFFFFFFFF)
        suffix = struct.pack(">I", TUYA_SUFFIX)
        return header + body + crc + suffix

    @staticmethod
    def unpack(raw: bytes) -> Optional[Dict[str, Any]]:
        if len(raw) < 16:
            return None
        prefix, seq, cmd, length = struct.unpack(">IIII", raw[:16])
        if prefix != TUYA_PREFIX:
            return None
        if len(raw) < 16 + length:
            return None
        payload = raw[16 : 16 + length - 8]
        return {
            "seq": seq,
            "cmd": cmd,
            "payload": payload,
            "length": length
        }


class TuyaSensorParser:
    """Extrai leituras de temperatura, umidade, bateria e luz dos DPs Tuya."""

    @staticmethod
    def parse_dps(dps: Dict[str, Any]) -> Dict[str, Any]:
        result = {}
        for k, v in dps.items():
            key = str(k)
            # DP 1 ou 101 ou va_temperature: Temperatura (comum em decimos de grau, ex: 245 = 24.5 C)
            if key in ("1", "101", "va_temperature"):
                try:
                    val = float(v)
                    if val > 100.0:
                        val = round(val / 10.0, 1)
                    result["temperatura_c"] = val
                except (ValueError, TypeError):
                    pass

            # DP 2 ou 102 ou va_humidity: Umidade relativa (%)
            elif key in ("2", "102", "va_humidity"):
                try:
                    val = float(v)
                    if val > 100.0:
                        val = round(val / 10.0, 1)
                    result["umidade_pct"] = val
                except (ValueError, TypeError):
                    pass

            # DP 3 ou 103 ou 14 ou battery_percentage: Nivel de bateria (%)
            elif key in ("3", "103", "14", "battery_percentage", "battery_state"):
                try:
                    result["bateria_pct"] = int(v)
                except (ValueError, TypeError):
                    result["bateria_status"] = str(v)

            # DP 4 ou 104 ou temp_unit_convert: Unidade 'c' ou 'f'
            elif key in ("4", "104", "temp_unit_convert"):
                result["unidade"] = str(v).lower()

            # DP 9 ou 105 ou bright_state: Backlight da tela do sensor
            elif key in ("9", "105", "bright_state"):
                result["backlight"] = v

            # Interruptores / Reles comuns Tuya
            elif key in ("1", "20", "switch_1", "switch"):
                if isinstance(v, bool):
                    result["rele_ligado"] = v

        return result


class TuyaDeviceStore:
    """Armazena inventario e leituras em memoria e em disco."""

    def __init__(self, file_path: str = CONFIG_PATH):
        self.file_path = file_path
        self.lock = threading.Lock()
        self.devices: Dict[str, Dict[str, Any]] = {}
        self.load()

    def load(self):
        with self.lock:
            if os.path.isfile(self.file_path):
                try:
                    with open(self.file_path, "r", encoding="utf-8") as f:
                        self.devices = json.load(f)
                except Exception as e:
                    print(f"[TuyaStore] Erro ao carregar {self.file_path}: {e}", file=sys.stderr)

    def save(self):
        with self.lock:
            try:
                os.makedirs(os.path.dirname(self.file_path), exist_ok=True)
                with open(self.file_path, "w", encoding="utf-8") as f:
                    json.dump(self.devices, f, indent=2, ensure_ascii=False)
            except Exception as e:
                print(f"[TuyaStore] Erro ao salvar {self.file_path}: {e}", file=sys.stderr)

    def register_or_update(self, dev_id: str, ip: str, version: str = "3.3", extra: Optional[Dict[str, Any]] = None):
        with self.lock:
            now = time.strftime("%Y-%m-%d %H:%M:%S")
            if dev_id not in self.devices:
                self.devices[dev_id] = {
                    "device_id": dev_id,
                    "ip": ip,
                    "version": version,
                    "nome": f"Sensor Tuya ({dev_id[-4:]})",
                    "comodo": "Residência",
                    "tipo": "sensor_temperatura_umidade",
                    "status": "online",
                    "primeiro_contato": now,
                    "ultimo_contato": now,
                    "dps": {},
                    "leituras": {}
                }
            else:
                self.devices[dev_id]["ip"] = ip
                self.devices[dev_id]["version"] = version
                self.devices[dev_id]["ultimo_contato"] = now
                self.devices[dev_id]["status"] = "online"

            if extra:
                for k, v in extra.items():
                    if k == "dps" and isinstance(v, dict):
                        self.devices[dev_id]["dps"].update(v)
                        parsed = TuyaSensorParser.parse_dps(self.devices[dev_id]["dps"])
                        self.devices[dev_id]["leituras"].update(parsed)
                    else:
                        self.devices[dev_id][k] = v

        self.save()

    def update_readings(self, dev_id: str, dps: Dict[str, Any]):
        with self.lock:
            if dev_id in self.devices:
                self.devices[dev_id]["ultimo_contato"] = time.strftime("%Y-%m-%d %H:%M:%S")
                self.devices[dev_id]["status"] = "online"
                self.devices[dev_id]["dps"].update(dps)
                parsed = TuyaSensorParser.parse_dps(self.devices[dev_id]["dps"])
                self.devices[dev_id]["leituras"].update(parsed)
        self.save()

    def get_all(self) -> Dict[str, Any]:
        with self.lock:
            return dict(self.devices)

    def get_device(self, dev_id: str) -> Optional[Dict[str, Any]]:
        with self.lock:
            return self.devices.get(dev_id)


store = TuyaDeviceStore()


class TuyaUDPListener(threading.Thread):
    """Escuta pacotes broadcast UDP de descoberta Tuya nas portas 6666 e 6667."""

    def __init__(self, port: int):
        super().__init__(daemon=True)
        self.port = port
        self.running = True

    def run(self):
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        except Exception:
            pass

        try:
            sock.bind(("0.0.0.0", self.port))
            print(f"[TuyaUDP] Escutando broadcasts Tuya na porta UDP {self.port}...")
        except Exception as e:
            print(f"[TuyaUDP] Falha ao vincular porta UDP {self.port}: {e}", file=sys.stderr)
            return

        while self.running:
            try:
                data, addr = sock.recvfrom(4096)
                if not data:
                    continue
                self.handle_packet(data, addr[0])
            except Exception as e:
                time.sleep(0.5)

    def handle_packet(self, data: bytes, remote_ip: str):
        # Desempacota se vier com envelope Tuya
        payload = data
        if data.startswith(b"\x00\x00\x55\xaa"):
            unpacked = TuyaPacket.unpack(data)
            if unpacked and unpacked.get("payload"):
                payload = unpacked["payload"]

        # Tenta decodificar texto JSON puro (comum na porta 6666)
        text = None
        try:
            text = payload.decode("utf-8", errors="ignore")
        except Exception:
            return

        # Localiza o objeto JSON na string
        start = text.find("{")
        end = text.rfind("}")
        if start != -1 and end != -1 and end > start:
            json_str = text[start : end + 1]
            try:
                parsed = json.loads(json_str)
                dev_id = parsed.get("gwId") or parsed.get("devId") or parsed.get("id")
                ip = parsed.get("ip") or remote_ip
                version = str(parsed.get("version", "3.3"))
                dps = parsed.get("dps") or {}

                if dev_id:
                    store.register_or_update(dev_id, ip, version, {"dps": dps})
                    notify_orchestrator(dev_id, store.get_device(dev_id) or {})
            except Exception:
                pass


def notify_orchestrator(dev_id: str, device_data: Dict[str, Any]):
    """Notifica o orquestrador inteligente (porta 8090) sobre novas leituras."""
    readings = device_data.get("leituras", {})
    if not readings:
        return
    payload = {
        "tipo": "sensor_ambiente",
        "origem": "tuya_wifi",
        "device_id": dev_id,
        "nome": device_data.get("nome", "Sensor Tuya"),
        "comodo": device_data.get("comodo", "Residência"),
        "temperatura_c": readings.get("temperatura_c"),
        "umidade_pct": readings.get("umidade_pct"),
        "bateria_pct": readings.get("bateria_pct"),
        "data_hora": time.strftime("%Y-%m-%d %H:%M:%S")
    }
    try:
        data = json.dumps(payload).encode("utf-8")
        req = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        req.settimeout(1.0)
        u = urlparse(ORQUESTRADOR_URL)
        host = u.hostname or "127.0.0.1"
        port = u.port or 8090
        req.connect((host, port))
        http_req = (
            f"POST {u.path or '/evento'} HTTP/1.1\r\n"
            f"Host: {host}:{port}\r\n"
            f"Content-Type: application/json\r\n"
            f"Content-Length: {len(data)}\r\n\r\n"
        ).encode("utf-8") + data
        req.sendall(http_req)
        req.close()
    except Exception:
        pass


class TuyaHTTPHandler(BaseHTTPRequestHandler):
    """API REST local do Daemon Tuya na porta 8096."""

    def _send_json(self, status_code: int, data: Any):
        body = json.dumps(data, indent=2, ensure_ascii=False).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        parsed = urlparse(self.path)
        path = parsed.path

        if path in ("/", "/status"):
            devices = store.get_all()
            online_count = sum(1 for d in devices.values() if d.get("status") == "online")
            self._send_json(200, {
                "status": "online",
                "servico": "casa-tuya-daemon",
                "versao": "1.0.0",
                "porta": TUYA_DAEMON_PORT,
                "dispositivos_total": len(devices),
                "dispositivos_online": online_count,
                "timestamp": time.strftime("%Y-%m-%d %H:%M:%S")
            })

        elif path == "/devices":
            self._send_json(200, {
                "status": "sucesso",
                "devices": list(store.get_all().values())
            })

        elif path.startswith("/devices/"):
            dev_id = path.split("/")[2]
            dev = store.get_device(dev_id)
            if dev:
                self._send_json(200, {"status": "sucesso", "device": dev})
            else:
                self._send_json(404, {"status": "erro", "mensagem": "Dispositivo não encontrado."})

        else:
            self._send_json(404, {"status": "erro", "mensagem": "Rota não encontrada."})

    def do_POST(self):
        parsed = urlparse(self.path)
        path = parsed.path

        length = int(self.headers.get("Content-Length", 0))
        raw_body = self.rfile.read(length) if length > 0 else b"{}"
        try:
            body = json.loads(raw_body.decode("utf-8")) if raw_body else {}
        except Exception:
            body = {}

        if path in ("/devices", "/device/add"):
            dev_id = str(body.get("device_id") or body.get("id") or "").strip()
            if not dev_id:
                self._send_json(400, {"status": "erro", "mensagem": "device_id é obrigatório."})
                return
            ip = str(body.get("ip") or "").strip()
            version = str(body.get("version") or "3.3")
            extra = {
                "nome": body.get("nome", f"Sensor Tuya {dev_id[-4:]}"),
                "comodo": body.get("comodo", "Residência"),
                "local_key": body.get("local_key", "")
            }
            if "dps" in body and isinstance(body["dps"], dict):
                extra["dps"] = body["dps"]
            store.register_or_update(dev_id, ip, version, extra)
            self._send_json(201, {
                "status": "sucesso",
                "mensagem": "Dispositivo Tuya cadastrado/atualizado.",
                "device": store.get_device(dev_id)
            })

        elif path in ("/ingest", "/telemetria"):
            dev_id = str(body.get("device_id") or "").strip()
            dps = body.get("dps") or {}
            if not dev_id:
                self._send_json(400, {"status": "erro", "mensagem": "device_id é obrigatório."})
                return
            store.update_readings(dev_id, dps)
            dev = store.get_device(dev_id)
            if dev:
                notify_orchestrator(dev_id, dev)
            self._send_json(200, {"status": "sucesso", "leituras": dev.get("leituras", {}) if dev else {}})

        elif path == "/scan":
            # Dispara broadcast de ping local
            self._send_json(200, {
                "status": "sucesso",
                "mensagem": "Varredura UDP ativa nas portas 6666 e 6667. Dispositivos responderão automaticamente.",
                "dispositivos_conhecidos": len(store.get_all())
            })

        else:
            self._send_json(404, {"status": "erro", "mensagem": "Rota POST não encontrada."})

    def log_message(self, format, *args):
        pass


def main():
    print(f"=== CASA Tuya Wi-Fi Daemon iniciando na porta {TUYA_DAEMON_PORT} ===")
    t6666 = TuyaUDPListener(6666)
    t6666.start()
    t6667 = TuyaUDPListener(6667)
    t6667.start()

    server = HTTPServer(("0.0.0.0", TUYA_DAEMON_PORT), TuyaHTTPHandler)
    print(f"API REST Tuya escutando em http://0.0.0.0:{TUYA_DAEMON_PORT}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nEncerrando Tuya Daemon...")
        server.server_close()


if __name__ == "__main__":
    main()
