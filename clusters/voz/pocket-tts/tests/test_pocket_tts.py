#!/usr/bin/env python3
"""Testes unitarios para o daemon Pocket-TTS."""

import importlib.util
import os
import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
DAEMON_DIR = HERE.parent
sys.path.insert(0, str(DAEMON_DIR))

spec = importlib.util.spec_from_file_location("casa_pocket_tts", DAEMON_DIR / "casa-pocket-tts.py")
pocket = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pocket)


class TestPocketTTS(unittest.TestCase):
    def setUp(self):
        self.tmp_dir = HERE / "tmp_test_pocket"
        self.tmp_dir.mkdir(parents=True, exist_ok=True)
        self.cfg = pocket.Config()
        self.cfg.audio_dir = str(self.tmp_dir)
        self.engine = pocket.PocketTTSEngine(self.cfg)

    def tearDown(self):
        for f in self.tmp_dir.glob("*.wav"):
            try:
                f.unlink()
            except OSError:
                pass
        try:
            self.tmp_dir.rmdir()
        except OSError:
            pass

    def test_sintese_pocket(self):
        res = self.engine.sintetizar("Teste de sintese com o Pocket-TTS.", speaker="jarvis")
        self.assertTrue(res["filename"].endswith(".wav"))
        self.assertTrue(os.path.isfile(res["filepath"]))
        self.assertGreater(res["duration_sec"], 0.2)
        self.assertEqual(res["engine"], "pocket-tts")
        self.assertEqual(self.engine.stats["total_synthesized"], 1)

    def test_texto_vazio(self):
        with self.assertRaises(ValueError):
            self.engine.sintetizar("")

    def test_vozes(self):
        self.assertTrue(len(self.engine.vozes) >= 2)


if __name__ == "__main__":
    unittest.main()
