#!/usr/bin/env python3
"""
JARVIS Casa Inteligente - Agente RunPod & SSH Remoto
Serviço de gerenciamento de pods GPU no RunPod e execução de comandos SSH na máquina remota.
"""

import os
import sys
import json
import urllib.request
import urllib.parse
from http.server import HTTPServer, BaseHTTPRequestHandler
from pathlib import Path

# Tenta importar paramiko para SSH; fallback se não instalado
try:
    import paramiko
    HAS_PARAMIKO = True
except ImportError:
    HAS_PARAMIKO = False

# Carregar variáveis de ambiente do arquivo .env
ENV_PATH = Path(__file__).parent / ".env"

def load_env():
    env_vars = {}
    if ENV_PATH.exists():
        with open(ENV_PATH, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    env_vars[k.strip()] = v.strip()
    return env_vars

ENV = load_env()
RUNPOD_API_KEY = os.getenv("RUNPOD_API_KEY", ENV.get("RUNPOD_API_KEY", ""))
SSH_HOST = os.getenv("SSH_HOST", ENV.get("SSH_HOST", "192.168.2.12"))
SSH_PORT = int(os.getenv("SSH_PORT", ENV.get("SSH_PORT", "22")))
SSH_USER = os.getenv("SSH_USER", ENV.get("SSH_USER", "mmm"))
SSH_PASS = os.getenv("SSH_PASS", ENV.get("SSH_PASS", "226468"))
AGENT_PORT = int(os.getenv("AGENT_PORT", ENV.get("AGENT_PORT", "5005")))
AGENT_HOST = os.getenv("AGENT_HOST", ENV.get("AGENT_HOST", "0.0.0.0"))


class RunPodManager:
    """Gerenciador de requisições para a API GraphQL / REST do RunPod."""

    RUNPOD_GRAPHQL_URL = "https://api.runpod.io/graphql"

    @classmethod
    def query_runpod(cls, query: str, variables: dict = None):
        if not RUNPOD_API_KEY:
            return {"error": "RUNPOD_API_KEY não configurada no arquivo .env"}

        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {RUNPOD_API_KEY}"
        }
        
        payload = json.dumps({"query": query, "variables": variables or {}}).encode("utf-8")
        req = urllib.request.Request(
            f"{cls.RUNPOD_GRAPHQL_URL}?api_key={RUNPOD_API_KEY}",
            data=payload,
            headers=headers,
            method="POST"
        )

        try:
            with urllib.request.urlopen(req, timeout=15) as response:
                res_data = response.read().decode("utf-8")
                return json.loads(res_data)
        except Exception as e:
            return {"error": str(e)}

    @classmethod
    def get_myself(cls):
        query = """
        query Myself {
            myself {
                id
                email
                pubKey
            }
        }
        """
        return cls.query_runpod(query)

    @classmethod
    def get_pods(cls):
        query = """
        query Pods {
            myself {
                pods {
                    id
                    name
                    runtime {
                        uptimeInSeconds
                    }
                    desiredStatus
                }
            }
        }
        """
        return cls.query_runpod(query)


class SSHManager:
    """Gerenciador de conexões SSH com o servidor local/remoto."""

    @classmethod
    def execute_command(cls, command: str):
        if not HAS_PARAMIKO:
            return {
                "success": False,
                "error": "Biblioteca 'paramiko' não está instalada. Execute: pip install paramiko"
            }

        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        try:
            client.connect(
                hostname=SSH_HOST,
                port=SSH_PORT,
                username=SSH_USER,
                password=SSH_PASS,
                timeout=10
            )
            stdin, stdout, stderr = client.exec_command(command)
            out_str = stdout.read().decode("utf-8")
            err_str = stderr.read().decode("utf-8")
            client.close()
            return {
                "success": True,
                "output": out_str,
                "error": err_str
            }
        except Exception as e:
            return {
                "success": False,
                "error": str(e)
            }


class RunPodAgentHTTPHandler(BaseHTTPRequestHandler):
    """Handler HTTP para expor endpoints REST do Agente RunPod/SSH."""

    def _send_json(self, data, status=200):
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps(data, indent=2, ensure_ascii=False).encode("utf-8"))

    def do_GET(self):
        if self.path == "/api/status" or self.path == "/":
            status_data = {
                "service": "casa-runpod-agent",
                "status": "online",
                "runpod_configured": bool(RUNPOD_API_KEY),
                "ssh_target": f"{SSH_USER}@{SSH_HOST}:{SSH_PORT}",
                "paramiko_installed": HAS_PARAMIKO
            }
            self._send_json(status_data)
        elif self.path == "/api/runpod/account":
            res = RunPodManager.get_myself()
            self._send_json(res)
        elif self.path == "/api/runpod/pods":
            res = RunPodManager.get_pods()
            self._send_json(res)
        else:
            self._send_json({"error": "Endpoint não encontrado"}, 404)

    def do_POST(self):
        content_length = int(self.headers.get("Content-Length", 0))
        body_bytes = self.rfile.read(content_length) if content_length > 0 else b"{}"
        try:
            body = json.loads(body_bytes.decode("utf-8"))
        except Exception:
            body = {}

        if self.path == "/api/ssh/execute":
            comando = body.get("command", "")
            if not comando:
                self._send_json({"error": "Parâmetro 'command' é obrigatório"}, 400)
                return
            res = SSHManager.execute_command(comando)
            self._send_json(res)
        else:
            self._send_json({"error": "Endpoint não encontrado"}, 404)


def run():
    server_address = (AGENT_HOST, AGENT_PORT)
    httpd = HTTPServer(server_address, RunPodAgentHTTPHandler)
    print(f"[*] Agente RunPod & SSH rodando em http://{AGENT_HOST}:{AGENT_PORT}")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\n[*] Encerrando agente...")
        httpd.server_close()

if __name__ == "__main__":
    run()
