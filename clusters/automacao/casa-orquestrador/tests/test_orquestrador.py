#!/usr/bin/env python3
"""Testes unitarios para o Daemon Orquestrador Residencial com IA (casa-orquestrador)."""

import os
import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE_DIR))

import importlib.util

spec = importlib.util.spec_from_file_location("casa_orquestrador", BASE_DIR / "casa-orquestrador.py")
casa_orquestrador = importlib.util.module_from_spec(spec)
spec.loader.exec_module(casa_orquestrador)


class TestCasaOrquestrador(unittest.TestCase):
    def setUp(self):
        self.cfg = casa_orquestrador.Config()
        self.cfg.base_url = "http://127.0.0.1:9999"
        self.cfg.timestral_url = "http://127.0.0.1:9998"
        self.orchestrator = casa_orquestrador.HouseOrchestrator(self.cfg)

    def test_configuracao_inicial(self):
        self.assertEqual(self.cfg.port, 8090)
        self.assertIn("total_comandos_executados", self.orchestrator.stats)
        self.assertIn("total_perguntas_respondidas", self.orchestrator.stats)
        self.assertIn("total_previsoes_realizadas", self.orchestrator.stats)

    def test_controlar_dispositivo_sem_parametros(self):
        res = self.orchestrator.controlar_dispositivo()
        self.assertFalse(res.get("ok"))
        self.assertIn("erro", res)

    def test_responder_pergunta_vazia(self):
        res = self.orchestrator.responder_pergunta("")
        self.assertFalse(res.get("ok"))
        self.assertIn("erro", res)

    def test_responder_pergunta_sensores_mock(self):
        # Simula resposta de sensores
        def mock_api(endpoint, metodo="GET", dados=None, timeout=15):
            if "/sensores" in endpoint:
                return 200, {
                    "status": "sucesso",
                    "sensores": [
                        {"tipo_sensor": "dht22_sala", "temperatura_c": 23.5, "umidade_pct": 55.0}
                    ]
                }
            return 404, {}

        self.orchestrator._fazer_requisicao_api = mock_api
        res = self.orchestrator.responder_pergunta("Como está a temperatura na casa?")
        self.assertTrue(res.get("ok"))
        self.assertEqual(res.get("origem"), "sensores_locais")
        self.assertIn("23.5°C", res.get("resposta", ""))

    def test_agendamento_evento_mock(self):
        def mock_api(endpoint, metodo="GET", dados=None, timeout=15):
            if "fila.php" in endpoint:
                return 201, {"status": "ok", "id": 123}
            return 404, {}

        self.orchestrator._fazer_requisicao_api = mock_api
        res = self.orchestrator.agendar_evento(
            titulo="Ligar Irrigação",
            tipo_acao="comando_jarvis",
            payload="Ligar relé irrigação",
            cron_expr="0 6 * * *"
        )
        self.assertTrue(res.get("ok"))
        self.assertEqual(self.orchestrator.stats["total_agendamentos_criados"], 1)


if __name__ == "__main__":
    unittest.main()
