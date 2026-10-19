#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
JARVIS CASA - ESP32-CAM Auto-Discovery, Configuration and AI Vision Daemon.

Standards: Star Trek Next Generation LCARS Central Computer.
Zero-Touch:
 1. Auto-discovers connected ESP32-CAM via USB Serial (COM* / /dev/ttyUSB*).
 2. Flashes / auto-configures Wi-Fi credentials (maurinsrv_1 / maurin_pwl) zero-touch.
 3. Scans local network subnet (192.168.2.0/24) to locate live camera IP.
 4. Connects MJPEG/JPEG stream, monitors security, and triggers AI vision analysis.
 5. Registers camera status into CASA server.
"""

import argparse
import concurrent.futures
import json
import os
import re
import socket
import subprocess
import sys
import time
from pathlib import Path

try:
    import requests
except ImportError:
    requests = None

try:
    import serial
    import serial.tools.list_ports
except ImportError:
    serial = None

CASA_SERVER = os.getenv("CASA_SERVER_URL", "https://maurinsoft.com.br/casa")
DEFAULT_SSID = "maurinsrv_1"
DEFAULT_PASS = "1425361425"
SUBNET_PREFIX = "192.168.2."
BASE_DIR = Path(__file__).resolve().parent
PROJECT_DIR = BASE_DIR.parents[2]
BUILD_DIR = PROJECT_DIR / "arduino" / "esp32_cam" / "jarvis_espcam" / "build"
ESPTOOL_PATH = os.path.expandvars(r"%USERPROFILE%\AppData\Local\Arduino15\packages\esp32\tools\esptool_py\5.3.1\esptool.exe")


def log_lcars(message: str, level: str = "INFO"):
    timestamp = time.strftime("%H:%M:%S")
    stardate = f"{time.strftime('%Y')}.{int(time.time() % 1000):03d}"
    print(f"[{timestamp}] [STARDATE {stardate}] [LCARS-{level}] {message}", flush=True)


def find_serial_ports():
    """Detects available serial ports that could be ESP32-CAM."""
    if not serial:
        return []
    ports = serial.tools.list_ports.comports()
    candidates = []
    for p in ports:
        desc = (p.description or "").lower()
        hwid = (p.hwid or "").lower()
        # FTDI (0403:6001), CH340 (1a86:7523), CP210x (10c4:ea60), or generic USB Serial
        if any(k in desc or k in hwid for k in ["usb", "ftdi", "ch340", "cp210", "serial", "uart", "0403:6001", "1a86:7523"]):
            candidates.append(p.device)
    return candidates


def test_serial_camera(port: str, baud: int = 115200, timeout: float = 3.0):
    """Sends discovery commands over serial port and listens for responses."""
    if not serial:
        return None
    try:
        ser = serial.Serial(port, baud, timeout=1.0)
        time.sleep(0.5)
        # Flush buffer
        ser.reset_input_buffer()
        ser.write(b"STATUS\n")
        time.sleep(0.5)
        ser.write(b"DISCOVER\n")
        
        t0 = time.time()
        output = []
        while time.time() - t0 < timeout:
            line = ser.readline().decode("utf-8", errors="replace").strip()
            if line:
                output.append(line)
                if "CASA-CAM" in line or "IP=" in line or "esp32cam" in line:
                    break
        ser.close()
        
        raw_text = "\n".join(output)
        ip_match = re.search(r"IP=([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)", raw_text)
        mac_match = re.search(r"MAC=([0-9A-Fa-f:]+)", raw_text)
        stream_match = re.search(r"STREAM=(https?://[^\s]+)", raw_text)
        capture_match = re.search(r"CAPTURE=(https?://[^\s]+)", raw_text)
        
        return {
            "port": port,
            "raw": raw_text,
            "ip": ip_match.group(1) if ip_match else None,
            "mac": mac_match.group(1) if mac_match else None,
            "stream": stream_match.group(1) if stream_match else None,
            "capture": capture_match.group(1) if capture_match else None,
            "online": bool(ip_match)
        }
    except Exception as e:
        return {"port": port, "erro": str(e), "online": False}


def configure_serial_wifi(port: str, ssid: str = DEFAULT_SSID, password: str = DEFAULT_PASS):
    """Configures Wi-Fi credentials via serial protocol."""
    if not serial:
        return False
    try:
        ser = serial.Serial(port, 115200, timeout=2.0)
        time.sleep(0.5)
        cmd = f"SET_WIFI:{ssid}:{password}:{CASA_SERVER}\n"
        log_lcars(f"Enviando configuração de rede para {port}...", "CONFIG")
        ser.write(cmd.encode("utf-8"))
        time.sleep(1.0)
        resp = ser.readline().decode("utf-8", errors="replace").strip()
        ser.close()
        log_lcars(f"Resposta do dispositivo: {resp}", "OK")
        return True
    except Exception as e:
        log_lcars(f"Falha ao enviar comando serial para {port}: {e}", "ERRO")
        return False


def flash_firmware(port: str):
    """Flashes compiled ESP32-CAM firmware binary to the given COM port."""
    bin_file = BUILD_DIR / "jarvis_espcam.ino.bin"
    bootloader = BUILD_DIR / "jarvis_espcam.ino.bootloader.bin"
    partitions = BUILD_DIR / "jarvis_espcam.ino.partitions.bin"
    boot_app0 = Path(os.path.expandvars(r"%USERPROFILE%\AppData\Local\Arduino15\packages\esp32\hardware\esp32\3.3.7\tools\partitions\boot_app0.bin"))
    
    if not bin_file.exists():
        log_lcars(f"Arquivo binário não encontrado em {bin_file}", "ERRO")
        return False
        
    cmd = [
        ESPTOOL_PATH,
        "--chip", "esp32",
        "--port", port,
        "--baud", "460800",
        "--before", "default-reset",
        "--after", "hard-reset",
        "write-flash", "-z",
        "--flash-mode", "dio",
        "--flash-freq", "40m",
        "--flash-size", "4MB",
        "0x1000", str(bootloader),
        "0x8000", str(partitions),
        "0xe000", str(boot_app0),
        "0x10000", str(bin_file)
    ]
    
    log_lcars(f"Iniciando gravação de firmware na porta {port}...", "UPLOAD")
    try:
        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=90)
        if proc.returncode == 0:
            log_lcars(f"Gravação concluída com sucesso na porta {port}!", "SUCESSO")
            return True
        else:
            log_lcars(f"Falha na gravação ({proc.returncode}):\n{proc.stdout}", "ERRO")
            return False
    except Exception as e:
        log_lcars(f"Exceção durante gravação: {e}", "ERRO")
        return False


def check_ip_camera(ip: str, timeout: float = 0.5):
    """Pings HTTP /status and /capture on candidate IP."""
    url = f"http://{ip}/status"
    try:
        r = requests.get(url, timeout=timeout)
        if r.status_code == 200:
            data = r.json()
            if data.get("type") == "esp32cam" or "stream" in data or "capture" in data or "ap" in data:
                return {
                    "ip": ip,
                    "status": "online",
                    "data": data,
                    "stream": f"http://{ip}/stream",
                    "capture": f"http://{ip}/capture"
                }
    except Exception:
        pass
    return None


def scan_network_cameras(subnet: str = SUBNET_PREFIX, max_workers: int = 50):
    """Scans local subnet concurrently for active ESP32-CAMs."""
    log_lcars(f"Iniciando varredura óptica sub-espacial na sub-rede {subnet}0/24...", "SCAN")
    found = []
    candidates = [f"{subnet}{i}" for i in range(1, 255)]
    
    with concurrent.futures.ThreadPoolExecutor(max_workers=max_workers) as executor:
        results = executor.map(check_ip_camera, candidates)
        for res in results:
            if res:
                found.append(res)
                log_lcars(f"CÂMERA DETECTADA no endereço {res['ip']}! Stream: {res['stream']}", "DISCOVERY")
                
    return found


def register_camera_in_casa(cam_info: dict):
    """Registers discovered camera into the CASA central system."""
    log_lcars(f"Registrando nó óptico {cam_info.get('ip')} no Computador Central CASA...", "INTEGRACAO")
    try:
        url = f"{CASA_SERVER}/api/v1/device.php?acao=heartbeat"
        payload = {
            "device_id": cam_info.get("data", {}).get("device_id") or f"CAM_{cam_info['ip'].replace('.', '_')}",
            "transport": "wifi",
            "health": "ok",
            "protocol_version": "CASA/1.0",
            "model": "ESP32-CAM AI Thinker",
            "local_ip": cam_info["ip"],
            "stream_url": cam_info.get("stream"),
            "capture_url": cam_info.get("capture"),
            "capabilities": ["camera", "snapshot", "mjpeg", "flash", "ai_vision", "telemetry"]
        }
        res = requests.post(url, json=payload, timeout=5, verify=False)
        log_lcars(f"Resposta do registro central CASA: HTTP {res.status_code}", "OK")
    except Exception as e:
        log_lcars(f"Registro central adiado (rede externa): {e}", "WARN")


def capture_snapshot(capture_url: str, dest_path: Path):
    """Downloads a fresh JPEG frame from camera."""
    try:
        r = requests.get(capture_url, timeout=4)
        if r.status_code == 200 and len(r.content) > 1000:
            dest_path.write_bytes(r.content)
            return True
    except Exception as e:
        log_lcars(f"Falha ao capturar frame de {capture_url}: {e}", "WARN")
    return False


def run_ai_vision_analysis(image_path: Path):
    """Dispatches captured image to AI vision models."""
    script_analisa = BASE_DIR / "analisa_cena_ia.py"
    script_google = BASE_DIR / "processa_imagem.py"
    
    log_lcars(f"Executando análise sensorial por IA na imagem {image_path.name}...", "VISION_AI")
    
    # 1. Análise multimodal via OpenAI / RunPod se configurado
    if os.getenv("JARVIS_VISION_AI_URL"):
        try:
            res = subprocess.run([sys.executable, str(script_analisa), str(image_path)],
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=25)
            log_lcars(f"Resultado IA Multimodal: {res.stdout.strip()}", "TELEMETRY")
            return res.stdout.strip()
        except Exception as e:
            log_lcars(f"Erro na análise multimodal: {e}", "WARN")

    # 2. Análise facial / objetos via Google Cloud Vision se credencial presente
    if os.getenv("GOOGLE_APPLICATION_CREDENTIALS"):
        try:
            res = subprocess.run([sys.executable, str(script_google), str(image_path)],
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=20)
            log_lcars(f"Resultado Google Vision: {res.stdout.strip()}", "TELEMETRY")
            return res.stdout.strip()
        except Exception as e:
            log_lcars(f"Erro no Google Vision: {e}", "WARN")

    log_lcars("Frame óptico capturado e validado. Aguardando disparo de evento de segurança.", "STANDBY")
    return None


def run_daemon_loop(interval_sec: float = 10.0):
    """Continuous LCARS background monitoring loop."""
    log_lcars("=== COMPUTADOR CENTRAL LCARS - SUB-SISTEMA ÓPTICO JARVIS ATIVADO ===", "START")
    
    # Snapshot storage
    snap_dir = BASE_DIR / "snapshots"
    snap_dir.mkdir(exist_ok=True)
    latest_frame = snap_dir / "latest_frame.jpg"
    
    active_cameras = []
    
    while True:
        try:
            # 1. Se não houver câmeras ativas, busca na rede e na serial
            if not active_cameras:
                log_lcars("Procurando nós de câmera na rede local...", "DISCOVERY")
                active_cameras = scan_network_cameras()
                
                # Se não achou na rede, tenta portas seriais
                if not active_cameras:
                    ports = find_serial_ports()
                    for p in ports:
                        log_lcars(f"Inspecionando porta serial {p}...", "PROBE")
                        st = test_serial_camera(p)
                        if st and st.get("ip"):
                            cam_entry = {
                                "ip": st["ip"],
                                "status": "online",
                                "stream": st.get("stream") or f"http://{st['ip']}/stream",
                                "capture": st.get("capture") or f"http://{st['ip']}/capture"
                            }
                            active_cameras.append(cam_entry)
                            register_camera_in_casa(cam_entry)
                        else:
                            # Tenta enviar credenciais Wi-Fi pela serial
                            configure_serial_wifi(p)

            # 2. Se temos câmeras ativas, processa frames
            for cam in active_cameras:
                ip = cam["ip"]
                capture_url = cam.get("capture") or f"http://{ip}/capture"
                log_lcars(f"Capturando frame do sensor óptico [{ip}]...", "CAPTURE")
                if capture_snapshot(capture_url, latest_frame):
                    run_ai_vision_analysis(latest_frame)
                else:
                    log_lcars(f"Câmera [{ip}] não respondeu. Reagendando varredura.", "WARN")
                    active_cameras.remove(cam)

        except Exception as e:
            log_lcars(f"Anomalia no loop do daemon: {e}", "ERRO")

        time.sleep(interval_sec)


def main():
    parser = argparse.ArgumentParser(description="JARVIS ESP32-CAM Zero-Touch Daemon")
    parser.add_argument("--scan", action="store_true", help="Varre portas seriais e rede local em busca da câmera")
    parser.add_argument("--flash", metavar="PORT", help="Grava firmware na porta serial especificada")
    parser.add_argument("--configure-wifi", metavar="PORT", help="Configura credenciais Wi-Fi via comando serial")
    parser.add_argument("--run", action="store_true", help="Executa o daemon em loop contínuo LCARS")
    args = parser.parse_args()

    if args.flash:
        flash_firmware(args.flash)
        return

    if args.configure_wifi:
        configure_serial_wifi(args.configure_wifi)
        return

    if args.scan:
        log_lcars("Iniciando diagnóstico completo de câmeras...", "DIAG")
        ports = find_serial_ports()
        log_lcars(f"Portas seriais detectadas: {ports}", "SERIAL")
        for p in ports:
            st = test_serial_camera(p)
            print("Serial Status:", json.dumps(st, indent=2))
        cams = scan_network_cameras()
        print("Câmeras na rede:", json.dumps(cams, indent=2))
        return

    # Padrão: loop contínuo
    run_daemon_loop()


if __name__ == "__main__":
    main()
