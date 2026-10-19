#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
JARVIS CASA - GRAVAFIRMWARE (Desktop LCARS)
Gravador de Firmware e Gerenciador de Tokens de Seguranca Serial para ESP32-CAM.

Fluxo:
 1. Seleciona a porta COM detectada (FTDI/CH340/ESP32-CAM).
 2. Grava o firmware jarvis_espcam via esptool.
 3. Conecta via Serial (115200 baud) e captura o TOKEN exclusivo da camera (GET_TOKEN).
 4. O token SO E PASSADO PELA SERIAL para garantir total seguranca fisica.
 5. Repassa o token para o Daemon Responsavel (e armazena no cofre local ~/.casa/camera_tokens.json).
"""

import json
import os
import re
import subprocess
import sys
import threading
import time
from pathlib import Path
import tkinter as tk
from tkinter import messagebox, ttk

try:
    import serial
    import serial.tools.list_ports
except ImportError:
    serial = None

try:
    import requests
except ImportError:
    requests = None

# Diretorios e Caminhos
BASE_DIR = Path(__file__).resolve().parent
PROJECT_DIR = BASE_DIR.parents[1]
BUILD_DIR = PROJECT_DIR / "arduino" / "esp32_cam" / "jarvis_espcam" / "build"
MERGED_BIN = BUILD_DIR / "jarvis_espcam.ino.merged.bin"
BIN_FILE = BUILD_DIR / "jarvis_espcam.ino.bin"

ESPTOOL_PATH = os.path.expandvars(r"%USERPROFILE%\AppData\Local\Arduino15\packages\esp32\tools\esptool_py\5.3.1\esptool.exe")
TOKEN_VAULT_DIR = Path(os.path.expanduser("~/.casa"))
TOKEN_VAULT_FILE = TOKEN_VAULT_DIR / "camera_tokens.json"
DAEMON_URL = "https://maurinsoft.com.br/casa/api/camera_discovery.php?acao=register_token"

# Cores Star Trek LCARS
C_BG = "#060913"
C_PANEL = "#0e1626"
C_BORDER = "#1e293b"
C_ORANGE = "#f59e0b"
C_AMBER = "#fbbf24"
C_CYAN = "#38bdf8"
C_GREEN = "#34d399"
C_PURPLE = "#c084fc"
C_TEXT = "#e2e8f0"
C_MUTED = "#94a3b8"
C_RED = "#ef4444"


class GravaFirmwareApp:
    def __init__(self, root):
        self.root = root
        self.root.title("CASA LCARS // GRAVAFIRMWARE & TOKEN MANAGER")
        self.root.geometry("860x680")
        self.root.minsize(780, 600)
        self.root.configure(bg=C_BG)

        self.selected_port = tk.StringVar()
        self.status_var = tk.StringVar(value="SISTEMA PRONTO PARA GRAVAÇÃO")
        self.token_var = tk.StringVar(value="-- NENHUM TOKEN CAPTURADO --")
        self.device_id_var = tk.StringVar(value="--")
        self.mac_var = tk.StringVar(value="--")
        self.ip_var = tk.StringVar(value="--")

        self.setup_ui()
        self.refresh_ports()

    def setup_ui(self):
        # Header LCARS
        header = tk.Frame(self.root, bg=C_BG)
        header.pack(fill="x", padx=16, pady=(14, 8))

        elbow = tk.Label(header, text="LCARS", font=("Impact", 28, "bold"), bg=C_ORANGE, fg="#111", padx=14, pady=4)
        elbow.pack(side="left")

        title_box = tk.Frame(header, bg=C_PURPLE, padx=16, pady=6)
        title_box.pack(side="left", fill="x", expand=True, padx=(8, 0))

        title_lbl = tk.Label(title_box, text="CENTRAL DE GRAVAÇÃO & TOKEN SERIAL // ESP32-CAM", font=("Helvetica", 13, "bold"), bg=C_PURPLE, fg="#111")
        title_lbl.pack(anchor="w")
        sub_lbl = tk.Label(title_box, text="SISTEMA RESIDENCIAL CASA · GESTÃO CRIPTOGRÁFICA DE SENSORES ÓPTICOS", font=("Helvetica", 8), bg=C_PURPLE, fg="#222")
        sub_lbl.pack(anchor="w")

        # Container Principal
        main_frame = tk.Frame(self.root, bg=C_BG)
        main_frame.pack(fill="both", expand=True, padx=16, pady=8)

        # Barra Superior: Selecao de Porta COM e Acoes
        top_bar = tk.LabelFrame(main_frame, text=" 1. CONEXÃO SERIAL & HARDWARE ", font=("Helvetica", 9, "bold"), bg=C_PANEL, fg=C_CYAN, padx=12, pady=10, relief="solid", bd=1)
        top_bar.pack(fill="x", pady=(0, 10))

        port_box = tk.Frame(top_bar, bg=C_PANEL)
        port_box.pack(fill="x")

        tk.Label(port_box, text="Porta Serial COM:", font=("Helvetica", 10, "bold"), bg=C_PANEL, fg=C_TEXT).pack(side="left", padx=(0, 8))
        self.cb_ports = ttk.Combobox(port_box, textvariable=self.selected_port, width=32, state="readonly")
        self.cb_ports.pack(side="left", padx=(0, 10))

        btn_refresh = tk.Button(port_box, text="🔄 Atualizar Portas", font=("Helvetica", 9, "bold"), bg=C_BORDER, fg=C_CYAN, activebackground=C_CYAN, activeforeground="#000", bd=0, padx=10, pady=4, cursor="hand2", command=self.refresh_ports)
        btn_refresh.pack(side="left", padx=4)

        # Botoes de Acao Principal
        actions_bar = tk.Frame(top_bar, bg=C_PANEL)
        actions_bar.pack(fill="x", pady=(10, 0))

        self.btn_flash = tk.Button(actions_bar, text="⚡ GRAVAR FIRMWARE (ESP32-CAM)", font=("Helvetica", 10, "bold"), bg=C_ORANGE, fg="#111", activebackground="#fbbf24", bd=0, padx=14, pady=7, cursor="hand2", command=self.start_flash_thread)
        self.btn_flash.pack(side="left", padx=(0, 8))

        self.btn_token = tk.Button(actions_bar, text="🔑 CAPTURAR TOKEN VIA SERIAL", font=("Helvetica", 10, "bold"), bg=C_CYAN, fg="#111", activebackground="#7dd3fc", bd=0, padx=14, pady=7, cursor="hand2", command=self.start_token_thread)
        self.btn_token.pack(side="left", padx=(0, 8))

        self.btn_send_daemon = tk.Button(actions_bar, text="📡 REPASSAR AO DAEMON", font=("Helvetica", 10, "bold"), bg=C_GREEN, fg="#111", activebackground="#86efac", bd=0, padx=14, pady=7, cursor="hand2", command=self.dispatch_token_to_daemon)
        self.btn_send_daemon.pack(side="left")

        # Painel do Token e Telemetria
        token_panel = tk.LabelFrame(main_frame, text=" 2. TOKEN EXCLUSIVO DE SEGURANÇA (OBTIDO VIA SERIAL) ", font=("Helvetica", 9, "bold"), bg=C_PANEL, fg=C_AMBER, padx=12, pady=10, relief="solid", bd=1)
        token_panel.pack(fill="x", pady=(0, 10))

        # Box de Destaque do Token
        token_display_box = tk.Frame(token_panel, bg="#000", padx=10, pady=8, highlightbackground=C_AMBER, highlightthickness=1)
        token_display_box.pack(fill="x", pady=(0, 8))

        tk.Label(token_display_box, text="CHAVE DA CÂMERA (TOKEN):", font=("Helvetica", 8, "bold"), bg="#000", fg=C_MUTED).pack(anchor="w")
        self.lbl_token = tk.Label(token_display_box, textvariable=self.token_var, font=("Consolas", 14, "bold"), bg="#000", fg=C_AMBER)
        self.lbl_token.pack(anchor="w", pady=2)

        # Meta dados
        meta_box = tk.Frame(token_panel, bg=C_PANEL)
        meta_box.pack(fill="x")

        tk.Label(meta_box, text="Dispositivo:", font=("Helvetica", 9, "bold"), bg=C_PANEL, fg=C_MUTED).grid(row=0, column=0, sticky="w", padx=(0, 4))
        tk.Label(meta_box, textvariable=self.device_id_var, font=("Helvetica", 9, "bold"), bg=C_PANEL, fg=C_TEXT).grid(row=0, column=1, sticky="w", padx=(0, 18))

        tk.Label(meta_box, text="MAC:", font=("Helvetica", 9, "bold"), bg=C_PANEL, fg=C_MUTED).grid(row=0, column=2, sticky="w", padx=(0, 4))
        tk.Label(meta_box, textvariable=self.mac_var, font=("Helvetica", 9, "bold"), bg=C_PANEL, fg=C_TEXT).grid(row=0, column=3, sticky="w", padx=(0, 18))

        tk.Label(meta_box, text="IP Wi-Fi:", font=("Helvetica", 9, "bold"), bg=C_PANEL, fg=C_MUTED).grid(row=0, column=4, sticky="w", padx=(0, 4))
        tk.Label(meta_box, textvariable=self.ip_var, font=("Helvetica", 9, "bold"), bg=C_PANEL, fg=C_GREEN).grid(row=0, column=5, sticky="w")

        # Terminal / Log da Conexão Serial
        log_panel = tk.LabelFrame(main_frame, text=" 3. CONSOLE DE COMUNICAÇÃO SERIAL & GRAVAÇÃO ", font=("Helvetica", 9, "bold"), bg=C_PANEL, fg=C_MUTED, padx=10, pady=8, relief="solid", bd=1)
        log_panel.pack(fill="both", expand=True)

        self.txt_log = tk.Text(log_panel, bg="#050811", fg="#a5f3fc", font=("Consolas", 9), insertbackground="#fff", wrap="word", bd=0, padx=8, pady=8)
        self.txt_log.pack(fill="both", expand=True)

        # Footer / Barra de Status
        footer = tk.Frame(self.root, bg=C_PANEL, height=26, padx=12, pady=4)
        footer.pack(fill="x", side="bottom")

        tk.Label(footer, text="STATUS:", font=("Helvetica", 8, "bold"), bg=C_PANEL, fg=C_ORANGE).pack(side="left", padx=(0, 6))
        tk.Label(footer, textvariable=self.status_var, font=("Helvetica", 8), bg=C_PANEL, fg=C_TEXT).pack(side="left")

    def log(self, text: str, tag: str = "INFO"):
        t_str = time.strftime("%H:%M:%S")
        msg = f"[{t_str}] [{tag}] {text}\n"
        self.txt_log.insert("end", msg)
        self.txt_log.see("end")

    def set_status(self, text: str):
        self.status_var.set(text)

    def refresh_ports(self):
        if not serial:
            self.log("Módulo pyserial não disponível.", "ERRO")
            return
        ports = serial.tools.list_ports.comports()
        port_list = []
        preferred = None
        for p in ports:
            entry = f"{p.device} - {p.description}"
            port_list.append(entry)
            desc = (p.description or "").lower()
            hwid = (p.hwid or "").lower()
            if any(k in desc or k in hwid for k in ["ftdi", "ch340", "cp210", "usb serial", "0403:6001"]):
                if not preferred:
                    preferred = entry

        self.cb_ports["values"] = port_list
        if preferred:
            self.selected_port.set(preferred)
        elif port_list:
            self.selected_port.set(port_list[0])
        else:
            self.selected_port.set("")
        self.log(f"Portas COM detectadas: {len(port_list)}")

    def get_port_name(self) -> str:
        val = self.selected_port.get()
        if not val:
            return ""
        return val.split(" - ")[0].strip()

    def start_flash_thread(self):
        port = self.get_port_name()
        if not port:
            messagebox.showwarning("Porta Serial", "Selecione a porta COM conectada à ESP32-CAM.")
            return

        confirm = messagebox.askyesno(
            "Gravação do Firmware",
            f"Deseja iniciar a gravação na porta {port}?\n\n"
            "LEMBRE-SE: Para gravação, conecte o jumper IO0 ao GND e aperte o botão RESET na placa antes de iniciar."
        )
        if not confirm:
            return

        threading.Thread(target=self._run_flash, args=(port,), daemon=True).start()

    def _run_flash(self, port: str):
        self.btn_flash.config(state="disabled")
        self.btn_token.config(state="disabled")
        self.set_status("GRAVANDO FIRMWARE VIA ESPTOOL...")
        self.log(f"Iniciando gravação de firmware na porta {port}...", "FLASH")

        bin_to_flash = MERGED_BIN if MERGED_BIN.exists() else BIN_FILE
        if not bin_to_flash.exists():
            self.log(f"Arquivo binário não encontrado em {bin_to_flash}", "ERRO")
            self.set_status("FALHA: BINÁRIO NÃO ENCONTRADO")
            self.btn_flash.config(state="normal")
            self.btn_token.config(state="normal")
            return

        addr = "0x0" if bin_to_flash == MERGED_BIN else "0x10000"
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
            addr, str(bin_to_flash)
        ]

        self.log(f"Executando esptool: {' '.join(cmd)}", "EXEC")
        try:
            p = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)
            for line in iter(p.stdout.readline, ""):
                line_clean = line.strip()
                if line_clean:
                    self.log(line_clean, "ESPTOOL")
            p.stdout.close()
            p.wait()

            if p.returncode == 0:
                self.log("GRAVAÇÃO CONCLUÍDA COM 100% DE SUCESSO!", "OK")
                self.set_status("GRAVAÇÃO CONCLUÍDA COM SUCESSO")
                messagebox.showinfo(
                    "Gravação Concluída",
                    "Firmware gravado com sucesso!\n\n"
                    "PRÓXIMO PASSO:\n"
                    "1. Remova o jumper IO0 do GND.\n"
                    "2. Aperte o botão RESET na placa.\n"
                    "3. Clique em 'CAPTURAR TOKEN VIA SERIAL' para obter a chave física."
                )
            else:
                self.log(f"Falha na gravação do firmware (código {p.returncode})", "ERRO")
                self.set_status(f"ERRO DE GRAVAÇÃO ({p.returncode})")
        except Exception as e:
            self.log(f"Exceção durante a gravação: {e}", "ERRO")
            self.set_status("ERRO AO EXECUTAR ESPTOOL")
        finally:
            self.btn_flash.config(state="normal")
            self.btn_token.config(state="normal")

    def start_token_thread(self):
        port = self.get_port_name()
        if not port:
            messagebox.showwarning("Porta Serial", "Selecione a porta COM da câmera.")
            return

        threading.Thread(target=self._run_token_capture, args=(port,), daemon=True).start()

    def _run_token_capture(self, port: str):
        self.btn_token.config(state="disabled")
        self.set_status("CONECTANDO À SERIAL E SOLICITANDO TOKEN...")
        self.log(f"Abrindo porta {port} a 115200 baud para leitura do token físico...", "SERIAL")

        try:
            ser = serial.Serial(port, 115200, timeout=1.0)
            time.sleep(0.5)
            ser.reset_input_buffer()

            # Envia comando de handshake serial para pedir o token
            self.log("Enviando comando: GET_TOKEN", "TX")
            ser.write(b"GET_TOKEN\n")

            token_found = False
            t0 = time.time()
            while time.time() - t0 < 6.0:
                raw_line = ser.readline()
                if raw_line:
                    line = raw_line.decode("utf-8", errors="replace").strip()
                    if line:
                        self.log(f"RX: {line}", "SERIAL")

                    # Reconhece resposta: CAM_SECRET:DEVICE_ID=...:MAC=...:TOKEN=...:IP=...
                    if "CAM_SECRET" in line or "TOKEN=" in line:
                        tok_m = re.search(r"TOKEN=([0-9a-fA-F]+)", line)
                        did_m = re.search(r"DEVICE_ID=([a-zA-Z0-9_-]+)", line)
                        mac_m = re.search(r"MAC=([0-9a-fA-F:]+)", line)
                        ip_m = re.search(r"IP=([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)", line)

                        if tok_m:
                            token_val = tok_m.group(1)
                            did_val = did_m.group(1) if did_m else "esp32cam"
                            mac_val = mac_m.group(1) if mac_m else "--"
                            ip_val = ip_m.group(1) if ip_m else "--"

                            self.token_var.set(token_val)
                            self.device_id_var.set(did_val)
                            self.mac_var.set(mac_val)
                            self.ip_var.set(ip_val)

                            self.log(f"TOKEN CAPTURADO COM SUCESSO: {token_val}", "SUCESSO")
                            self.set_status("TOKEN FÍSICO CAPTURADO COM SUCESSO")
                            token_found = True

                            # Salva imediatamente no cofre local ~/.casa/camera_tokens.json
                            self.save_token_to_vault(did_val, token_val, mac_val, ip_val)
                            # Repassa ao daemon automaticamente
                            self.dispatch_token_to_daemon(did_val, token_val, mac_val, ip_val)
                            break

                # Envia retry apos 2 segundos se nao respondeu
                if time.time() - t0 > 2.0 and not token_found:
                    ser.write(b"GET_TOKEN\n")
                    time.sleep(0.2)

            ser.close()
            if not token_found:
                self.log("Nenhum token recebido. Verifique se o jumper IO0 foi desconectado e o botão RESET foi pressionado.", "AVISO")
                self.set_status("AGUARDANDO REINICIALIZAÇÃO DO ESP32")
                messagebox.showwarning(
                    "Token Não Recebido",
                    "A câmera não respondeu ao comando de token na Serial.\n\n"
                    "Certifique-se de que:\n"
                    "1. O pino IO0 NÃO está aterrado (remova o jumper).\n"
                    "2. Você apertou o botão RESET (ou desconectou e reconectou o USB)."
                )

        except Exception as e:
            self.log(f"Falha de comunicação serial em {port}: {e}", "ERRO")
            self.set_status("ERRO SERIAL")
        finally:
            self.btn_token.config(state="normal")

    def save_token_to_vault(self, device_id: str, token: str, mac: str, ip: str):
        try:
            TOKEN_VAULT_DIR.mkdir(parents=True, exist_ok=True)
            data = {}
            if TOKEN_VAULT_FILE.exists():
                try:
                    data = json.loads(TOKEN_VAULT_FILE.read_text(encoding="utf-8"))
                except Exception:
                    data = {}

            data[device_id] = {
                "device_id": device_id,
                "token": token,
                "mac": mac,
                "ip": ip,
                "saved_at": time.strftime("%Y-%m-%dT%H:%M:%SZ")
            }

            TOKEN_VAULT_FILE.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
            self.log(f"Token gravado com segurança no cofre: {TOKEN_VAULT_FILE}", "COFRE")
        except Exception as e:
            self.log(f"Erro ao salvar no cofre local: {e}", "ERRO")

    def dispatch_token_to_daemon(self, did: str = None, token: str = None, mac: str = None, ip: str = None):
        if not did:
            did = self.device_id_var.get()
        if not token:
            token = self.token_var.get()
        if not mac:
            mac = self.mac_var.get()
        if not ip:
            ip = self.ip_var.get()

        if token.startswith("--") or not token:
            messagebox.showwarning("Token Ausente", "Capture primeiro o token via serial antes de enviar ao daemon.")
            return

        payload = {
            "device_id": did,
            "token": token,
            "mac": mac,
            "ip": ip
        }

        self.log(f"Repassando chave criptográfica do nó [{did}] ao Daemon Responsável...", "DISPATCH")
        dispatched = False

        if requests:
            try:
                r = requests.post(DAEMON_URL, json=payload, timeout=2.0)
                if r.status_code == 200:
                    self.log(f"Daemon confirmou recepção da chave! Resposta: {r.text}", "DAEMON_OK")
                    dispatched = True
            except Exception as e:
                self.log(f"Daemon local (porta 8088) ainda não está ativo no host: {e}", "INFO")

        # Salva sempre no cofre
        self.save_token_to_vault(did, token, mac, ip)

        if dispatched:
            messagebox.showinfo("Sucesso", f"Token da câmera [{did}] repassado com sucesso ao Daemon Responsável!")
        else:
            self.log("Token persistido no cofre da casa (~/.casa/camera_tokens.json). O Daemon único carregará automaticamente.", "INFO")
            messagebox.showinfo(
                "Token Armazenado no Cofre",
                f"Chave da câmera [{did}] gravada com segurança no cofre da residência!\n\n"
                f"Arquivo: {TOKEN_VAULT_FILE}\n\n"
                "O Daemon Responsável das câmeras carregará esta chave automaticamente."
            )


def main():
    root = tk.Tk()
    app = GravaFirmwareApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
