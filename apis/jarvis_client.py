#!/usr/bin/env python3
"""
Cliente SDK Python Seguro para JARVIS Residencial
Permite interagir com o JARVIS via túnel criptografado Cloudflare de qualquer lugar do mundo.
"""

import os
import sys
import json
import requests

class JarvisClient:
    def __init__(self, base_url: str = None, api_key: str = None):
        self.base_url = (base_url or os.getenv("JARVIS_URL", "")).rstrip("/")
        self.api_key = api_key or os.getenv("JARVIS_API_KEY", "")
        
        if not self.base_url:
            raise ValueError("JARVIS_URL não definida. Passe no construtor ou exporte JARVIS_URL.")
        if not self.api_key:
            raise ValueError("JARVIS_API_KEY não definida. Passe no construtor ou exporte JARVIS_API_KEY.")

        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
            "User-Agent": "JARVIS-PythonClient/1.0"
        })

    def get_status(self) -> dict:
        """Obtém status geral da residência e nós do cluster."""
        r = self.session.get(f"{self.base_url}/api/v1/status", timeout=15)
        r.raise_for_status()
        return r.json()

    def enviar_comando(self, comando: str, ia_mode: str = "auto") -> dict:
        """Envia comando em linguagem natural para a IA do JARVIS."""
        payload = {"comando": comando, "ia_mode": ia_mode}
        r = self.session.post(f"{self.base_url}/api/v1/comando", json=payload, timeout=35)
        r.raise_for_status()
        return r.json()

    def listar_dispositivos(self) -> dict:
        """Lista todos os dispositivos e relés da residência."""
        r = self.session.get(f"{self.base_url}/api/v1/dispositivos", timeout=15)
        r.raise_for_status()
        return r.json()

    def acionar_dispositivo(self, iddevice: int, parametro: str = "dev1", valor: str = "1") -> dict:
        """Aciona um relé ou dispositivo específico."""
        payload = {"iddevice": iddevice, "parametro": parametro, "valor": str(valor)}
        r = self.session.post(f"{self.base_url}/api/v1/dispositivos/acionar", json=payload, timeout=15)
        r.raise_for_status()
        return r.json()

    def obter_sensores(self, limite: int = 50) -> dict:
        """Obtém últimas leituras de telemetria dos sensores."""
        r = self.session.get(f"{self.base_url}/api/v1/sensores?limite={limite}", timeout=15)
        r.raise_for_status()
        return r.json()

    def obter_clima(self) -> dict:
        """Obtém dados meteorológicos e previsão para automação."""
        r = self.session.get(f"{self.base_url}/api/v1/clima", timeout=15)
        r.raise_for_status()
        return r.json()

if __name__ == "__main__":
    print("=== JARVIS Client Teste Rápido ===")
    url = sys.argv[1] if len(sys.argv) > 1 else os.getenv("JARVIS_URL", "")
    key = sys.argv[2] if len(sys.argv) > 2 else os.getenv("JARVIS_API_KEY", "")

    if not url or not key:
        print("Uso: python jarvis_client.py <URL_TUNEL_HTTPS> <MASTER_API_KEY>")
        print("Exemplo: python jarvis_client.py https://sua-url.trycloudflare.com jarvis_sec_v1_...")
        sys.exit(1)

    client = JarvisClient(url, key)
    print("\n1. Consultando Status Geral:")
    print(json.dumps(client.get_status(), indent=2, ensure_ascii=False))

    print("\n2. Enviando Comando para o JARVIS:")
    res = client.enviar_comando("JARVIS, informe o estado da iluminação")
    print(f"Resposta: {res.get('resposta')}")
    print(f"Provedor: {res.get('provedor_ia')}")
