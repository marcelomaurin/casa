#!/usr/bin/env python3
"""Testes unitarios para o daemon Parakeet STT."""

import importlib.util
import os
import struct
import sys
import unittest
import wave
from pathlib import Path

HERE = Path(__file__).resolve().parent
DAEMON_DIR = HERE.parent
sys.path.insert(0, str(DAEMON_DIR))

spec = importlib.util.spec_from_file_location("casa_parakeet_stt", DAEMON_DIR / "casa-parakeet-stt.py")
parakeet = importlib.util.module_from_spec(spec)
spec.loader.exec_module(parakeet)


class TestParakeetSTT(unittest.TestCase):
    def setUp(self):
        self.tmp_dir = HERE / "tmp_test_stt"
        self.tmp_dir.mkdir(parents=True, exist_ok=True)
        self.cfg = parakeet.Config()
        self.cfg.temp_dir = str(self.tmp_dir)
        self.engine = parakeet.ParakeetSTTEngine(self.cfg)

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

    def _criar_wav_teste(self):
        wav_path = str(self.tmp_dir / "sample.wav")
        with wave.open(wav_path, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(16000)
            samples = [0] * 16000  # 1 segundo de silencio
            raw = struct.pack(f"<{len(samples)}h", *samples)
            w.writeframes(raw)
        return wav_path

    def test_transcricao_wav(self):
        sample = self._criar_wav_teste()
        res = self.engine.transcrever_arquivo(sample)
        self.assertTrue(res["ok"])
        self.assertIn("texto", res)
        self.assertGreater(res["duracao_segundos"], 0.9)
        self.assertEqual(self.engine.stats["total_transcriptions"], 1)

    def test_arquivo_inexistente(self):
        with self.assertRaises(FileNotFoundError):
            self.engine.transcrever_arquivo(str(self.tmp_dir / "inexistente.wav"))


if __name__ == "__main__":
    unittest.main()
