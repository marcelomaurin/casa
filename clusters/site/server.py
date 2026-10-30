#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CASA / JARVIS - Cluster Node Web Portal Server
Compatible with Python 3.2+ (Cubieboard armv7l, Raspberry Pi 3/4, etc.)
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

# Polyfills for Python 3.2 / 3.3 compatibility on legacy ARM boards (Cubieboard)
if not hasattr(os, "cpu_count"):
    def _fallback_cpu_count():
        try:
            with open("/proc/cpuinfo", "r") as f:
                count = sum(1 for line in f if line.startswith("processor"))
                return count or 1
        except Exception:
            return 1
    os.cpu_count = _fallback_cpu_count

if not hasattr(time, "monotonic"):
    time.monotonic = time.time

if not hasattr(hmac, "compare_digest"):
    hmac.compare_digest = lambda a, b: a == b

try:
    _PermissionError = PermissionError
except NameError:
    _PermissionError = OSError

PORT = int(os.environ.get("CLUSTER_SITE_PORT", 8080))
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
STATIC_DIR = BASE_DIR


class CpuTelemetry:
    """Sample Linux CPU counters independently of HTTP traffic (Python 3.2+)."""
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
    ("Portal do cluster", "site/server.py", "/opt/casa-cluster-site/server.py"),
    ("Agendador", "automacao/casa-scheduler/casa-scheduler.py", "/opt/casa-scheduler/casa-scheduler.py"),
    ("Google Home", "voz/google-home-agent/google_home_agent.py", "/home/mmm/servicos/google-home-agent/google_home_agent.py"),
    ("Voz TTS", "voz/tts/server_tts.py", "/home/mmm/servicos/tts/server_tts.py"),
    ("Visão ESPCam", "visao/espcam/processa_imagem.py", "/opt/casa/espcam/processa_imagem.py"),
    ("Visão ESPCam", "visao/espcam/espcam_daemon.py", "/home/mmm/servicos/espcam/espcam_daemon.py"),
    ("Análise de cena", "visao/espcam/analisa_cena_ia.py", "/opt/casa/espcam/analisa_cena_ia.py"),
    ("Avatar", "visao/raspberrypi-avatar-agent/meta-casa-avatar/recipes-casa/casa-avatar/files/casa_avatar.py", "/opt/casa-avatar/casa_avatar.py"),
    ("Pesquisa web", "pesquisa/web-agent/web_agent.py", "/opt/jarvis-web-agent/web_agent.py"),
    ("Túnel", "infraestrutura/casa-tunnel/casa-tunnel.py", "/opt/casa-tunnel/casa-tunnel.py"),
    ("RunPod", "infraestrutura/runpod-agent/casa-runpod-agent.py", "/opt/casa/servicos/runpod-agent/casa-runpod-agent.py"),
    ("Agente SSH", "infraestrutura/casa-ssh-agent/casa-ssh-agent.py", "/opt/casa/ssh-agent/casa-ssh-agent.py"),
    ("Atualizador", "infraestrutura/casa-cluster-update/casa-cluster-update.py", "/opt/casa/cluster-update/casa-cluster-update.py"),
]

PROTECTED_SERVICES = ("casa-cluster-site",)

CLUSTER_NODE_PROFILES = {
    "192.168.2.13": {
        "id": "raspberry-pi-local",
        "name": "Raspberry Pi 4B (.13)",
        "role": "Nó de IA, Voz Neural TTS, Visão ESPCam & Controlador SSH",
        "assigned_services": [
            "casa-cluster-site", "casa-node-agent", "ssh",
            "casa-tts", "jarvis-web-agent", "espcam_daemon", "casa-ssh-agent", "casa-cluster-update"
        ],
    },
    "192.168.2.16": {
        "id": "raspberry-pi-node-08",
        "name": "Raspberry Pi 3B (.16)",
        "role": "Nó de Automação (Scheduler), Túnel Nuvem & RunPod GPU",
        "assigned_services": [
            "casa-cluster-site", "casa-node-agent", "ssh",
            "casa-scheduler", "casa-tunnel", "casa-runpod-agent", "casa-cluster-update"
        ],
    },
    "192.168.2.14": {
        "id": "cubieboard-arm-07",
        "name": "Cubieboard 2 (.14)",
        "role": "Nó Gateway de Hardware, Serial, RS485 & Sensores",
        "assigned_services": [
            "casa-cluster-site", "casa-node-agent", "ssh"
        ],
    },
    "192.168.2.15": {
        "id": "cubieboard-db",
        "name": "Cubieboard DB (.15)",
        "role": "Nó de Banco de Dados Central (maurinsoftdb)",
        "assigned_services": [
            "casa-cluster-site", "casa-node-agent", "ssh", "mariadb"
        ],
    },
}

MANAGED_SERVICES = [
    {
        "id": "casa-cluster-site",
        "label": "Portal Web do Cluster (:8080)",
        "category": "cluster",
        "protected": True,
        "owner_ip": "local",
        "owner_name": "Todos os nós (Base)",
        "port": 8080,
        "entrypoint": "/opt/casa-cluster-site/server.py",
        "purpose": "Interface web local e API REST de telemetria em tempo real (CPU, RAM, temperatura, processos) e controle remoto de daemons deste servidor.",
        "impact_when_stopped": "SERVIÇO PROTEGIDO: Não pode ser desativado pois deixaria o nó sem painel web e sem API local de gerenciamento.",
    },
    {
        "id": "casa-node-agent",
        "label": "Agente ARM do Control Plane (:8098)",
        "category": "cluster",
        "protected": False,
        "owner_ip": "local",
        "owner_name": "Todos os nós (Base)",
        "port": 8098,
        "entrypoint": "/opt/casa-node-agent/casa-node-agent.py",
        "purpose": "Daemon cliente do Control Plane CASA/1.0: envia heartbeat a cada 20s para o site principal, reporta telemetria/serviços e executa comandos remotos de GPIO, Relés, Serial/RS485 e controle de serviços.",
        "impact_when_stopped": "O servidor passará a aparecer como OFFLINE no site principal da casa e deixará de receber comandos da nuvem.",
    },
    {
        "id": "ssh",
        "label": "Servidor OpenSSH (:22)",
        "category": "system",
        "protected": False,
        "owner_ip": "local",
        "owner_name": "Todos os nós (Base)",
        "port": 22,
        "entrypoint": "/usr/sbin/sshd",
        "purpose": "Acesso seguro de terminal remoto (SSH) para administração Linux, deploy de código e comunicação com o controlador da malha SSH.",
        "impact_when_stopped": "Bloqueia conexões de terminal SSH externas para manutenção direta neste servidor.",
    },
    {
        "id": "casa-tts",
        "label": "Síntese Neural de Voz TTS (:8097)",
        "category": "cluster",
        "protected": False,
        "owner_ip": "192.168.2.13",
        "owner_name": "Raspberry Pi 4B (.13)",
        "port": 8097,
        "entrypoint": "/home/mmm/servicos/tts/server_tts.py",
        "purpose": "Motor de síntese de voz neural (Piper/eSpeak/Coqui) que converte textos e respostas do Jarvis em áudio falado nas caixas de som e nos dispositivos da casa.",
        "impact_when_stopped": "A casa e o assistente Jarvis ficam mudos (avisos falados, confirmações de comandos e alertas sonoros deixam de funcionar).",
    },
    {
        "id": "jarvis-web-agent",
        "label": "Agente de Pesquisa Web IA (:8092)",
        "category": "cluster",
        "protected": False,
        "owner_ip": "192.168.2.13",
        "owner_name": "Raspberry Pi 4B (.13)",
        "port": 8092,
        "entrypoint": "/opt/jarvis-web-agent/web_agent.py",
        "purpose": "Daemon de busca na internet, raspagem de páginas, consulta de notícias, previsão do tempo e alimentação de contexto externo atualizado para a IA Jarvis.",
        "impact_when_stopped": "O Jarvis perde a capacidade de consultar informações em tempo real na internet (notícias, cotações, clima e pesquisas web).",
    },
    {
        "id": "espcam_daemon",
        "label": "Visão Computacional & ESP32-CAM (:8088)",
        "category": "cluster",
        "protected": False,
        "owner_ip": "192.168.2.13",
        "owner_name": "Raspberry Pi 4B (.13)",
        "port": 8088,
        "entrypoint": "/home/mmm/servicos/espcam/espcam_daemon.py",
        "purpose": "Captura quadros das câmeras ESP32-CAM e RTSP da residência, executa detecção de presença/movimento e encaminha imagens para análise visual por IA.",
        "impact_when_stopped": "Interrompe o monitoramento automático das câmeras ESP32-CAM, detecção visual de presença e captura de fotos de segurança.",
    },
    {
        "id": "casa-ssh-agent",
        "label": "Controlador SSH da Malha (:8095)",
        "category": "cluster",
        "protected": False,
        "owner_ip": "192.168.2.13",
        "owner_name": "Raspberry Pi 4B (.13)",
        "port": 8095,
        "entrypoint": "/opt/casa/ssh-agent/casa-ssh-agent.py",
        "purpose": "Gateway central da malha SSH: permite que o site principal e as ferramentas de administração executem diagnósticos e comandos remotos autenticados em todos os nós ARM da LAN.",
        "impact_when_stopped": "Desativa a ponte central de gerenciamento SSH entre os servidores do cluster.",
    },
    {
        "id": "casa-scheduler",
        "label": "Agendador Residencial (Scheduler)",
        "category": "cluster",
        "protected": False,
        "owner_ip": "192.168.2.16",
        "owner_name": "Raspberry Pi 3B (.16)",
        "port": None,
        "entrypoint": "/opt/casa-scheduler/casa-scheduler.py",
        "purpose": "Motor cronológico da residência: avalia regras agendadas (horários de ligar/desligar luzes, relés, cenas automáticas, avisos matinais e rotinas periódicas) e dispara as ações no horário exato.",
        "impact_when_stopped": "Nenhuma rotina agendada por horário (despertador, acionamento programado de relés/iluminação ou cenas automáticas) será disparada.",
    },
    {
        "id": "casa-tunnel",
        "label": "Conectividade & Túnel Nuvem",
        "category": "cluster",
        "protected": False,
        "owner_ip": "192.168.2.16",
        "owner_name": "Raspberry Pi 3B (.16)",
        "port": None,
        "entrypoint": "/opt/casa-tunnel/casa-tunnel.py",
        "purpose": "Mantém o túnel reverso e a ponte de conectividade segura entre a rede interna da residência (LAN 192.168.2.x) e o servidor em nuvem (maurinsoft.com.br).",
        "impact_when_stopped": "A nuvem perde acesso direto por túnel aos serviços internos da rede local da casa.",
    },
    {
        "id": "casa-runpod-agent",
        "label": "Agente RunPod & GPU Remota (:8096)",
        "category": "cluster",
        "protected": False,
        "owner_ip": "192.168.2.16",
        "owner_name": "Raspberry Pi 3B (.16)",
        "port": 8096,
        "entrypoint": "/opt/casa/servicos/runpod-agent/casa-runpod-agent.py",
        "purpose": "Gerencia sob demanda instâncias de GPU na nuvem (RunPod) para tarefas pesadas de IA (modelos LLM grandes, visão avançada e treinamento), desligando pods ociosos para economizar custos.",
        "impact_when_stopped": "Desativa o provisionamento automático e o monitoramento de GPUs remotas no RunPod.",
    },
    {
        "id": "casa-cluster-update",
        "label": "Atualizador Automático Git",
        "category": "cluster",
        "protected": False,
        "owner_ip": "local",
        "owner_name": "Nós Raspberry Pi (.13 / .16)",
        "port": None,
        "entrypoint": "/opt/casa/cluster-update/casa-cluster-update.py",
        "purpose": "Serviço sob demanda (oneshot) que sincroniza o código-fonte do repositório Git oficial (GitHub), atualiza os pacotes em /opt/casa/ e reinicia os daemons atualizados.",
        "impact_when_stopped": "Normal permanecer inativo quando não há atualização em andamento (roda sob demanda ou via timer).",
    },
    {
        "id": "mariadb",
        "label": "Banco MariaDB / MySQL (maurinsoftdb)",
        "category": "database",
        "protected": False,
        "owner_ip": "192.168.2.15",
        "owner_name": "Cubieboard DB (.15)",
        "port": 3306,
        "entrypoint": "/usr/sbin/mariadbd",
        "purpose": "Servidor de banco de dados relacional que hospeda o banco central da casa (maurinsoftdb: dispositivos, sensores, agendamentos, usuários e logs). Deve rodar exclusivamente no servidor de banco.",
        "impact_when_stopped": "Desativado nos nós de aplicação (.13, .14, .16) para economizar RAM. Se parado no servidor de banco (.15), interrompe as consultas SQL locais.",
    },
    {
        "id": "postgresql",
        "label": "Banco PostgreSQL (:5432)",
        "category": "database",
        "protected": False,
        "owner_ip": "none",
        "owner_name": "Desativado (Economia de Recursos)",
        "port": 5432,
        "entrypoint": "/usr/lib/postgresql/15/bin/postgres",
        "purpose": "Banco de dados PostgreSQL legado/auxiliar. Mantido desativado por padrão nos nós ARM para liberar memória RAM.",
        "impact_when_stopped": "Economiza ~60 MB de RAM; os daemons operam normalmente usando a API/MySQL.",
    },
    {
        "id": "lightdm",
        "label": "Interface Gráfica Desktop (LightDM / X11)",
        "category": "gui",
        "protected": False,
        "owner_ip": "none",
        "owner_name": "Desativado (Modo Headless)",
        "port": None,
        "entrypoint": "/usr/sbin/lightdm",
        "purpose": "Gerenciador de login gráfico e servidor X11 (ambiente de área de trabalho no monitor HDMI). Desnecessário em servidores de cluster headless.",
        "impact_when_stopped": "Economiza 120 MB a 250 MB de RAM e CPU; o servidor opera 100% via Web e SSH.",
    },
    {
        "id": "vncserver-x11-serviced",
        "label": "Servidor VNC Gráfico",
        "category": "gui",
        "protected": False,
        "owner_ip": "none",
        "owner_name": "Desativado (Modo Headless)",
        "port": 5900,
        "entrypoint": "/usr/bin/vncserver-x11-serviced",
        "purpose": "Servidor de área de trabalho remota gráfica (RealVNC). Desativado nos servidores headless.",
        "impact_when_stopped": "Economiza memória RAM; toda administração é feita via Portal Web (:8080) e SSH (:22).",
    },
    {
        "id": "apache2",
        "label": "Servidor Web Apache2 (:80)",
        "category": "system",
        "protected": False,
        "owner_ip": "none",
        "owner_name": "Desativado (Substituído por :8080)",
        "port": 80,
        "entrypoint": "/usr/sbin/apache2",
        "purpose": "Servidor HTTP Apache tradicional. Nos nós do cluster foi substituído pelo Portal Web leve na porta 8080.",
        "impact_when_stopped": "Libera memória RAM nos nós ARM sem afetar o Portal do Cluster (:8080).",
    },
    {
        "id": "mosquitto",
        "label": "Broker MQTT Mosquitto (:1883)",
        "category": "system",
        "protected": False,
        "owner_ip": "none",
        "owner_name": "Sob demanda",
        "port": 1883,
        "entrypoint": "/usr/sbin/mosquitto",
        "purpose": "Broker de mensageria leve protocolo MQTT para sensores e relés IoT (ESP8266/ESP32/Tasmota) que publicam tópicos locais.",
        "impact_when_stopped": "Dispositivos configurados exclusivamente via MQTT local não trocarão mensagens no broker deste nó.",
    },
]

SERVICE_PROCESS_PATTERNS = {
    "casa-node-agent": "casa-node-agent.py",
    "casa-cluster-site": "site/server.py",
    "casa-ssh-agent": "casa-ssh-agent.py",
    "casa-tts": "server_tts.py",
    "casa-scheduler": "casa-scheduler.py",
    "espcam_daemon": "espcam_daemon.py|processa_imagem.py",
    "casa-tunnel": "casa-tunnel.py",
    "jarvis-web-agent": "web_agent.py",
    "casa-runpod-agent": "casa-runpod-agent.py",
    "casa-cluster-update": "casa-cluster-update.py",
    "ssh": "/usr/sbin/sshd",
    "mariadb": "mariadbd|mysqld",
    "mysql": "mariadbd|mysqld",
    "postgresql": "bin/postgres",
    "lightdm": "sbin/lightdm|bin/X",
    "vncserver-x11-serviced": "vncserver-x11",
    "apache2": "sbin/apache2",
    "mosquitto": "sbin/mosquitto",
}


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
        except _PermissionError:
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
    allow_reuse_address = True


def run_cmd(args):
    try:
        p = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
        out, err = p.communicate()
        combined = ((out or "") + "\n" + (err or "")).strip()
        return p.returncode, (out or "").strip() if (out or "").strip() else combined
    except Exception:
        return 1, ""


def has_systemctl():
    return os.path.isdir("/run/systemd/system") and (
        os.path.exists("/bin/systemctl") or os.path.exists("/usr/bin/systemctl")
    )


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
        pct = round((used / total * 100.0) if total else 0.0, 1)
        return {"used_mb": used, "total_mb": total, "free_mb": avail, "used_pct": pct}
    except Exception:
        return {"used_mb": 0, "total_mb": 0, "free_mb": 0, "used_pct": 0}


def get_disk_info():
    try:
        st = os.statvfs("/")
        total = (st.f_blocks * st.f_frsize) // (1024 * 1024 * 1024)
        free = (st.f_bavail * st.f_frsize) // (1024 * 1024 * 1024)
        used = max(0, total - free)
        pct = round((used / total * 100.0) if total else 0.0, 1)
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
    clean = name.replace(".service", "").strip()
    if clean == "casa-cluster-site":
        return "active"

    # PostgreSQL wrapper service on Debian can report 'active' (exited) even when cluster is stopped
    if clean == "postgresql":
        if has_systemctl():
            ret, out = run_cmd(["systemctl", "is-active", "postgresql@15-main.service"])
            if ret == 0 and out.strip() == "active":
                return "active"
        ret_pg, out_pg = run_cmd(["pgrep", "-f", "bin/postgres"])
        return "active" if (ret_pg == 0 and out_pg.strip()) else "inactive"

    if has_systemctl():
        candidates = [clean + ".service"]
        if clean in ("mariadb", "mysql"):
            candidates = ["mariadb.service", "mysql.service"]
        elif clean == "ssh":
            candidates = ["ssh.service", "sshd.service"]
        for unit in candidates:
            ret, out = run_cmd(["systemctl", "is-active", unit])
            if ret == 0 and out.strip() == "active":
                return "active"
        return "inactive"

    # Fallback for Upstart / SysVinit systems (e.g. Cubieboard Linaro)
    if os.path.exists("/etc/init/" + clean + ".conf"):
        ret, out = run_cmd(["status", clean])
        if ret == 0 and "start/running" in out.lower():
            return "active"

    if os.path.exists("/etc/init.d/" + clean):
        ret, out = run_cmd(["/etc/init.d/" + clean, "status"])
        if ret == 0 and ("running" in out.lower() or "active" in out.lower()):
            return "active"

    pattern = SERVICE_PROCESS_PATTERNS.get(clean)
    if pattern:
        ret, out = run_cmd(["pgrep", "-f", pattern])
        if ret == 0 and out.strip():
            return "active"

    return "inactive"


def is_service_installed(name):
    clean = name.replace(".service", "").strip()
    if clean == "casa-cluster-site":
        return True
    if has_systemctl():
        candidates = [clean + ".service"]
        if clean in ("mariadb", "mysql"):
            candidates = ["mariadb.service", "mysql.service"]
        elif clean == "ssh":
            candidates = ["ssh.service", "sshd.service"]
        for unit in candidates:
            for base in ("/etc/systemd/system/", "/lib/systemd/system/", "/usr/lib/systemd/system/", "/etc/init.d/"):
                if os.path.exists(base + unit) or os.path.exists(base + unit.replace(".service", "")):
                    return True
        return False
    if os.path.exists("/etc/init/" + clean + ".conf") or os.path.exists("/etc/init.d/" + clean):
        return True
    if clean == "casa-node-agent" and os.path.exists("/opt/casa-node-agent/casa-node-agent.py"):
        return True
    if clean == "ssh" and os.path.exists("/usr/sbin/sshd"):
        return True
    return False


NODE_SERVICES_CONFIG_PATH = os.environ.get("CASA_NODE_SERVICES_CONFIG", "/etc/casa-node-services.json")

SINGLETON_CLUSTER_SERVICES = (
    "casa-tts",
    "jarvis-web-agent",
    "espcam_daemon",
    "casa-ssh-agent",
    "casa-scheduler",
    "casa-tunnel",
    "casa-runpod-agent",
    "mariadb",
)


def load_node_services_config():
    for path in (NODE_SERVICES_CONFIG_PATH, "/tmp/casa-node-services.json"):
        if os.path.exists(path):
            try:
                with open(path, "r", encoding="utf-8") as f:
                    data = json.load(f)
                    if isinstance(data, dict):
                        return data
            except Exception:
                pass
    return {}


def save_node_services_config(data):
    for path in (NODE_SERVICES_CONFIG_PATH, "/tmp/casa-node-services.json"):
        try:
            with open(path, "w", encoding="utf-8") as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
            return True
        except Exception:
            continue
    return False


def get_node_profile(ip=None):
    cur_ip = ip or get_ip()
    if cur_ip in CLUSTER_NODE_PROFILES:
        base = dict(CLUSTER_NODE_PROFILES[cur_ip])
        base["assigned_services"] = list(base.get("assigned_services", []))
    else:
        base = {
            "id": socket.gethostname(),
            "name": socket.gethostname(),
            "role": "Nó do Cluster ARM",
            "assigned_services": ["casa-cluster-site", "casa-node-agent", "ssh"],
        }

    if ip is None or cur_ip == get_ip():
        cfg = load_node_services_config()
        if cfg.get("role"):
            base["role"] = str(cfg["role"])
        if isinstance(cfg.get("assigned_services"), list):
            merged = list(cfg["assigned_services"])
            for req_svc in ("casa-cluster-site",):
                if req_svc not in merged:
                    merged.insert(0, req_svc)
            base["assigned_services"] = merged
        if isinstance(cfg.get("owner_overrides"), dict):
            base["owner_overrides"] = dict(cfg["owner_overrides"])
    return base


def control_service(name, action, force=False, update_assignment=False):
    """Ativa, desativa ou reinicia um servico do no local, protegendo o servico web e evitando redundancia."""
    clean = (name or "").replace(".service", "").strip()
    act = (action or "").strip().lower()

    allowed_ids = set(item["id"] for item in MANAGED_SERVICES) | set(["mysql", "sshd"])
    if clean not in allowed_ids:
        return {"ok": False, "error": "Serviço não gerenciado por este painel: {0}".format(clean)}, 400

    if act not in ("start", "stop", "restart", "activate", "deactivate", "enable", "disable"):
        return {"ok": False, "error": "Ação inválida: {0}. Use start, stop ou restart.".format(act)}, 400

    # REGRA CRÍTICA: Nunca permitir desativar/parar o serviço web (casa-cluster-site)
    if clean in PROTECTED_SERVICES and act in ("stop", "deactivate", "disable"):
        return {
            "ok": False,
            "service": clean,
            "action": act,
            "state": "active",
            "active": True,
            "protected": True,
            "error": "Operação bloqueada: o serviço web (casa-cluster-site) é protegido e não pode ser desativado.",
        }, 403

    if clean in PROTECTED_SERVICES and act in ("start", "activate", "enable", "restart"):
        return {
            "ok": True,
            "service": clean,
            "action": act,
            "state": "active",
            "active": True,
            "protected": True,
            "message": "O serviço web (casa-cluster-site) já está ativo e protegido.",
        }, 200

    # Evitar redundância no cluster: bloquear ativação se o serviço pertence a outro nó do cluster
    cur_ip = get_ip()
    profile = get_node_profile(cur_ip)
    owner_overrides = profile.get("owner_overrides") or {}
    if not force and act in ("start", "activate", "enable") and cur_ip in CLUSTER_NODE_PROFILES:
        if clean not in profile.get("assigned_services", []):
            owner_info = None
            for item in MANAGED_SERVICES:
                if item["id"] == clean:
                    owner_info = item
                    break
            ov = owner_overrides.get(clean) or {}
            owner_ip = ov.get("owner_ip") or (owner_info.get("owner_ip", "none") if owner_info else "none")
            owner_name = ov.get("owner_name") or (owner_info.get("owner_name", "outro nó") if owner_info else "outro nó")
            if owner_ip not in ("local", "none", cur_ip):
                return {
                    "ok": False,
                    "service": clean,
                    "action": act,
                    "state": check_service(clean),
                    "active": False,
                    "error": "Redundância evitada: o serviço '{0}' é atribuído ao servidor {1} ({2}). Gerencie-o pelo site principal ou em http://{2}:8080/.".format(
                        clean, owner_name, owner_ip
                    ),
                }, 409

    if force or update_assignment:
        cfg = load_node_services_config()
        assigned_list = list(cfg.get("assigned_services") or profile.get("assigned_services") or ["casa-cluster-site", "casa-node-agent", "ssh"])
        ov_map = dict(cfg.get("owner_overrides") or {})
        if act in ("start", "activate", "enable"):
            if clean not in assigned_list:
                assigned_list.append(clean)
            ov_map[clean] = {"owner_ip": cur_ip, "owner_name": profile.get("name", socket.gethostname())}
        elif act in ("stop", "deactivate", "disable") and update_assignment:
            if clean not in ("casa-cluster-site", "casa-node-agent", "ssh") and clean in assigned_list:
                assigned_list = [s for s in assigned_list if s != clean]
        cfg["assigned_services"] = assigned_list
        cfg["owner_overrides"] = ov_map
        save_node_services_config(cfg)

    if has_systemctl():
        if act in ("start", "activate", "enable"):
            if clean == "postgresql":
                run_cmd(["systemctl", "enable", "--now", "postgresql.service"])
                run_cmd(["systemctl", "start", "postgresql@15-main.service"])
            elif clean in ("mariadb", "mysql"):
                run_cmd(["systemctl", "enable", "--now", "mariadb.service"])
                run_cmd(["systemctl", "start", "mariadb.service"])
            else:
                ret, _ = run_cmd(["systemctl", "enable", "--now", clean + ".service"])
                if ret != 0:
                    run_cmd(["systemctl", "start", clean + ".service"])
        elif act in ("stop", "deactivate", "disable"):
            if clean == "postgresql":
                run_cmd(["systemctl", "disable", "--now", "postgresql.service"])
                run_cmd(["systemctl", "stop", "postgresql@15-main.service"])
            elif clean in ("mariadb", "mysql"):
                run_cmd(["systemctl", "disable", "--now", "mariadb.socket"])
                run_cmd(["systemctl", "disable", "--now", "mariadb.service"])
                run_cmd(["systemctl", "disable", "--now", "mysql.service"])
            elif clean == "lightdm":
                run_cmd(["systemctl", "set-default", "multi-user.target"])
                run_cmd(["systemctl", "disable", "--now", "lightdm.service"])
            else:
                run_cmd(["systemctl", "disable", "--now", clean + ".service"])
                run_cmd(["systemctl", "stop", clean + ".service"])
        elif act == "restart":
            if clean == "postgresql":
                run_cmd(["systemctl", "restart", "postgresql@15-main.service"])
            else:
                run_cmd(["systemctl", "restart", clean + ".service"])
    else:
        # Upstart / SysVinit (Cubieboard Linaro)
        override_file = "/etc/init/" + clean + ".override"
        if act in ("start", "activate", "enable"):
            if os.path.exists(override_file):
                try:
                    os.remove(override_file)
                except Exception:
                    pass
            if clean == "casa-node-agent" and os.path.exists("/opt/casa-node-agent/casa-node-agent.py"):
                if os.path.exists("/etc/init.d/casa-node-agent"):
                    run_cmd(["update-rc.d", "casa-node-agent", "defaults"])
                if check_service("casa-node-agent") != "active":
                    devnull_in = open(os.devnull, "rb")
                    devnull_out = open("/var/log/casa-node-agent.log", "ab")
                    subprocess.Popen(["/usr/bin/python3", "/opt/casa-node-agent/casa-node-agent.py"], stdin=devnull_in, stdout=devnull_out, stderr=devnull_out, close_fds=True)
            elif os.path.exists("/etc/init.d/" + clean):
                run_cmd(["update-rc.d", clean, "defaults"])
                run_cmd(["/etc/init.d/" + clean, "start"])
            elif os.path.exists("/etc/init/" + clean + ".conf"):
                run_cmd(["start", clean])
        elif act in ("stop", "deactivate", "disable"):
            if os.path.exists("/etc/init/" + clean + ".conf"):
                try:
                    with open(override_file, "w") as f:
                        f.write("manual\n")
                except Exception:
                    pass
                run_cmd(["stop", clean])
            if os.path.exists("/etc/init.d/" + clean):
                run_cmd(["/etc/init.d/" + clean, "stop"])
                run_cmd(["update-rc.d", "-f", clean, "remove"])
            pattern = SERVICE_PROCESS_PATTERNS.get(clean)
            if pattern and clean != "casa-cluster-site":
                run_cmd(["pkill", "-f", pattern])
        elif act == "restart":
            if clean == "casa-node-agent" and os.path.exists("/opt/casa-node-agent/casa-node-agent.py"):
                run_cmd(["pkill", "-f", "casa-node-agent.py"])
                time.sleep(0.3)
                devnull_in = open(os.devnull, "rb")
                devnull_out = open("/var/log/casa-node-agent.log", "ab")
                subprocess.Popen(["/usr/bin/python3", "/opt/casa-node-agent/casa-node-agent.py"], stdin=devnull_in, stdout=devnull_out, stderr=devnull_out, close_fds=True)
            elif os.path.exists("/etc/init.d/" + clean):
                run_cmd(["/etc/init.d/" + clean, "restart"])
            elif os.path.exists("/etc/init/" + clean + ".conf"):
                run_cmd(["restart", clean])

    time.sleep(0.4)
    new_state = check_service(clean)
    expected_active = act in ("start", "activate", "enable", "restart")
    ok = (new_state == "active") if expected_active else (new_state != "active")
    return {
        "ok": ok,
        "service": clean,
        "action": act,
        "state": new_state,
        "active": new_state == "active",
        "protected": clean in PROTECTED_SERVICES,
        "message": "Serviço {0} {1} (estado atual: {2}).".format(
            clean,
            "ativado" if new_state == "active" else "desativado",
            new_state.upper(),
        ),
    }, (200 if ok else 500)


_cached_nodes = []
_last_nodes_fetch = 0


def get_cluster_nodes_from_registry():
    """Busca dinamicamente os nós registrados no banco/API da central, sem IPs chumbados."""
    global _cached_nodes, _last_nodes_fetch
    now = time.time()
    cache_file = os.path.join(BASE_DIR, "cluster_nodes_cache.json")

    if _cached_nodes and (now - _last_nodes_fetch < 30):
        return _cached_nodes

    env = get_env_map()
    base_url = env.get("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
    token = env.get("JARVIS_DEVICE_TOKEN", env.get("DEVICE_TOKEN", env.get("CASA_API_KEY", ""))).strip()

    nodes_fetched = []
    try:
        url = base_url + "/api/arm_nodes.php" if token else ""
        if not url:
            raise RuntimeError("Token nao configurado")
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

    if nodes_fetched:
        _cached_nodes = nodes_fetched
        _last_nodes_fetch = now
        try:
            with open(cache_file, "w", encoding="utf-8") as cf:
                json.dump(nodes_fetched, cf, ensure_ascii=False, indent=2)
        except Exception:
            pass
        return nodes_fetched

    for candidate in (cache_file, "/opt/casa/site/cluster_nodes_cache.json"):
        if os.path.exists(candidate):
            try:
                with open(candidate, "r", encoding="utf-8") as cf:
                    _cached_nodes = json.load(cf)
                    _last_nodes_fetch = now
                    return _cached_nodes
            except Exception:
                pass

    return _cached_nodes or []


class ClusterSiteHandler(SimpleHTTPRequestHandler):
    def translate_path(self, path):
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
        if path.startswith("/api/v1/"):
            path = "/api/" + path[len("/api/v1/"):]

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
        elif path.startswith("/api/services/") or path == "/api/services":
            svc_name = path.replace("/api/services/", "").replace("/api/services", "").strip()
            self.handle_service_status(svc_name)
        else:
            super().do_GET()

    def do_POST(self):
        url = urllib.parse.urlparse(self.path)
        path = url.path
        if path.startswith("/api/v1/"):
            path = "/api/" + path[len("/api/v1/"):]

        if path == "/api/falar" or path == "/api/tts":
            self.handle_tts()
        elif path == "/api/heartbeat":
            self.handle_heartbeat()
        elif path == "/api/cluster/update":
            self.handle_cluster_update_trigger()
        elif path == "/api/processes/terminate":
            self.handle_process_termination()
        elif path == "/api/services/control" or path.startswith("/api/services/"):
            self.handle_service_control(path)
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

    def handle_service_control(self, path):
        try:
            length = int(self.headers.get("Content-Length", "0") or 0)
            raw = self.rfile.read(length).decode("utf-8") if length > 0 else ""
            data = json.loads(raw) if raw else {}
        except Exception:
            self.send_json({"ok": False, "error": "Payload JSON inválido."}, code=400)
            return

        if path == "/api/services/assign" or data.get("action") == "assign":
            self.handle_service_assign(data)
            return

        svc_name = data.get("service", "")
        action = data.get("action", "")
        force = bool(data.get("force", False))
        update_assignment = bool(data.get("update_assignment", False))

        # Allow /api/services/<name>/<action> URL style too
        if path != "/api/services/control" and path.startswith("/api/services/"):
            parts = [p for p in path.replace("/api/services/", "").split("/") if p]
            if len(parts) >= 1 and not svc_name:
                svc_name = parts[0]
            if len(parts) >= 2 and not action:
                action = parts[1]

        result, status_code = control_service(svc_name, action, force=force, update_assignment=update_assignment)
        self.send_json(result, code=status_code)

    def handle_service_assign(self, data):
        cur_ip = get_ip()
        profile = get_node_profile(cur_ip)
        cfg = load_node_services_config()

        role = data.get("role")
        if role is not None and str(role).strip():
            cfg["role"] = str(role).strip()

        raw_assigned = data.get("assigned_services")
        if isinstance(raw_assigned, list):
            assigned = [str(s).strip() for s in raw_assigned if str(s).strip()]
            for base_svc in ("casa-cluster-site", "casa-node-agent", "ssh"):
                if base_svc not in assigned:
                    assigned.insert(0, base_svc)
            cfg["assigned_services"] = assigned
        else:
            assigned = list(cfg.get("assigned_services") or profile.get("assigned_services") or ["casa-cluster-site", "casa-node-agent", "ssh"])

        owner_overrides = dict(cfg.get("owner_overrides") or {})
        if isinstance(data.get("owner_overrides"), dict):
            owner_overrides.update(data["owner_overrides"])
        for s in assigned:
            if s in SINGLETON_CLUSTER_SERVICES:
                owner_overrides[s] = {"owner_ip": cur_ip, "owner_name": profile.get("name", socket.gethostname())}
        cfg["owner_overrides"] = owner_overrides
        save_node_services_config(cfg)

        applied = {}
        auto_apply = bool(data.get("auto_apply", True))
        if auto_apply:
            assigned_set = set(assigned)
            for sid in SINGLETON_CLUSTER_SERVICES:
                cur_state = check_service(sid)
                if sid in assigned_set and cur_state != "active" and is_service_installed(sid):
                    res, _ = control_service(sid, "start", force=True)
                    applied[sid] = res.get("state", check_service(sid))
                elif sid not in assigned_set and cur_state == "active":
                    res, _ = control_service(sid, "stop", force=True)
                    applied[sid] = res.get("state", check_service(sid))

        self.send_json({
            "ok": True,
            "ip": cur_ip,
            "role": cfg.get("role", profile.get("role")),
            "assigned_services": assigned,
            "applied": applied,
            "message": "Atribuição de serviços do nó atualizada com sucesso.",
        })

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
        if isinstance(known, dict):
            for nid, n in known.items():
                host = n.get("host") or n.get("ip", "")
                nodes_list.append({
                    "id": nid,
                    "name": n.get("name", nid),
                    "host": host,
                    "lan_ip": host,
                    "port": int(n.get("port") or 22),
                    "user": "mmm",
                    "role": n.get("role", "ARM Node"),
                    "online": bool(n.get("online", True))
                })
        elif isinstance(known, list):
            for n in known:
                nid = n.get("id") or n.get("name") or "node"
                host = n.get("ip") or n.get("host", "")
                nodes_list.append({
                    "id": nid,
                    "name": n.get("name", nid),
                    "host": host,
                    "lan_ip": host,
                    "port": int(n.get("port") or 22),
                    "user": "mmm",
                    "role": n.get("role", "ARM Node"),
                    "online": bool(n.get("online", True))
                })
        self.send_json({"ok": True, "nodes": nodes_list, "total": len(nodes_list), "fallback": True})

    def handle_service_status(self, name):
        if not name or name == "all":
            res = {}
            details_list = []
            for item in MANAGED_SERVICES:
                sid = item["id"]
                st = check_service(sid)
                inst = is_service_installed(sid)
                res[sid] = {
                    "status": "online" if st == "active" else "offline",
                    "state": st,
                    "active": st == "active",
                    "installed": inst,
                    "protected": item["protected"],
                    "label": item["label"],
                    "category": item["category"],
                    "purpose": item.get("purpose", ""),
                    "impact_when_stopped": item.get("impact_when_stopped", ""),
                    "entrypoint": item.get("entrypoint", ""),
                    "port": item.get("port"),
                }
                details_list.append({
                    "id": sid,
                    "label": item["label"],
                    "category": item["category"],
                    "state": st,
                    "active": st == "active",
                    "installed": inst,
                    "protected": item["protected"],
                    "purpose": item.get("purpose", ""),
                    "impact_when_stopped": item.get("impact_when_stopped", ""),
                    "entrypoint": item.get("entrypoint", ""),
                    "port": item.get("port"),
                })
            self.send_json({"ok": True, "services": res, "services_detail": details_list, "timestamp": int(time.time())})
            return

        svc = name if name.endswith(".service") else name + ".service"
        clean = name.replace(".service", "")
        state = check_service(clean)

        details = {}
        if has_systemctl():
            ret_show, out_show = run_cmd(["systemctl", "show", svc, "--property=ActiveState,SubState,MainPID,ExecMainStartTimestamp"])
            if ret_show == 0:
                for line in out_show.splitlines():
                    if "=" in line:
                        k, v = line.split("=", 1)
                        details[k.strip()] = v.strip()

        ports_map = {
            "casa-node-agent": 8098,
            "casa-tts": 8097,
            "casa-ssh-agent": 8095,
            "casa-cluster-site": PORT,
            "jarvis-web-agent": 8092,
            "casa-runpod-agent": 8096,
        }
        port = ports_map.get(clean)
        http_check = None
        if port:
            endpoint = "/health" if port in (8095, 8096) else ("/status" if port != PORT else "/api/status")
            try:
                req = urllib.request.Request("http://127.0.0.1:{0}{1}".format(port, endpoint), headers={"User-Agent": "ClusterSiteIntegrity/1.0"})
                with urllib.request.urlopen(req, timeout=2.0) as r:
                    code = getattr(r, "status", getattr(r, "code", 200))
                    http_check = {
                        "ok": True,
                        "code": code,
                        "data": json.loads(r.read().decode("utf-8", errors="ignore"))
                    }
            except Exception as e:
                http_check = {"ok": False, "error": str(e)}

        self.send_json({
            "status": "online" if state == "active" else "offline",
            "service": clean,
            "active": state == "active",
            "protected": clean in PROTECTED_SERVICES,
            "installed": is_service_installed(clean),
            "state": details.get("ActiveState", state),
            "sub_state": details.get("SubState", "unknown"),
            "pid": int(details.get("MainPID", 0)) if details.get("MainPID", "").isdigit() else None,
            "started_at": details.get("ExecMainStartTimestamp", None),
            "port": port,
            "http_probe": http_check,
            "timestamp": int(time.time()),
        })

    def handle_telemetry(self):
        env = get_env_map()
        hostname = socket.gethostname()
        ip = get_ip()
        cpu_temp = get_cpu_temp()
        ram = get_ram_info()
        disk = get_disk_info()
        uptime = get_uptime()

        ssh_user = env.get("JARVIS_SSH_USER", "linaro" if "cubie" in hostname.lower() else "mmm")
        profile = get_node_profile(ip)
        assigned_set = set(profile.get("assigned_services", []))
        owner_overrides = profile.get("owner_overrides") or {}
        services = {}
        services_detail = []
        cluster_service_map = []
        for item in MANAGED_SERVICES:
            sid = item["id"]
            st = check_service(sid)
            inst = is_service_installed(sid)
            services[sid] = st
            ov = owner_overrides.get(sid) or {}
            owner_ip = ov.get("owner_ip") or item.get("owner_ip", "local")
            owner_name = ov.get("owner_name") or item.get("owner_name", "")
            is_assigned = (sid in assigned_set) or (owner_ip == ip and sid in assigned_set) or (owner_ip == "local" and sid in assigned_set)

            # Na lista de controle deste nó, exibe apenas os serviços atribuídos a este nó
            # (ou que estejam ativos localmente para permitir desativá-los se estiverem sobrando).
            if is_assigned or st == "active":
                services_detail.append({
                    "id": sid,
                    "label": item["label"],
                    "category": item["category"],
                    "state": st,
                    "active": st == "active",
                    "installed": inst,
                    "protected": item["protected"],
                    "assigned": is_assigned,
                    "owner_ip": ip if owner_ip == "local" else owner_ip,
                    "owner_name": owner_name,
                    "purpose": item.get("purpose", ""),
                    "impact_when_stopped": item.get("impact_when_stopped", ""),
                    "entrypoint": item.get("entrypoint", ""),
                    "port": item.get("port"),
                })

            if owner_ip not in ("local", "none"):
                cluster_service_map.append({
                    "id": sid,
                    "label": item["label"],
                    "category": item["category"],
                    "owner_ip": owner_ip,
                    "owner_name": owner_name,
                    "is_this_node": owner_ip == ip,
                    "local_state": st if owner_ip == ip else None,
                    "portal_url": "http://{0}:{1}/".format(owner_ip, PORT),
                    "purpose": item.get("purpose", ""),
                    "impact_when_stopped": item.get("impact_when_stopped", ""),
                    "entrypoint": item.get("entrypoint", ""),
                    "port": item.get("port"),
                })

        # Inclui tambem o papel dedicado de Gateway Serial/RS485 do Cubieboard .14 no mapa do cluster
        cluster_service_map.append({
            "id": "gateway-serial-rs485",
            "label": "Gateway de Hardware, Serial, RS485 & Sensores",
            "category": "cluster",
            "owner_ip": "192.168.2.14",
            "owner_name": "Cubieboard 2 (.14)",
            "is_this_node": ip == "192.168.2.14",
            "local_state": "active" if ip == "192.168.2.14" else None,
            "portal_url": "http://192.168.2.14:{0}/".format(PORT),
            "purpose": "Interface física direta com barramentos Serial/RS485 (/dev/ttyUSB*, /dev/ttyS*), pinos GPIO e leitura de sensores de hardware conectados ao Cubieboard.",
            "impact_when_stopped": "Interrompe o acionamento físico de placas de relés seriais/RS485 e leitura de sensores cabeados neste gateway.",
            "entrypoint": "/opt/casa-node-agent/casa-node-agent.py",
            "port": 8098,
        })

        known_nodes = get_cluster_nodes_from_registry()

        resp = {
            "status": "online",
            "device_id": env.get("JARVIS_DEVICE_ID", profile.get("id", hostname)),
            "hostname": hostname,
            "node_role": profile.get("role", "Nó do Cluster ARM"),
            "assigned_services": list(assigned_set),
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
            "services_detail": services_detail,
            "cluster_service_map": cluster_service_map,
            "ssh": {
                "service": services.get("ssh", "unknown"),
                "agent_service": services.get("casa-ssh-agent", "unknown"),
                "port": 22,
                "agent_port": 8095,
                "user": ssh_user,
                "command": "ssh {0}@{1}".format(ssh_user, ip),
            },
            "cluster_nodes": known_nodes,
            "timestamp": int(time.time()),
        }
        self.send_json(resp)

    def handle_heartbeat(self):
        env = get_env_map()
        base_url = env.get("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
        token = env.get("JARVIS_DEVICE_TOKEN", env.get("DEVICE_TOKEN", env.get("CASA_API_KEY", ""))).strip()
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

        if not token:
            self.send_json({"status": "error", "error": "JARVIS_DEVICE_TOKEN nao configurado"}, 400)
            return
        url = "{0}/api/v1/device.php?acao=heartbeat".format(base_url)
        headers = {
            "Content-Type": "application/json",
            "Authorization": "Bearer {0}".format(token),
            "X-Device-Token": token,
            "User-Agent": "ClusterSite-Server/1.0",
        }

        try:
            req = urllib.request.Request(url, data=json.dumps(payload).encode("utf-8"), headers=headers)
            with urllib.request.urlopen(req, timeout=5) as response:
                res_data = response.read().decode("utf-8")
                code = getattr(response, "status", getattr(response, "code", 200))
                self.send_json({"ok": True, "http_code": code, "response": res_data})
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

            run_cmd(["espeak", "-v", "pt-br", texto])
            self.send_json({"ok": True, "fala": texto})
        except Exception as e:
            self.send_json({"ok": False, "error": str(e)}, code=500)

    def handle_cluster_update_status(self):
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
            ret = 1
            if has_systemctl():
                ret, _ = run_cmd(["systemctl", "start", "casa-cluster-update.service"])
            if ret != 0:
                cmd = ["/usr/bin/python3", "/opt/casa/cluster-update/casa-cluster-update.py", "--apply"]
                if not os.path.exists("/opt/casa/cluster-update/casa-cluster-update.py"):
                    cmd = ["/usr/bin/python3", "/home/mmm/projetos/maurinsoft/casa/clusters/infraestrutura/casa-cluster-update/casa-cluster-update.py", "--apply"]
                devnull = getattr(subprocess, "DEVNULL", open(os.devnull, "wb"))
                subprocess.Popen(cmd, stdout=devnull, stderr=devnull)

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
