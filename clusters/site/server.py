#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CASA / JARVIS - Cluster Node Web Portal Server
Compatible with Python 3.4+ (Cubieboard armv7l, Raspberry Pi 4, etc.)
Runs on port 8080 by default.
"""

import os
import sys
import json
import time
import socket
import posixpath
import urllib.parse
import urllib.request
import subprocess
import threading
import signal
import hmac
from collections import deque
from http.server import HTTPServer, SimpleHTTPRequestHandler
from socketserver import ThreadingMixIn

PORT = int(os.environ.get("CLUSTER_SITE_PORT", 8080))
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
STATIC_DIR = BASE_DIR

class CpuTelemetry:
    """Sample Linux CPU counters independently of HTTP traffic (Python 3.4+)."""
    def __init__(self):
        self.lock = threading.Lock()
        self.previous = None
        self.history = deque(maxlen=61)

    def sample(self):
        now = time.time()
        try:
            with open("/proc/stat", "r") as handle:
                fields = handle.readline().split()
            if fields[0] != "cpu" or len(fields) < 5:
                raise ValueError("CPU counters unavailable")
            # guest/guest_nice are already included in user/nice.
            ticks = [int(value) for value in fields[1:9]]
            if any(value < 0 for value in ticks):
                raise ValueError("Invalid CPU counters")
            total = sum(ticks)
            idle = ticks[3] + (ticks[4] if len(ticks) > 4 else 0)
            with self.lock:
                usage = None
                if self.previous is not None:
                    delta = total - self.previous[0]
                    idle_delta = idle - self.previous[1]
                    if delta > 0 and 0 <= idle_delta <= delta:
                        usage = round(100.0 * (delta - idle_delta) / delta, 1)
                self.previous = (total, idle)
                self.history.append({"timestamp": now, "usage_pct": usage})
        except (OSError, ValueError, IndexError):
            with self.lock:
                self.previous = None
                self.history.append({"timestamp": now, "usage_pct": None})

    def snapshot(self):
        now = time.time()
        with self.lock:
            history = [dict(point) for point in self.history if now - point["timestamp"] <= 60]
        latest = history[-1] if history else None
        usage = latest["usage_pct"] if latest and now - latest["timestamp"] <= 3 else None
        try:
            loads = os.getloadavg()
        except (AttributeError, OSError):
            loads = (None, None, None)
        return {
            "cores": os.cpu_count(), "usage_pct": usage,
            "load_1m": loads[0], "load_5m": loads[1], "load_15m": loads[2],
            "temp_c": get_cpu_temp(), "history": history, "timestamp": now,
            "sample_interval_seconds": 1, "window_seconds": 60,
        }

    def run(self, stop):
        self.sample()
        while not stop.wait(1):
            self.sample()


CPU_TELEMETRY = CpuTelemetry()

# Only known application entrypoints are eligible; never match arbitrary argv text.
APPLICATIONS = [
    ("Agente ARM", "comunicacao/arm-agent/casa-node-agent.py", "/opt/casa-node-agent/casa-node-agent.py"),
    ("Portal do cluster", "site/server.py", "/opt/casa/site/server.py"),
    ("Agendador", "automacao/casa-scheduler/casa-scheduler.py", "/opt/casa-scheduler/casa-scheduler.py"),
    ("Google Home", "voz/google-home-agent/google_home_agent.py", "/home/mmm/servicos/google-home-agent/google_home_agent.py"),
    ("Voz TTS", "voz/tts/server_tts.py", "/home/mmm/servicos/tts/server_tts.py"),
    ("Visão ESPCam", "visao/espcam/processa_imagem.py", "/opt/casa/espcam/processa_imagem.py"),
    ("Análise de cena", "visao/espcam/analisa_cena_ia.py", "/opt/casa/espcam/analisa_cena_ia.py"),
    ("Avatar", "visao/raspberrypi-avatar-agent/meta-casa-avatar/recipes-casa/casa-avatar/files/casa_avatar.py", "/opt/casa-avatar/casa_avatar.py"),
    ("Pesquisa web", "pesquisa/web-agent/web_agent.py", "/opt/jarvis-web-agent/web_agent.py"),
    ("Túnel", "infraestrutura/casa-tunnel/casa-tunnel.py", "/opt/casa-tunnel/casa-tunnel.py"),
    ("RunPod", "infraestrutura/runpod-agent/casa-runpod-agent.py", "/opt/casa/servicos/runpod-agent/casa-runpod-agent.py"),
    ("Agente SSH", "infraestrutura/casa-ssh-agent/casa-ssh-agent.py", "/opt/casa/ssh-agent/casa-ssh-agent.py"),
    ("Atualizador", "infraestrutura/casa-cluster-update/casa-cluster-update.py", "/opt/casa/cluster-update/casa-cluster-update.py"),
]


class ApplicationProcesses:
    def __init__(self, proc_root="/proc"):
        self.proc_root = proc_root
        self.lock = threading.Lock()
        self.samples = {}
        self.allowed = {}
        cluster_root = os.path.dirname(BASE_DIR)
        for name, relative, installed in APPLICATIONS:
            for path in (installed, os.path.join(cluster_root, relative)):
                self.allowed[os.path.realpath(path)] = name
        self.allowed[os.path.realpath(__file__)] = "Portal do cluster"

    def read(self, pid):
        directory = os.path.join(self.proc_root, str(pid))
        with open(os.path.join(directory, "cmdline"), "rb") as handle:
            args = [part.decode("utf-8", "replace") for part in handle.read(65536).split(b"\0") if part]
        executable = os.path.basename(os.readlink(os.path.join(directory, "exe")))
        if not executable.startswith("python") or len(args) < 2:
            return None
        script_index = 1
        while script_index < len(args) and args[script_index] in ("-u", "-B", "-E", "-s", "-S", "-I", "-O", "-OO"):
            script_index += 1
        if script_index >= len(args) or args[script_index].startswith("-"):
            return None
        script = args[script_index]
        if not os.path.isabs(script):
            script = os.path.join(os.readlink(os.path.join(directory, "cwd")), script)
        name = self.allowed.get(os.path.realpath(script))
        if not name:
            return None
        with open(os.path.join(directory, "stat"), "r") as handle:
            # comm can contain spaces and parentheses; fields after its closing ')'.
            fields = handle.read().rsplit(")", 1)[1].split()
        start_time = fields[19]
        ticks = int(fields[11]) + int(fields[12])
        rss_mb = max(0, int(fields[21])) * os.sysconf("SC_PAGE_SIZE") / (1024.0 * 1024.0)
        uid = os.stat(directory).st_uid
        try:
            import pwd
            user = pwd.getpwuid(uid).pw_name
        except (ImportError, KeyError):
            user = str(uid)
        protected = pid in (1, os.getpid()) or name in ("Portal do cluster", "Atualizador")
        return {
            "pid": pid, "start_time": start_time, "name": name,
            "user": user, "state": fields[0], "memory_mb": round(rss_mb, 1),
            "can_terminate": not protected and fields[0] not in ("Z", "X"),
            "protected": protected, "ticks": ticks,
        }

    def snapshot(self):
        if not os.path.isdir(self.proc_root):
            raise OSError("Lista de processos disponível somente em nós Linux")
        now = time.monotonic()
        clock_ticks = os.sysconf("SC_CLK_TCK")
        cores = os.cpu_count() or 1
        rows = []
        with self.lock:
            new_samples = {}
            for entry in os.listdir(self.proc_root):
                if not entry.isdigit():
                    continue
                try:
                    row = self.read(int(entry))
                    if not row:
                        continue
                    key = (row["pid"], row["start_time"])
                    ticks = row.pop("ticks")
                    previous = self.samples.get(key)
                    row["cpu_pct"] = None
                    if previous and now - previous[0] >= 0.5:
                        delta = ticks - previous[1]
                        if delta >= 0:
                            row["cpu_pct"] = round(min(100.0, 100.0 * delta / clock_ticks / (now - previous[0]) / cores), 1)
                        new_samples[key] = (now, ticks, row["cpu_pct"])
                    elif previous:
                        row["cpu_pct"] = previous[2]
                        new_samples[key] = previous
                    else:
                        new_samples[key] = (now, ticks, None)
                    rows.append(row)
                except (OSError, ValueError, IndexError):
                    # Processes may exit or become unreadable during enumeration.
                    continue
            self.samples = new_samples
        return sorted(rows, key=lambda row: (row["name"], row["pid"]))

    def terminate(self, pid, start_time):
        descriptor = None
        try:
            # pidfd binds the signal to a process instance on newer Linux/Python.
            if hasattr(os, "pidfd_open") and hasattr(signal, "pidfd_send_signal"):
                try:
                    descriptor = os.pidfd_open(pid, 0)
                except OSError as error:
                    if error.errno not in (22, 38):  # EINVAL/ENOSYS: old kernel.
                        raise
            current = self.read(pid)
            if not current or current["start_time"] != start_time:
                return {"pid": pid, "ok": False, "message": "Processo mudou ou não pertence à aplicação. Atualize a lista."}
            if not current["can_terminate"]:
                return {"pid": pid, "ok": False, "message": "Processo protegido ou já encerrado."}
            if descriptor is not None:
                signal.pidfd_send_signal(descriptor, signal.SIGTERM)
            else:
                # Legacy ARM kernels lack pidfd: revalidate immediately before kill.
                check = self.read(pid)
                if not check or check["start_time"] != start_time or not check["can_terminate"]:
                    return {"pid": pid, "ok": False, "message": "Processo mudou. Atualize a lista."}
                os.kill(pid, signal.SIGTERM)
            return {"pid": pid, "ok": True, "message": "Encerramento solicitado (SIGTERM)."}
        except PermissionError:
            return {"pid": pid, "ok": False, "message": "O portal não tem permissão para encerrar este processo."}
        except (OSError, ValueError, IndexError):
            return {"pid": pid, "ok": False, "message": "Processo indisponível. Atualize a lista."}
        finally:
            if descriptor is not None:
                os.close(descriptor)


APPLICATION_PROCESSES = ApplicationProcesses()


def process_admin_token():
    return os.environ.get("CLUSTER_SITE_ADMIN_TOKEN", "").strip() or get_env_map().get("CLUSTER_SITE_ADMIN_TOKEN", "").strip()


class ClusterHTTPServer(ThreadingMixIn, HTTPServer):
    # Cloud/service checks must not block the CPU graph's lightweight polling.
    daemon_threads = True

def run_cmd(args):
    try:
        p = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
        out, err = p.communicate()
        return p.returncode, (out or "").strip()
    except Exception:
        return 1, ""

def get_env_map():
    env = {}
    path = "/etc/casa-node-agent.env"
    if os.path.exists(path):
        try:
            with open(path, "r", encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, v = line.split("=", 1)
                        env[k.strip()] = v.strip().strip("'\"")
        except Exception:
            pass
    return env

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
    paths = [
        "/sys/class/thermal/thermal_zone0/temp",
        "/sys/devices/virtual/thermal/thermal_zone0/temp",
        "/sys/class/hwmon/hwmon0/temp1_input",
    ]
    for p in paths:
        if os.path.exists(p):
            try:
                with open(p, "r", encoding="utf-8") as f:
                    val = float(f.read().strip())
                    if val > 1000:
                        val = val / 1000.0
                    return round(val, 1)
            except Exception:
                pass
    return None

def get_ram_info():
    try:
        total = 0
        avail = 0
        with open("/proc/meminfo", "r", encoding="utf-8") as f:
            for line in f:
                if line.startswith("MemTotal:"):
                    total = int(line.split()[1]) // 1024
                elif line.startswith("MemAvailable:"):
                    avail = int(line.split()[1]) // 1024
                elif avail == 0 and line.startswith("MemFree:"):
                    avail = int(line.split()[1]) // 1024
        used = max(0, total - avail)
        pct = round((used / total * 100) if total else 0, 1)
        return {"used_mb": used, "total_mb": total, "free_mb": avail, "used_pct": pct}
    except Exception:
        return {"used_mb": 0, "total_mb": 0, "free_mb": 0, "used_pct": 0}

def get_disk_info():
    try:
        st = os.statvfs("/")
        total = (st.f_blocks * st.f_frsize) // (1024 * 1024 * 1024)
        free = (st.f_bavail * st.f_frsize) // (1024 * 1024 * 1024)
        used = max(0, total - free)
        pct = round((used / total * 100) if total else 0, 1)
        return {"total_gb": total, "used_gb": used, "free_gb": free, "used_pct": pct}
    except Exception:
        return {"total_gb": 0, "used_gb": 0, "free_gb": 0, "used_pct": 0}

def get_uptime():
    try:
        with open("/proc/uptime", "r", encoding="utf-8") as f:
            sec = int(float(f.read().split()[0]))
            days = sec // 86400
            hours = (sec % 86400) // 3600
            mins = (sec % 3600) // 60
            if days > 0:
                return "{0}d {1}h {2}m".format(days, hours, mins)
            return "{0}h {1}m".format(hours, mins)
    except Exception:
        return "N/A"

def check_service(name):
    # Try systemctl
    try:
        ret, out = run_cmd(["systemctl", "is-active", name])
        if ret == 0 and out == "active":
            return "active"
    except Exception:
        pass

    # Try service / init.d
    short = name.replace(".service", "").replace("casa-", "")
    try:
        if os.path.exists("/etc/init.d/" + short):
            ret, out = run_cmd(["/etc/init.d/" + short, "status"])
            if ret == 0 and ("running" in out.lower() or "is running" in out.lower()):
                return "active"
        if os.path.exists("/etc/init.d/" + name):
            ret, out = run_cmd(["/etc/init.d/" + name, "status"])
            if ret == 0 and ("running" in out.lower() or "is running" in out.lower()):
                return "active"
    except Exception:
        pass

    # Try pgrep
    try:
        ret, out = run_cmd(["pgrep", "-f", short])
        if ret == 0 and out:
            return "active"
    except Exception:
        pass

    return "inactive"

_cached_nodes = []
_last_nodes_fetch = 0

def get_cluster_nodes_from_registry():
    """Busca dinamicamente os nós registrados no banco/API da central, sem IPs chumbados."""
    global _cached_nodes, _last_nodes_fetch
    now = time.time()
    cache_file = "/opt/casa/site/cluster_nodes_cache.json"

    # Retorna do cache em memória se recente (30 segundos)
    if _cached_nodes and (now - _last_nodes_fetch < 30):
        return _cached_nodes

    env = get_env_map()
    base_url = env.get("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
    token = env.get("JARVIS_DEVICE_TOKEN", "casa_sec_a4c5a1a2fea405d668edf5934f67d9eb9df23d4c89c5c616")

    nodes_fetched = []
    # 1. Tenta carregar da API oficial de nós da central
    try:
        url = base_url + "/api/arm_nodes.php"
        req = urllib.request.Request(url, headers={
            "User-Agent": "CASA-ClusterSite/2.0",
            "Authorization": "Bearer " + token,
            "X-Device-Token": token
        })
        with urllib.request.urlopen(req, timeout=3) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            raw_nodes = data.get("nodes", [])
            for n in raw_nodes:
                ip = n.get("ip_address") or n.get("local_ip")
                if ip:
                    nodes_fetched.append({
                        "id": n.get("device_id") or n.get("id"),
                        "ip": ip,
                        "name": n.get("nome") or n.get("hostname") or n.get("device_id"),
                        "port": int(n.get("porta") or 8080),
                        "online": bool(n.get("online")),
                        "version": n.get("version", "")
                    })
    except Exception:
        pass

    # 2. Se a central respondeu nós válidos, atualiza cache
    if nodes_fetched:
        _cached_nodes = nodes_fetched
        _last_nodes_fetch = now
        try:
            with open(cache_file, "w", encoding="utf-8") as cf:
                json.dump(nodes_fetched, cf, ensure_ascii=False, indent=2)
        except Exception:
            pass
        return nodes_fetched

    # 3. Fallback: carrega do arquivo de cache local
    if os.path.exists(cache_file):
        try:
            with open(cache_file, "r", encoding="utf-8") as cf:
                _cached_nodes = json.load(cf)
                _last_nodes_fetch = now
                return _cached_nodes
        except Exception:
            pass

    return _cached_nodes or []

class ClusterSiteHandler(SimpleHTTPRequestHandler):
    def translate_path(self, path):
        # Clean URL and resolve inside STATIC_DIR (Python 3.4 compatible)
        path = path.split("?", 1)[0].split("#", 1)[0]
        path = posixpath.normpath(urllib.parse.unquote(path))
        words = [w for w in path.split("/") if w]
        cur = STATIC_DIR
        for word in words:
            if word in (os.curdir, os.pardir):
                continue
            cur = os.path.join(cur, word)
        if os.path.isdir(cur):
            idx = os.path.join(cur, "index.html")
            if os.path.exists(idx):
                return idx
        return cur

    def end_headers(self):
        if not urllib.parse.urlparse(self.path).path.startswith("/api/processes"):
            self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
        super().end_headers()

    def do_OPTIONS(self):
        self.send_response(200)
        self.end_headers()

    def do_GET(self):
        url = urllib.parse.urlparse(self.path)
        path = url.path

        if path == "/api/telemetry" or path == "/api/status":
            self.handle_telemetry()
        elif path == "/api/cpu":
            self.send_json({"cpu": CPU_TELEMETRY.snapshot()})
        elif path == "/api/processes":
            try:
                self.send_json({"processes": APPLICATION_PROCESSES.snapshot(), "timestamp": time.time(),
                                "termination_enabled": bool(process_admin_token())})
            except OSError:
                self.send_json({"error": "Não foi possível consultar os processos deste nó Linux."}, code=503)
        elif path == "/api/heartbeat":
            self.handle_heartbeat()
        elif path == "/api/cluster/update" or path == "/api/cluster/update/status":
            self.handle_cluster_update_status()
        elif path == "/api/ssh/nodes" or path == "/api/ssh/status":
            self.handle_ssh_mesh()
        else:
            super().do_GET()

    def do_POST(self):
        url = urllib.parse.urlparse(self.path)
        path = url.path

        if path == "/api/falar" or path == "/api/tts":
            self.handle_tts()
        elif path == "/api/heartbeat":
            self.handle_heartbeat()
        elif path == "/api/cluster/update":
            self.handle_cluster_update_trigger()
        elif path == "/api/processes/terminate":
            self.handle_process_termination()
        else:
            self.send_error(404, "Endpoint not found")

    def send_json(self, data, code=200):
        body = json.dumps(data).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def handle_process_termination(self):
        expected = process_admin_token()
        if not expected:
            self.send_json({"error": "Configure CLUSTER_SITE_ADMIN_TOKEN no nó para habilitar o encerramento."}, code=503)
            return
        supplied = self.headers.get("Authorization", "")
        if not hmac.compare_digest(supplied.encode("utf-8"), ("Bearer " + expected).encode("utf-8")):
            self.send_json({"error": "Token administrativo inválido."}, code=401)
            return
        origin = self.headers.get("Origin")
        if origin:
            parsed = urllib.parse.urlparse(origin)
            if parsed.scheme not in ("http", "https") or parsed.netloc != self.headers.get("Host"):
                self.send_json({"error": "Origem não permitida."}, code=403)
                return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if not 0 < length <= 8192 or self.headers.get("Content-Type", "").split(";")[0].strip() != "application/json":
                raise ValueError()
            data = json.loads(self.rfile.read(length).decode("utf-8"))
            selections = data.get("processes") if isinstance(data, dict) else None
            if not isinstance(selections, list) or not 1 <= len(selections) <= 20:
                raise ValueError()
            seen = set()
            for item in selections:
                if not isinstance(item, dict) or type(item.get("pid")) is not int or not 1 < item["pid"] <= 4194304:
                    raise ValueError()
                if not isinstance(item.get("start_time"), str) or not item["start_time"].isdigit() or len(item["start_time"]) > 24 or item["pid"] in seen:
                    raise ValueError()
                seen.add(item["pid"])
        except (ValueError, UnicodeError):
            self.send_json({"error": "Selecione de 1 a 20 processos válidos da lista atual."}, code=400)
            return
        results = [APPLICATION_PROCESSES.terminate(item["pid"], item["start_time"]) for item in selections]
        for result in results:
            self.log_message("process termination pid=%s ok=%s", result["pid"], result["ok"])
        self.send_json({"results": results})

    def handle_ssh_mesh(self):
        agent_url = "http://127.0.0.1:8095/api/ssh/nodes"
        try:
            req = urllib.request.Request(agent_url, headers={"User-Agent": "casa-cluster-site"})
            with urllib.request.urlopen(req, timeout=3) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                self.send_json(data)
                return
        except Exception:
            pass

        known = get_cluster_nodes_from_registry()
        nodes_list = []
        for nid, n in known.items():
            nodes_list.append({
                "id": nid,
                "name": n.get("name", nid),
                "host": n.get("host", ""),
                "lan_ip": n.get("host", ""),
                "port": 22,
                "user": "mmm",
                "role": n.get("role", "ARM Node"),
                "online": True
            })
        self.send_json({"ok": True, "nodes": nodes_list, "total": len(nodes_list), "fallback": True})

    def handle_telemetry(self):
        env = get_env_map()
        hostname = socket.gethostname()
        ip = get_ip()
        cpu_temp = get_cpu_temp()
        ram = get_ram_info()
        disk = get_disk_info()
        uptime = get_uptime()

        ssh_user = env.get("JARVIS_SSH_USER", "mmm")
        services = {
            "casa-node-agent": check_service("casa-node-agent"),
            "casa-cluster-site": "active",
            "casa-ssh-agent": check_service("casa-ssh-agent"),
            "apache2": check_service("apache2"),
            "mosquitto": check_service("mosquitto"),
            "ssh": check_service("ssh"),
        }

        # Cluster nodes summary - Carregado dinamicamente do registro da central
        known_nodes = get_cluster_nodes_from_registry()

        resp = {
            "status": "online",
            "device_id": env.get("JARVIS_DEVICE_ID", hostname),
            "hostname": hostname,
            "ip": ip,
            "port": PORT,
            "uptime": uptime,
            "cpu_temp": cpu_temp,
            "cpu": CPU_TELEMETRY.snapshot(),
            "ram": ram,
            "disk": disk,
            "base_url": env.get("CASA_BASE_URL", "https://maurinsoft.com.br/casa"),
            "capabilities": env.get("JARVIS_CAPABILITIES", "cluster-node,site"),
            "services": services,
            "ssh": {
                "service": services.get("ssh", "unknown"),
                "agent_service": services.get("casa-ssh-agent", "unknown"),
                "port": 22,
                "agent_port": 8095,
                "user": ssh_user,
                "command": f"ssh {ssh_user}@{ip}",
            },
            "cluster_nodes": known_nodes,
            "timestamp": int(time.time()),
        }
        self.send_json(resp)

    def handle_heartbeat(self):
        env = get_env_map()
        base_url = env.get("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
        token = env.get("JARVIS_DEVICE_TOKEN", "casa_sec_a4c5a1a2fea405d668edf5934f67d9eb9df23d4c89c5c616")
        device_id = env.get("JARVIS_DEVICE_ID", socket.gethostname())

        payload = {
            "device_id": device_id,
            "device_type": "arm-node",
            "cluster": "cubie-pi-cluster",
            "capabilities": env.get("JARVIS_CAPABILITIES", "arm-agent,linux-arm,cluster-site"),
            "ip_local": get_ip(),
            "porta": PORT,
            "cpu_temp": get_cpu_temp(),
            "uptime": get_uptime(),
            "timestamp": int(time.time()),
        }

        url = "{0}/api/v1/device.php?acao=heartbeat".format(base_url)
        headers = {
            "Content-Type": "application/json",
            "Authorization": "Bearer {0}".format(token),
            "X-Device-Token": token,
            "User-Agent": "ClusterSite-Server/1.0",
        }

        try:
            req = urllib.request.Request(url, data=json.dumps(payload).encode("utf-8"), headers=headers, method="POST")
            with urllib.request.urlopen(req, timeout=5) as response:
                res_data = response.read().decode("utf-8")
                self.send_json({"ok": True, "http_code": response.status, "response": res_data})
        except Exception as e:
            self.send_json({"ok": False, "error": str(e)}, code=500)

    def handle_tts(self):
        try:
            length = int(self.headers.get("Content-Length", 0))
            raw = self.rfile.read(length).decode("utf-8")
            data = json.loads(raw) if raw else {}
            texto = data.get("texto", "")
            if not texto:
                self.send_json({"ok": False, "error": "Texto vazio"}, code=400)
                return

            # Try espeak or pico2wave
            run_cmd(["espeak", "-v", "pt-br", texto])
            self.send_json({"ok": True, "fala": texto})
        except Exception as e:
            self.send_json({"ok": False, "error": str(e)}, code=500)


    def handle_cluster_update_status(self):
        # O Git local nao representa mais a release instalada: ler estado do updater.
        state = {}
        try:
            with open("/var/lib/casa-cluster-update/state.json", "r", encoding="utf-8") as handle:
                state = json.load(handle)
        except (OSError, ValueError):
            pass
        components = state.get("components", {})
        commits = sorted(set(item.get("commit") for item in components.values() if item.get("commit")))
        current = state.get("commit") or (commits[0] if len(commits) == 1 else "mixed" if commits else "unknown")
        self.send_json({
            "ok": True,
            "current_commit": current,
            "remote_commit": state.get("remote_commit"),
            "branch": state.get("branch"),
            "components": components,
            "pending_reports": len(state.get("pending_reports", [])),
            "last_update": state.get("last_update"),
            "progress": state.get("progress")
        })

    def handle_cluster_update_trigger(self):
        try:
            ret, _ = run_cmd(["systemctl", "start", "casa-cluster-update.service"])
            if ret != 0:
                cmd = ["/usr/bin/python3", "/opt/casa/cluster-update/casa-cluster-update.py", "--apply"]
                if not os.path.exists("/opt/casa/cluster-update/casa-cluster-update.py"):
                    cmd = ["/usr/bin/python3", "/home/mmm/projetos/maurinsoft/casa/clusters/infraestrutura/casa-cluster-update/casa-cluster-update.py", "--apply"]
                subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

            self.send_json({
                "ok": True,
                "status": "started",
                "message": "Servico de atualizacao do cluster disparado com sucesso."
            })
        except Exception as e:
            self.send_json({"ok": False, "error": str(e)}, code=500)

def main():
    os.chdir(STATIC_DIR)
    server_address = ("0.0.0.0", PORT)
    httpd = ClusterHTTPServer(server_address, ClusterSiteHandler)
    stop_sampling = threading.Event()
    sampler = threading.Thread(target=CPU_TELEMETRY.run, args=(stop_sampling,))
    sampler.daemon = True
    sampler.start()
    print("==================================================================")
    print(" CASA / JARVIS - Cluster Site Server started on port {0}".format(PORT))
    print(" Serving UI: {0}".format(STATIC_DIR))
    print(" Access: http://{0}:{1}/".format(get_ip(), PORT))
    print("==================================================================")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down server...")
    finally:
        stop_sampling.set()
        sampler.join(timeout=2)
        httpd.server_close()

if __name__ == "__main__":
    main()

