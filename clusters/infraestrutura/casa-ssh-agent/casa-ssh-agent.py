#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CASA / JARVIS - Servico Agente SSH de Manutencao dos Clusters
Executa como daemon na porta 8095 (HTTP REST).
Permite que modulos de IA (JARVIS, Claude, OpenAI, Agentes Autonomos) executem:
- Comandos de diagnostico e reparo nos clusters
- Execucao paralela em todos os nos
- Reinicializacao e manutencao de servicos
- Upload de scripts e verificacao de telemetria
"""

import os
import sys
import json
import time
import socket
import logging
import urllib.parse
from http.server import HTTPServer, BaseHTTPRequestHandler
from datetime import datetime

# Importa o controlador do cluster
try:
    from ssh_cluster_controller import ClusterSSHController
except ImportError:
    from clusters.infraestrutura.casa_ssh_agent.ssh_cluster_controller import ClusterSSHController

PORT = int(os.environ.get("SSH_AGENT_PORT", 8095))
HOST = os.environ.get("SSH_AGENT_HOST", "0.0.0.0")

logging.basicConfig(
    level=logging.INFO,
    format="[%(asctime)s] [%(levelname)s] [SSH-AGENT] %(message)s"
)
logger = logging.getLogger("casa-ssh-agent")

controller = ClusterSSHController()

class SSHAgentRequestHandler(BaseHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Device-Id, X-Device-Token")
        super().end_headers()

    def do_OPTIONS(self):
        self.send_response(200)
        self.end_headers()

    def send_json(self, data, code=200):
        body = json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def read_json_body(self):
        try:
            length = int(self.headers.get("Content-Length", 0))
            if length <= 0:
                return {}
            raw = self.rfile.read(length).decode("utf-8")
            return json.loads(raw) if raw else {}
        except Exception as e:
            logger.error("Erro ao decodificar JSON: %s", e)
            return {}

    def do_GET(self):
        url = urllib.parse.urlparse(self.path)
        path = url.path.rstrip("/") or "/"

        if path in ["/", "/health", "/api/ssh/status"]:
            self.send_json({
                "status": "online",
                "service": "casa-ssh-agent",
                "version": "1.0.0",
                "protocol": "CASA/SSH-v1",
                "port": PORT,
                "nodes_count": len(controller.nodes),
                "timestamp": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
            })

        elif path == "/api/ssh/nodes":
            nodes_data = controller.list_nodes()
            # Adiciona ping ao vivo
            for n in nodes_data:
                online, lat = controller.ping_node(n, timeout=1.0)
                n["online"] = online
                n["latency_ms"] = lat
            self.send_json({
                "ok": True,
                "nodes": nodes_data,
                "total": len(nodes_data),
                "timestamp": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
            })

        elif path == "/api/ssh/history":
            query = urllib.parse.parse_qs(url.query)
            limit = int(query.get("limit", [50])[0])
            self.send_json({
                "ok": True,
                "total": len(controller.history),
                "history": controller.history[-limit:]
            })

        else:
            self.send_json({"ok": False, "error": "Endpoint nao encontrado: " + path}, code=404)

    def do_POST(self):
        url = urllib.parse.urlparse(self.path)
        path = url.path.rstrip("/")

        data = self.read_json_body()

        # 1. Execucao em um unico no
        if path == "/api/ssh/exec":
            node = data.get("node") or data.get("target") or data.get("host")
            command = data.get("command") or data.get("cmd")
            sudo = bool(data.get("sudo", False))
            timeout = int(data.get("timeout", 30))
            task_id = data.get("task_id")
            ai_context = data.get("ai_context") or data.get("description")

            if not node or not command:
                self.send_json({"ok": False, "error": "Parametros obrigatorios ausentes: 'node' e 'command'"}, code=400)
                return

            logger.info("Executando no no '%s': %s (sudo=%s)", node, command, sudo)
            res = controller.execute_command(
                target=node,
                command=command,
                sudo=sudo,
                timeout=timeout,
                task_id=task_id,
                ai_context=ai_context
            )
            code = 200 if res.get("ok") else 500
            self.send_json(res, code=200)

        # 2. Execucao em lote / paralela
        elif path == "/api/ssh/batch":
            targets = data.get("nodes") or data.get("targets") or "all"
            command = data.get("command") or data.get("cmd")
            sudo = bool(data.get("sudo", False))
            timeout = int(data.get("timeout", 30))
            task_id = data.get("task_id")

            if not command:
                self.send_json({"ok": False, "error": "Parametro obrigatorio ausente: 'command'"}, code=400)
                return

            logger.info("Executando em lote nos nos %s: %s (sudo=%s)", targets, command, sudo)
            res = controller.execute_batch(
                targets=targets,
                command=command,
                sudo=sudo,
                timeout=timeout,
                task_id=task_id
            )
            self.send_json(res)

        # 3. Upload de script ou arquivo
        elif path == "/api/ssh/upload":
            node = data.get("node") or data.get("target")
            remote_path = data.get("remote_path")
            content = data.get("content", "")
            make_executable = bool(data.get("executable", False))

            if not node or not remote_path or not content:
                self.send_json({"ok": False, "error": "Parametros obrigatorios ausentes: 'node', 'remote_path', 'content'"}, code=400)
                return

            ok, msg = controller.upload_content(node, remote_path, content.encode("utf-8"), make_executable=make_executable)
            self.send_json({"ok": ok, "message": msg, "node": node, "remote_path": remote_path})

        else:
            self.send_json({"ok": False, "error": "Endpoint nao encontrado: " + path}, code=404)

def main():
    server_address = (HOST, PORT)
    httpd = HTTPServer(server_address, SSHAgentRequestHandler)
    print("==================================================================")
    print(" CASA / JARVIS - Cluster SSH Maintenance Agent")
    print(" Listening on http://{0}:{1}/".format(HOST, PORT))
    print(" Gerenciando {0} nos de cluster".format(len(controller.nodes)))
    print("==================================================================")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nEncerrando casa-ssh-agent...")
        httpd.server_close()

if __name__ == "__main__":
    main()
