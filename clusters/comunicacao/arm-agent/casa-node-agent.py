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


def run_cmd(cmd, timeout=10):
    """Executa comando externo compativel com todas as versoes de Python (2.7, 3.4+)."""
    try:
        p = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
        out, err = p.communicate(timeout=timeout)
        return p.returncode, out, err
    except Exception as e:
        return -1, "", str(e)
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import HTTPServer, BaseHTTPRequestHandler

def load_env_file(path="/etc/casa-node-agent.env"):
    if os.path.isfile(path):
        try:
            with open(path, "r", encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, v = line.split("=", 1)
                        k = k.strip()
                        v = v.strip().strip('"').strip("'")
                        if k:
                            os.environ[k] = v
        except Exception:
            pass

load_env_file()

CASA_BASE_URL = os.environ.get(
    "CASA_BASE_URL", "https://maurinsoft.com.br/casa"
).rstrip("/")
DEVICE_API = CASA_BASE_URL + "/api/v1/device.php"

DEVICE_ID = os.environ.get("JARVIS_DEVICE_ID", os.environ.get("DEVICE_ID", socket.gethostname())).strip()
DEVICE_TOKEN = os.environ.get("JARVIS_DEVICE_TOKEN", os.environ.get("DEVICE_TOKEN", "casa_sec_a4c5a1a2fea405d668edf5934f67d9eb9df23d4c89c5c616")).strip()
CAPABILITIES = [
    x.strip() for x in os.environ.get(
        "JARVIS_CAPABILITIES",
        "gateway,arm-agent,hardware-gateway,update-agent"
    ).split(",") if x.strip()
]
LOCAL_TTS_URL = os.environ.get("JARVIS_LOCAL_TTS_URL", "").strip().rstrip("/")
AGENT_PORT = int(os.environ.get("AGENT_PORT", 8098))
HEARTBEAT_INTERVAL = int(os.environ.get("HEARTBEAT_INTERVAL", 20))
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


_last_git_check_ts = 0


def get_deployed_version():
    """Commit instalado pelo casa-cluster-update (fonte oficial); depois, clone Git local."""
    try:
        with open("/var/lib/casa-cluster-update/state.json", "r", encoding="utf-8") as f:
            data = json.load(f)
        commit = data.get("commit") or (data.get("last_update") or {}).get("commit_after")
        if commit:
            stamp = (data.get("last_update") or {}).get("timestamp")
            return "#" + commit[:7] + " (" + (data.get("branch") or "master") + ")", commit, stamp
    except Exception:
        pass

    try:
        for repo_dir in ["/home/mmm/projetos/maurinsoft/casa", "/opt/casa/repo"]:
            if os.path.isdir(os.path.join(repo_dir, ".git")):
                p = subprocess.Popen(["git", "-C", repo_dir, "rev-parse", "HEAD"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
                out, _ = p.communicate()
                commit = (out or "").strip()
                if commit and len(commit) >= 7:
                    return "#" + commit[:7] + " (clone local)", commit, None
    except Exception:
        pass

    return AGENT_VERSION, None, None


def get_update_info(refresh=False):
    """Obtem situacao do cluster perante o Git remoto e commit atual instalado."""
    global _last_git_check_ts
    now = time.time()

    # Se solicitado ou a cada 10 min (600s), roda --check se o binario existir
    if refresh or (_last_git_check_ts == 0) or (now - _last_git_check_ts > 600):
        update_bin = "/usr/local/bin/casa-cluster-update"
        if os.path.isfile(update_bin):
            try:
                p = subprocess.Popen([update_bin, "--check"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
                p.communicate(timeout=15)
                _last_git_check_ts = now
            except Exception as e:
                print("[Check Update Aviso]: {0}".format(e))

    state_file = "/var/lib/casa-cluster-update/state.json"
    state = {}
    try:
        if os.path.isfile(state_file):
            with open(state_file, "r", encoding="utf-8") as f:
                state = json.load(f)
    except Exception:
        pass

    installed = state.get("commit") or (state.get("last_update") or {}).get("commit_after")
    remote = state.get("remote_commit")
    branch = state.get("branch") or "master"
    last_check = state.get("last_check")

    if not installed:
        _, commit_hash, _ = get_deployed_version()
        installed = commit_hash

    up_to_date = bool(installed and remote and (installed == remote or installed[:7] == remote[:7]))
    status_str = "up-to-date" if up_to_date else ("available" if remote else "unknown")

    return {
        "status": status_str,
        "up_to_date": up_to_date,
        "installed": installed,
        "remote": remote,
        "branch": branch,
        "last_check": last_check,
    }


def run_cluster_update():
    """Executa atualizacao direta do Git no no local via casa-cluster-update --force."""
    update_bin = "/usr/local/bin/casa-cluster-update"
    if not os.path.isfile(update_bin):
        raise RuntimeError("Binario de atualizacao nao encontrado: " + update_bin)

    p = subprocess.Popen([update_bin, "--force"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
    out, err = p.communicate(timeout=180)

    try:
        get_update_info(refresh=True)
        send_heartbeat()
    except Exception as e:
        print("[Pos-Atualizacao Aviso]: {0}".format(e))

    return {
        "returncode": p.returncode,
        "ok": (p.returncode == 0),
        "stdout": out,
        "stderr": err,
    }


def send_heartbeat():
    version_str, commit_hash, deployed_at = get_deployed_version()
    up_info = get_update_info(refresh=False)
    payload = {
        "device_id": DEVICE_ID,
        "transport": "https",
        "local_ip": get_ip(),
        "health": "ok",
        "firmware_version": version_str,
        "protocol_version": PROTOCOL_VERSION,
        "uptime_sec": int(time.monotonic()),
        "capabilities": CAPABILITIES,
        "data": {
            "platform": "linux-arm",
            "hostname": socket.gethostname(),
            "version": version_str,
            "commit": commit_hash,
            "remote_commit": up_info.get("remote"),
            "up_to_date": up_info.get("up_to_date"),
            "branch": up_info.get("branch", "master"),
            "last_check": up_info.get("last_check"),
            "deployed_at": deployed_at,
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
            rc, _, _ = run_cmd(command, timeout=120)
            if rc == 0:
                return {"played": True, "player": command[0]}
        except Exception:
            continue

    raise RuntimeError("Nenhum player de audio disponivel")


import glob


def hardware_scan():
    """Descobre portas seriais, barramentos I2C, SPI e pinos de hardware no Linux."""
    serials = sorted(glob.glob('/dev/ttyUSB*') + glob.glob('/dev/ttyACM*') + glob.glob('/dev/ttyAMA*') + glob.glob('/dev/ttyS*'))
    i2c_devs = sorted(glob.glob('/dev/i2c*'))
    spi_devs = sorted(glob.glob('/dev/spidev*'))
    gpio_chips = sorted(glob.glob('/dev/gpiochip*'))

    thermal_zones = {}
    for tz in sorted(glob.glob('/sys/class/thermal/thermal_zone*')):
        try:
            with open(os.path.join(tz, 'type'), 'r') as f:
                name = f.read().strip()
            with open(os.path.join(tz, 'temp'), 'r') as f:
                temp_val = float(f.read().strip()) / 1000.0
            thermal_zones[name] = round(temp_val, 1)
        except Exception:
            pass

    return {
        "device_id": DEVICE_ID,
        "capabilities": CAPABILITIES,
        "serial_ports": serials,
        "i2c_busses": i2c_devs,
        "spi_devices": spi_devs,
        "gpio_chips": gpio_chips,
        "has_sysfs_gpio": os.path.isdir('/sys/class/gpio'),
        "thermal_zones": thermal_zones,
    }


def hardware_gpio_write(pin, value):
    """Aciona saida digital GPIO ou rele via Linux sysfs ou gpiod."""
    try:
        pin = int(pin)
    except Exception:
        raise ValueError("Pino GPIO invalido: {0}".format(pin))
    val = 1 if value in (1, True, "1", "true", "on", "ON", "ligado") else 0

    gpio_dir = "/sys/class/gpio/gpio{0}".format(pin)
    if not os.path.exists(gpio_dir) and os.path.exists("/sys/class/gpio/export"):
        try:
            with open("/sys/class/gpio/export", "w") as f:
                f.write(str(pin))
            time.sleep(0.05)
        except Exception:
            pass

    dir_file = "{0}/direction".format(gpio_dir)
    if os.path.exists(dir_file):
        try:
            with open(dir_file, "w") as f:
                f.write("out")
        except Exception:
            pass

    val_file = "{0}/value".format(gpio_dir)
    if os.path.exists(val_file):
        with open(val_file, "w") as f:
            f.write(str(val))
        try:
            publish_event("gpio.changed", {"pin": pin, "value": val, "status": "ok"})
        except Exception:
            pass
        return {"pin": pin, "value": val, "ok": True, "method": "sysfs"}

    # Fallback gpioset (libgpiod)
    for chip in ("0", "1", "2"):
        try:
            rc, _, _ = run_cmd(["gpioset", chip, "{0}={1}".format(pin, val)], timeout=5)
            if rc == 0:
                try:
                    publish_event("gpio.changed", {"pin": pin, "value": val, "method": "gpiod_chip{0}".format(chip)})
                except Exception:
                    pass
                return {"pin": pin, "value": val, "ok": True, "method": "gpiod_chip{0}".format(chip)}
        except Exception:
            break

    return {"pin": pin, "value": val, "ok": False, "error": "Interface GPIO nao acessivel neste no"}


def hardware_gpio_read(pin):
    """Le entrada digital GPIO via Linux sysfs ou gpiod."""
    try:
        pin = int(pin)
    except Exception:
        raise ValueError("Pino GPIO invalido: {0}".format(pin))

    gpio_dir = "/sys/class/gpio/gpio{0}".format(pin)
    if not os.path.exists(gpio_dir) and os.path.exists("/sys/class/gpio/export"):
        try:
            with open("/sys/class/gpio/export", "w") as f:
                f.write(str(pin))
            time.sleep(0.05)
        except Exception:
            pass

    val_file = "{0}/value".format(gpio_dir)
    if os.path.exists(val_file):
        try:
            with open(val_file, "r") as f:
                v = int(f.read().strip())
            return {"pin": pin, "value": v, "ok": True, "method": "sysfs"}
        except Exception as e:
            return {"pin": pin, "value": None, "ok": False, "error": str(e)}

    for chip in ("0", "1", "2"):
        try:
            rc, out, _ = run_cmd(["gpioget", chip, str(pin)], timeout=5)
            if rc == 0 and out.strip().isdigit():
                return {"pin": pin, "value": int(out.strip()), "ok": True, "method": "gpiod_chip{0}".format(chip)}
        except Exception:
            break

    return {"pin": pin, "value": None, "ok": False, "error": "Pino GPIO nao exportado ou sem suporte no kernel"}


def hardware_serial_send(port, data, baudrate=9600, timeout=2.0):
    """Envia quadro de comando via Serial ou RS485 (Arduino/ESP/Rele) e le resposta."""
    if not port or port == "auto":
        candidates = sorted(glob.glob('/dev/ttyUSB*') + glob.glob('/dev/ttyACM*') + glob.glob('/dev/ttyS*'))
        if not candidates:
            return {"ok": False, "error": "Nenhuma porta serial detectada no gateway"}
        port = candidates[0]

    if isinstance(data, dict):
        data = json.dumps(data)
    text_data = str(data)
    if not text_data.endswith('\n'):
        text_data += '\n'

    # 1. Tentar via biblioteca serial (pyserial)
    try:
        import serial
        with serial.Serial(port, int(baudrate), timeout=float(timeout)) as ser:
            ser.write(text_data.encode("utf-8"))
            ser.flush()
            response = ser.readline().decode("utf-8", errors="replace").strip()
            try:
                publish_event("serial.exchange", {"port": port, "command": text_data.strip(), "response": response})
            except Exception:
                pass
            return {"port": port, "baudrate": baudrate, "sent": text_data.strip(), "response": response, "ok": True}
    except ImportError:
        pass
    except Exception as e:
        return {"port": port, "error": str(e), "ok": False}

    # 2. Fallback via descritor de arquivo POSIX Linux
    try:
        fd = os.open(port, os.O_RDWR | os.O_NOCTTY | os.O_NONBLOCK)
        try:
            os.write(fd, text_data.encode("utf-8"))
            time.sleep(0.15)
            buf = b""
            start = time.time()
            while time.time() - start < float(timeout):
                try:
                    chunk = os.read(fd, 256)
                    if chunk:
                        buf += chunk
                        if b"\n" in chunk:
                            break
                    time.sleep(0.05)
                except (OSError, BlockingIOError):
                    time.sleep(0.05)
            resp = buf.decode("utf-8", errors="replace").strip()
            return {"port": port, "baudrate": baudrate, "sent": text_data.strip(), "response": resp, "ok": True, "method": "posix"}
        finally:
            os.close(fd)
    except Exception as e:
        return {"port": port, "error": str(e), "ok": False}


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

    if command in ("cluster.update", "system.update", "atualizar"):
        return run_cluster_update()

    if command in ("cluster.check_update", "check_update"):
        return get_update_info(refresh=True)

    # Descoberta de Hardware do Gateway e No:
    if command in ("device.discovery", "gateway.discovery", "hardware.scan", "hardware.telemetry"):
        return hardware_scan()

    # Controle de Rele e GPIO Digital:
    if command in ("relay.set", "rele.set", "rele.ligar", "rele.desligar", "gpio.set", "gpio.write"):
        pin = payload.get("pin", payload.get("relay", payload.get("rele", 17)))
        state = payload.get("state", payload.get("estado", payload.get("valor", 1)))
        if command == "rele.desligar":
            state = 0
        elif command == "rele.ligar":
            state = 1
        protocol = str(payload.get("protocol", "gpio")).lower()
        if protocol in ("serial", "rs485"):
            port = payload.get("port", "auto")
            frame = "RELAY:{0}:{1}\n".format(pin, state)
            return hardware_serial_send(port, frame)
        return hardware_gpio_write(pin, state)

    if command in ("relay.get", "rele.get", "gpio.get", "gpio.read"):
        pin = payload.get("pin", payload.get("relay", payload.get("rele", 17)))
        return hardware_gpio_read(pin)

    # Comunicacao Serial e RS485:
    if command in ("serial.send", "serial.command", "rs485.send", "rs485.command"):
        port = payload.get("port", "auto")
        data = payload.get("data", payload.get("comando", payload.get("frame", "")))
        baudrate = int(payload.get("baudrate", 9600))
        timeout = float(payload.get("timeout", 2.0))
        return hardware_serial_send(port, data, baudrate=baudrate, timeout=timeout)

    # Computador de Bordo - Jornada nas Estrelas (LCARS Computer):
    if command in ("computer.command", "lcars.command", "star_trek.command"):
        status_data = {
            "device_id": DEVICE_ID,
            "system": "LCARS Online",
            "telemetry": hardware_scan(),
            "cpu": get_cpu_info(),
            "ram": get_ram_info(),
            "status": "Todos os subsistemas operando nominalmente.",
        }
        speech = payload.get("falar", False)
        text_resp = "Computador operacional. Sensores e subsistemas respondendo nominalmente."
        if speech:
            try:
                play_audio(text=text_resp)
            except Exception:
                pass
        return {"status": "ok", "lcars": status_data, "resposta": text_resp}

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
            up_info = get_update_info(refresh=False)
            self._json(200, {
                "status": "online",
                "device_id": DEVICE_ID,
                "protocol_version": PROTOCOL_VERSION,
                "agent_version": AGENT_VERSION,
                "hostname": socket.gethostname(),
                "ip": get_ip(),
                "commit": up_info.get("installed"),
                "remote_commit": up_info.get("remote"),
                "up_to_date": up_info.get("up_to_date"),
                "branch": up_info.get("branch", "master"),
                "last_check": up_info.get("last_check"),
                "cpu": get_cpu_info(),
                "ram": get_ram_info(),
                "casa": CASA_BASE_URL,
                "capabilities": CAPABILITIES,
            })
            return

        if self.path in ("/check-update", "/update-check"):
            up_info = get_update_info(refresh=True)
            self._json(200, {
                "status": "ok",
                "device_id": DEVICE_ID,
                "update": up_info
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

        if self.path in ("/update", "/atualizar"):
            try:
                res = run_cluster_update()
                self._json(200 if res.get("ok") else 500, {
                    "status": "ok" if res.get("ok") else "error",
                    "device_id": DEVICE_ID,
                    "resultado": res
                })
            except Exception as exc:
                self._json(500, {
                    "status": "erro",
                    "device_id": DEVICE_ID,
                    "erro": str(exc)
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

    HTTPServer.allow_reuse_address = True
    server = HTTPServer(("0.0.0.0", AGENT_PORT), AgentHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.server_close()


if __name__ == "__main__":
    main()
