#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CASA / JARVIS - Cluster SSH Client & CLI
Interface em Python e linha de comando para uso pela IA e operadores.
"""

import os
import sys
import json
import argparse
import urllib.request
import urllib.parse

DEFAULT_AGENT_URL = os.environ.get("CASA_SSH_AGENT_URL", "http://127.0.0.1:8095")

class ClusterSSHClient:
    """SDK Python para a IA disparar manutencao via SSH no cluster."""
    def __init__(self, base_url=DEFAULT_AGENT_URL):
        self.base_url = base_url.rstrip("/")

    def _request(self, endpoint, method="GET", payload=None):
        url = self.base_url + endpoint
        data = json.dumps(payload).encode("utf-8") if payload else None
        headers = {"Content-Type": "application/json"}
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=45) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except Exception as e:
            return {"ok": False, "error": str(e)}

    def status(self):
        return self._request("/health")

    def list_nodes(self):
        return self._request("/api/ssh/nodes")

    def exec(self, node, command, sudo=False, timeout=30, task_id=None, ai_context=None):
        payload = {
            "node": node,
            "command": command,
            "sudo": sudo,
            "timeout": timeout,
            "task_id": task_id,
            "ai_context": ai_context
        }
        return self._request("/api/ssh/exec", method="POST", payload=payload)

    def batch(self, nodes, command, sudo=False, timeout=30, task_id=None):
        payload = {
            "nodes": nodes,
            "command": command,
            "sudo": sudo,
            "timeout": timeout,
            "task_id": task_id
        }
        return self._request("/api/ssh/batch", method="POST", payload=payload)

    def upload(self, node, remote_path, content, executable=False):
        payload = {
            "node": node,
            "remote_path": remote_path,
            "content": content,
            "executable": executable
        }
        return self._request("/api/ssh/upload", method="POST", payload=payload)

    def history(self, limit=50):
        return self._request("/api/ssh/history?limit=" + str(limit))


def main():
    parser = argparse.ArgumentParser(description="CASA Cluster SSH Maintenance CLI")
    subparsers = parser.add_subparsers(dest="action", help="Acao a executar")

    # Nodes
    subparsers.add_parser("nodes", help="Lista nos e latencia")
    subparsers.add_parser("status", help="Status do agente")
    subparsers.add_parser("history", help="Historico de tarefas executadas pela IA")

    # Exec
    p_exec = subparsers.add_parser("exec", help="Executa comando em um no")
    p_exec.add_argument("--node", "-n", required=True, help="Nome, ID ou IP do no")
    p_exec.add_argument("--cmd", "-c", "--command", required=True, dest="command", help="Comando shell")
    p_exec.add_argument("--sudo", "-s", action="store_true", help="Executa com elevacao sudo")
    p_exec.add_argument("--timeout", "-t", type=int, default=30, help="Tempo limite em segundos")
    p_exec.add_argument("--task-id", default=None, help="ID da tarefa da IA")

    # Batch
    p_batch = subparsers.add_parser("batch", help="Executa comando em multiplos nos")
    p_batch.add_argument("--nodes", default="all", help="Lista de nos separados por virgula ou 'all'")
    p_batch.add_argument("--cmd", "-c", "--command", required=True, dest="command", help="Comando shell")
    p_batch.add_argument("--sudo", "-s", action="store_true", help="Executa com elevacao sudo")

    args = parser.parse_args()
    client = ClusterSSHClient()

    if args.action == "status":
        print(json.dumps(client.status(), indent=2))
    elif args.action == "nodes":
        print(json.dumps(client.list_nodes(), indent=2))
    elif args.action == "history":
        print(json.dumps(client.history(), indent=2))
    elif args.action == "exec":
        res = client.exec(
            node=args.node,
            command=args.command,
            sudo=args.sudo,
            timeout=args.timeout,
            task_id=args.task_id
        )
        print(json.dumps(res, indent=2))
    elif args.action == "batch":
        res = client.batch(
            nodes=args.nodes,
            command=args.command,
            sudo=args.sudo
        )
        print(json.dumps(res, indent=2))
    else:
        parser.print_help()

if __name__ == "__main__":
    main()
