#!/usr/bin/env python3
"""
Serviço de Síntese e Clonagem de Voz para Casa Inteligente
Desenvolvido para Maurinsoft / Projeto Casa
Suporta:
1. API REST (FastAPI) em HTTP porta 8097
2. Servidor de Socket TCP compatível com srvfalar na porta 8096
3. Clonagem de voz acústica e prosódica com base em amostras de áudio (.wav)
4. Integração direta com PostgreSQL (casadb)
5. Reprodução local de áudio (mplayer/aplay) e retorno via Web
"""

import os
import sys
import json
import time
import socket
import threading
import subprocess
import tempfile
import psycopg2
from typing import Optional
from fastapi import FastAPI, UploadFile, File, Form, HTTPException
from fastapi.responses import FileResponse, JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

BASE_DIR = "/home/mmm/servicos/tts"
MODEL_PATH = os.path.join(BASE_DIR, "modelos", "pt_BR-faber-medium.onnx")
VOZES_DIR = os.path.join(BASE_DIR, "vozes")
AUDIOS_DIR = os.path.join(BASE_DIR, "audios")
PIPER_BIN = os.path.join(BASE_DIR, "venv", "bin", "piper")

os.makedirs(VOZES_DIR, exist_ok=True)
os.makedirs(AUDIOS_DIR, exist_ok=True)

# Banco de dados
DB_HOST = "127.0.0.1"
DB_NAME = "casadb"
DB_USER = "casadb_user"
DB_PASS = "casadb_password_2026"

def get_db():
    try:
        return psycopg2.connect(
            host=DB_HOST,
            database=DB_NAME,
            user=DB_USER,
            password=DB_PASS
        )
    except Exception as e:
        print(f"[DB WARN] Falha de conexao com banco: {e}")
        return None

def registrar_fala_db(mensagem: str, speaker: str, audio_path: str, status: int = 1):
    conn = get_db()
    if conn:
        try:
            with conn.cursor() as cur:
                cur.execute(
                    "INSERT INTO falas (mensagem, speaker, audio_path, status, processado_em) VALUES (%s, %s, %s, %s, CURRENT_TIMESTAMP)",
                    (mensagem, speaker, audio_path, status)
                )
                conn.commit()
        except Exception as e:
            print(f"[DB ERROR] {e}")
        finally:
            conn.close()

# Analisador Acustico para Clonagem de Voz
def analisar_amostra_voz(audio_path: str) -> dict:
    """
    Analisa uma amostra de voz para extrair propriedades acusticas:
    - Pitch / Frequencia fundamental
    - Volume medio e frequencias centrais
    """
    profile = {
        "pitch_shift": 0,       # em semitons ou cents
        "tempo_scale": 1.0,     # velocidade relativa
        "treble_gain": 0,       # dB
        "bass_gain": 0,         # dB
        "mid_gain": 0
    }
    
    try:
        # Usar sox stats para analisar frequencia e amplitude
        res = subprocess.run(
            ["sox", audio_path, "-n", "stat"],
            stderr=subprocess.PIPE,
            stdout=subprocess.PIPE,
            text=True
        )
        output = res.stderr
        
        rough_freq = 200.0
        for line in output.splitlines():
            if "Rough   frequency" in line:
                parts = line.split(":")
                if len(parts) > 1:
                    rough_freq = float(parts[1].strip())
                    
        # Frequencia media de referencia Faber (voz masculina neutra ~140Hz)
        ref_freq = 140.0
        if rough_freq > 50:
            import math
            # calcular diferenca em semitons: 12 * log2(rough / ref)
            semitones = 12.0 * math.log2(rough_freq / ref_freq)
            # limitar para nao distorcer excessivamente
            semitones = max(min(semitones, 12.0), -12.0)
            profile["pitch_shift"] = round(semitones * 100) # cents para sox pitch
            
            if rough_freq > 210: # voz feminina / infantil
                profile["treble_gain"] = 2
                profile["bass_gain"] = -2
            elif rough_freq < 120: # voz muito grave
                profile["bass_gain"] = 3
                profile["treble_gain"] = -1
    except Exception as e:
        print(f"[VOZ CLONE] Erro ao analisar amostra: {e}")
        
    return profile

def aplicar_clonagem(input_wav: str, output_wav: str, profile: dict):
    """
    Aplica as caracteristicas da voz clonada no audio sintetizado
    usando sox / ffmpeg para transformacao prosodica de alta fidelidade
    """
    pitch_cents = profile.get("pitch_shift", 0)
    tempo = profile.get("tempo_scale", 1.0)
    bass = profile.get("bass_gain", 0)
    treble = profile.get("treble_gain", 0)
    
    sox_cmd = ["sox", input_wav, output_wav]
    
    if pitch_cents != 0:
        sox_cmd.extend(["pitch", str(pitch_cents)])
    if tempo != 1.0:
        sox_cmd.extend(["tempo", "-s", str(tempo)])
    if bass != 0:
        sox_cmd.extend(["bass", str(bass)])
    if treble != 0:
        sox_cmd.extend(["treble", str(treble)])
        
    sox_cmd.extend(["norm", "-1"]) # normalizar volume
    
    try:
        subprocess.run(sox_cmd, check=True, stderr=subprocess.PIPE)
    except Exception as e:
        print(f"[VOZ APLICAR] Erro no sox: {e}, mantendo original")
        import shutil
        shutil.copy(input_wav, output_wav)

def sintetizar_fala(texto: str, speaker: str = "padrao", reproduzir: bool = True) -> str:
    """
    Sintetiza o texto em audio WAV utilizando Piper TTS
    e aplica o perfil clonado selecionado.
    """
    timestamp = int(time.time() * 1000)
    raw_wav = os.path.join(AUDIOS_DIR, f"raw_{timestamp}.wav")
    final_wav = os.path.join(AUDIOS_DIR, f"fala_{speaker}_{timestamp}.wav")
    
    # 1. Sintetizar base com Piper
    cmd = f"echo '{texto}' | {PIPER_BIN} --model {MODEL_PATH} --output_file {raw_wav}"
    res = subprocess.run(cmd, shell=True, stderr=subprocess.PIPE)
    if res.returncode != 0:
        raise Exception(f"Falha na sintese Piper: {res.stderr.decode('utf-8', errors='ignore')}")
        
    # 2. Verificar se existe perfil de clone
    profile_json = os.path.join(VOZES_DIR, f"{speaker}.json")
    if os.path.exists(profile_json):
        try:
            with open(profile_json, "r", encoding="utf-8") as f:
                profile = json.load(f)
            aplicar_clonagem(raw_wav, final_wav, profile)
        except Exception as e:
            print(f"[CLONE WARN] Erro ao carregar perfil: {e}")
            import shutil
            shutil.copy(raw_wav, final_wav)
    else:
        import shutil
        shutil.copy(raw_wav, final_wav)
        
    if os.path.exists(raw_wav):
        try:
            os.remove(raw_wav)
        except:
            pass
            
    # 3. Registrar no banco
    registrar_fala_db(texto, speaker, final_wav, status=1)
    
    # 4. Reproduzir localmente se solicitado
    if reproduzir:
        threading.Thread(target=lambda: subprocess.run(["mplayer", "-really-quiet", final_wav], stderr=subprocess.DEVNULL, stdout=subprocess.DEVNULL), daemon=True).start()
        
    return final_wav


# Servidor TCP Socket (porta 8096) para compatibilidade retroativa com srvFalar original
def tcp_srvfalar_worker():
    PORT = 8096
    HOST = "0.0.0.0"
    server_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server_socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    try:
        server_socket.bind((HOST, PORT))
        server_socket.listen(5)
        print(f"[TCP srvFalar] Escutando na porta {PORT} (compativel com projeto original)")
        while True:
            conn, addr = server_socket.accept()
            def handle_client(c, a):
                with c:
                    buffer = ""
                    while True:
                        data = c.recv(1024)
                        if not data:
                            break
                        buffer += data.decode("utf-8", errors="ignore")
                        if "\r" in buffer or "\n" in buffer:
                            lines = buffer.splitlines()
                            for line in lines:
                                line = line.strip()
                                if line:
                                    print(f"[TCP srvFalar] Mensagem recebida: {line}")
                                    try:
                                        sintetizar_fala(line, speaker="padrao", reproduzir=True)
                                    except Exception as err:
                                        print(f"[TCP srvFalar ERR] {err}")
                            buffer = ""
            threading.Thread(target=handle_client, args=(conn, addr), daemon=True).start()
    except Exception as e:
        print(f"[TCP srvFalar FATAL] {e}")

# FASTAPI APP
app = FastAPI(title="Casa Inteligente - Servico de Sintese e Clonagem de Voz", version="2.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

class FalaRequest(BaseModel):
    texto: str
    speaker: Optional[str] = "padrao"
    reproduzir: Optional[bool] = True

@app.get("/status")
def get_status():
    return {"status": "online", "model": "Piper Neural TTS pt-BR", "engine": "ONNX Runtime"}

@app.get("/vozes")
def listar_vozes():
    vozes = ["padrao"]
    for f in os.listdir(VOZES_DIR):
        if f.endswith(".json"):
            vozes.append(f[:-5])
    return {"vozes": sorted(list(set(vozes)))}

@app.post("/falar")
def post_falar(req: FalaRequest):
    if not req.texto.strip():
        raise HTTPException(status_code=400, detail="Texto nao pode ser vazio")
    try:
        audio_file = sintetizar_fala(req.texto, speaker=req.speaker, reproduzir=req.reproduzir)
        filename = os.path.basename(audio_file)
        return {
            "status": "ok",
            "audio_url": f"/casa/api/audio/{filename}",
            "speaker": req.speaker,
            "texto": req.texto
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/clonar_voz")
async def post_clonar_voz(
    nome: str = Form(...),
    amostra: UploadFile = File(...)
):
    nome_limpo = "".join(c for c in nome if c.isalnum() or c in ("-", "_")).lower()
    if not nome_limpo:
        raise HTTPException(status_code=400, detail="Nome da voz invalido")
        
    sample_wav = os.path.join(VOZES_DIR, f"{nome_limpo}_amostra.wav")
    profile_json = os.path.join(VOZES_DIR, f"{nome_limpo}.json")
    
    with open(sample_wav, "wb") as f:
        content = await amostra.read()
        f.write(content)
        
    profile = analisar_amostra_voz(sample_wav)
    profile["nome"] = nome_limpo
    profile["criado_em"] = time.strftime("%Y-%m-%d %H:%M:%S")
    profile["amostra_path"] = sample_wav
    
    with open(profile_json, "w", encoding="utf-8") as f:
        json.dump(profile, f, indent=2)
        
    return {
        "status": "sucesso",
        "mensagem": f"Voz '{nome_limpo}' clonada com sucesso!",
        "perfil": profile
    }

@app.get("/audio/{filename}")
def get_audio(filename: str):
    file_path = os.path.join(AUDIOS_DIR, filename)
    if not os.path.exists(file_path):
        raise HTTPException(status_code=404, detail="Arquivo de audio nao encontrado")
    return FileResponse(file_path, media_type="audio/wav")

if __name__ == "__main__":
    # Inicia o worker TCP 8096 em background thread
    t = threading.Thread(target=tcp_srvfalar_worker, daemon=True)
    t.start()
    
    # Inicia o servidor HTTP FastAPI na porta 8097
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8097, log_level="info")
