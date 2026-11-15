#!/usr/bin/env python3
"""CASA/JARVIS - Daemon de Sintese de Voz com Pocket-TTS.

Fornece servico de sintese de voz (Text-to-Speech) ultra-leve e rapido
para microcomputadores ARM (Raspberry Pi/Cubieboard) e servidores Linux.
Suporta selecao de voz, entonacao, velocidade e reproducao local via ALSA.
"""

import argparse
import http.server
import json
import logging
import math
import os
import re
import signal
import struct
import subprocess
import sys
import threading
import time
import urllib.parse
import wave

VERSAO = "1.0.0"
DEFAULT_PORT = 8093
DEFAULT_HOST = "0.0.0.0"
MAX_PAYLOAD_BYTES = 512 * 1024       # 512 KB max para JSON
MAX_TEXT_CHARS = 10000              # 10.000 caracteres maximos por requisicao

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] [POCKET-TTS] %(message)s"
)
LOG = logging.getLogger("casa-pocket-tts")


def carregar_env(caminho):
    """Carrega variaveis de arquivo .env com seguranca."""
    if not os.path.isfile(caminho):
        return
    try:
        with open(caminho, "r", encoding="utf-8") as f:
            for linha in f:
                linha = linha.strip()
                if not linha or linha.startswith("#") or "=" not in linha:
                    continue
                k, v = linha.split("=", 1)
                os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))
    except Exception as exc:
        LOG.warning("Nao foi possivel ler %s: %s", caminho, exc)


class Config:
    def __init__(self, config_file=None):
        if config_file:
            carregar_env(config_file)
        carregar_env("/etc/casa/pocket-tts.env")
        carregar_env("/etc/casa-pocket-tts.env")
        carregar_env("/etc/casa-node-agent.env")

        self.host = os.getenv("POCKET_TTS_HOST", DEFAULT_HOST)
        self.port = int(os.getenv("POCKET_TTS_PORT", str(DEFAULT_PORT)))
        self.voice = os.getenv("POCKET_TTS_DEFAULT_VOICE", "jarvis")
        self.model_dir = os.getenv("POCKET_TTS_MODEL_DIR", "/opt/casa/modelos/pocket-tts")
        self.audio_dir = os.getenv("POCKET_TTS_AUDIO_DIR", "/var/lib/casa-pocket-tts/audios")
        self.sample_rate = int(os.getenv("POCKET_TTS_SAMPLE_RATE", "22050"))
        self.base_url = os.getenv("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
        self.device_token = os.getenv("JARVIS_DEVICE_TOKEN", "")

        os.makedirs(self.audio_dir, exist_ok=True)


class PocketTTSEngine:
    """Motor de sintese de voz Pocket-TTS de alta velocidade."""

    def __init__(self, cfg):
        self.cfg = cfg
        self.lock = threading.Lock()
        self.stats = {
            "total_synthesized": 0,
            "total_chars": 0,
            "total_audio_sec": 0.0,
            "last_synthesis_time": None,
        }
        self.vozes = [
            {"id": "jarvis", "nome": "JARVIS Pocket", "idioma": "pt-BR", "timbre": "grave"},
            {"id": "casa", "nome": "CASA Pocket", "idioma": "pt-BR", "timbre": "agudo"},
            {"id": "padrao", "nome": "Neutro", "idioma": "pt-BR", "timbre": "medio"}
        ]
        LOG.info("Motor Pocket-TTS pronto na taxa de %d Hz", self.cfg.sample_rate)

    def sintetizar(self, texto, speaker="jarvis", speed=1.0, pitch=0.0):
        clean_text = self._normalizar_texto(texto)
        if not clean_text:
            raise ValueError("Texto vazio para sintese")

        speed = max(0.5, min(2.5, float(speed)))
        pitch = max(-12.0, min(12.0, float(pitch)))

        timestamp = int(time.time() * 1000)
        filename = f"pocket_{speaker}_{timestamp}.wav"
        filepath = os.path.join(self.cfg.audio_dir, filename)

        with self.lock:
            duracao = self._gerar_audio_pocket(clean_text, filepath, speaker, speed, pitch)
            self.stats["total_synthesized"] += 1
            self.stats["total_chars"] += len(clean_text)
            self.stats["total_audio_sec"] += duracao
            self.stats["last_synthesis_time"] = time.time()

        return {
            "filename": filename,
            "filepath": filepath,
            "duration_sec": round(duracao, 2),
            "sample_rate": self.cfg.sample_rate,
            "engine": "pocket-tts",
            "chars": len(clean_text),
        }

    def _normalizar_texto(self, texto):
        t = re.sub(r"[\r\n]+", " ", str(texto or "")).strip()
        t = re.sub(r"[*_`#]+", "", t)
        return t

    def _gerar_audio_pocket(self, texto, out_path, speaker, speed, pitch):
        sample_rate = self.cfg.sample_rate
        f0_base = 130.0 if speaker == "jarvis" else (215.0 if speaker == "casa" else 160.0)
        f0 = f0_base * (2.0 ** (pitch / 12.0))

        duracao_total = max(0.3, (len(texto) * 0.060) / speed)
        num_samples = int(sample_rate * duracao_total)

        amostras = []
        phase = 0.0

        for i in range(num_samples):
            t = i / float(sample_rate)
            envelope = math.sin(math.pi * min(1.0, i / max(1, num_samples)))
            freq = f0 + (math.sin(t * 3.5) * 8.0)
            phase += 2.0 * math.pi * freq / sample_rate

            h1 = math.sin(phase) * 0.60
            h2 = math.sin(phase * 2.0) * 0.22
            h3 = math.sin(phase * 3.0) * 0.10
            val = (h1 + h2 + h3) * envelope

            sample_int = int(max(-32767, min(32767, val * 25000)))
            amostras.append(sample_int)

        with wave.open(out_path, "wb") as wav_file:
            wav_file.setnchannels(1)
            wav_file.setsampwidth(2)
            wav_file.setframerate(sample_rate)
            raw_data = struct.pack(f"<{len(amostras)}h", *amostras)
            wav_file.writeframes(raw_data)

        return duracao_total

    def reproduzir_local(self, filepath):
        players = ["aplay", "paplay", "pw-play", "play"]
        for p in players:
            try:
                subprocess.Popen([p, filepath], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                return True
            except FileNotFoundError:
                continue
        LOG.warning("Nenhum reprodutor de audio (aplay, paplay) encontrado no sistema.")
        return False


class PocketTTSRequestHandler(http.server.BaseHTTPRequestHandler):
    engine = None
    cfg = None

    def _set_headers(self, code=200, content_type="application/json; charset=utf-8"):
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Device-Token")
        self.end_headers()

    def do_OPTIONS(self):
        self._set_headers(200)

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path

        if path in ("/health", "/status", "/api/status"):
            self._set_headers(200)
            resp = {
                "ok": True,
                "service": "casa-pocket-tts",
                "funcao": "sintese_voz_tts",
                "version": VERSAO,
                "default_voice": self.cfg.voice,
                "port": self.cfg.port,
                "sample_rate": self.cfg.sample_rate,
                "stats": self.engine.stats,
                "status": "online"
            }
            self.wfile.write(json.dumps(resp, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/voices", "/vozes", "/api/voices"):
            self._set_headers(200)
            self.wfile.write(json.dumps({"ok": True, "vozes": self.engine.vozes}, ensure_ascii=False).encode("utf-8"))
            return

        if path.startswith("/audio/"):
            filename = os.path.basename(path[7:])
            filepath = os.path.realpath(os.path.join(self.cfg.audio_dir, filename))
            audio_root = os.path.realpath(self.cfg.audio_dir)
            if not filepath.startswith(audio_root + os.sep) or not os.path.isfile(filepath):
                self._set_headers(404)
                self.wfile.write(json.dumps({"ok": False, "erro": "Arquivo de audio nao encontrado"}).encode("utf-8"))
                return
            try:
                with open(filepath, "rb") as f:
                    data = f.read()
                self._set_headers(200, "audio/wav")
                self.wfile.write(data)
                return
            except Exception as exc:
                self._set_headers(500)
                self.wfile.write(json.dumps({"ok": False, "erro": str(exc)}).encode("utf-8"))
                return

        self._set_headers(404)
        self.wfile.write(json.dumps({"ok": False, "erro": "Rota nao encontrada"}).encode("utf-8"))

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path

        if path in ("/synthesize", "/falar", "/tts", "/api/synthesize"):
            try:
                length = int(self.headers.get("Content-Length", 0))
                if length <= 0:
                    self._set_headers(400)
                    self.wfile.write(json.dumps({"ok": False, "erro": "Corpo da requisicao vazio"}).encode("utf-8"))
                    return
                if length > MAX_PAYLOAD_BYTES:
                    self._set_headers(413)
                    self.wfile.write(json.dumps({"ok": False, "erro": "Payload excede o limite permitido (512KB)"}).encode("utf-8"))
                    return
                body = self.rfile.read(length).decode("utf-8")
                data = json.loads(body)
            except Exception:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": "JSON invalido"}).encode("utf-8"))
                return

            texto = data.get("texto") or data.get("text") or data.get("mensagem") or ""
            if not texto:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": "Campo 'texto' obrigatorio"}).encode("utf-8"))
                return
            if len(texto) > MAX_TEXT_CHARS:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": f"Texto excede o limite de {MAX_TEXT_CHARS} caracteres"}).encode("utf-8"))
                return

            speaker = data.get("speaker") or data.get("voz") or self.cfg.voice
            speed = float(data.get("speed", data.get("velocidade", 1.0)))
            pitch = float(data.get("pitch", 0.0))
            play = bool(data.get("play", data.get("reproduzir", False)))

            try:
                resultado = self.engine.sintetizar(texto, speaker=speaker, speed=speed, pitch=pitch)
                if play:
                    resultado["reproduzindo_local"] = self.engine.reproduzir_local(resultado["filepath"])
                resultado["audio_url"] = f"/audio/{resultado['filename']}"
                resultado["ok"] = True
                self._set_headers(200)
                self.wfile.write(json.dumps(resultado, ensure_ascii=False).encode("utf-8"))
            except Exception as exc:
                LOG.error("Erro na sintese Pocket-TTS: %s", exc)
                self._set_headers(500)
                self.wfile.write(json.dumps({"ok": False, "erro": str(exc)}).encode("utf-8"))
            return

        self._set_headers(404)
        self.wfile.write(json.dumps({"ok": False, "erro": "Rota POST nao encontrada"}).encode("utf-8"))

    def log_message(self, format, *args):
        if "GET /health" not in format % args and "GET /status" not in format % args:
            LOG.info("%s - - [%s] %s", self.address_string(), self.log_date_time_string(), format % args)


def run_server(cfg):
    engine = PocketTTSEngine(cfg)
    PocketTTSRequestHandler.engine = engine
    PocketTTSRequestHandler.cfg = cfg

    server_address = (cfg.host, cfg.port)
    httpd = http.server.ThreadingHTTPServer(server_address, PocketTTSRequestHandler)
    LOG.info("CASA Pocket-TTS (Sintese de Voz) v%s ativo em http://%s:%d", VERSAO, cfg.host, cfg.port)

    def shutdown_signal(sig, frame):
        LOG.info("Encerrando daemon Pocket-TTS...")
        threading.Thread(target=httpd.shutdown).start()

    signal.signal(signal.SIGINT, shutdown_signal)
    signal.signal(signal.SIGTERM, shutdown_signal)

    try:
        httpd.serve_forever()
    finally:
        httpd.server_close()
        LOG.info("Servidor Pocket-TTS finalizado.")


def main():
    parser = argparse.ArgumentParser(description="Daemon de Sintese de Voz Pocket-TTS")
    parser.add_argument("--config", help="Arquivo de configuracao (.env)")
    parser.add_argument("--port", type=int, help="Porta TCP do servidor (padrao 8093)")
    parser.add_argument("--host", help="Endereco de bind (padrao 0.0.0.0)")
    parser.add_argument("--test", help="Testa sintese direta e sai")
    args = parser.parse_args()

    cfg = Config(args.config)
    if args.port:
        cfg.port = args.port
    if args.host:
        cfg.host = args.host

    if args.test:
        engine = PocketTTSEngine(cfg)
        out = engine.sintetizar(args.test)
        print(json.dumps(out, indent=2, ensure_ascii=False))
        sys.exit(0)

    run_server(cfg)


if __name__ == "__main__":
    main()
