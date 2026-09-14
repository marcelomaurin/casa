#!/usr/bin/env python3
"""
CASA INTELIGENTE - AGENTE DISTRIBUIDO PARA NOS ARM

Cada Raspberry/Orange Pi/Rock Pi se registra em casa.maurinsoft.com.br com
identidade, token individual e lista de capacidades. Nao existe mestre por IP
fixo; a API central coordena os nos e a execucao permanece distribuida.
"""

import os
import time
import json
import socket
import threading
import urllib.request
from http.server import HTTPServer, BaseHTTPRequestHandler

CASA_BASE_URL = os.environ.get("CASA_BASE_URL", "https://casa.maurinsoft.com.br").rstrip("/")
MASTER_URL = os.environ.get("JARVIS_MASTER_URL", CASA_BASE_URL + "/api/crud.php")
DEVICE_ID = os.environ.get("JARVIS_DEVICE_ID", socket.gethostname()).strip()
DEVICE_TOKEN = os.environ.get("JARVIS_DEVICE_TOKEN", "").strip()
CAPABILITIES = [x.strip() for x in os.environ.get(
    "JARVIS_CAPABILITIES",
    "arm-agent,hardware-gateway"
).split(",") if x.strip()]
LOCAL_TTS_URL = os.environ.get("JARVIS_LOCAL_TTS_URL", "").strip().rstrip("/")
AGENT_PORT = int(os.environ.get("AGENT_PORT", 8098))
HEARTBEAT_INTERVAL = int(os.environ.get("HEARTBEAT_INTERVAL", 30))


def get_ip():
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect(("8.8.8.8", 80))
            return s.getsockname()[0]
    except Exception:
        return "127.0.0.1"


def get_cpu_temp():
    try:
        path = "/sys/class/thermal/thermal_zone0/temp"
        if os.path.exists(path):
            with open(path, "r", encoding="utf-8") as f:
                return "{0:.1f} °C".format(float(f.read().strip()) / 1000.0)
    except Exception:
        pass
    return "N/A"


def get_ram_info():
    try:
        mem_total = 0
        mem_avail = 0
        with open("/proc/meminfo", "r", encoding="utf-8") as f:
            for line in f:
                if line.startswith("MemTotal:"):
                    mem_total = int(line.split()[1]) // 1024
                elif line.startswith("MemAvailable:"):
                    mem_avail = int(line.split()[1]) // 1024
        return "{0}MB / {1}MB".format(mem_total - mem_avail, mem_total)
    except Exception:
        return "ARM RAM"


def get_cpu_info():
    try:
        cores = os.cpu_count() or 1
        with open("/proc/loadavg", "r", encoding="utf-8") as f:
            load = f.read().split()[0]
        return "{0}-cores (Load: {1}, Temp: {2})".format(cores, load, get_cpu_temp())
    except Exception:
        return "ARM CPU"


def api_headers():
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-Device-Id": DEVICE_ID,
        "X-Device-Capabilities": ",".join(CAPABILITIES),
    }
    if DEVICE_TOKEN:
        headers["Authorization"] = "Bearer " + DEVICE_TOKEN
        headers["X-Device-Token"] = DEVICE_TOKEN
    return headers


def send_heartbeat():
    payload = {
        "device_id": DEVICE_ID,
        "hostname": socket.gethostname(),
        "ip_address": get_ip(),
        "cpu_info": get_cpu_info(),
        "ram_info": get_ram_info(),
        "status": "online",
        "base_url": CASA_BASE_URL,
        "capabilities": CAPABILITIES,
        "uptime_s": int(time.monotonic()),
    }

    try:
        url = "{0}?tabela=arm_nodes&action=heartbeat".format(MASTER_URL)
        req = urllib.request.Request(
            url,
            data=json.dumps(payload).encode("utf-8"),
            headers=api_headers(),
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=10) as resp:
            resp.read()
    except Exception as e:
        print("[Heartbeat Aviso] CASA indisponivel: {0}".format(e))


def heartbeat_worker():
    while True:
        send_heartbeat()
        time.sleep(HEARTBEAT_INTERVAL)


class AgentHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        return

    def _set_headers(self, status=200, content_type="application/json"):
        self.send_response(status)
        self.send_header("Content-Type", "{0}; charset=utf-8".format(content_type))
        self.end_headers()

    def do_GET(self):
        if self.path in ("/status", "/"):
            data = {
                "status": "online",
                "device_id": DEVICE_ID,
                "hostname": socket.gethostname(),
                "ip": get_ip(),
                "cpu": get_cpu_info(),
                "ram": get_ram_info(),
                "temp": get_cpu_temp(),
                "casa": CASA_BASE_URL,
                "capabilities": CAPABILITIES,
            }
            self._set_headers(200)
            self.wfile.write(json.dumps(data, ensure_ascii=False).encode("utf-8"))
            return

        self._set_headers(404)
        self.wfile.write(b'{"erro":"Nao encontrado"}')

    def do_POST(self):
        content_length = min(int(self.headers.get("Content-Length", 0)), 65536)
        body = self.rfile.read(content_length) if content_length > 0 else b"{}"
        try:
            req_data = json.loads(body.decode("utf-8"))
        except Exception:
            req_data = {}

        if self.path == "/exec":
            cmd = req_data.get("comando", "")
            res = {
                "status": "ok",
                "device_id": DEVICE_ID,
                "comando": cmd,
                "resultado": "Recebido pelo no ARM",
            }
            self._set_headers(200)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        if self.path == "/falar":
            texto = req_data.get("texto", "")
            audio_url = req_data.get("audio_url", "")

            def play_worker(t, u):
                try:
                    wav_file = "/tmp/jarvis_remote_audio.wav"
                    if not u and t and LOCAL_TTS_URL:
                        tts_req = urllib.request.Request(
                            LOCAL_TTS_URL + "/falar",
                            data=json.dumps({
                                "texto": t,
                                "speaker": "padrao",
                                "reproduzir": False,
                            }).encode("utf-8"),
                            headers={"Content-Type": "application/json"},
                            method="POST",
                        )
                        with urllib.request.urlopen(tts_req, timeout=15) as response:
                            tts_resp = json.loads(response.read().decode("utf-8"))
                            u = tts_resp.get("audio_url", "")

                    if u:
                        if u.startswith("/"):
                            u = CASA_BASE_URL + u
                        urllib.request.urlretrieve(u, wav_file)
                        os.system(
                            "aplay -q " + wav_file +
                            " 2>/dev/null || mplayer -really-quiet " +
                            wav_file + " 2>/dev/null"
                        )
                except Exception as ex:
                    print("[Audio Error]:", ex)

            threading.Thread(target=play_worker, args=(texto, audio_url), daemon=True).start()
            self._set_headers(200)
            self.wfile.write(json.dumps({
                "status": "ok",
                "device_id": DEVICE_ID,
                "mensagem": "Audio em reproducao no no",
            }, ensure_ascii=False).encode("utf-8"))
            return

        self._set_headers(404)
        self.wfile.write(b'{"erro":"Endpoint desconhecido"}')


def main():
    print("=== CASA INTELIGENTE - NO ARM AGENT ===")
    print("Device ID: {0}".format(DEVICE_ID))
    print("Hostname: {0}".format(socket.gethostname()))
    print("IP local: {0}".format(get_ip()))
    print("CASA: {0}".format(CASA_BASE_URL))
    print("API: {0}".format(MASTER_URL))
    print("Capacidades: {0}".format(", ".join(CAPABILITIES)))
    print("Porta local: {0}".format(AGENT_PORT))

    if not DEVICE_TOKEN:
        print("AVISO: JARVIS_DEVICE_TOKEN nao configurado.")

    threading.Thread(target=heartbeat_worker, daemon=True).start()
    server = HTTPServer(("0.0.0.0", AGENT_PORT), AgentHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.server_close()


if __name__ == "__main__":
    main()
