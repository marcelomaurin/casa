#!/usr/bin/env python3
"""Agente Cast: busca tarefas no Casa por HTTPS e reproduz na rede local."""
import asyncio
from contextlib import asynccontextmanager
import hmac
import logging
import os
from pathlib import Path
import socket
import threading
import time
import wave
from typing import Optional
from urllib.parse import quote, urlparse
import pychromecast
import requests
from fastapi import Depends, FastAPI, HTTPException, Request
from fastapi.responses import FileResponse
from pydantic import BaseModel, Field
from queue_bridge import QueueBridge

PORT = int(os.getenv("CASA_GOOGLE_HOME_PORT", "8100"))
BASE_URL = os.getenv("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
COMPUTER_URL = os.getenv("CASA_COMPUTER_URL", BASE_URL + "/api/v1/comando")
TTS_URL = os.getenv("CASA_TTS_URL", "http://127.0.0.1:8097/falar")
SYSTEM_API_TOKEN = os.getenv("CASA_SYSTEM_API_TOKEN", os.getenv("CASA_TAREFAS_CHAVE", ""))
API_TOKEN = os.getenv("CASA_GOOGLE_HOME_API_TOKEN", "")
PUBLIC_BASE = os.getenv("CASA_GOOGLE_HOME_PUBLIC_BASE_URL", "").rstrip("/")
AUDIO_DIR = Path(os.getenv("CASA_TTS_AUDIO_DIR", "/home/mmm/servicos/tts/audios"))
DEFAULT_DEVICE = os.getenv("CASA_GOOGLE_HOME_DEFAULT_DEVICE", "")
KNOWN_HOSTS = [x.strip() for x in os.getenv("CASA_GOOGLE_HOME_KNOWN_HOSTS", "").split(",") if x.strip()]
_lock = threading.RLock()
_play_lock = threading.Lock()
_casts = {}
_browser = None
_queue = None
PLAYBACK_TIMEOUT = 15

class SpeakRequest(BaseModel):
    texto: str = Field(min_length=1, max_length=4000)
    device: Optional[str] = None
    speaker: str = Field(default="padrao", pattern=r"^[A-Za-z0-9_-]{1,64}$")

class CommandRequest(BaseModel):
    comando: str = Field(min_length=1, max_length=4000)
    device: Optional[str] = None
    speaker: str = Field(default="padrao", pattern=r"^[A-Za-z0-9_-]{1,64}$")
    task_context: Optional[dict] = None

def require_local_auth(request: Request):
    supplied = request.headers.get("X-API-Key", "")
    if API_TOKEN and hmac.compare_digest(supplied, API_TOKEN):
        return
    if not API_TOKEN and request.client and request.client.host in ("127.0.0.1", "::1"):
        return
    raise HTTPException(401, "Chave do agente obrigatoria")

def local_ip():
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.connect(("192.168.2.1", 80))
        return sock.getsockname()[0]

def public_base():
    base = PUBLIC_BASE or f"http://{local_ip()}:{PORT}"
    parsed = urlparse(base)
    if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.hostname in ("127.0.0.1", "localhost", "::1"):
        raise RuntimeError("URL de audio deve ser acessivel pelo Google Home na rede local")
    return base

def discover():
    global _browser
    def found(cast):
        with _lock:
            old = _casts.get(str(cast.uuid))
            _casts[str(cast.uuid)] = cast
        if old is not None and old is not cast:
            old.disconnect()
    with _lock:
        if _browser is None:
            _browser = pychromecast.get_chromecasts(blocking=False, callback=found,
                known_hosts=KNOWN_HOSTS or None, tries=1, timeout=5)
        return dict(_casts)

def device_list():
    return [{"uuid": uid, "name": c.name or "Google Home", "host": c.cast_info.host}
            for uid, c in discover().items()]

def choose(device=None):
    casts = discover()
    target = device or DEFAULT_DEVICE
    if target:
        matches = [c for uid, c in casts.items() if uid == target or (c.name or "").casefold() == target.casefold()]
        if len(matches) != 1:
            raise RuntimeError("Destino Google Home nao encontrado ou ambiguo: " + target)
        cast = matches[0]
    elif len(casts) == 1:
        cast = next(iter(casts.values()))
    elif not casts:
        raise RuntimeError("Nenhum Google Home/Chromecast encontrado na rede local")
    else:
        raise RuntimeError("Selecione o Google Home de destino; ha mais de um aparelho")
    cast.wait(timeout=10)
    return cast

def computer(command, task_context=None):
    if not SYSTEM_API_TOKEN:
        raise RuntimeError("Chave do Casa nao configurada")
    response = requests.post(COMPUTER_URL,
        json={"comando": command, "origem": "GOOGLE_HOME_HARDWARE", "task_context": task_context},
        headers={"X-API-Key": SYSTEM_API_TOKEN}, timeout=(5, 55))
    response.raise_for_status()
    data = response.json()
    if data.get("status") == "erro" or data.get("status_jarvis") == "erro":
        raise RuntimeError(str(data.get("mensagem") or "COMPUTER recusou o comando"))
    text = str(data.get("resposta") or data.get("mensagem") or "").strip()
    if not text:
        raise RuntimeError("COMPUTER nao retornou resposta")
    return text

def audio_path(filename):
    if Path(filename).name != filename or not filename.lower().endswith(".wav"):
        raise RuntimeError("Arquivo de audio invalido")
    path = AUDIO_DIR / filename
    if path.is_symlink() or not path.is_file() or path.resolve().parent != AUDIO_DIR.resolve():
        raise RuntimeError("Arquivo de audio nao encontrado")
    with path.open("rb") as stream:
        header = stream.read(12)
    if len(header) != 12 or header[:4] != b"RIFF" or header[8:] != b"WAVE":
        raise RuntimeError("TTS nao gerou audio WAV valido")
    return path

def synthesize(text, speaker="padrao"):
    response = requests.post(TTS_URL, json={"texto": text, "speaker": speaker, "reproduzir": False}, timeout=(5, 90))
    response.raise_for_status()
    filename = Path(urlparse(str(response.json().get("audio_url") or "")).path).name
    audio_path(filename)
    return filename

def say(text, device=None, speaker="padrao"):
    if not text.strip():
        raise ValueError("Texto vazio")
    with _play_lock:
        cast = choose(device)
        filename = synthesize(text, speaker)
        url = public_base() + "/audio/" + quote(filename)
        with wave.open(str(audio_path(filename)), "rb") as wav:
            duration = wav.getnframes() / wav.getframerate()
        cast.media_controller.play_media(url, "audio/wav", stream_type="BUFFERED")
        deadline = time.monotonic() + PLAYBACK_TIMEOUT
        confirmed = False
        while time.monotonic() < deadline:
            cast.media_controller.update_status()
            media = cast.media_controller.status
            if media.content_id == url and media.player_state == "PLAYING":
                confirmed = True
                break
            time.sleep(0.25)
        if not confirmed:
            raise RuntimeError("Google Home nao confirmou inicio da reproducao")
        # Segurar a vaga da fila evita que a proxima fala interrompa esta mensagem.
        deadline = time.monotonic() + min(duration + 30, 600)
        while time.monotonic() < deadline:
            cast.media_controller.update_status()
            media = cast.media_controller.status
            if media.content_id != url or media.player_state == "IDLE":
                break
            time.sleep(0.5)
        return {"status": "ok", "texto": text, "device": {"uuid": str(cast.uuid), "name": cast.name, "url": url},
                "reproducao": "iniciada"}

def status_data():
    devices = device_list()
    return {"status": "ok" if devices else "sem_dispositivos", "agent_online": True, "online": bool(devices),
        "devices": devices, "queue_alive": bool(_queue and _queue.alive()),
        "hardware_bridge": bool(_queue and _queue.alive()), "version": "1.1.0"}

@asynccontextmanager
async def lifespan(app):
    global _queue, _browser
    discover()
    _queue = QueueBridge(say, status_data)
    _queue.start()
    try:
        yield
    finally:
        await asyncio.to_thread(_queue.stop)
        if _browser is not None:
            _browser.stop_discovery()
            _browser = None
        for cast in list(_casts.values()):
            cast.disconnect()
        _casts.clear()

app = FastAPI(title="CASA Google Home Agent", version="1.1.0", lifespan=lifespan)

@app.get("/status")
def status():
    return status_data()

@app.get("/devices")
def devices():
    return {"status": "ok", "devices": device_list()}

@app.post("/speak", dependencies=[Depends(require_local_auth)])
def speak(req: SpeakRequest):
    try:
        return say(req.texto, req.device, req.speaker)
    except Exception as error:
        raise HTTPException(502, str(error)) from error

@app.post("/command", dependencies=[Depends(require_local_auth)])
def command(req: CommandRequest):
    try:
        response = computer(req.comando, req.task_context)
        return dict(say(response, req.device, req.speaker), comando=req.comando, resposta=response)
    except Exception as error:
        raise HTTPException(502, str(error)) from error

@app.get("/audio/{filename}")
def audio(filename: str):
    try:
        return FileResponse(str(audio_path(filename)), media_type="audio/wav")
    except RuntimeError as error:
        raise HTTPException(404, str(error)) from error

if __name__ == "__main__":
    import uvicorn
    logging.basicConfig(level=logging.INFO)
    uvicorn.run(app, host="0.0.0.0", port=PORT)
