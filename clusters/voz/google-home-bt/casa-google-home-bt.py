#!/usr/bin/env python3
"""CASA / JARVIS - Daemon Ponte Bluetooth Bidirecional com Google Home / Google Assistant.

Objetivos:
1. Conectar via Bluetooth (BlueZ / A2DP) ao Google Home / Nest.
2. Falar com o Google Home: sintetiza comandos de voz (ex: "Ok Google, ...")
   usando o daemon Pocket-TTS (:8093) ou Piper TTS (:8097) e transmite pelo áudio Bluetooth.
3. Ouvir e ler o que o Google Home fala: captura o áudio emitido pelo Google Home,
   transcreve localmente com o daemon Parakeet STT (:8092).
4. Orquestração com IA: integra o diálogo com o Orquestrador IA (:8090) e os demais daemons
   do ecossistema residencial.
"""

import argparse
import base64
import concurrent.futures
import http.server
import json
import logging
import os
import re
import socket
import socketserver
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import wave

VERSAO = "1.0.0"
PORTA_PADRAO = 8094
MAC_REGEX = re.compile(r"^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] [GOOGLE-HOME-BT] %(message)s"
)
LOG = logging.getLogger("casa-google-home-bt")


def carregar_env(caminho):
    """Carrega variáveis de ambiente de um arquivo .env."""
    if not caminho or not os.path.exists(caminho):
        return
    try:
        with open(caminho, "r", encoding="utf-8") as f:
            for linha in f:
                linha = linha.strip()
                if linha and not linha.startswith("#") and "=" in linha:
                    k, v = linha.split("=", 1)
                    os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))
    except Exception as exc:
        LOG.warning("Falha ao ler env %s: %s", caminho, exc)


for env_path in ["/etc/casa-google-home-bt.env", "/etc/casa-orquestrador.env", "/etc/casa-node-agent.env"]:
    carregar_env(env_path)

os.environ.setdefault("XDG_RUNTIME_DIR", "/run/user/1000")
os.environ.setdefault("PULSE_SERVER", "unix:/run/user/1000/pulse/native")


class Config:
    def __init__(self):
        self.host = os.getenv("CASA_GH_BT_HOST", "0.0.0.0")
        self.port = int(os.getenv("CASA_GH_BT_PORT", str(PORTA_PADRAO)))
        self.target_mac = os.getenv("CASA_GH_BT_TARGET_MAC", "").strip().upper()
        self.target_name = os.getenv("CASA_GH_BT_TARGET_NAME", "Google Home").strip()

        # URLs dos daemons especializados
        self.stt_url = os.getenv("CASA_PARAKEET_URL", "http://127.0.0.1:8092").rstrip("/")
        self.tts_url = os.getenv("CASA_POCKET_TTS_URL", "http://127.0.0.1:8093").rstrip("/")
        self.orquestrador_url = os.getenv("CASA_ORQUESTRADOR_URL", "http://127.0.0.1:8090").rstrip("/")
        self.base_url = os.getenv("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
        self.device_token = os.getenv("DEVICE_TOKEN", os.getenv("JARVIS_DEVICE_TOKEN", "")).strip()
        self.scan_interval = int(os.getenv("CASA_GH_BT_SCAN_INTERVAL", "25"))

        # Configurações de áudio PulseAudio / ALSA
        self.audio_temp_dir = os.getenv("CASA_GH_BT_TEMP_DIR", "/tmp/casa-gh-bt")
        self.silencio_limiar = int(os.getenv("CASA_GH_BT_SILENCE_THRESHOLD", "400"))
        self.tempo_escuta_padrao = int(os.getenv("CASA_GH_BT_LISTEN_SEC", "6"))
        self.auto_reconectar = os.getenv("CASA_GH_BT_AUTO_RECONNECT", "1") == "1"


class BluetoothGoogleHomeBridge:
    """Gerenciador da ponte Bluetooth bidirecional com Google Home."""

    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.lock = threading.Lock()
        self.conectado = False
        self.mac_ativo = self.cfg.target_mac
        self.nome_ativo = self.cfg.target_name
        self.sink_ativo = None
        self.source_ativo = None
        self.historico_dialogos = []
        self.dispositivos_descobertos = []
        self.wifi_descobertos = []

        self.stats = {
            "total_falas_enviadas": 0,
            "total_audios_ouvidos": 0,
            "total_transcricoes": 0,
            "total_dialogos": 0,
            "ultimo_dialogo": None,
            "status_conexao": "desconectado"
        }

        os.makedirs(self.cfg.audio_temp_dir, exist_ok=True)

    def _exec_cmd(self, cmd_args, timeout=10):
        """Executa comandos seguros sem shell."""
        try:
            p = subprocess.run(cmd_args, capture_output=True, text=True, timeout=timeout)
            return p.returncode, p.stdout.strip(), p.stderr.strip()
        except Exception as exc:
            return -1, "", str(exc)

    def escanear_dispositivos(self, timeout=6):
        """Varre dispositivos Bluetooth próximos com foco em caixas Google Home/Nest."""
        # Dispara escaneamento rápido via bluetoothctl
        self._exec_cmd(["bluetoothctl", "--timeout", str(timeout), "scan", "on"], timeout=timeout + 2)
        code, out, _ = self._exec_cmd(["bluetoothctl", "devices"])

        encontrados = []
        if code == 0:
            for linha in out.splitlines():
                partes = linha.split(" ", 2)
                if len(partes) >= 3 and partes[0].lower() == "device":
                    mac = partes[1].upper()
                    nome = partes[2].strip()
                    eh_google = any(g in nome.lower() for g in ["google", "nest", "home", "assistant"])
                    encontrados.append({
                        "mac": mac,
                        "nome": nome,
                        "google_home_provavel": eh_google
                    })
        self.dispositivos_descobertos = encontrados
        return encontrados

    def loop_escanear_bluetooth_periodico(self, parar_evento, intervalo=25):
        """Escaneia periodicamente o ambiente em busca de caixas Google Home e sincroniza com o servidor central."""
        LOG.info("Iniciando escaneamento periódico de dispositivos Bluetooth a cada %ds...", intervalo)
        while not parar_evento.is_set():
            try:
                encontrados = self.escanear_dispositivos(timeout=4)
                if encontrados:
                    LOG.info("Varredura Bluetooth periódica: %d dispositivo(s) detectado(s)", len(encontrados))
                    if not self.conectado:
                        for d in encontrados:
                            if d.get("google_home_provavel") or (self.cfg.target_mac and d.get("mac") == self.cfg.target_mac):
                                LOG.info("Auto-conectando à caixa Google Home detectada: %s (%s)", d.get("nome"), d.get("mac"))
                                self.conectar(d.get("mac"))
                                break

                # Sincroniza status e dispositivos Bluetooth descobertos com o servidor central CASA
                if self.cfg.base_url and self.cfg.device_token:
                    try:
                        url_hb = f"{self.cfg.base_url}/api/google_home.php"
                        payload = json.dumps({
                            "acao": "heartbeat",
                            "trabalhador": "casa-google-home-bt",
                            "versao": VERSAO,
                            "conectado": self.conectado,
                            "mac_alvo": self.mac_ativo,
                            "dispositivos": self.dispositivos_descobertos,
                            "dispositivos_wifi": self.wifi_descobertos
                        }).encode("utf-8")
                        req_hb = urllib.request.Request(
                            url_hb,
                            data=payload,
                            headers={
                                "Content-Type": "application/json",
                                "X-Device-Token": self.cfg.device_token
                            },
                            method="POST"
                        )
                        with urllib.request.urlopen(req_hb, timeout=8) as resp_hb:
                            LOG.debug("Heartbeat Bluetooth sincronizado com o servidor central: status %s", resp_hb.status)
                    except Exception as exc_hb:
                        LOG.debug("Aviso de sincronização com servidor central: %s", exc_hb)
            except Exception as exc:
                LOG.warning("Erro no escaneamento periódico Bluetooth: %s", exc)
            parar_evento.wait(intervalo)

    def conectar(self, mac=None):
        """Emparelha, confia e conecta a uma caixa Google Home via Bluetooth A2DP."""
        alvo = (mac or self.cfg.target_mac or self.mac_ativo).strip().upper()
        if not alvo or not MAC_REGEX.match(alvo):
            return {"ok": False, "erro": "Endereço MAC Bluetooth inválido."}

        with self.lock:
            LOG.info("Iniciando conexão Bluetooth com %s...", alvo)
            # 1. Confiar no dispositivo
            self._exec_cmd(["bluetoothctl", "trust", alvo], timeout=5)
            # 2. Parear (se ainda não pareado)
            self._exec_cmd(["bluetoothctl", "pair", alvo], timeout=10)
            # 3. Conectar
            code, out, err = self._exec_cmd(["bluetoothctl", "connect", alvo], timeout=12)

            conectou = "Connection successful" in out or code == 0
            if conectou:
                self.conectado = True
                self.mac_ativo = alvo
                self.stats["status_conexao"] = "conectado"
                LOG.info("Conexão Bluetooth estabelecida com sucesso: %s", alvo)
                self._atualizar_sinks_pulseaudio()
                return {"ok": True, "mac": alvo, "status": "conectado", "saida": out}

            self.conectado = False
            self.stats["status_conexao"] = "falha"
            LOG.warning("Falha ao conectar Bluetooth a %s: %s %s", alvo, out, err)
            return {"ok": False, "mac": alvo, "status": "erro", "erro": err or out}

    def desconectar(self, mac=None):
        """Desconecta a conexão Bluetooth."""
        alvo = (mac or self.mac_ativo).strip().upper()
        if not alvo:
            return {"ok": True, "status": "desconectado"}

        with self.lock:
            code, out, err = self._exec_cmd(["bluetoothctl", "disconnect", alvo], timeout=6)
            self.conectado = False
            self.stats["status_conexao"] = "desconectado"
            return {"ok": code == 0, "mac": alvo, "saida": out}

    def _atualizar_sinks_pulseaudio(self):
        """Descobre o sink e o source do PulseAudio associados ao dispositivo Bluetooth."""
        code, out, _ = self._exec_cmd(["pactl", "list", "sinks", "short"])
        if code == 0:
            mac_clean = self.mac_ativo.replace(":", "_").lower()
            for line in out.splitlines():
                if "bluez_sink" in line and mac_clean in line.lower():
                    partes = line.split()
                    if len(partes) >= 2:
                        self.sink_ativo = partes[1]
                        LOG.info("PulseAudio Sink Bluetooth detectado: %s", self.sink_ativo)

        code_src, out_src, _ = self._exec_cmd(["pactl", "list", "sources", "short"])
        if code_src == 0:
            mac_clean = self.mac_ativo.replace(":", "_").lower()
            for line in out_src.splitlines():
                if "bluez_source" in line and mac_clean in line.lower():
                    partes = line.split()
                    if len(partes) >= 2:
                        self.source_ativo = partes[1]
                        LOG.info("PulseAudio Source Bluetooth detectado: %s", self.source_ativo)

    def _sincronizar_registro_central(self, mac=None, nome=None, tipo="bluetooth", ip=None, modelo=None):
        """Envia cadastro imediato do dispositivo descoberto para o servidor central CASA."""
        if not self.cfg.base_url or not self.cfg.device_token:
            return False
        try:
            url = f"{self.cfg.base_url}/api/google_home.php"
            payload = json.dumps({
                "acao": "cadastrar",
                "nome": nome or "Google Home",
                "tipo_conexao": tipo,
                "ip_address": ip or "",
                "mac_address": mac or "",
                "localizacao": "Residência",
                "modelo": modelo or "Google Home"
            }).encode("utf-8")
            req = urllib.request.Request(
                url,
                data=payload,
                headers={
                    "Content-Type": "application/json",
                    "X-Device-Token": self.cfg.device_token
                },
                method="POST"
            )
            with urllib.request.urlopen(req, timeout=8) as resp:
                LOG.info("Dispositivo %s registrado no servidor central (HTTP %s)", nome, resp.status)
                return True
        except Exception as exc:
            LOG.warning("Falha ao registrar %s no servidor central: %s", nome, exc)
            return False

    def parear_dispositivo_automatico(self, timeout=8):
        """Varre o ambiente e conecta automaticamente à primeira caixa Google Home em modo de pareamento."""
        LOG.info("Iniciando busca ativa para pareamento automático Bluetooth (timeout=%ds)...", timeout)
        encontrados = self.escanear_dispositivos(timeout=timeout)

        # 1. Filtra candidatos prováveis Google Home / Nest
        candidato = None
        for d in encontrados:
            if d.get("google_home_provavel"):
                candidato = d
                break

        # 2. Se não achou com nome explícito, busca outros dispositivos de áudio recém-anunciados
        if not candidato and encontrados:
            for d in encontrados:
                nome = d.get("nome", "").lower()
                if not any(x in nome for x in ["peso", "keyboard", "mouse", "tag", "ibeacon"]):
                    candidato = d
                    break
            if not candidato:
                candidato = encontrados[0]

        if not candidato:
            return {
                "ok": False,
                "erro": "Nenhum dispositivo Bluetooth detectado. Diga 'Ok Google, ativar Bluetooth' para o Google Home e tente novamente."
            }

        mac = candidato.get("mac")
        nome = candidato.get("nome") or "Google Home"
        LOG.info("Dispositivo candidato selecionado para pareamento automático: %s (%s)", nome, mac)

        res_conn = self.conectar(mac)
        if not res_conn.get("ok"):
            return {
                "ok": False,
                "mac": mac,
                "nome": nome,
                "erro": f"Dispositivo detectado ({nome}), mas o pareamento falhou: {res_conn.get('erro')}"
            }

        # Sincroniza e registra no servidor central CASA
        self._sincronizar_registro_central(mac=mac, nome=nome, tipo="bluetooth")

        return {
            "ok": True,
            "mac": mac,
            "nome": nome,
            "tipo_conexao": "bluetooth",
            "status": "online",
            "mensagem": f"Google Home '{nome}' ({mac}) pareado e conectado com sucesso via Bluetooth!"
        }

    def varrer_wifi_google_cast(self, subnet="192.168.2", ip_especifico=None, timeout=0.35):
        """Varre a sub-rede Wi-Fi em busca de portas e serviços Google Cast (8008/8009)."""
        LOG.info("Iniciando varredura Wi-Fi para serviços Google Cast na rede %s...", ip_especifico or subnet)
        encontrados = []

        def test_ip(ip):
            # 1. Testa porta 8008 (eureka_info HTTP)
            try:
                with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
                    s.settimeout(timeout)
                    if s.connect_ex((ip, 8008)) == 0:
                        try:
                            url = f"http://{ip}:8008/setup/eureka_info"
                            req = urllib.request.Request(url, headers={"User-Agent": "curl/7.68.0"})
                            with urllib.request.urlopen(req, timeout=1.5) as resp:
                                data = json.loads(resp.read().decode("utf-8"))
                                nome = data.get("name") or data.get("setup", {}).get("device_name") or f"Google Cast ({ip})"
                                info = data.get("device_info", {})
                                mac = info.get("mac_address")
                                model = info.get("model_name") or "Google Cast / Nest"
                                return {
                                    "ip": ip,
                                    "nome": nome,
                                    "mac": mac,
                                    "modelo": model,
                                    "porta": 8008,
                                    "tipo_conexao": "wifi"
                                }
                        except Exception:
                            return {
                                "ip": ip,
                                "nome": f"Google Cast ({ip})",
                                "mac": None,
                                "modelo": "Google Cast Device",
                                "porta": 8008,
                                "tipo_conexao": "wifi"
                            }
            except Exception:
                pass

            # 2. Testa porta 8009 (Cast V2 TLS)
            try:
                with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
                    s.settimeout(timeout)
                    if s.connect_ex((ip, 8009)) == 0:
                        return {
                            "ip": ip,
                            "nome": f"Google Cast ({ip})",
                            "mac": None,
                            "modelo": "Google Cast / Nest Mini",
                            "porta": 8009,
                            "tipo_conexao": "wifi"
                        }
            except Exception:
                pass

            return None

        if ip_especifico and ip_especifico.strip():
            item = test_ip(ip_especifico.strip())
            if item:
                encontrados.append(item)
        else:
            sub = subnet.strip().rstrip(".")
            ips = [f"{sub}.{i}" for i in range(1, 255)]
            with concurrent.futures.ThreadPoolExecutor(max_workers=60) as executor:
                res = list(filter(None, executor.map(test_ip, ips)))
                encontrados.extend(res)

        if not encontrados:
            return {
                "ok": False,
                "erro": f"Nenhuma caixa Google Cast encontrada na rede ({ip_especifico or subnet}). Verifique se a caixinha está ligada ao Wi-Fi."
            }

        self.wifi_descobertos = encontrados
        # Registra cada dispositivo encontrado no servidor central
        for dev in encontrados:
            self._sincronizar_registro_central(
                mac=dev.get("mac"),
                nome=dev.get("nome"),
                tipo="wifi",
                ip=dev.get("ip"),
                modelo=dev.get("modelo")
            )

        primeiro = encontrados[0]
        return {
            "ok": True,
            "dispositivos": encontrados,
            "primeiro": primeiro,
            "ip": primeiro.get("ip"),
            "nome": primeiro.get("nome"),
            "mac": primeiro.get("mac"),
            "modelo": primeiro.get("modelo"),
            "tipo_conexao": "wifi",
            "mensagem": f"Google Home '{primeiro.get('nome')}' detectado com sucesso no IP {primeiro.get('ip')}!"
        }

    def loop_verificar_comandos_central(self, parar_evento: threading.Event):
        """Consulta periodicamente se há comandos de busca/pareamento disparados pelo site."""
        if not self.cfg.base_url or not self.cfg.device_token:
            return
        LOG.info("Iniciando monitoramento de comandos de busca do site em %s...", self.cfg.base_url)
        while not parar_evento.is_set():
            try:
                url = f"{self.cfg.base_url}/api/google_home.php?acao=solicitacoes_daemon"
                req = urllib.request.Request(url, headers={"X-Device-Token": self.cfg.device_token})
                with urllib.request.urlopen(req, timeout=5) as resp:
                    data = json.loads(resp.read().decode("utf-8"))
                    sol = data.get("solicitacao")

                if sol and sol.get("id"):
                    sol_id = sol.get("id")
                    tipo_busca = sol.get("tipo", "bluetooth")
                    LOG.info("Solicitação de busca recebida do site: ID=%s (tipo=%s)", sol_id, tipo_busca)

                    if tipo_busca == "bluetooth":
                        resultado = self.parear_dispositivo_automatico(timeout=8)
                    elif tipo_busca == "wifi":
                        subnet = sol.get("subnet") or "192.168.2"
                        ip_esp = sol.get("ip_especifico")
                        resultado = self.varrer_wifi_google_cast(subnet=subnet, ip_especifico=ip_esp)
                    else:
                        resultado = {"ok": False, "erro": f"Tipo de busca desconhecido: {tipo_busca}"}

                    # Devolve o resultado da busca para o site
                    url_conc = f"{self.cfg.base_url}/api/google_home.php"
                    payload_conc = json.dumps({
                        "acao": "concluir_busca_daemon",
                        "id": sol_id,
                        "resultado": resultado
                    }).encode("utf-8")
                    req_conc = urllib.request.Request(
                        url_conc,
                        data=payload_conc,
                        headers={
                            "Content-Type": "application/json",
                            "X-Device-Token": self.cfg.device_token
                        },
                        method="POST"
                    )
                    with urllib.request.urlopen(req_conc, timeout=8) as resp_c:
                        LOG.info("Resultado da busca #%s enviado ao site: HTTP %s", sol_id, resp_c.status)
            except Exception:
                pass
            parar_evento.wait(2.0)

    def falar_com_google(self, texto_comando, prefixar_wake_word=True):
        """Gera comando de voz sintetizado (Pocket-TTS) e reproduz no canal Bluetooth do Google Home."""
        texto = texto_comando.strip()
        if not texto:
            return {"ok": False, "erro": "Texto do comando vazio"}

        # Assegura que o comando acorde o assistente caso o usuário não tenha colocado "Ok Google"
        if prefixar_wake_word and not any(texto.lower().startswith(p) for p in ["ok google", "hey google", "google"]):
            texto_falado = f"Ok Google, {texto}"
        else:
            texto_falado = texto

        with self.lock:
            self.stats["total_falas_enviadas"] += 1

        LOG.info("Sintetizando fala para Google Home: '%s'", texto_falado)

        # 1. Chamar o daemon Pocket-TTS (:8093) para sintetizar áudio WAV
        wav_path = None
        remover_temp = False
        try:
            req = urllib.request.Request(
                f"{self.cfg.tts_url}/synthesize",
                data=json.dumps({"texto": texto_falado, "reproduzir": False}).encode("utf-8"),
                headers={"Content-Type": "application/json"},
                method="POST"
            )
            with urllib.request.urlopen(req, timeout=15) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                filepath = data.get("filepath")
                audio_url = data.get("audio_url")
                audio_b64 = data.get("audio_base64", "")
        except Exception as exc:
            LOG.error("Falha ao chamar Pocket-TTS: %s", exc)
            return {"ok": False, "erro": f"Erro Pocket-TTS: {exc}"}

        if filepath and os.path.exists(filepath):
            wav_path = filepath
        elif audio_url:
            remover_temp = True
            wav_path = os.path.join(self.cfg.audio_temp_dir, f"comando_gh_{int(time.time()*1000)}.wav")
            try:
                urllib.request.urlretrieve(f"{self.cfg.tts_url}{audio_url}", wav_path)
            except Exception as e:
                return {"ok": False, "erro": f"Falha ao baixar áudio Pocket-TTS: {e}"}
        elif audio_b64:
            remover_temp = True
            wav_path = os.path.join(self.cfg.audio_temp_dir, f"comando_gh_{int(time.time()*1000)}.wav")
            try:
                with open(wav_path, "wb") as f:
                    f.write(base64.b64decode(audio_b64))
            except Exception as exc:
                return {"ok": False, "erro": f"Falha ao salvar WAV temporário: {exc}"}
        else:
            return {"ok": False, "erro": "Pocket-TTS não retornou áudio"}

        # 2. Reproduzir o áudio no sink Bluetooth ou padrão via paplay
        cmd_play = ["paplay"]
        if self.sink_ativo:
            cmd_play.extend(["--device", self.sink_ativo])
        cmd_play.append(wav_path)

        play_code, _, play_err = self._exec_cmd(cmd_play, timeout=12)

        # Limpeza do arquivo temporário se criado por este método
        if remover_temp:
            try:
                if os.path.exists(wav_path):
                    os.remove(wav_path)
            except Exception:
                pass

        return {
            "ok": play_code == 0,
            "texto_enviado": texto_falado,
            "sink_utilizado": self.sink_ativo or "padrao_pulseaudio",
            "erro": play_err if play_code != 0 else None
        }

    def ouvir_resposta_google(self, duracao_segundos=None):
        """Captura o áudio emitido pelo Google Home e transcreve via Parakeet STT (:8092)."""
        duracao = max(2, min(20, int(duracao_segundos or self.cfg.tempo_escuta_padrao)))
        timestamp = int(time.time() * 1000)
        gravacao_path = os.path.join(self.cfg.audio_temp_dir, f"resposta_gh_{timestamp}.wav")

        LOG.info("Ouvindo resposta do Google Home por %d segundos...", duracao)

        # Grava áudio via parec / parecord a 16000 Hz mono (ideal para o modelo Parakeet)
        cmd_rec = [
            "parec",
            "--format=s16le",
            "--rate=16000",
            "--channels=1",
            "--file-format=wav"
        ]
        if self.source_ativo:
            cmd_rec.extend(["--device", self.source_ativo])

        try:
            with open(gravacao_path, "wb") as f_out:
                p = subprocess.Popen(cmd_rec, stdout=f_out, stderr=subprocess.PIPE)
                time.sleep(duracao)
                p.terminate()
                p.wait(timeout=2)
        except Exception as exc:
            LOG.error("Falha ao gravar resposta de áudio: %s", exc)
            return {"ok": False, "erro": str(exc), "transcricao": ""}

        with self.lock:
            self.stats["total_audios_ouvidos"] += 1

        # Transcrever o áudio gravado no Parakeet STT (:8092)
        transcricao = ""
        try:
            if os.path.exists(gravacao_path) and os.path.getsize(gravacao_path) > 1024:
                with open(gravacao_path, "rb") as f_in:
                    audio_bytes = f_in.read()

                req_stt = urllib.request.Request(
                    f"{self.cfg.stt_url}/transcribe",
                    data=audio_bytes,
                    headers={"Content-Type": "audio/wav", "User-Agent": "casa-gh-bt/1.0"},
                    method="POST"
                )
                with urllib.request.urlopen(req_stt, timeout=20) as r:
                    stt_data = json.loads(r.read().decode("utf-8"))
                    transcricao = stt_data.get("transcricao", "").strip()

                with self.lock:
                    self.stats["total_transcricoes"] += 1
        except Exception as exc:
            LOG.warning("Falha ao transcrever resposta do Google com Parakeet: %s", exc)
        finally:
            try:
                if os.path.exists(gravacao_path):
                    os.remove(gravacao_path)
            except Exception:
                pass

        return {
            "ok": True,
            "duracao_gravacao_s": duracao,
            "transcricao": transcricao,
            "source_utilizado": self.source_ativo or "padrao_pulseaudio"
        }

    def conversar_com_google(self, comando_pergunta):
        """Ciclo completo: Fala com o Google, ouve a resposta, transcreve e integra com a IA."""
        with self.lock:
            self.stats["total_dialogos"] += 1

        LOG.info("Iniciando diálogo bidirecional com Google Home: '%s'", comando_pergunta)

        # 1. Enviar comando falado ao Google
        envio = self.falar_com_google(comando_pergunta, prefixar_wake_word=True)
        if not envio.get("ok"):
            return {"ok": False, "etapa": "fala", "erro": envio.get("erro")}

        # Pausa breve para o Google Assistant iniciar a resposta
        time.sleep(1.2)

        # 2. Ouvir e transcrever a resposta do Google
        captura = self.ouvir_resposta_google(duracao_segundos=self.cfg.tempo_escuta_padrao)
        fala_google = captura.get("transcricao", "")

        # 3. Notificar o Orquestrador IA da residência (:8090)
        orquestrador_resposta = None
        if fala_google:
            try:
                req_orq = urllib.request.Request(
                    f"{self.cfg.orquestrador_url}/api/v1/perguntar",
                    data=json.dumps({"pergunta": f"O Google Home respondeu: {fala_google}", "origem": "google_home_bt"}).encode("utf-8"),
                    headers={"Content-Type": "application/json"},
                    method="POST"
                )
                with urllib.request.urlopen(req_orq, timeout=15) as resp:
                    orquestrador_resposta = json.loads(resp.read().decode("utf-8"))
            except Exception as exc:
                LOG.warning("Aviso ao notificar orquestrador IA: %s", exc)

        registro_dialogo = {
            "timestamp": time.time(),
            "comando_enviado": envio.get("texto_enviado"),
            "resposta_google": fala_google,
            "orquestrador_analise": orquestrador_resposta
        }

        with self.lock:
            self.historico_dialogos.append(registro_dialogo)
            if len(self.historico_dialogos) > 50:
                self.historico_dialogos.pop(0)
            self.stats["ultimo_dialogo"] = registro_dialogo

        return {
            "ok": True,
            "comando_falado": envio.get("texto_enviado"),
            "resposta_google": fala_google,
            "orquestrador_ia": orquestrador_resposta
        }

    def loop_verificar_solicitacoes(self, parar_evento: threading.Event):
        """Worker contínuo: consulta solicitações pendentes para este daemon no Despachante Central."""
        LOG.info("Iniciando monitoramento de solicitações no Despachante Central (%s)...", self.cfg.orquestrador_url)
        while not parar_evento.is_set():
            try:
                url_pendentes = f"{self.cfg.orquestrador_url}/api/v1/solicitacoes/pendentes?daemon=google-home-bt"
                req = urllib.request.Request(url_pendentes, headers={"User-Agent": "casa-gh-bt/1.0"})
                with urllib.request.urlopen(req, timeout=5) as resp:
                    data = json.loads(resp.read().decode("utf-8"))
                    solicitacoes = data.get("solicitacoes", [])

                for sol in solicitacoes:
                    sol_id = sol.get("id")
                    acao = sol.get("acao", "")
                    payload = sol.get("payload", {})
                    LOG.info("Solicitação recebida do Despachante Central: #%s (%s)", sol_id, acao)

                    res = {}
                    erro = None
                    try:
                        if acao in ("falar", "speak"):
                            res = self.falar_com_google(payload.get("texto") or payload.get("comando", ""))
                        elif acao in ("ouvir", "listen"):
                            res = self.ouvir_resposta_google(payload.get("duracao_segundos"))
                        elif acao in ("dialogo", "conversar"):
                            res = self.conversar_com_google(payload.get("comando") or payload.get("pergunta", ""))
                        elif acao in ("conectar", "connect"):
                            res = self.conectar(payload.get("mac"))
                        elif acao in ("desconectar", "disconnect"):
                            res = self.desconectar(payload.get("mac"))
                        elif acao in ("escanear", "scan"):
                            res = {"dispositivos": self.escanear_dispositivos()}
                        else:
                            erro = f"Ação desconhecida: {acao}"
                    except Exception as exc:
                        erro = str(exc)

                    # Devolve o resultado da solicitação para o Despachante Central
                    try:
                        url_concluir = f"{self.cfg.orquestrador_url}/api/v1/solicitacoes/concluir"
                        corpo = json.dumps({
                            "id_solicitacao": sol_id,
                            "resultado": res,
                            "erro": erro,
                            "status": "CONCLUIDO" if not erro and res.get("ok", True) else "ERRO"
                        }).encode("utf-8")
                        req_conc = urllib.request.Request(url_concluir, data=corpo, headers={"Content-Type": "application/json"}, method="POST")
                        with urllib.request.urlopen(req_conc, timeout=5) as _:
                            pass
                    except Exception as exc_conc:
                        LOG.warning("Falha ao reportar conclusão da solicitação %s: %s", sol_id, exc_conc)

            except Exception:
                pass

            parar_evento.wait(2.0)


class GoogleHomeBTRequestHandler(http.server.BaseHTTPRequestHandler):
    bridge: BluetoothGoogleHomeBridge = None
    cfg: Config = None

    def _set_headers(self, code=200, content_type="application/json; charset=utf-8"):
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization, X-API-Key, X-Device-Token")
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
                "service": "casa-google-home-bt",
                "versao": VERSAO,
                "port": self.cfg.port,
                "conectado": self.bridge.conectado,
                "mac_alvo": self.bridge.mac_ativo,
                "sink_audio": self.bridge.sink_ativo,
                "source_audio": self.bridge.source_ativo,
                "stats": self.bridge.stats
            }
            self.wfile.write(json.dumps(resp, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/v1/bluetooth/dispositivos", "/api/bluetooth/dispositivos"):
            refresh = "refresh=1" in (parsed.query or "")
            if refresh or not self.bridge.dispositivos_descobertos:
                disps = self.bridge.escanear_dispositivos(timeout=3)
            else:
                disps = self.bridge.dispositivos_descobertos
            self._set_headers(200)
            self.wfile.write(json.dumps({"ok": True, "dispositivos": disps}, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/v1/google_home/historico", "/api/google_home/historico"):
            self._set_headers(200)
            self.wfile.write(json.dumps({"ok": True, "historico": self.bridge.historico_dialogos}, ensure_ascii=False).encode("utf-8"))
            return

        self._set_headers(404)
        self.wfile.write(json.dumps({"ok": False, "erro": "Rota não encontrada"}).encode("utf-8"))

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path

        length = int(self.headers.get("Content-Length", 0))
        data = {}
        if length > 0:
            try:
                body = self.rfile.read(length)
                data = json.loads(body.decode("utf-8")) if body.strip() else {}
            except Exception:
                data = {}

        # 1. Conexão Bluetooth
        if path in ("/api/v1/bluetooth/conectar", "/api/bluetooth/conectar"):
            mac = data.get("mac")
            res = self.bridge.conectar(mac=mac)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/v1/bluetooth/desconectar", "/api/bluetooth/desconectar"):
            mac = data.get("mac")
            res = self.bridge.desconectar(mac=mac)
            self._set_headers(200)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/v1/bluetooth/auto_parear", "/api/bluetooth/auto_parear"):
            timeout = int(data.get("timeout", 8))
            res = self.bridge.parear_dispositivo_automatico(timeout=timeout)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/v1/wifi/escanear", "/api/wifi/escanear"):
            subnet = data.get("subnet") or "192.168.2"
            ip_esp = data.get("ip_especifico")
            res = self.bridge.varrer_wifi_google_cast(subnet=subnet, ip_especifico=ip_esp)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        # 2. Falar com o Google
        if path in ("/api/v1/google_home/falar", "/api/google_home/falar"):
            texto = data.get("texto") or data.get("comando")
            wake_word = bool(data.get("prefixar_wake_word", True))
            res = self.bridge.falar_com_google(texto, prefixar_wake_word=wake_word)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        # 3. Ouvir o que o Google fala
        if path in ("/api/v1/google_home/ouvir", "/api/google_home/ouvir"):
            duracao = data.get("duracao_segundos")
            res = self.bridge.ouvir_resposta_google(duracao_segundos=duracao)
            self._set_headers(200)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        # 4. Diálogo Bidirecional Completo (Falar -> Ouvir -> Transcrever com IA)
        if path in ("/api/v1/google_home/dialogo", "/api/google_home/dialogo"):
            cmd = data.get("comando") or data.get("pergunta") or data.get("texto")
            if not cmd:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": "Parâmetro 'comando' obrigatório"}).encode("utf-8"))
                return
            res = self.bridge.conversar_com_google(cmd)
            self._set_headers(200 if res.get("ok") else 500)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        self._set_headers(404)
        self.wfile.write(json.dumps({"ok": False, "erro": "Rota não encontrada"}).encode("utf-8"))


class ThreadingHTTPServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True


def main():
    parser = argparse.ArgumentParser(description="CASA / JARVIS - Daemon Ponte Bluetooth com Google Home")
    parser.add_argument("--port", type=int, default=PORTA_PADRAO, help="Porta HTTP do daemon (padrão: 8094)")
    parser.add_argument("--mac", type=str, default="", help="MAC Bluetooth do Google Home")
    args = parser.parse_args()

    cfg = Config()
    if args.port:
        cfg.port = args.port
    if args.mac:
        cfg.target_mac = args.mac

    bridge = BluetoothGoogleHomeBridge(cfg)
    GoogleHomeBTRequestHandler.bridge = bridge
    GoogleHomeBTRequestHandler.cfg = cfg

    server_address = (cfg.host, cfg.port)
    httpd = ThreadingHTTPServer(server_address, GoogleHomeBTRequestHandler)
    LOG.info("CASA Google Home Bluetooth Daemon v%s ativo em http://%s:%d", VERSAO, cfg.host, cfg.port)

    # Thread em segundo plano para monitorar solicitações no Despachante Central
    parar_evento = threading.Event()
    t_worker = threading.Thread(target=bridge.loop_verificar_solicitacoes, args=(parar_evento,), daemon=True)
    t_worker.start()

    # Thread periódica para escanear e detectar dispositivos Bluetooth
    t_scan = threading.Thread(target=bridge.loop_escanear_bluetooth_periodico, args=(parar_evento,), daemon=True)
    t_scan.start()

    # Thread para atender solicitações imediatas de busca do site central CASA
    t_central = threading.Thread(target=bridge.loop_verificar_comandos_central, args=(parar_evento,), daemon=True)
    t_central.start()

    # Tenta conexão inicial se houver MAC configurado
    if cfg.target_mac and cfg.auto_reconectar:
        t_conn = threading.Thread(target=bridge.conectar, daemon=True)
        t_conn.start()

    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        LOG.info("Encerrando Google Home Bluetooth Daemon...")
    finally:
        parar_evento.set()
        httpd.server_close()


if __name__ == "__main__":
    main()
