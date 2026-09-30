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
from http.server import HTTPServer, SimpleHTTPRequestHandler

PORT = int(os.environ.get("CLUSTER_SITE_PORT", 8080))
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
STATIC_DIR = BASE_DIR

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
        elif path == "/api/heartbeat":
            self.handle_heartbeat()
        else:
            super().do_GET()

    def do_POST(self):
        url = urllib.parse.urlparse(self.path)
        path = url.path

        if path == "/api/falar" or path == "/api/tts":
            self.handle_tts()
        elif path == "/api/heartbeat":
            self.handle_heartbeat()
        else:
            self.send_error(404, "Endpoint not found")

    def send_json(self, data, code=200):
        body = json.dumps(data).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def handle_telemetry(self):
        env = get_env_map()
        hostname = socket.gethostname()
        ip = get_ip()
        cpu_temp = get_cpu_temp()
        ram = get_ram_info()
        disk = get_disk_info()
        uptime = get_uptime()

        services = {
            "casa-node-agent": check_service("casa-node-agent"),
            "casa-cluster-site": "active",
            "apache2": check_service("apache2"),
            "mosquitto": check_service("mosquitto"),
            "ssh": check_service("ssh"),
        }

        # Cluster nodes summary
        known_nodes = [
            {"id": "cubieboard-arm-07", "ip": "192.168.2.7", "name": "Cubieboard ARMv7", "port": 8080},
            {"id": "raspberry-pi-local", "ip": "192.168.2.12", "name": "Raspberry Pi 4 Cluster Hub", "port": 8080},
            {"id": "raspberry-pi-node-13", "ip": "192.168.2.13", "name": "Raspberry Pi Node 13", "port": 8080},
            {"id": "raspberry-pi-node-08", "ip": "192.168.2.8", "name": "Raspberry Pi Node 08", "port": 8080},
        ]

        resp = {
            "status": "online",
            "device_id": env.get("JARVIS_DEVICE_ID", hostname),
            "hostname": hostname,
            "ip": ip,
            "port": PORT,
            "uptime": uptime,
            "cpu_temp": cpu_temp,
            "ram": ram,
            "disk": disk,
            "base_url": env.get("CASA_BASE_URL", "https://maurinsoft.com.br/casa"),
            "capabilities": env.get("JARVIS_CAPABILITIES", "cluster-node,site"),
            "services": services,
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

def main():
    os.chdir(STATIC_DIR)
    server_address = ("0.0.0.0", PORT)
    httpd = HTTPServer(server_address, ClusterSiteHandler)
    print("==================================================================")
    print(" CASA / JARVIS - Cluster Site Server started on port {0}".format(PORT))
    print(" Serving UI: {0}".format(STATIC_DIR))
    print(" Access: http://{0}:{1}/".format(get_ip(), PORT))
    print("==================================================================")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down server...")
        httpd.server_close()

if __name__ == "__main__":
    main()
