#!/usr/bin/env python3
"""Testes unitarios para o Daemon Ponte Bluetooth com Google Home (casa-google-home-bt)."""

import importlib.util
import json
import os
import sys
import unittest
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE_DIR))

spec = importlib.util.spec_from_file_location("casa_google_home_bt", BASE_DIR / "casa-google-home-bt.py")
casa_google_home_bt = importlib.util.module_from_spec(spec)
spec.loader.exec_module(casa_google_home_bt)


class TestGoogleHomeBT(unittest.TestCase):
    def setUp(self):
        self.cfg = casa_google_home_bt.Config()
        self.bridge = casa_google_home_bt.BluetoothGoogleHomeBridge(self.cfg)

    def test_configuracao_inicial(self):
        self.assertEqual(self.cfg.port, 8094)
        self.assertIn("status_conexao", self.bridge.stats)
        self.assertIn("total_dialogos", self.bridge.stats)
        self.assertFalse(self.bridge.conectado)

    def test_conectar_mac_invalido(self):
        res = self.bridge.conectar("MAC_ERRADO_123")
        self.assertFalse(res.get("ok"))
        self.assertIn("inválido", res.get("erro", ""))

    def test_falar_com_google_vazio(self):
        res = self.bridge.falar_com_google("")
        self.assertFalse(res.get("ok"))
        self.assertIn("vazio", res.get("erro", ""))

    def test_falar_com_google_mock(self):
        # Simula resposta do Pocket-TTS
        chamadas_exec = []
        def mock_exec(cmd_args, timeout=10):
            chamadas_exec.append(cmd_args)
            return 0, "OK", ""

        def mock_pocket(url, payload=None):
            return {"ok": True, "audio_base64": "UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA="}

        self.bridge._exec_cmd = mock_exec

        import urllib.request
        orig_urlopen = urllib.request.urlopen

        class MockResp:
            def read(self):
                return json.dumps(mock_pocket("")).encode("utf-8")
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass

        urllib.request.urlopen = lambda req, timeout=15: MockResp()
        try:
            res = self.bridge.falar_com_google("que horas são?")
            self.assertTrue(res.get("ok"))
            self.assertEqual(res.get("texto_enviado"), "Ok Google, que horas são?")
            self.assertEqual(self.bridge.stats["total_falas_enviadas"], 1)
        finally:
            urllib.request.urlopen = orig_urlopen


if __name__ == "__main__":
    unittest.main()

