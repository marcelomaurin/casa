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

# Sem IPs chumbados no codigo: carregado dinamicamente do registro da central
DEFAULT_NODES = {}


class ClusterSSHController:
    def __init__(self, config_env_path="/etc/casa-ssh-agent.env", audit_log_path="/opt/casa/ssh-agent/audit.jsonl"):
        self.config_env_path = config_env_path
        self.audit_log_path = audit_log_path
        self.env_map = {}
        self.nodes = {}
        self.history = []
        self._load_env_config()
        self.refresh_nodes_from_registry()
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
        self.env_map = env
        self._apply_custom_and_passwords()

    def refresh_nodes_from_registry(self, timeout=4.0):
        """Busca dinamicamente os nós registrados no banco/API da central, sem IPs chumbados."""
        cache_file = "/opt/casa/ssh-agent/cached_cluster_nodes.json"
        base_url = self.env_map.get("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
        token = self.env_map.get("JARVIS_DEVICE_TOKEN", self.env_map.get("CASA_DEVICE_TOKEN", "casa_sec_a4c5a1a2fea405d668edf5934f67d9eb9df23d4c89c5c616"))
        default_user = self.env_map.get("CASA_SSH_DEFAULT_USER", "mmm")
        default_pass = self.env_map.get("CASA_SSH_DEFAULT_PASSWORD", "PASSWORD_PLACEHOLDER")

        fetched_nodes = {}
        # 1. Busca da API arm_nodes.php da central
        try:
            import urllib.request
            url = base_url + "/api/arm_nodes.php"
            req = urllib.request.Request(url, headers={
                "User-Agent": "CASA-SSH-Agent/1.0",
                "Authorization": "Bearer " + token,
                "X-Device-Token": token
            })
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                for n in data.get("nodes", []):
                    node_id = n.get("device_id") or n.get("id")
                    ip = n.get("ip_address") or n.get("local_ip")
                    if node_id and ip:
                        caps = n.get("capabilities")
                        if isinstance(caps, str):
                            try:
                                caps = json.loads(caps)
                            except Exception:
                                caps = [c.strip() for c in caps.split(",") if c.strip()]
                        elif not isinstance(caps, list):
                            caps = []

                        fetched_nodes[node_id] = {
                            "id": node_id,
                            "name": n.get("nome") or n.get("papel") or n.get("hostname") or node_id,
                            "host": ip,
                            "lan_ip": ip,
                            "port": int(n.get("porta") or 22),
                            "user": default_user,
                            "password": default_pass,
                            "arch": n.get("arch", "aarch64"),
                            "role": n.get("papel", "no"),
                            "tags": caps or ["cluster", "arm"]
                        }
        except Exception as e:
            logger.debug("Nao foi possivel obter nos da API central: %s", e)

        # 2. Se obteve com sucesso, atualiza dicionario e salva cache local
        if fetched_nodes:
            self.nodes = fetched_nodes
            self._apply_custom_and_passwords()
            try:
                os.makedirs(os.path.dirname(cache_file), exist_ok=True)
                with open(cache_file, "w", encoding="utf-8") as cf:
                    json.dump(self.nodes, cf, ensure_ascii=False, indent=2)
                logger.info("Inventario de nos atualizado dinamicamente via registro (%d nos)", len(self.nodes))
            except Exception as e:
                logger.warning("Falha ao salvar cache de nos: %s", e)
            return True

        # 3. Fallback: carregar de cache local se API central inacessivel
        if os.path.exists(cache_file):
            try:
                with open(cache_file, "r", encoding="utf-8") as cf:
                    cached = json.load(cf)
                    if isinstance(cached, dict) and cached:
                        self.nodes = cached
                        self._apply_custom_and_passwords()
                        logger.info("Inventario de nos carregado do cache local (%d nos)", len(self.nodes))
                        return True
            except Exception as e:
                logger.warning("Falha ao ler cache local de nos: %s", e)

        return False

    def _apply_custom_and_passwords(self):
        default_pass = self.env_map.get("CASA_SSH_DEFAULT_PASSWORD", "PASSWORD_PLACEHOLDER")
        default_user = self.env_map.get("CASA_SSH_DEFAULT_USER", "mmm")
        for node_id, info in self.nodes.items():
            if not info.get("password") or info.get("password") == "PASSWORD_PLACEHOLDER":
                info["password"] = default_pass
            if not info.get("user"):
                info["user"] = default_user

        custom_raw = self.env_map.get("CASA_SSH_CUSTOM_NODES")
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
