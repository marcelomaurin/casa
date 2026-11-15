#!/usr/bin/env python3
"""CASA / JARVIS - Daemon Orquestrador Residencial Inteligente com IA.

Responsabilidades do Orquestrador:
1. Controlar a casa: Traduz intenções em linguagem natural ou comandos estruturados
   em acionamentos seguros de dispositivos via API REST v1 (/api/v1/dispositivos/acionar).
2. Responder perguntas: Integra reconhecimento de voz local (Parakeet STT :8092),
   busca web em tempo real (Web Agent :8099), estado dos sensores da residência e síntese
   de voz (Pocket-TTS :8093 / Piper :8097).
3. Prever necessidades: Ciclo contínuo de telemetria integrado ao Timestral-5M (:8091)
   para previsão preditiva de clima interno, consumo energético e detecção de anomalias.
4. Agendar eventos: Interpreta horários e regras para criar agendamentos e rotinas no
   planejador de regras e fila distribuída (/api/v1/rules_scheduler.php, /api/v1/fila.php).
5. Orquestrar artefatos: Malha unificada de sensores, câmeras, relés, Raspberry Pis e
   alto-falantes da residência.
"""

import argparse
import base64
import http.server
import json
import logging
import os
import re
import socket
import socketserver
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

VERSAO = "1.0.0"
PORTA_PADRAO = 8090
MAX_PAYLOAD_BYTES = 5 * 1024 * 1024  # 5MB (permite envio de trechos de áudio para STT)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] [ORQUESTRADOR] %(message)s"
)
LOG = logging.getLogger("casa-orquestrador")


def carregar_env(caminho):
    """Carrega variáveis de ambiente a partir de um arquivo .env de forma segura."""
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


for env_path in ["/etc/casa-orquestrador.env", "/etc/casa-tarefas.env", "/etc/casa-node-agent.env"]:
    carregar_env(env_path)


class Config:
    def __init__(self):
        self.host = os.getenv("CASA_ORQUESTRADOR_HOST", "0.0.0.0")
        self.port = int(os.getenv("CASA_ORQUESTRADOR_PORT", str(PORTA_PADRAO)))
        self.base_url = os.getenv("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
        self.api_key = os.getenv("CASA_API_KEY", os.getenv("CASA_TAREFAS_CHAVE", os.getenv("JARVIS_SYSTEM_API_TOKEN", ""))).strip()
        self.device_token = os.getenv("JARVIS_DEVICE_TOKEN", os.getenv("CASA_AGENT_TOKEN", "")).strip()

        # URLs dos serviços auxiliares locais
        self.timestral_url = os.getenv("CASA_TIMESTRAL_URL", "http://127.0.0.1:8091").rstrip("/")
        self.parakeet_url = os.getenv("CASA_PARAKEET_URL", "http://127.0.0.1:8092").rstrip("/")
        self.pocket_tts_url = os.getenv("CASA_POCKET_TTS_URL", "http://127.0.0.1:8093").rstrip("/")
        self.google_home_bt_url = os.getenv("CASA_GOOGLE_HOME_BT_URL", "http://127.0.0.1:8094").rstrip("/")
        self.tts_url = os.getenv("CASA_TTS_URL", "http://127.0.0.1:8097").rstrip("/")
        self.web_agent_url = os.getenv("CASA_WEB_AGENT_URL", "http://127.0.0.1:8099").rstrip("/")
        self.node_agent_url = os.getenv("CASA_NODE_AGENT_URL", "http://127.0.0.1:8098").rstrip("/")

        # Intervalos operacionais
        self.intervalo_predicao_segundos = max(30, int(os.getenv("CASA_PREDICAO_INTERVALO", "120")))
        self.auto_acao_preditiva = os.getenv("CASA_AUTO_ACAO_PREDITIVA", "0") == "1"


class Solicitacao:
    """Modelo de uma solicitação de trabalho ou mensagem distribuída entre daemons."""
    def __init__(self, id_solicitacao, origem, destino, acao, payload):
        self.id = id_solicitacao
        self.origem = str(origem)
        self.destino = str(destino)
        self.acao = str(acao)
        self.payload = payload or {}
        self.status = "PENDENTE"  # PENDENTE, PROCESSANDO, CONCLUIDO, ERRO, TIMEOUT
        self.criado_em = time.time()
        self.iniciado_em = None
        self.concluido_em = None
        self.resultado = None
        self.erro = None
        self.evento = threading.Event()

    def to_dict(self):
        return {
            "id": self.id,
            "origem": self.origem,
            "destino": self.destino,
            "acao": self.acao,
            "payload": self.payload,
            "status": self.status,
            "criado_em": self.criado_em,
            "iniciado_em": self.iniciado_em,
            "concluido_em": self.concluido_em,
            "resultado": self.resultado,
            "erro": self.erro
        }


class CentralDispatcher:
    """Barramento centralizado de distribuição de solicitações para os daemons da casa."""
    def __init__(self, orchestrator=None):
        self.orchestrator = orchestrator
        self.lock = threading.Lock()
        self.contador = 0
        self.solicitacoes = {}
        self.filas = {}

    def publicar(self, origem, destino, acao, payload=None, timeout=0):
        with self.lock:
            self.contador += 1
            sol_id = f"sol_{int(time.time())}_{self.contador}"
            sol = Solicitacao(sol_id, origem, destino, acao, payload)
            self.solicitacoes[sol_id] = sol
            if destino not in self.filas:
                self.filas[destino] = []
            self.filas[destino].append(sol)
            if len(self.solicitacoes) > 200:
                chaves_remover = list(self.solicitacoes.keys())[:-200]
                for k in chaves_remover:
                    self.solicitacoes.pop(k, None)

        LOG.info("[DESPACHANTE] Solicitação %s enfileirada: %s -> %s (%s)", sol_id, origem, destino, acao)

        # Se houver timeout síncrono, aguarda o daemon alvo processar e devolver a resposta
        if timeout and timeout > 0:
            finalizou = sol.evento.wait(timeout)
            if not finalizou:
                with self.lock:
                    if sol.status in ("PENDENTE", "PROCESSANDO"):
                        sol.status = "TIMEOUT"
                        sol.erro = f"Tempo limite de {timeout}s excedido aguardando daemon {destino}."
                return {"ok": False, "id": sol_id, "status": "timeout", "erro": sol.erro}
            return {
                "ok": sol.status == "CONCLUIDO",
                "id": sol_id,
                "status": sol.status,
                "resultado": sol.resultado,
                "erro": sol.erro
            }

        return {"ok": True, "id": sol_id, "status": "enfileirada", "destino": destino}

    def pendentes(self, daemon_nome, max_itens=5):
        """Retorna solicitações pendentes para o daemon solicitante."""
        with self.lock:
            if daemon_nome not in self.filas:
                return []
            fila = self.filas[daemon_nome]
            pendentes_lista = []
            restantes = []
            agora = time.time()
            for sol in fila:
                if sol.status == "PENDENTE" and len(pendentes_lista) < max_itens:
                    sol.status = "PROCESSANDO"
                    sol.iniciado_em = agora
                    pendentes_lista.append(sol.to_dict())
                elif sol.status in ("PENDENTE", "PROCESSANDO"):
                    restantes.append(sol)
            self.filas[daemon_nome] = restantes
            return pendentes_lista

    def concluir(self, id_solicitacao, resultado=None, erro=None, status="CONCLUIDO"):
        """Registra a conclusão da solicitação por um daemon."""
        with self.lock:
            sol = self.solicitacoes.get(id_solicitacao)
            if not sol:
                return False
            sol.concluido_em = time.time()
            sol.status = "ERRO" if erro or status in ("ERRO", "FALHA") else "CONCLUIDO"
            sol.resultado = resultado
            sol.erro = erro
            sol.evento.set()
            LOG.info("[DESPACHANTE] Solicitação %s concluída por %s (status: %s)", id_solicitacao, sol.destino, sol.status)
            return True

    def status(self, id_solicitacao):
        with self.lock:
            sol = self.solicitacoes.get(id_solicitacao)
            return sol.to_dict() if sol else None

    def resumo_filas(self):
        with self.lock:
            resumo = {}
            for d, fila in self.filas.items():
                resumo[d] = {
                    "total": len(fila),
                    "pendentes": sum(1 for s in fila if s.status == "PENDENTE"),
                    "processando": sum(1 for s in fila if s.status == "PROCESSANDO")
                }
            return resumo


class HouseOrchestrator:
    """Núcleo cognitivo e orquestrador de artefatos da casa."""

    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.lock = threading.Lock()
        self.dispatcher = CentralDispatcher(self)
        self.stats = {
            "total_comandos_executados": 0,
            "total_perguntas_respondidas": 0,
            "total_previsoes_realizadas": 0,
            "total_anomalias_detectadas": 0,
            "total_agendamentos_criados": 0,
            "total_solicitacoes_distribuidas": 0,
            "ultimo_ciclo_preditivo": None,
            "inicio_servico": time.time(),
        }
        self.dispositivos_cache = []
        self.ultimo_cache_dispositivos = 0.0

    def interagir_com_daemon(self, daemon_nome, acao, payload=None, timeout=15):
        """A IA interage com qualquer daemon através do despachante centralizado."""
        with self.lock:
            self.stats["total_solicitacoes_distribuidas"] += 1
        return self.dispatcher.publicar(
            origem="ia_orquestrador",
            destino=daemon_nome,
            acao=acao,
            payload=payload or {},
            timeout=timeout
        )

    def _fazer_requisicao_api(self, endpoint, metodo="GET", dados=None, timeout=15):
        """Faz requisição autenticada à API v1 do site central com tratamento de erros."""
        url = f"{self.cfg.base_url}{endpoint}" if endpoint.startswith("/") else f"{self.cfg.base_url}/{endpoint}"
        headers = {
            "User-Agent": "CASA-Orquestrador-IA/1.0",
            "Content-Type": "application/json"
        }
        if self.cfg.api_key:
            headers["Authorization"] = f"Bearer {self.cfg.api_key}"
            headers["X-API-Key"] = self.cfg.api_key
        elif self.cfg.device_token:
            headers["X-Device-Token"] = self.cfg.device_token

        corpo = json.dumps(dados).encode("utf-8") if dados is not None else None
        req = urllib.request.Request(url, data=corpo, headers=headers, method=metodo)
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                status = getattr(resp, "status", getattr(resp, "code", 200))
                payload = resp.read().decode("utf-8")
                try:
                    return status, json.loads(payload)
                except Exception:
                    return status, {"raw": payload}
        except urllib.error.HTTPError as he:
            corpo_erro = he.read().decode("utf-8", errors="replace")
            LOG.warning("Erro HTTP %d ao chamar %s: %s", he.code, url, corpo_erro[:200])
            try:
                return he.code, json.loads(corpo_erro)
            except Exception:
                return he.code, {"erro": corpo_erro}
        except Exception as exc:
            LOG.error("Falha de conexão com %s: %s", url, exc)
            return 503, {"erro": str(exc)}

    def listar_dispositivos(self, force=False):
        """Obtém a lista atualizada de dispositivos e atuadores da casa."""
        agora = time.time()
        if not force and self.dispositivos_cache and (agora - self.ultimo_cache_dispositivos) < 60:
            return self.dispositivos_cache

        code, resp = self._fazer_requisicao_api("/api/v1/dispositivos")
        if code == 200 and isinstance(resp, dict):
            itens = []
            for d in resp.get("dispositivos_legados", []):
                itens.append({
                    "id": d.get("iddevice"),
                    "nome": d.get("devname"),
                    "descricao": d.get("devdesc"),
                    "tipo": "legado",
                    "parametros": d.get("parametros", [])
                })
            for c in resp.get("cluster_iot", []):
                itens.append({
                    "id": c.get("device_id") or c.get("id"),
                    "nome": c.get("nome"),
                    "tipo": "cluster_iot",
                    "ip": c.get("ip_address"),
                    "status": c.get("status"),
                    "capabilities": c.get("capabilities", [])
                })
            self.dispositivos_cache = itens
            self.ultimo_cache_dispositivos = agora
            return itens
        return self.dispositivos_cache

    def controlar_dispositivo(self, comando_texto=None, iddevice=None, parametro=None, valor=None):
        """Controla dispositivos da residência através da API v1."""
        with self.lock:
            self.stats["total_comandos_executados"] += 1

        # Caso seja acionamento direto estruturado
        if iddevice is not None:
            parametro = parametro or "dev1"
            valor = str(valor if valor is not None else "1")
            code, resp = self._fazer_requisicao_api(
                "/api/v1/dispositivos/acionar",
                metodo="POST",
                dados={"iddevice": int(iddevice), "parametro": parametro, "valor": valor}
            )
            return {
                "ok": code in (200, 201),
                "iddevice": iddevice,
                "parametro": parametro,
                "valor": valor,
                "resposta_api": resp
            }

        # Caso seja comando em linguagem natural
        if comando_texto:
            cmd = comando_texto.strip().lower()
            # Mapeamento heurístico rápido para comandos frequentes
            disps = self.listar_dispositivos()
            match_id = None
            match_par = "dev1"
            match_val = "1"

            if any(termo in cmd for termo in ["desligar", "apagar", "desative", "fechar"]):
                match_val = "0"
            elif any(termo in cmd for termo in ["ligar", "acender", "ative", "abrir"]):
                match_val = "1"

            for d in disps:
                nome_d = (d.get("nome") or "").lower()
                desc_d = (d.get("descricao") or "").lower()
                if (nome_d and nome_d in cmd) or (desc_d and desc_d in cmd):
                    match_id = d.get("id")
                    break

            if match_id is not None and isinstance(match_id, int):
                code, resp = self._fazer_requisicao_api(
                    "/api/v1/dispositivos/acionar",
                    metodo="POST",
                    dados={"iddevice": match_id, "parametro": match_par, "valor": match_val}
                )
                ok = code in (200, 201)
                return {
                    "ok": ok,
                    "tipo": "mapeamento_direto",
                    "comando": comando_texto,
                    "iddevice": match_id,
                    "valor": match_val,
                    "resposta": f"Dispositivo {match_id} acionado com valor {match_val}." if ok else f"Comando para dispositivo {match_id} registrado localmente (API central indisponível).",
                    "resposta_api": resp
                }

            # Fallback para o motor cognitivo central do JARVIS ou resposta local
            code, resp = self._fazer_requisicao_api(
                "/api/v1/comando",
                metodo="POST",
                dados={"comando": comando_texto, "ia_mode": "auto"}
            )
            if code in (200, 201):
                return {
                    "ok": True,
                    "tipo": "cognitivo_central",
                    "comando": comando_texto,
                    "resposta": resp.get("resposta", "Comando processado.") if isinstance(resp, dict) else str(resp),
                    "resposta_api": resp
                }
            # Fallback cognitivo local
            return {
                "ok": True,
                "tipo": "cognitivo_local",
                "comando": comando_texto,
                "resposta": f"Comando '{comando_texto}' aceito e registrado pelo orquestrador local.",
                "resposta_api": resp
            }

        return {"ok": False, "erro": "Nenhum parâmetro de comando fornecido."}

    def transcrever_audio(self, audio_bytes, formato="wav"):
        """Envia áudio para o daemon local Parakeet STT (:8092)."""
        try:
            req = urllib.request.Request(
                f"{self.cfg.parakeet_url}/transcribe",
                data=audio_bytes,
                headers={"Content-Type": f"audio/{formato}", "User-Agent": "CASA-Orquestrador/1.0"},
                method="POST"
            )
            with urllib.request.urlopen(req, timeout=15) as r:
                data = json.loads(r.read().decode("utf-8"))
                return data.get("transcricao", "")
        except Exception as exc:
            LOG.error("Falha ao transcrever com Parakeet STT: %s", exc)
            return ""

    def sintetizar_voz(self, texto, modo="pocket", reproduzir=False):
        """Sintetiza voz via Pocket-TTS (:8093) ou Piper TTS (:8097)."""
        try:
            url = f"{self.cfg.pocket_tts_url}/synthesize" if modo == "pocket" else f"{self.cfg.tts_url}/falar"
            payload = {"texto": texto, "reproduzir": reproduzir}
            corpo = json.dumps(payload).encode("utf-8")
            req = urllib.request.Request(url, data=corpo, headers={"Content-Type": "application/json"}, method="POST")
            with urllib.request.urlopen(req, timeout=15) as r:
                return json.loads(r.read().decode("utf-8"))
        except Exception as exc:
            LOG.warning("Falha ao sintetizar voz (%s): %s", modo, exc)
            return {"ok": False, "erro": str(exc)}

    def pesquisar_web(self, query):
        """Consulta o Web Agent local (:8099) para notícias, clima ou fatos."""
        try:
            url = f"{self.cfg.web_agent_url}/search"
            payload = {"query": query, "max_results": 4}
            req = urllib.request.Request(url, data=json.dumps(payload).encode("utf-8"), headers={"Content-Type": "application/json"}, method="POST")
            with urllib.request.urlopen(req, timeout=20) as r:
                return json.loads(r.read().decode("utf-8"))
        except Exception as exc:
            LOG.warning("Falha ao consultar web-agent: %s", exc)
            return {"ok": False, "erro": str(exc)}

    def responder_pergunta(self, texto_pergunta, audio_bytes=None, falar=False):
        """Responde perguntas integrando contexto da casa, web e síntese de voz."""
        with self.lock:
            self.stats["total_perguntas_respondidas"] += 1

        if audio_bytes and not texto_pergunta:
            texto_pergunta = self.transcrever_audio(audio_bytes)

        if not texto_pergunta or not texto_pergunta.strip():
            return {"ok": False, "erro": "Nenhuma pergunta ou áudio reconhecido."}

        pergunta = texto_pergunta.strip()
        pergunta_lower = pergunta.lower()
        resposta_texto = ""
        origem = "cognitivo"

        # 0. Perguntas direcionadas ao Google Home via Bluetooth
        if any(termo in pergunta_lower for termo in ["ok google", "hey google", "pergunte ao google", "fale com o google", "google home"]):
            res_gh = self.interagir_com_daemon("google-home-bt", "dialogo", {"comando": pergunta}, timeout=18)
            if res_gh.get("ok") and isinstance(res_gh.get("resultado"), dict):
                resposta_texto = res_gh["resultado"].get("resposta_google") or "Comando enviado ao Google Home com sucesso."
                origem = "google_home_bt"
            else:
                res_direto = self.conversar_com_google_home(pergunta)
                resposta_texto = res_direto.get("resposta_google") or "Comando transmitido ao Google Home via Bluetooth."
                origem = "google_home_bt_direto"

        # 1. Perguntas sobre estado ou sensores da casa
        elif any(termo in pergunta_lower for termo in ["temperatura", "clima na casa", "sensores", "status da casa", "como está a casa", "umidade"]):
            code, resp = self._fazer_requisicao_api("/api/v1/sensores")
            if code == 200 and isinstance(resp, dict) and resp.get("sensores"):
                ultimas = resp["sensores"][:5]
                info_sensores = []
                for s in ultimas:
                    tipo = s.get("tipo_sensor", "ambiente")
                    t = s.get("temperatura_c")
                    u = s.get("umidade_pct")
                    info_sensores.append(f"{tipo}: {t}°C, {u}% umidade")
                resposta_texto = "Leituras recentes dos sensores da casa: " + "; ".join(info_sensores) + "."
                origem = "sensores_locais"
            else:
                resposta_texto = "Não consegui obter a leitura atual dos sensores no momento."

        # 2. Perguntas sobre clima externo, notícias ou pesquisa web
        elif any(termo in pergunta_lower for termo in ["previsão do tempo", "vai chover", "notícia", "quem é", "o que é", "pesquise", "resultado do"]):
            web_res = self.pesquisar_web(pergunta)
            if web_res.get("ok") and web_res.get("resposta"):
                resposta_texto = web_res.get("resposta")
                origem = "web_agent"
            elif web_res.get("results"):
                resumos = [r.get("snippet", "") for r in web_res["results"][:2]]
                resposta_texto = " ".join(resumos)
                origem = "web_agent_snippets"
            else:
                resposta_texto = f"Consultei a internet sobre '{pergunta}', mas não obtive resposta conclusiva."

        # 3. Perguntas de controle disfarçadas (ex: "pode ligar a luz?")
        elif any(termo in pergunta_lower for termo in ["ligue", "acenda", "apague", "desligue"]):
            ctrl = self.controlar_dispositivo(comando_texto=pergunta)
            resposta_texto = ctrl.get("resposta", "Comando de controle executado.")
            origem = "controle"

        # 4. Diálogo geral / fallback
        if not resposta_texto:
            code, resp = self._fazer_requisicao_api(
                "/api/v1/comando",
                metodo="POST",
                dados={"comando": pergunta, "ia_mode": "auto"}
            )
            if code == 200 and isinstance(resp, dict):
                resposta_texto = resp.get("resposta", "Entendido, processei sua solicitação.")
            else:
                resposta_texto = f"Entendido. Sua solicitação foi registrada pelo orquestrador: {pergunta}."

        resultado = {
            "ok": True,
            "pergunta": pergunta,
            "resposta": resposta_texto,
            "origem": origem
        }

        if falar and resposta_texto:
            tts_res = self.sintetizar_voz(resposta_texto, modo="pocket", reproduzir=True)
            resultado["audio_sintetizado"] = tts_res.get("ok", False)

        return resultado

    def conversar_com_google_home(self, comando):
        """Dispara diálogo bidirecional com Google Home via Bluetooth."""
        try:
            req = urllib.request.Request(
                f"{self.cfg.google_home_bt_url}/api/v1/google_home/dialogo",
                data=json.dumps({"comando": comando}).encode("utf-8"),
                headers={"Content-Type": "application/json"},
                method="POST"
            )
            with urllib.request.urlopen(req, timeout=25) as r:
                return json.loads(r.read().decode("utf-8"))
        except Exception as exc:
            LOG.warning("Falha ao comunicar com Google Home BT: %s", exc)
            return {"ok": False, "erro": str(exc)}

    def executar_analise_preditiva(self, serie_direta=None):
        """Coleta telemetria dos sensores ou utiliza série fornecida e envia para o Timestral-5M para prever necessidades."""
        serie_temp = []
        if serie_direta and isinstance(serie_direta, list):
            serie_temp = [float(x) for x in serie_direta if isinstance(x, (int, float))]
        else:
            code, resp = self._fazer_requisicao_api("/api/v1/sensores")
            if code == 200 and isinstance(resp, dict) and resp.get("sensores"):
                leituras = resp.get("sensores", [])
                for item in reversed(leituras):  # ordem cronológica
                    t = item.get("temperatura_c")
                    if isinstance(t, (int, float)):
                        serie_temp.append(float(t))

        if len(serie_temp) < 4:
            return {"ok": False, "motivo": "Menos de 4 leituras para análise de série temporal"}

        # 1. Chamar previsão no Timestral-5M
        previsao_resultado = {}
        try:
            req_forecast = urllib.request.Request(
                f"{self.cfg.timestral_url}/forecast",
                data=json.dumps({"serie": serie_temp, "horizonte": 6, "metrica": "temperatura_ambiente"}).encode("utf-8"),
                headers={"Content-Type": "application/json"},
                method="POST"
            )
            with urllib.request.urlopen(req_forecast, timeout=10) as r:
                previsao_resultado = json.loads(r.read().decode("utf-8"))
        except Exception as exc:
            LOG.warning("Falha na previsão Timestral: %s", exc)

        # 2. Chamar detecção de anomalias no Timestral-5M
        anomalias_resultado = {}
        try:
            req_anom = urllib.request.Request(
                f"{self.cfg.timestral_url}/anomalies",
                data=json.dumps({"serie": serie_temp, "limiar": 2.2}).encode("utf-8"),
                headers={"Content-Type": "application/json"},
                method="POST"
            )
            with urllib.request.urlopen(req_anom, timeout=10) as r:
                anomalias_resultado = json.loads(r.read().decode("utf-8"))
        except Exception as exc:
            LOG.warning("Falha na detecção de anomalias Timestral: %s", exc)

        with self.lock:
            self.stats["total_previsoes_realizadas"] += 1
            total_anom = anomalias_resultado.get("total_anomalias", 0)
            self.stats["total_anomalias_detectadas"] += total_anom
            self.stats["ultimo_ciclo_preditivo"] = time.time()

        # Decisão Preditiva: se houver tendência forte de alta térmica ou anomalia, avisar ou agir
        alertas = []
        tendencia = previsao_resultado.get("tendencia")
        previsoes = previsao_resultado.get("previsoes", [])

        if tendencia == "alta" and previsoes and previsoes[-1] > 28.0:
            alerta_msg = f"Atenção: previsão de subida térmica até {previsoes[-1]}°C nos próximos passos."
            alertas.append(alerta_msg)
            if self.cfg.auto_acao_preditiva:
                self.sintetizar_voz(alerta_msg, modo="pocket", reproduzir=True)

        if anomalias_resultado.get("anomalias"):
            for a in anomalias_resultado["anomalias"]:
                alerta_anom = f"Anomalia detectada em telemetria: valor {a.get('valor_observado')} com desvio Z {a.get('desvio_z')}."
                alertas.append(alerta_anom)

        return {
            "ok": True,
            "pontos_analisados": len(serie_temp),
            "previsao": previsao_resultado,
            "anomalias": anomalias_resultado,
            "alertas_gerados": alertas
        }

    def agendar_evento(self, titulo, tipo_acao, payload, cron_expr=None, executar_em=None):
        """Registra novo agendamento ou tarefa no sistema via API v1."""
        with self.lock:
            self.stats["total_agendamentos_criados"] += 1

        dados_tarefa = {
            "titulo": str(titulo)[:100],
            "tipo_acao": str(tipo_acao),
            "payload": payload,
            "cron_expr": cron_expr,
            "executar_em": executar_em,
            "origem": "CASA_ORQUESTRADOR_IA"
        }

        # Enfileira na API v1 da fila de tarefas
        code, resp = self._fazer_requisicao_api(
            "/api/v1/fila.php?acao=enfileirar",
            metodo="POST",
            dados={
                "fila": "agendador",
                "executor": "jarvis" if tipo_acao == "comando_jarvis" else "comando",
                "payload": dados_tarefa,
                "prioridade": 2
            }
        )
        remoto_ok = code in (200, 201)
        return {
            "ok": True,
            "agendado_remoto": remoto_ok,
            "tarefa": dados_tarefa,
            "resposta_api": resp
        }

    def loop_predicao(self, parar_evento: threading.Event):
        """Thread em segundo plano que executa análises preditivas periódicas."""
        LOG.info("Iniciando loop de predição contínua (intervalo: %ds)...", self.cfg.intervalo_predicao_segundos)
        while not parar_evento.is_set():
            try:
                self.executar_analise_preditiva()
            except Exception as exc:
                LOG.error("Exceção no ciclo preditivo: %s", exc)
            parar_evento.wait(self.cfg.intervalo_predicao_segundos)


class OrquestradorHTTPRequestHandler(http.server.BaseHTTPRequestHandler):
    orchestrator: HouseOrchestrator = None
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
                "service": "casa-orquestrador",
                "versao": VERSAO,
                "port": self.cfg.port,
                "status": "online",
                "stats": self.orchestrator.stats,
                "auxiliares": {
                    "timestral": self.cfg.timestral_url,
                    "parakeet_stt": self.cfg.parakeet_url,
                    "pocket_tts": self.cfg.pocket_tts_url,
                    "web_agent": self.cfg.web_agent_url,
                    "node_agent": self.cfg.node_agent_url
                }
            }
            self.wfile.write(json.dumps(resp, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/dispositivos", "/api/v1/dispositivos"):
            disps = self.orchestrator.listar_dispositivos()
            self._set_headers(200)
            self.wfile.write(json.dumps({"ok": True, "dispositivos": disps}, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/orquestrar/estado", "/api/estado"):
            disps = self.orchestrator.listar_dispositivos()
            self._set_headers(200)
            self.wfile.write(json.dumps({
                "ok": True,
                "service": "casa-orquestrador",
                "dispositivos_total": len(disps),
                "stats": self.orchestrator.stats,
                "filas_distribuicao": self.orchestrator.dispatcher.resumo_filas()
            }, ensure_ascii=False).encode("utf-8"))
            return

        # Rotas do Despachante Central de Mensagens e Solicitações
        if path in ("/api/v1/solicitacoes/pendentes", "/api/solicitacoes/pendentes"):
            query_params = urllib.parse.parse_qs(parsed.query)
            daemon_nome = query_params.get("daemon", [""])[0].strip()
            max_itens = int(query_params.get("max", [5])[0])
            pend = self.orchestrator.dispatcher.pendentes(daemon_nome, max_itens=max_itens)
            self._set_headers(200)
            self.wfile.write(json.dumps({
                "ok": True,
                "daemon": daemon_nome,
                "total": len(pend),
                "solicitacoes": pend
            }, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/v1/solicitacoes/status", "/api/solicitacoes/status"):
            query_params = urllib.parse.parse_qs(parsed.query)
            sol_id = query_params.get("id", [""])[0].strip()
            st = self.orchestrator.dispatcher.status(sol_id)
            if not st:
                self._set_headers(404)
                self.wfile.write(json.dumps({"ok": False, "erro": "Solicitação não encontrada"}).encode("utf-8"))
                return
            self._set_headers(200)
            self.wfile.write(json.dumps({"ok": True, "solicitacao": st}, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/v1/solicitacoes/filas", "/api/solicitacoes/filas"):
            self._set_headers(200)
            self.wfile.write(json.dumps({"ok": True, "filas": self.orchestrator.dispatcher.resumo_filas()}, ensure_ascii=False).encode("utf-8"))
            return

        self._set_headers(404)
        self.wfile.write(json.dumps({"ok": False, "erro": "Rota não encontrada"}).encode("utf-8"))

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path

        length = int(self.headers.get("Content-Length", 0))
        if length <= 0:
            self._set_headers(400)
            self.wfile.write(json.dumps({"ok": False, "erro": "Corpo da requisição vazio"}).encode("utf-8"))
            return

        if length > MAX_PAYLOAD_BYTES:
            self._set_headers(413)
            self.wfile.write(json.dumps({"ok": False, "erro": f"Payload muito grande (máx {MAX_PAYLOAD_BYTES} bytes)"}).encode("utf-8"))
            return

        try:
            body = self.rfile.read(length)
            content_type = self.headers.get("Content-Type", "")
            if "application/json" in content_type:
                data = json.loads(body.decode("utf-8"))
            elif "audio/" in content_type:
                data = {"audio_bytes_raw": body}
            else:
                data = json.loads(body.decode("utf-8"))
        except Exception:
            self._set_headers(400)
            self.wfile.write(json.dumps({"ok": False, "erro": "Corpo inválido ou JSON malformado"}).encode("utf-8"))
            return

        # 1. Rota de Controle da Casa
        if path in ("/api/controlar", "/api/v1/controlar", "/controlar"):
            cmd = data.get("comando")
            iddev = data.get("iddevice")
            par = data.get("parametro")
            val = data.get("valor")
            res = self.orchestrator.controlar_dispositivo(comando_texto=cmd, iddevice=iddev, parametro=par, valor=val)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        # 2. Rota de Perguntas e Interação Cognitiva
        if path in ("/api/interagir", "/api/perguntar", "/api/v1/perguntar", "/perguntar"):
            pergunta = data.get("pergunta") or data.get("texto") or data.get("query")
            audio_b64 = data.get("audio_base64")
            audio_bytes = None
            if audio_b64:
                try:
                    audio_bytes = base64.b64decode(audio_b64)
                except Exception:
                    pass
            elif "audio_bytes_raw" in data:
                audio_bytes = data["audio_bytes_raw"]

            falar = bool(data.get("falar", False))
            res = self.orchestrator.responder_pergunta(pergunta, audio_bytes=audio_bytes, falar=falar)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        # 3. Rota de Análise e Previsão de Necessidades (Timestral-5M)
        if path in ("/api/prever", "/api/v1/prever", "/prever"):
            serie = data.get("serie") or data.get("valores")
            res = self.orchestrator.executar_analise_preditiva(serie_direta=serie)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        # 4. Rota de Agendamento de Eventos
        if path in ("/api/agendar", "/api/v1/agendar", "/agendar"):
            titulo = data.get("titulo", "Agendamento Inteligente")
            tipo_acao = data.get("tipo_acao", "comando_jarvis")
            payload = data.get("payload", "")
            cron = data.get("cron_expr")
            executar_em = data.get("executar_em")
            res = self.orchestrator.agendar_evento(titulo, tipo_acao, payload, cron_expr=cron, executar_em=executar_em)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        # 5. Rota de Conversação com Google Home via Bluetooth
        if path in ("/api/v1/google_home/conversar", "/api/google_home/conversar"):
            cmd = data.get("comando") or data.get("texto") or data.get("pergunta")
            if not cmd:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": "Comando obrigatório"}).encode("utf-8"))
                return
            res = self.orchestrator.conversar_com_google_home(cmd)
            self._set_headers(200 if res.get("ok") else 500)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        # 6. Rotas do Despachante Central: Publicação e Conclusão de Solicitações
        if path in ("/api/v1/solicitacoes/publicar", "/api/solicitacoes/publicar"):
            origem = str(data.get("origem", "cliente"))
            destino = str(data.get("destino", "")).strip()
            acao = str(data.get("acao", "executar")).strip()
            payload = data.get("payload") or {}
            timeout = float(data.get("aguardar_timeout", 0))

            if not destino or not acao:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": "Parâmetros 'destino' e 'acao' são obrigatórios"}).encode("utf-8"))
                return

            res = self.orchestrator.dispatcher.publicar(origem, destino, acao, payload=payload, timeout=timeout)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/v1/solicitacoes/concluir", "/api/solicitacoes/concluir"):
            sol_id = str(data.get("id_solicitacao") or data.get("id") or "").strip()
            status = str(data.get("status", "CONCLUIDO")).upper()
            resultado = data.get("resultado")
            erro = data.get("erro")

            if not sol_id:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": "id_solicitacao obrigatório"}).encode("utf-8"))
                return

            sucesso = self.orchestrator.dispatcher.concluir(sol_id, resultado=resultado, erro=erro, status=status)
            self._set_headers(200 if sucesso else 404)
            self.wfile.write(json.dumps({"ok": sucesso, "id": sol_id, "status": status}, ensure_ascii=False).encode("utf-8"))
            return

        # 5. Rota de Conversação com Google Home via Bluetooth
        if path in ("/api/v1/google_home/conversar", "/api/google_home/conversar"):
            cmd = data.get("comando") or data.get("texto") or data.get("pergunta")
            if not cmd:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": "Comando obrigatório"}).encode("utf-8"))
                return
            res = self.orchestrator.conversar_com_google_home(cmd)
            self._set_headers(200 if res.get("ok") else 500)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        # 6. Rotas do Despachante Central: Publicação e Conclusão de Solicitações
        if path in ("/api/v1/solicitacoes/publicar", "/api/solicitacoes/publicar"):
            origem = str(data.get("origem", "cliente"))
            destino = str(data.get("destino", "")).strip()
            acao = str(data.get("acao", "executar")).strip()
            payload = data.get("payload") or {}
            timeout = float(data.get("aguardar_timeout", 0))

            if not destino or not acao:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": "Parâmetros 'destino' e 'acao' são obrigatórios"}).encode("utf-8"))
                return

            res = self.orchestrator.dispatcher.publicar(origem, destino, acao, payload=payload, timeout=timeout)
            self._set_headers(200 if res.get("ok") else 400)
            self.wfile.write(json.dumps(res, ensure_ascii=False).encode("utf-8"))
            return

        if path in ("/api/v1/solicitacoes/concluir", "/api/solicitacoes/concluir"):
            sol_id = str(data.get("id_solicitacao") or data.get("id") or "").strip()
            status = str(data.get("status", "CONCLUIDO")).upper()
            resultado = data.get("resultado")
            erro = data.get("erro")

            if not sol_id:
                self._set_headers(400)
                self.wfile.write(json.dumps({"ok": False, "erro": "id_solicitacao obrigatório"}).encode("utf-8"))
                return

            sucesso = self.orchestrator.dispatcher.concluir(sol_id, resultado=resultado, erro=erro, status=status)
            self._set_headers(200 if sucesso else 404)
            self.wfile.write(json.dumps({"ok": sucesso, "id": sol_id, "status": status}, ensure_ascii=False).encode("utf-8"))
            return

        self._set_headers(404)
        self.wfile.write(json.dumps({"ok": False, "erro": "Rota não encontrada"}).encode("utf-8"))


class ThreadingHTTPServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True


def main():
    parser = argparse.ArgumentParser(description="CASA / JARVIS - Daemon Orquestrador Residencial com IA")
    parser.add_argument("--port", type=int, default=PORTA_PADRAO, help="Porta HTTP do orquestrador (padrão: 8090)")
    parser.add_argument("--host", type=str, default="0.0.0.0", help="Host de escuta (padrão: 0.0.0.0)")
    parser.add_argument("--sem-predicao", action="store_true", help="Desativa thread em segundo plano de predição")
    args = parser.parse_args()

    cfg = Config()
    cfg.port = args.port
    cfg.host = args.host

    orchestrator = HouseOrchestrator(cfg)
    OrquestradorHTTPRequestHandler.orchestrator = orchestrator
    OrquestradorHTTPRequestHandler.cfg = cfg

    parar_evento = threading.Event()
    thread_predicao = None
    if not args.sem_predicao:
        thread_predicao = threading.Thread(target=orchestrator.loop_predicao, args=(parar_evento,), daemon=True)
        thread_predicao.start()

    server_address = (cfg.host, cfg.port)
    httpd = ThreadingHTTPServer(server_address, OrquestradorHTTPRequestHandler)
    LOG.info("CASA Orquestrador IA v%s ativo em http://%s:%d", VERSAO, cfg.host, cfg.port)

    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        LOG.info("Encerrando Orquestrador IA...")
    finally:
        parar_evento.set()
        httpd.server_close()


if __name__ == "__main__":
    main()
