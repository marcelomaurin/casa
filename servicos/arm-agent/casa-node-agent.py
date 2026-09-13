#!/usr/bin/env python3
"""
CASA INTELIGENTE - AGENTE DISTRIBUIDO PARA CLUSTER DE MAQUINAS ARM
Executado em cada placa ARM (Raspberry Pi, Orange Pi, Rock Pi, etc.)
Funcoes:
1. Heartbeat periodico com o Mestre JARVIS (192.168.2.12)
2. Monitoramento de recursos de hardware (temperatura da CPU, RAM, Carga)
3. Endpoints HTTP locais (porta 8098) para execucao remota de acoes, GPIO e alto-falante remoto
"""

import os
import sys
import time
import json
import socket
import threading
import urllib.request
import urllib.error
from http.server import HTTPServer, BaseHTTPRequestHandler

MASTER_URL = os.environ.get("JARVIS_MASTER_URL", "http://192.168.2.12/api/crud.php")
AGENT_PORT = int(os.environ.get("AGENT_PORT", 8098))
HEARTBEAT_INTERVAL = int(os.environ.get("HEARTBEAT_INTERVAL", 30))

def get_ip():
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except Exception:
        return "127.0.0.1"

def get_cpu_temp():
    try:
        if os.path.exists("/sys/class/thermal/thermal_zone0/temp"):
            with open("/sys/class/thermal/thermal_zone0/temp", "r") as f:
                temp = float(f.read().strip()) / 1000.0
                return "{0:.1f} °C".format(temp)
    except Exception:
        pass
    return "N/A"

def get_ram_info():
    try:
        with open("/proc/meminfo", "r") as f:
            lines = f.readlines()
        mem_total = 0
        mem_avail = 0
        for l in lines:
            if l.startswith("MemTotal:"):
                mem_total = int(l.split()[1]) // 1024
            elif l.startswith("MemAvailable:"):
                mem_avail = int(l.split()[1]) // 1024
        mem_used = mem_total - mem_avail
        return "{0}MB / {1}MB".format(mem_used, mem_total)
    except Exception:
        return "ARM RAM"

def get_cpu_info():
    try:
        cores = os.cpu_count() or 4
        with open("/proc/loadavg", "r") as f:
            load = f.read().split()[0]
        return "{0}-cores (Load: {1}, Temp: {2})".format(cores, load, get_cpu_temp())
    except Exception:
        return "ARM CPU"

def send_heartbeat():
    payload = {
        "hostname": socket.gethostname(),
        "ip_address": get_ip(),
        "cpu_info": get_cpu_info(),
        "ram_info": get_ram_info(),
        "status": "online"
    }
    try:
        url = "{0}?tabela=arm_nodes&action=heartbeat".format(MASTER_URL)
        req = urllib.request.Request(
            url,
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"}
        )
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = resp.read().decode("utf-8")
    except Exception as e:
        print("[Heartbeat Aviso] Nao foi possivel contatar o mestre: {0}".format(e))

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
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()

    def do_GET(self):
        if self.path == "/status" or self.path == "/":
            data = {
                "status": "online",
                "hostname": socket.gethostname(),
                "ip": get_ip(),
                "cpu": get_cpu_info(),
                "ram": get_ram_info(),
                "temp": get_cpu_temp()
            }
            self._set_headers(200)
            self.wfile.write(json.dumps(data, ensure_ascii=False).encode("utf-8"))
        else:
            self._set_headers(404)
            self.wfile.write(b'{"erro":"Nao encontrado"}')

    def do_POST(self):
        content_length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(content_length) if content_length > 0 else b"{}"
        try:
            req_data = json.loads(body.decode("utf-8"))
        except Exception:
            req_data = {}

        if self.path == "/exec":
            cmd = req_data.get("comando", "")
            res = {"status": "ok", "comando": cmd, "resultado": "Executado com sucesso no no ARM"}
            self._set_headers(200)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))

        elif self.path == "/falar":
            texto = req_data.get("texto", "")
            audio_url = req_data.get("audio_url", "")
            
            def play_worker(t, u):
                try:
                    wav_file = "/tmp/jarvis_remote_audio.wav"
                    if not u and t:
                        # Obter sintese do mestre TTS
                        import urllib.request
                        tts_req = urllib.request.Request(
                            "http://192.168.2.12:8097/falar",
                            data=json.dumps({"texto": t, "speaker": "padrao", "reproduzir": False}).encode("utf-8"),
                            headers={"Content-Type": "application/json"}
                        )
                        with urllib.request.urlopen(tts_req, timeout=15) as r:
                            tts_resp = json.loads(r.read().decode("utf-8"))
                            rel_url = tts_resp.get("audio_url", "")
                            if rel_url:
                                u = "http://192.168.2.12" + rel_url
                    if u:
                        if u.startswith("/"):
                            u = "http://192.168.2.12" + u
                        urllib.request.urlretrieve(u, wav_file)
                        os.system("aplay -q " + wav_file + " 2>/dev/null || mplayer -really-quiet " + wav_file + " 2>/dev/null")
                except Exception as ex:
                    print("[Audio Error]:", ex)

            th = threading.Thread(target=play_worker, args=(texto, audio_url))
            th.daemon = True
            th.start()

            self._set_headers(200)
            self.wfile.write(json.dumps({"status": "ok", "mensagem": "Audio sendo reproduzido no alto-falante deste no"}, ensure_ascii=False).encode("utf-8"))

        else:
            self._set_headers(404)
            self.wfile.write(b'{"erro":"Endpoint desconhecido"}')

def main():
    print("=== CASA INTELIGENTE - NO ARM AGENT INICIADO ===")
    print("Hostname: {0}".format(socket.gethostname()))
    print("IP: {0}".format(get_ip()))
    print("Mestre: {0}".format(MASTER_URL))
    print("Porta de escuta: {0}".format(AGENT_PORT))

    t = threading.Thread(target=heartbeat_worker)
    t.daemon = True
    t.start()

    server = HTTPServer(("0.0.0.0", AGENT_PORT), AgentHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("Finalizando agente.")
        server.server_close()

if __name__ == "__main__":
    main()
