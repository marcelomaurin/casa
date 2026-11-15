#!/usr/bin/env python3
"""Testes unitarios para o daemon Timestral-5M."""

import importlib.util
import json
import os
import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
DAEMON_DIR = HERE.parent
sys.path.insert(0, str(DAEMON_DIR))

spec = importlib.util.spec_from_file_location("casa_timestral", DAEMON_DIR / "casa-timestral.py")
timestral = importlib.util.module_from_spec(spec)
spec.loader.exec_module(timestral)


class TestTimestralModel(unittest.TestCase):
    def setUp(self):
        self.cfg = timestral.Config()
        self.model = timestral.TimestralModel(self.cfg)

    def test_previsao_tendencia_alta(self):
        serie = [20.0, 20.5, 21.0, 21.5, 22.0, 22.5, 23.0]
        res = self.model.prever(serie, horizonte=5, metrica="temperatura")
        self.assertTrue(res["ok"])
        self.assertEqual(len(res["previsoes"]), 5)
        self.assertEqual(res["tendencia"], "alta")
        self.assertGreater(res["previsoes"][-1], serie[-1])

    def test_deteccao_anomalia(self):
        # Serie estavel com pico anomalo no meio
        serie = [22.0, 22.1, 22.0, 21.9, 35.0, 22.0, 22.1, 21.9]
        res = self.model.detectar_anomalias(serie, limiar=2.0)
        self.assertTrue(res["ok"])
        self.assertGreaterEqual(res["total_anomalias"], 1)
        indices = [a["indice"] for a in res["anomalias"]]
        self.assertIn(4, indices)  # Ponto 35.0

    def test_decomposicao(self):
        serie = [10.0, 12.0, 14.0, 11.0, 10.0, 12.0, 14.0, 11.0]
        res = self.model.decompor(serie, periodo=4)
        self.assertTrue(res["ok"])
        self.assertEqual(len(res["tendencia"]), len(serie))
        self.assertEqual(len(res["sazonalidade"]), len(serie))

    def test_validacao_seguranca(self):
        with self.assertRaises(ValueError):
            self.model.prever([float("nan"), 10.0])
        with self.assertRaises(ValueError):
            self.model.prever([1.0])  # Tamanho insuficiente


if __name__ == "__main__":
    unittest.main()
