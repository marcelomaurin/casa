#!/usr/bin/env python3
"""
Cliente Python para interação com o Agente RunPod & SSH da Casa Inteligente.
"""

import json
import urllib.request
import urllib.parse

class RunPodClient:
    """Cliente de API para gerenciar RunPod e SSH através do casa-runpod-agent."""

    def __init__(self, host="127.0.0.1", port=5005):
        self.base_url = f"http://{host}:{port}"

    def _request(self, endpoint, method="GET", payload=None):
        url = f"{self.base_url}{endpoint}"
        headers = {"Content-Type": "application/json"}
        data = json.dumps(payload).encode("utf-8") if payload else None

        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=10) as response:
                return json.loads(response.read().decode("utf-8"))
        except Exception as e:
            return {"error": f"Falha na comunicação com o agente: {str(e)}"}

    def get_status(self):
        """Retorna o status geral do serviço agente."""
        return self._request("/api/status")

    def get_account_info(self):
        """Retorna informações da conta RunPod associada."""
        return self._request("/api/runpod/account")

    def list_pods(self):
        """Lista as instâncias/pods de GPU ativos no RunPod."""
        return self._request("/api/runpod/pods")

    def execute_ssh(self, command: str):
        """Executa um comando remoto via SSH na máquina configurada."""
        return self._request("/api/ssh/execute", method="POST", payload={"command": command})


if __name__ == "__main__":
    # Teste de conexão local do cliente
    client = RunPodClient()
    print("Testando cliente local...")
    print("Status:", client.get_status())
