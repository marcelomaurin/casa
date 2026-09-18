#!/usr/bin/env python3
import os
import socket
import threading
import time
from pathlib import Path
from typing import Optional

import pychromecast
import requests
from fastapi import FastAPI, HTTPException
from fastapi.responses import FileResponse
from pydantic import BaseModel

PORT=int(os.getenv("CASA_GOOGLE_HOME_PORT","8100"))
COMPUTER_URL=os.getenv("CASA_COMPUTER_URL","http://127.0.0.1/api/jarvis.php")
TTS_URL=os.getenv("CASA_TTS_URL","http://127.0.0.1:8097/falar")
SYSTEM_API_TOKEN=os.getenv("CASA_SYSTEM_API_TOKEN","")
HARDWARE_URL=os.getenv("CASA_GOOGLE_HOME_HARDWARE_URL","")
DEVICE_TOKEN=os.getenv("CASA_GOOGLE_HOME_DEVICE_TOKEN","")
PUBLIC_BASE=os.getenv("CASA_GOOGLE_HOME_PUBLIC_BASE_URL","").rstrip("/")
AUDIO_DIR=Path(os.getenv("CASA_TTS_AUDIO_DIR","/home/mmm/servicos/tts/audios"))
DISCOVERY_TTL=int(os.getenv("CASA_GOOGLE_HOME_DISCOVERY_TTL","30"))

app=FastAPI(title="CASA COMPUTER Google Home Agent",version="1.0")
_lock=threading.Lock()
_casts={}
_last_discovery=0.0

class SpeakRequest(BaseModel):
    texto:str
    device:Optional[str]=None
    speaker:Optional[str]="padrao"

class CommandRequest(BaseModel):
    comando:str
    device:Optional[str]=None
    speaker:Optional[str]="padrao"

def local_ip():
    s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM)
    try:
        s.connect(("8.8.8.8",80))
        return s.getsockname()[0]
    except Exception:
        return "127.0.0.1"
    finally:
        s.close()

def public_base():
    return PUBLIC_BASE or f"http://{local_ip()}:{PORT}"

def discover(force=False):
    global _casts,_last_discovery
    with _lock:
        if not force and _casts and time.time()-_last_discovery<DISCOVERY_TTL:
            return _casts
        casts,browser=pychromecast.get_chromecasts(timeout=5)
        found={}
        for cast in casts:
            try:
                cast.wait(timeout=5)
            except Exception:
                pass
            key=str(cast.uuid)
            found[key]=cast
        try:
            browser.stop_discovery()
        except Exception:
            pass
        _casts=found
        _last_discovery=time.time()
        return _casts

def device_list():
    items=[]
    for uid,c in discover().items():
        items.append({
            "uuid":uid,
            "name":getattr(c,"name",None) or getattr(c,"friendly_name",None) or "Google Home",
            "host":getattr(getattr(c,"cast_info",None),"host",None)
        })
    return items

def choose(device=None):
    casts=discover()
    if not casts:
        raise RuntimeError("Nenhum Google Home/Chromecast encontrado na rede local")
    if device and device in casts:
        return casts[device]
    if device:
        for c in casts.values():
            name=(getattr(c,"name",None) or getattr(c,"friendly_name",None) or "").lower()
            if name==device.lower():
                return c
    return next(iter(casts.values()))

def computer(command):
    headers={"Content-Type":"application/json"}
    if SYSTEM_API_TOKEN:
        headers["X-API-Key"]=SYSTEM_API_TOKEN
    r=requests.post(COMPUTER_URL,json={"comando":command,"origem":"GOOGLE_HOME_HARDWARE"},headers=headers,timeout=50)
    r.raise_for_status()
    data=r.json()
    return str(data.get("resposta") or data.get("mensagem") or "")

def synthesize(text,speaker="padrao"):
    r=requests.post(TTS_URL,json={"texto":text,"speaker":speaker,"reproduzir":False},timeout=30)
    r.raise_for_status()
    data=r.json()
    audio_url=str(data.get("audio_url") or "")
    filename=os.path.basename(audio_url)
    if not filename:
        raise RuntimeError("TTS não retornou arquivo de áudio")
    return filename

def cast_file(filename,device=None):
    cast=choose(device)
    url=f"{public_base()}/audio/{filename}"
    cast.media_controller.play_media(url,"audio/wav")
    cast.media_controller.block_until_active(timeout=10)
    return {
        "uuid":str(cast.uuid),
        "name":getattr(cast,"name",None) or getattr(cast,"friendly_name",None) or "Google Home",
        "url":url
    }

def heartbeat_loop():
    while True:
        if HARDWARE_URL and DEVICE_TOKEN:
            try:
                requests.post(
                    HARDWARE_URL,
                    json={"acao":"heartbeat","capabilities":["google-home","cast-speaker","computer-command"],"devices":device_list()},
                    headers={"Content-Type":"application/json","X-Device-Token":DEVICE_TOKEN},
                    timeout=10
                )
            except Exception:
                pass
        time.sleep(30)

@app.on_event("startup")
def startup():
    threading.Thread(target=heartbeat_loop,daemon=True).start()

@app.get("/status")
def status():
    try:
        devices=device_list()
        return {"status":"ok","online":True,"devices":devices,"hardware_bridge":bool(HARDWARE_URL and DEVICE_TOKEN)}
    except Exception as e:
        return {"status":"erro","online":False,"mensagem":str(e),"devices":[],"hardware_bridge":bool(HARDWARE_URL and DEVICE_TOKEN)}

@app.get("/devices")
def devices():
    return {"status":"ok","devices":device_list()}

@app.post("/speak")
def speak(req:SpeakRequest):
    if not req.texto.strip():
        raise HTTPException(400,"Texto vazio")
    try:
        filename=synthesize(req.texto,req.speaker or "padrao")
        d=cast_file(filename,req.device)
        return {"status":"ok","device":d,"texto":req.texto}
    except Exception as e:
        raise HTTPException(502,str(e))

@app.post("/command")
def command(req:CommandRequest):
    if not req.comando.strip():
        raise HTTPException(400,"Comando vazio")
    try:
        resposta=computer(req.comando)
        if not resposta:
            raise RuntimeError("COMPUTER não retornou resposta")
        filename=synthesize(resposta,req.speaker or "padrao")
        d=cast_file(filename,req.device)
        return {"status":"ok","comando":req.comando,"resposta":resposta,"device":d}
    except Exception as e:
        raise HTTPException(502,str(e))

@app.get("/audio/{filename}")
def audio(filename:str):
    safe=os.path.basename(filename)
    if safe!=filename or not safe.lower().endswith(".wav"):
        raise HTTPException(400,"Arquivo inválido")
    path=AUDIO_DIR/safe
    if not path.is_file():
        raise HTTPException(404,"Áudio não encontrado")
    return FileResponse(str(path),media_type="audio/wav")

if __name__=="__main__":
    import uvicorn
    uvicorn.run(app,host="0.0.0.0",port=PORT)
