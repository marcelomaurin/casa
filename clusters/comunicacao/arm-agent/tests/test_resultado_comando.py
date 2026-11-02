#!/usr/bin/env python3
"""Agente ARM: resultado {"ok": false} de um adaptador é relatado como falha (não sucesso)."""
import importlib.util
import os
import unittest
from pathlib import Path

os.environ.setdefault("JARVIS_DEVICE_TOKEN", "token-de-teste")
spec = importlib.util.spec_from_file_location("agente", Path(__file__).resolve().parents[1] / "casa-node-agent.py")
agente = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agente)


class TesteResultado(unittest.TestCase):
    def rodar(self, resultado_ou_erro):
        relatos = []
        agente.api_request = lambda *a, **k: {"commands": [{"id": 7, "comando": "relay.set", "payload": {"relay": 3, "state": 1}}]}
        agente.ack_command = lambda cid: None
        agente.report_result = lambda cid, ok, result=None, error=None: relatos.append((cid, ok, result, error))

        def adaptador(cmd, payload):
            if isinstance(resultado_ou_erro, Exception):
                raise resultado_ou_erro
            return resultado_ou_erro
        agente.execute_adapter = adaptador
        agente.poll_commands_once()
        self.assertEqual(len(relatos), 1)
        return relatos[0]

    def test_ok_false_e_falha(self):
        cid, ok, result, erro = self.rodar({"pin": 3, "value": 1, "ok": False, "error": "Interface GPIO nao acessivel neste no"})
        self.assertEqual((cid, ok), (7, False))
        self.assertIn("GPIO", erro)

    def test_sucesso_continua_sucesso(self):
        self.assertTrue(self.rodar({"pin": 3, "value": 1, "ok": True})[1])
        self.assertTrue(self.rodar({"status": "online"})[1])

    def test_excecao_e_falha(self):
        self.assertFalse(self.rodar(RuntimeError("serial ausente"))[1])


if __name__ == "__main__":
    unittest.main(verbosity=2)
