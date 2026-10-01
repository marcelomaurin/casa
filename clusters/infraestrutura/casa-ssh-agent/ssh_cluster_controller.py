# -*- coding: utf-8 -*-
"""
CASA / JARVIS - Cluster SSH Controller Engine
Gerenciador e executor centralizado de operacoes SSH nos nos do cluster.
Suporta:
- Inventario de nos (Raspberry Pi, Cubieboard, Linux ARM/x86)
- Execucao de comandos remotos (com e sem sudo)
- Execucao em lote / paralela (ThreadPoolExecutor)
- Transferencia de arquivos / scripts via SFTP
- Diagnostico de latencia e status de conexao
- Registro de auditoria de comandos da IA
"""

import os
import sys
import time
import json
import socket
import logging
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed

try:
    import paramiko
    HAS_PARAMIKO = True
except ImportError:
    HAS_PARAMIKO = False

import subprocess

logger = logging.getLogger("casa-ssh-agent")

DEFAULT_NODES = {
    "raspberry-pi-local": {
        "id": "raspberry-pi-local",
        "name": "Raspberry Pi 4B (Hub Principal)",
        "host": "127.0.0.1",
        "lan_ip": "192.168.2.13",
        "port": 22,
        "user": "mmm",
        "password": "PASSWORD_PLACEHOLDER",
        "arch": "aarch64",
        "role": "hub",
        "tags": ["hub", "local", "pi", "arm64"]
    },
    "cubieboard-arm-07": {
        "id": "cubieboard-arm-07",
        "name": "Cubieboard 2 ARMv7",
        "host": "192.168.2.7",
        "lan_ip": "192.168.2.7",
        "port": 22,
        "user": "mmm",
        "password": "PASSWORD_PLACEHOLDER",
        "arch": "armv7l",
        "role": "gateway",
        "tags": ["cubieboard", "serial", "rs485", "armv7"]
    },
    "raspberry-pi-node-08": {
        "id": "raspberry-pi-node-08",
        "name": "Raspberry Pi Node 08",
        "host": "192.168.2.8",
        "lan_ip": "192.168.2.8",
        "port": 22,
        "user": "mmm",
        "password": "PASSWORD_PLACEHOLDER",
        "arch": "aarch64",
        "role": "periferico",
        "tags": ["pi", "reles", "sensores", "arm64"]
    },
    "raspberry-pi-node-13": {
        "id": "raspberry-pi-node-13",
        "name": "Raspberry Pi Node 13",
        "host": "192.168.2.13",
        "lan_ip": "192.168.2.13",
        "port": 22,
        "user": "mmm",
        "password": "PASSWORD_PLACEHOLDER",
        "arch": "aarch64",
        "role": "secundario",
        "tags": ["pi", "secundario", "arm64"]
    }
}


class ClusterSSHController:
    def __init__(self, config_env_path="/etc/casa-ssh-agent.env", audit_log_path="/opt/casa/ssh-agent/audit.jsonl"):
        self.config_env_path = config_env_path
        self.audit_log_path = audit_log_path
        self.nodes = dict(DEFAULT_NODES)
        self.history = []
        self._load_env_config()
        self._ensure_audit_dir()

    def _load_env_config(self):
        env = {}
        if os.path.exists(self.config_env_path):
            try:
                with open(self.config_env_path, "r", encoding="utf-8") as f:
                    for line in f:
                        line = line.strip()
                        if line and not line.startswith("#") and "=" in line:
                            k, v = line.split("=", 1)
                            env[k.strip()] = v.strip().strip("'\"")
            except Exception as e:
                logger.warning("Falha ao ler %s: %s", self.config_env_path, e)

        # Atualiza credenciais padrao ou nos adicionais
        default_pass = env.get("CASA_SSH_DEFAULT_PASSWORD", "PASSWORD_PLACEHOLDER")
        default_user = env.get("CASA_SSH_DEFAULT_USER", "mmm")

        for node_id, info in self.nodes.items():
            if not info.get("password") or info.get("password") == "PASSWORD_PLACEHOLDER":
                info["password"] = default_pass
            if not info.get("user"):
                info["user"] = default_user

        # Nos adicionais via JSON na variavel CASA_SSH_CUSTOM_NODES
        custom_raw = env.get("CASA_SSH_CUSTOM_NODES")
        if custom_raw:
            try:
                custom_list = json.loads(custom_raw)
                for item in custom_list:
                    if isinstance(item, dict) and "id" in item:
                        self.nodes[item["id"]] = item
            except Exception as e:
                logger.warning("Falha ao processar CASA_SSH_CUSTOM_NODES: %s", e)

    def _ensure_audit_dir(self):
        try:
            d = os.path.dirname(self.audit_log_path)
            if d:
                os.makedirs(d, exist_ok=True)
        except Exception:
            pass

    def _log_audit(self, entry):
        self.history.append(entry)
        if len(self.history) > 200:
            self.history = self.history[-200:]
        try:
            with open(self.audit_log_path, "a", encoding="utf-8") as f:
                f.write(json.dumps(entry, ensure_ascii=False) + "\n")
        except Exception:
            pass

    def resolve_node(self, target):
        if not target:
            return None
        target = target.strip()
        # Direct key match
        if target in self.nodes:
            return self.nodes[target]
        # Match by IP
        for node in self.nodes.values():
            if node.get("host") == target or node.get("lan_ip") == target:
                return node
        # Match by name or tag
        target_lower = target.lower()
        for node in self.nodes.values():
            if target_lower in node.get("name", "").lower():
                return node
            if target_lower in [t.lower() for t in node.get("tags", [])]:
                return node
        return None

    def list_nodes(self):
        result = []
        for n in self.nodes.values():
            safe = dict(n)
            safe.pop("password", None)
            safe.pop("key_passphrase", None)
            result.append(safe)
        return result

    def ping_node(self, node_info, timeout=2.0):
        host = node_info.get("host", "127.0.0.1")
        port = int(node_info.get("port", 22))
        t0 = time.time()
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.settimeout(timeout)
            res = s.connect_ex((host, port))
            s.close()
            elapsed_ms = round((time.time() - t0) * 1000, 1)
            return (res == 0), elapsed_ms
        except Exception:
            return False, None

    def execute_command(self, target, command, sudo=False, timeout=30, task_id=None, ai_context=None):
        t_start = time.time()
        iso_time = datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")

        node = self.resolve_node(target)
        if not node:
            err_res = {
                "ok": False,
                "node": target,
                "exit_code": -1,
                "stdout": "",
                "stderr": "No de cluster '{0}' nao encontrado no inventario".format(target),
                "elapsed_sec": 0,
                "timestamp": iso_time,
                "task_id": task_id
            }
            self._log_audit(err_res)
            return err_res

        host = node.get("host", "127.0.0.1")
        port = int(node.get("port", 22))
        user = node.get("user", "mmm")
        password = node.get("password", "")

        # Format command for sudo if requested
        actual_cmd = command
        if sudo:
            actual_cmd = "echo {0} | sudo -S {1}".format(password, command)

        # 1. Try Paramiko if available
        if HAS_PARAMIKO:
            try:
                client = paramiko.SSHClient()
                client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
                client.connect(
                    host,
                    port=port,
                    username=user,
                    password=password,
                    timeout=min(timeout, 10),
                    look_for_keys=True
                )
                stdin, stdout, stderr = client.exec_command(actual_cmd, timeout=timeout)
                out_str = stdout.read().decode("utf-8", errors="replace")
                err_str = stderr.read().decode("utf-8", errors="replace")
                exit_code = stdout.channel.recv_exit_status()
                client.close()

                elapsed = round(time.time() - t_start, 2)
                res = {
                    "ok": (exit_code == 0),
                    "node": node.get("id"),
                    "host": host,
                    "command": command,
                    "sudo": sudo,
                    "exit_code": exit_code,
                    "stdout": out_str.strip(),
                    "stderr": err_str.strip(),
                    "elapsed_sec": elapsed,
                    "timestamp": iso_time,
                    "task_id": task_id,
                    "ai_context": ai_context
                }
                self._log_audit(res)
                return res
            except Exception as e:
                # If paramiko connection fails, fall back to OpenSSH CLI
                logger.debug("Paramiko falhou em %s, tentando OpenSSH: %s", host, e)

        # 2. Fallback: OpenSSH CLI (ssh / sshpass)
        try:
            ssh_cmd = []
            if password:
                ssh_cmd.extend(["sshpass", "-p", password])
            ssh_cmd.extend([
                "ssh",
                "-o", "StrictHostKeyChecking=no",
                "-o", "UserKnownHostsFile=/dev/null",
                "-o", "ConnectTimeout=5",
                "-p", str(port),
                "{0}@{1}".format(user, host),
                actual_cmd
            ])
            proc = subprocess.Popen(
                ssh_cmd,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                universal_newlines=True
            )
            out_str, err_str = proc.communicate(timeout=timeout)
            exit_code = proc.returncode

            elapsed = round(time.time() - t_start, 2)
            res = {
                "ok": (exit_code == 0),
                "node": node.get("id"),
                "host": host,
                "command": command,
                "sudo": sudo,
                "exit_code": exit_code,
                "stdout": (out_str or "").strip(),
                "stderr": (err_str or "").strip(),
                "elapsed_sec": elapsed,
                "timestamp": iso_time,
                "task_id": task_id,
                "ai_context": ai_context
            }
            self._log_audit(res)
            return res
        except subprocess.TimeoutExpired:
            proc.kill()
            res = {
                "ok": False,
                "node": node.get("id"),
                "host": host,
                "command": command,
                "exit_code": -2,
                "stdout": "",
                "stderr": "Comando expirou o tempo limite de {0}s".format(timeout),
                "elapsed_sec": timeout,
                "timestamp": iso_time,
                "task_id": task_id
            }
            self._log_audit(res)
            return res
        except Exception as e:
            res = {
                "ok": False,
                "node": node.get("id"),
                "host": host,
                "command": command,
                "exit_code": -3,
                "stdout": "",
                "stderr": "Falha na conexao SSH: {0}".format(e),
                "elapsed_sec": round(time.time() - t_start, 2),
                "timestamp": iso_time,
                "task_id": task_id
            }
            self._log_audit(res)
            return res

    def execute_batch(self, targets, command, sudo=False, timeout=30, task_id=None, ai_context=None):
        if targets == "all" or targets == ["all"] or not targets:
            node_keys = list(self.nodes.keys())
        elif isinstance(targets, str):
            node_keys = [t.strip() for t in targets.split(",") if t.strip()]
        else:
            node_keys = list(targets)

        results = {}
        with ThreadPoolExecutor(max_workers=min(len(node_keys) or 1, 8)) as executor:
            futures = {
                executor.submit(self.execute_command, k, command, sudo, timeout, task_id, ai_context): k
                for k in node_keys
            }
            for fut in as_completed(futures):
                k = futures[fut]
                try:
                    results[k] = fut.result()
                except Exception as e:
                    results[k] = {"ok": False, "node": k, "error": str(e)}

        return {
            "batch_size": len(node_keys),
            "command": command,
            "results": results,
            "timestamp": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
        }

    def upload_content(self, target, remote_path, content_bytes, make_executable=False):
        node = self.resolve_node(target)
        if not node:
            return False, "No nao encontrado: " + str(target)

        host = node.get("host", "127.0.0.1")
        port = int(node.get("port", 22))
        user = node.get("user", "mmm")
        password = node.get("password", "")

        if HAS_PARAMIKO:
            try:
                client = paramiko.SSHClient()
                client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
                client.connect(host, port=port, username=user, password=password, timeout=10)
                sftp = client.open_sftp()
                with sftp.open(remote_path, "wb") as f:
                    f.write(content_bytes)
                if make_executable:
                    sftp.chmod(remote_path, 0o755)
                sftp.close()
                client.close()
                return True, "Arquivo gravado via SFTP com sucesso"
            except Exception as e:
                logger.debug("SFTP falhou: %s", e)

        # Fallback via SSH stdin pipe
        try:
            ssh_cmd = []
            if password:
                ssh_cmd.extend(["sshpass", "-p", password])
            ssh_cmd.extend([
                "ssh",
                "-o", "StrictHostKeyChecking=no",
                "-o", "UserKnownHostsFile=/dev/null",
                "-p", str(port),
                "{0}@{1}".format(user, host),
                "cat > '{0}' && chmod {1} '{0}'".format(remote_path, "755" if make_executable else "644")
            ])
            p = subprocess.Popen(ssh_cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            out, err = p.communicate(input=content_bytes, timeout=15)
            if p.returncode == 0:
                return True, "Arquivo gravado com sucesso"
            return False, err or out
        except Exception as e:
            return False, str(e)
