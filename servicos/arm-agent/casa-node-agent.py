#!/usr/bin/env python3
"""
CASA/JARVIS - AGENTE DISTRIBUIDO PARA NOS ARM

O agente e um device comum do Control Plane CASA/1.0.
Usa a API universal para heartbeat, comandos, ACK, resultados e eventos.
A API HTTP local e mantida apenas para compatibilidade e diagnostico.
"""

import json
import os
import socket
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import HTTPServer, BaseHTTPRequestHandler

CASA_BASE_URL = os.environ.get(
    "CASA_BASE_URL", "https://casa.maurinsoft.com.br"
).rstrip("/")
DEVICE_API = CASA_BASE_URL + "/api/v1/device.php"

DEVICE_ID = os.environ.get("JARVIS_DEVICE_ID", socket.gethostname()).strip()
DEVICE_TOKEN = os.environ.get("JARVIS_DEVICE_TOKEN", "").strip()
CAPABILITIES = [
    x.strip() for x in os.environ.get(
        "JARVIS_CAPABILITIES",
        "gateway,arm-agent,hardware-gateway"
    ).split(",") if x.strip()
]
LOCAL_TTS_URL = os.environ.get("JARVIS_LOCAL_TTS_URL", "").strip().rstrip("/")
AGENT_PORT = int(os.environ.get("AGENT_PORT", 8098))
HEARTBEAT_INTERVAL = int(os.environ.get("HEARTBEAT_INTERVAL", 30))
COMMAND_POLL_INTERVAL = float(os.environ.get("COMMAND_POLL_INTERVAL", 2.5))
PROTOCOL_VERSION = "CASA/1.0"
AGENT_VERSION = "1.1.0"


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
                return round(float(f.read().strip()) / 1000.0, 1)
    except Exception:
        pass
    return None


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
        return {
            "used_mb": mem_total - mem_avail,
            "total_mb": mem_total,
        }
    except Exception:
        return {}


def get_cpu_info():
    try:
        with open("/proc/loadavg", "r", encoding="utf-8") as f:
            load = float(f.read().split()[0])
        return {
            "cores": os.cpu_count() or 1,
            "load_1m": load,
            "temp_c": get_cpu_temp(),
        }
    except Exception:
        return {"cores": os.cpu_count() or 1}


def api_headers():
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-Device-Id": DEVICE_ID,
    }
    if DEVICE_TOKEN:
        headers["Authorization"] = "Bearer " + DEVICE_TOKEN
        headers["X-Device-Token"] = DEVICE_TOKEN
    return headers


def api_request(action, method="GET", payload=None, params=None, timeout=10):
    query = {"acao": action}
    if params:
        query.update(params)
    url = DEVICE_API + "?" + urllib.parse.urlencode(query)

    data = None
    if payload is not None:
        if "device_id" not in payload:
            payload["device_id"] = DEVICE_ID
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")

    req = urllib.request.Request(
        url,
        data=data,
        headers=api_headers(),
        method=method,
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8")
        return json.loads(raw) if raw else {}


def send_heartbeat():
    payload = {
        "device_id": DEVICE_ID,
        "transport": "https",
        "local_ip": get_ip(),
        "health": "ok",
        "firmware_version": AGENT_VERSION,
        "protocol_version": PROTOCOL_VERSION,
        "uptime_sec": int(time.monotonic()),
        "capabilities": CAPABILITIES,
        "data": {
            "platform": "linux-arm",
            "hostname": socket.gethostname(),
            "cpu": get_cpu_info(),
            "ram": get_ram_info(),
            "agent_port": AGENT_PORT,
        },
    }
    return api_request("heartbeat", "POST", payload, timeout=10)


def publish_event(event_type, data=None, priority="normal", correlation_id=None):
    payload = {
        "device_id": DEVICE_ID,
        "type": event_type,
        "priority": priority,
        "data": data or {},
    }
    if correlation_id:
        payload["correlation_id"] = correlation_id
    return api_request("event", "POST", payload, timeout=10)


def ack_command(command_id):
    return api_request(
        "command_ack",
        "POST",
        {"device_id": DEVICE_ID, "id": int(command_id)},
        timeout=10,
    )


def report_result(command_id, ok, result=None, error=None):
    payload = {
        "device_id": DEVICE_ID,
        "id": int(command_id),
        "status": "success" if ok else "error",
        "result": result or {},
    }
    if not ok:
        payload["error"] = str(error or "Falha no adapter")
    return api_request("command_result", "POST", payload, timeout=10)


def parse_payload(value):
    if isinstance(value, dict):
        return value
    if isinstance(value, str) and value.strip():
        try:
            decoded = json.loads(value)
            return decoded if isinstance(decoded, dict) else {}
        except Exception:
            return {}
    return {}


def play_audio(text="", audio_url=""):
    url = (audio_url or "").strip()

    if not url and text and LOCAL_TTS_URL:
        req = urllib.request.Request(
            LOCAL_TTS_URL + "/falar",
            data=json.dumps({
                "texto": text,
                "speaker": "padrao",
                "reproduzir": False,
            }).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=20) as response:
            tts_resp = json.loads(response.read().decode("utf-8"))
            url = str(tts_resp.get("audio_url", "")).strip()

    if not url:
        raise RuntimeError("Nenhum audio_url disponivel e TTS local nao configurado")

    if url.startswith("/"):
        url = CASA_BASE_URL + url

    wav_file = "/tmp/jarvis_remote_audio.wav"
    urllib.request.urlretrieve(url, wav_file)

    players = [
        ["aplay", "-q", wav_file],
        ["mplayer", "-really-quiet", wav_file],
    ]
    for command in players:
        try:
            proc = subprocess.run(
                command,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                timeout=120,
                check=False,
            )
            if proc.returncode == 0:
                return {"played": True, "player": command[0]}
        except FileNotFoundError:
            continue

    raise RuntimeError("Nenhum player de audio disponivel")


def execute_adapter(command, payload):
    """
    Dispatcher deterministico. A IA escolhe a intencao; este agente executa
    apenas comandos explicitamente conhecidos.
    """
    if command in ("system.status", "status"):
        return {
            "device_id": DEVICE_ID,
            "hostname": socket.gethostname(),
            "ip": get_ip(),
            "cpu": get_cpu_info(),
            "ram": get_ram_info(),
            "capabilities": CAPABILITIES,
        }

    if command in ("tts.speak", "audio.play", "falar"):
        result = play_audio(
            str(payload.get("text", payload.get("texto", ""))),
            str(payload.get("audio_url", "")),
        )
        return result

    if command in ("device.discovery", "gateway.discovery"):
        return {
            "device_id": DEVICE_ID,
            "capabilities": CAPABILITIES,
            "message": "Discovery de adapters especificos ainda nao configurado",
        }

    raise RuntimeError("Comando sem adapter autorizado: {0}".format(command))


def poll_commands_once():
    response = api_request(
        "commands",
        "GET",
        params={"device_id": DEVICE_ID, "limit": 10},
        timeout=10,
    )
    commands = response.get("commands", [])
    if not isinstance(commands, list):
        return

    for item in commands:
        if not isinstance(item, dict):
            continue

        command_id = int(item.get("id") or 0)
        command = str(item.get("comando") or "").strip()
        payload = parse_payload(item.get("payload"))

        if command_id <= 0 or not command:
            continue

        try:
            ack_command(command_id)
            result = execute_adapter(command, payload)
            report_result(command_id, True, result=result)
        except Exception as exc:
            print("[Command Error] #{0} {1}: {2}".format(
                command_id, command, exc
            ))
            try:
                report_result(
                    command_id,
                    False,
                    result={"command": command},
                    error=str(exc),
                )
            except Exception as report_exc:
                print("[Result Error]:", report_exc)


def heartbeat_worker():
    while True:
        try:
            send_heartbeat()
        except Exception as exc:
            print("[Heartbeat Aviso] CASA indisponivel: {0}".format(exc))
        time.sleep(HEARTBEAT_INTERVAL)


def command_worker():
    delay = COMMAND_POLL_INTERVAL
    while True:
        try:
            poll_commands_once()
            delay = COMMAND_POLL_INTERVAL
        except urllib.error.HTTPError as exc:
            print("[Command HTTP] {0}".format(exc))
            delay = min(max(delay * 2, 5), 30)
        except Exception as exc:
            print("[Command Aviso] {0}".format(exc))
            delay = min(max(delay * 2, 5), 30)
        time.sleep(delay)


class AgentHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        return

    def _set_headers(self, status=200, content_type="application/json"):
        self.send_response(status)
        self.send_header(
            "Content-Type", "{0}; charset=utf-8".format(content_type)
        )
        self.end_headers()

    def _json(self, status, obj):
        self._set_headers(status)
        self.wfile.write(
            json.dumps(obj, ensure_ascii=False).encode("utf-8")
        )

    def do_GET(self):
        if self.path in ("/status", "/", "/health"):
            self._json(200, {
                "status": "online",
                "device_id": DEVICE_ID,
                "protocol_version": PROTOCOL_VERSION,
                "agent_version": AGENT_VERSION,
                "hostname": socket.gethostname(),
                "ip": get_ip(),
                "cpu": get_cpu_info(),
                "ram": get_ram_info(),
                "casa": CASA_BASE_URL,
                "capabilities": CAPABILITIES,
            })
            return

        self._json(404, {"erro": "Nao encontrado"})

    def do_POST(self):
        content_length = min(
            int(self.headers.get("Content-Length", 0)), 65536
        )
        body = self.rfile.read(content_length) if content_length > 0 else b"{}"
        try:
            req_data = json.loads(body.decode("utf-8"))
        except Exception:
            req_data = {}

        # Compatibilidade local: /exec usa o mesmo dispatcher do Command Bus.
        if self.path == "/exec":
            command = str(req_data.get("comando", "")).strip()
            payload = req_data.get("payload", {})
            try:
                result = execute_adapter(
                    command,
                    payload if isinstance(payload, dict) else {},
                )
                self._json(200, {
                    "status": "ok",
                    "device_id": DEVICE_ID,
                    "comando": command,
                    "resultado": result,
                })
            except Exception as exc:
                self._json(400, {
                    "status": "erro",
                    "device_id": DEVICE_ID,
                    "erro": str(exc),
                })
            return

        if self.path == "/falar":
            try:
                result = play_audio(
                    str(req_data.get("texto", "")),
                    str(req_data.get("audio_url", "")),
                )
                self._json(200, {
                    "status": "ok",
                    "device_id": DEVICE_ID,
                    "resultado": result,
                })
            except Exception as exc:
                self._json(500, {
                    "status": "erro",
                    "erro": str(exc),
                })
            return

        self._json(404, {"erro": "Endpoint desconhecido"})


def main():
    print("=== CASA/JARVIS - ARM NODE AGENT ===")
    print("Device ID: {0}".format(DEVICE_ID))
    print("Hostname: {0}".format(socket.gethostname()))
    print("IP local: {0}".format(get_ip()))
    print("CASA: {0}".format(CASA_BASE_URL))
    print("Device API: {0}".format(DEVICE_API))
    print("Protocolo: {0}".format(PROTOCOL_VERSION))
    print("Capacidades: {0}".format(", ".join(CAPABILITIES)))
    print("Porta local: {0}".format(AGENT_PORT))

    if not DEVICE_TOKEN:
        print("AVISO: JARVIS_DEVICE_TOKEN nao configurado.")

    threading.Thread(target=heartbeat_worker, daemon=True).start()
    threading.Thread(target=command_worker, daemon=True).start()

    server = HTTPServer(("0.0.0.0", AGENT_PORT), AgentHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.server_close()


if __name__ == "__main__":
    main()
