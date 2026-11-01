#!/usr/bin/env python3
"""CASA/JARVIS - Daemon executor da fila de tarefas do planejador.

Pega tarefas em /api/v1/fila (somente API v1), executa com o executor indicado e devolve
o resultado. Roda em qualquer nó (Raspberry Pi, PC, servidor externo) e serve de modelo
para agentes externos: o protocolo está descrito no README.

Executores embutidos
    jarvis   -> /api/v1/comando (a IA executa a parte; recebe o resultado da etapa anterior)
    web      -> agente de pesquisa local (clusters/pesquisa/web-agent)
    fala     -> serviço de voz local (clusters/voz/tts)
    comando  -> script autorizado em CASA_TAREFAS_COMANDOS_DIR (nunca linha de comando livre)
Executores extras: arquivos .py em CASA_TAREFAS_EXTENSOES_DIR (veja executores/exemplo_eco.py).

Uma tarefa pode ser dividida em partes: o executor levanta Dividir([...]) ou o script de
comando imprime {"dividir": [...]} e as partes voltam para a fila.
"""
import argparse
import importlib.util
import json
import logging
import os
import re
import signal
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor

VERSAO = "1.0.0"
LOG = logging.getLogger("casa-tarefas")
NOME_VALIDO = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9_.:\-]{0,79}$")


# ---------------------------------------------------------------------------
# Configuração
# ---------------------------------------------------------------------------
def carregar_env(caminho):
    if not caminho or not os.path.exists(caminho):
        return
    with open(caminho, "r", encoding="utf-8") as f:
        for linha in f:
            linha = linha.strip()
            if linha and not linha.startswith("#") and "=" in linha:
                k, v = linha.split("=", 1)
                os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def lista(valor):
    return [x.strip().lower() for x in (valor or "").split(",") if x.strip()]


class Config:
    def __init__(self):
        self.base_url = os.getenv("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
        self.chave = os.getenv("CASA_TAREFAS_CHAVE", "").strip()
        self.nome = os.getenv("CASA_TAREFAS_NOME", socket.gethostname().split(".")[0] or "casa-tarefas").strip()
        self.executores = lista(os.getenv("CASA_TAREFAS_EXECUTORES", "jarvis,web,fala"))
        self.filas = lista(os.getenv("CASA_TAREFAS_FILAS", ""))
        self.paralelo = max(1, min(16, int(os.getenv("CASA_TAREFAS_PARALELO", "2"))))
        # A API v1 bloqueia por 5 min a chave que passar de 60 pedidos/min (o IP da casa é isento):
        # no mínimo 3 s entre consultas mantém folga para renovações e resultados.
        self.intervalo = max(3.0, float(os.getenv("CASA_TAREFAS_INTERVALO", "5")))
        self.prazo = max(30, min(3600, int(os.getenv("CASA_TAREFAS_PRAZO", "300"))))
        self.tempo_comando = max(5, int(os.getenv("CASA_TAREFAS_TEMPO_LIMITE", "240")))
        self.tempo_http = max(5, int(os.getenv("CASA_TAREFAS_TEMPO_HTTP", "60")))
        self.tts_url = os.getenv("CASA_TTS_URL", "http://127.0.0.1:8097/falar")
        self.web_url = os.getenv("CASA_WEB_AGENT_URL", "http://127.0.0.1:8099/search")
        self.comandos_dir = os.getenv("CASA_TAREFAS_COMANDOS_DIR", "/etc/casa-tarefas/comandos.d")
        self.extensoes_dir = os.getenv("CASA_TAREFAS_EXTENSOES_DIR", "/opt/casa-tarefas/executores")

    def validar(self):
        erros = []
        if not self.chave:
            erros.append("CASA_TAREFAS_CHAVE vazia (crie uma chave com o escopo tasks.worker no painel)")
        if not NOME_VALIDO.match(self.nome):
            erros.append(f"CASA_TAREFAS_NOME inválido: {self.nome!r}")
        if self.nome.lower() == "servidor":
            erros.append("O nome 'servidor' é reservado ao site")
        for e in self.executores + self.filas:
            if not NOME_VALIDO.match(e):
                erros.append(f"Nome inválido em executores/filas: {e!r}")
        return erros


# ---------------------------------------------------------------------------
# Sinais que um executor pode usar
# ---------------------------------------------------------------------------
class FalhaDefinitiva(Exception):
    """Falha que não adianta repetir (dados inválidos, recurso inexistente)."""


class Dividir(Exception):
    """Pede ao servidor para dividir a tarefa nas partes informadas."""

    def __init__(self, partes):
        super().__init__(f"dividir em {len(partes)} parte(s)")
        self.partes = partes


class ErroApi(Exception):
    def __init__(self, http, mensagem):
        super().__init__(f"HTTP {http}: {mensagem}")
        self.http = http


# ---------------------------------------------------------------------------
# HTTP
# ---------------------------------------------------------------------------
def http_json(url, dados=None, chave=None, tempo=60, metodo=None):
    corpo = None if dados is None else json.dumps(dados, ensure_ascii=False).encode("utf-8")
    cab = {"Content-Type": "application/json", "User-Agent": f"casa-tarefas/{VERSAO}"}
    if chave:
        cab["Authorization"] = f"Bearer {chave}"
    req = urllib.request.Request(url, data=corpo, headers=cab, method=metodo or ("POST" if corpo is not None else "GET"))
    try:
        with urllib.request.urlopen(req, timeout=tempo) as resp:
            texto = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        texto = exc.read().decode("utf-8", errors="replace")
        try:
            msg = json.loads(texto).get("mensagem") or texto
        except Exception:
            msg = texto[:300]
        raise ErroApi(exc.code, msg) from None
    except urllib.error.URLError as exc:
        raise ErroApi(0, str(exc.reason)) from None
    try:
        return json.loads(texto) if texto.strip() else {}
    except json.JSONDecodeError:
        raise ErroApi(200, "Resposta não é JSON: " + texto[:200]) from None


class Fila:
    """Cliente de /api/v1/fila."""

    def __init__(self, cfg):
        self.cfg = cfg
        self.url = f"{cfg.base_url}/api/v1/fila.php"

    def _post(self, acao, **campos):
        campos.update({"trabalhador": self.cfg.nome})
        return http_json(f"{self.url}?acao={acao}", campos, self.cfg.chave, self.cfg.tempo_http)

    def reservar(self, limite):
        r = self._post("reservar", executores=self.cfg.executores, filas=self.cfg.filas, limite=limite,
                       prazo_segundos=self.cfg.prazo, versao=VERSAO, host=socket.gethostname())
        return r.get("tarefas") or []

    def renovar(self, tid):
        return self._post("renovar", id=tid, prazo_segundos=self.cfg.prazo)

    def concluir(self, tid, resultado):
        return self._post("concluir", id=tid, resultado=resultado)

    def falhar(self, tid, erro, repetir=True):
        return self._post("falhar", id=tid, erro=str(erro)[:4000], repetir=bool(repetir))

    def liberar(self, tid):
        return self._post("liberar", id=tid)

    def dividir(self, tid, partes):
        return self._post("dividir", id=tid, partes=partes)


# ---------------------------------------------------------------------------
# Executores embutidos
# ---------------------------------------------------------------------------
def texto_da_tarefa(tarefa, *campos):
    p = tarefa.get("payload") or {}
    if isinstance(p, dict):
        for c in campos:
            v = p.get(c)
            if isinstance(v, str) and v.strip():
                return v.strip()
    elif isinstance(p, str) and p.strip():
        return p.strip()
    return (tarefa.get("descricao") or tarefa.get("titulo") or "").strip()


def resumo_entrada(tarefa, limite=3000):
    """Resultado da etapa anterior em texto curto, para encadear partes."""
    entrada = tarefa.get("entrada")
    if not entrada or entrada.get("resultado") is None:
        return ""
    r = entrada["resultado"]
    if isinstance(r, dict):
        if isinstance(r.get("resposta"), str):
            txt = r["resposta"]
        elif isinstance(r.get("resultados"), list):
            txt = "\n".join(f"- {i.get('titulo', '')}: {i.get('trecho', '')} ({i.get('url', '')})" for i in r["resultados"])
        else:
            txt = json.dumps(r, ensure_ascii=False)
    else:
        txt = str(r)
    return txt[:limite]


def executor_jarvis(tarefa, ctx):
    comando = texto_da_tarefa(tarefa, "comando", "texto", "query")
    if not comando:
        raise FalhaDefinitiva("Tarefa sem comando")
    anterior = resumo_entrada(tarefa)
    if anterior:
        titulo = (tarefa.get("entrada") or {}).get("titulo") or "etapa anterior"
        comando = f"{comando}\n\nResultado da etapa anterior ({titulo}):\n{anterior}"
    r = http_json(f"{ctx.cfg.base_url}/api/v1/comando", {
        "comando": comando,
        "origem": "DAEMON_TAREFAS",
        "skip_planner": True,
        "correlation_id": tarefa.get("correlation_id"),
        "task_context": tarefa.get("task_context"),
    }, ctx.cfg.chave, max(ctx.cfg.tempo_http, 90))
    if r.get("status_jarvis") == "erro" or (r.get("status") not in (None, "sucesso", "ok")):
        raise RuntimeError(r.get("resposta") or r.get("mensagem") or "JARVIS respondeu com erro")
    return {"resposta": r.get("resposta", ""), "acao": r.get("acao_executada"), "provedor": r.get("provedor_ia")}


def executor_web(tarefa, ctx):
    consulta = texto_da_tarefa(tarefa, "query", "comando", "texto")
    if len(consulta) < 2:
        raise FalhaDefinitiva("Consulta de pesquisa vazia")
    p = tarefa.get("payload") if isinstance(tarefa.get("payload"), dict) else {}
    r = http_json(ctx.cfg.web_url, {
        "query": consulta[:500],
        "max_results": max(1, min(10, int(p.get("max_results", 5)))),
        "fetch_pages": bool(p.get("fetch_pages", False)),
    }, None, max(ctx.cfg.tempo_http, 60))
    resultados = [{k: i.get(k) for k in ("titulo", "url", "trecho", "conteudo") if i.get(k)} for i in r.get("resultados", [])]
    return {"query": consulta, "quantidade": len(resultados), "resultados": resultados}


def executor_fala(tarefa, ctx):
    texto = texto_da_tarefa(tarefa, "texto", "comando")
    anterior = resumo_entrada(tarefa, 1200)
    if anterior and (not texto or texto == tarefa.get("titulo")):
        texto = anterior
    if not texto:
        raise FalhaDefinitiva("Nada para falar")
    p = tarefa.get("payload") if isinstance(tarefa.get("payload"), dict) else {}
    r = http_json(ctx.cfg.tts_url, {"texto": texto[:1500], "speaker": p.get("speaker", "padrao"), "reproduzir": True},
                  None, 60)
    return {"falado": texto[:1500], "audio_url": r.get("audio_url")}


def executor_comando(tarefa, ctx):
    """Roda SOMENTE um executável que exista em CASA_TAREFAS_COMANDOS_DIR, sem shell.

    A tarefa chega em JSON pela entrada padrão. Se a última linha da saída for JSON, ela vira
    o resultado; {"dividir": [...]} pede a divisão em partes.
    """
    p = tarefa.get("payload") if isinstance(tarefa.get("payload"), dict) else {}
    nome = str(p.get("nome") or p.get("comando") or "").strip()
    if not re.match(r"^[a-zA-Z0-9][a-zA-Z0-9_.\-]{0,63}$", nome) or ".." in nome:
        raise FalhaDefinitiva(f"Comando inválido: {nome!r}")
    caminho = os.path.realpath(os.path.join(ctx.cfg.comandos_dir, nome))
    base = os.path.realpath(ctx.cfg.comandos_dir)
    if not caminho.startswith(base + os.sep) or not os.path.isfile(caminho) or not os.access(caminho, os.X_OK):
        raise FalhaDefinitiva(f"Comando não autorizado neste nó: {nome}")
    args = [str(a) for a in (p.get("args") or []) if isinstance(a, (str, int, float))][:20]
    proc = subprocess.run([caminho, *args], input=json.dumps(tarefa, ensure_ascii=False), capture_output=True,
                          text=True, timeout=ctx.cfg.tempo_comando, cwd=base,
                          env={"PATH": "/usr/local/bin:/usr/bin:/bin", "CASA_TAREFA_ID": str(tarefa.get("id")),
                               "LANG": "C.UTF-8"})
    saida = (proc.stdout or "").strip()
    ultima = saida.splitlines()[-1] if saida else ""
    estruturado = None
    try:
        estruturado = json.loads(ultima) if ultima.startswith("{") else None
    except json.JSONDecodeError:
        estruturado = None
    if proc.returncode != 0:
        raise RuntimeError(f"Saída {proc.returncode}: {(proc.stderr or saida)[-800:]}")
    if isinstance(estruturado, dict) and isinstance(estruturado.get("dividir"), list):
        raise Dividir(estruturado["dividir"])
    return estruturado if isinstance(estruturado, dict) else {"saida": saida[-4000:]}


EMBUTIDOS = {"jarvis": executor_jarvis, "web": executor_web, "fala": executor_fala, "comando": executor_comando}


def carregar_extensoes(pasta):
    """Cada .py define EXECUTOR = "nome" e executar(tarefa, ctx)."""
    achados = {}
    if not pasta or not os.path.isdir(pasta):
        return achados
    for arq in sorted(os.listdir(pasta)):
        if not arq.endswith(".py") or arq.startswith("_"):
            continue
        caminho = os.path.join(pasta, arq)
        try:
            spec = importlib.util.spec_from_file_location(f"casa_tarefas_ext_{arq[:-3]}", caminho)
            mod = importlib.util.module_from_spec(spec)
            mod.FalhaDefinitiva, mod.Dividir = FalhaDefinitiva, Dividir
            spec.loader.exec_module(mod)
            nome = str(getattr(mod, "EXECUTOR", "")).lower()
            if NOME_VALIDO.match(nome) and callable(getattr(mod, "executar", None)):
                achados[nome] = mod.executar
                LOG.info("Extensão carregada: %s (%s)", nome, arq)
            else:
                LOG.warning("Extensão ignorada (sem EXECUTOR/executar): %s", arq)
        except Exception as exc:  # extensão com defeito não derruba o daemon
            LOG.error("Falha ao carregar extensão %s: %s", arq, exc)
    return achados


# ---------------------------------------------------------------------------
# Daemon
# ---------------------------------------------------------------------------
class Contexto:
    """O que um executor recebe além da tarefa."""

    def __init__(self, daemon, tarefa):
        self.cfg = daemon.cfg
        self.fila = daemon.fila
        self.tarefa = tarefa
        self.log = LOG

    def renovar(self):
        self.fila.renovar(self.tarefa["id"])


class Daemon:
    def __init__(self, cfg, fila=None, executores=None):
        self.cfg = cfg
        self.fila = fila or Fila(cfg)
        todos = dict(EMBUTIDOS)
        todos.update(executores if executores is not None else carregar_extensoes(cfg.extensoes_dir))
        faltando = [e for e in cfg.executores if e not in todos]
        if faltando:
            LOG.warning("Executores sem implementação neste nó, removidos: %s", ", ".join(faltando))
        self.cfg.executores = [e for e in cfg.executores if e in todos]
        self.executores = {e: todos[e] for e in self.cfg.executores}
        self.parar = threading.Event()
        self.ativas = {}
        self.trava = threading.Lock()
        self.pool = ThreadPoolExecutor(max_workers=cfg.paralelo, thread_name_prefix="tarefa")

    # -- ciclo de uma tarefa ------------------------------------------------
    def executar(self, tarefa):
        tid = tarefa["id"]
        nome_exec = tarefa.get("executor")
        LOG.info("Tarefa #%s [%s] %s (tentativa %s/%s)", tid, nome_exec, tarefa.get("titulo"),
                 tarefa.get("tentativa"), tarefa.get("max_tentativas"))
        inicio = time.monotonic()
        try:
            fn = self.executores.get(nome_exec)
            if fn is None:
                raise FalhaDefinitiva(f"Executor {nome_exec} não disponível neste nó")
            resultado = fn(tarefa, Contexto(self, tarefa))
            if not isinstance(resultado, dict):
                resultado = {"resultado": resultado}
            resultado.setdefault("duracao_ms", int((time.monotonic() - inicio) * 1000))
            resultado.setdefault("trabalhador", self.cfg.nome)
            self._reportar(lambda: self.fila.concluir(tid, resultado))
            LOG.info("Tarefa #%s concluída em %d ms", tid, resultado["duracao_ms"])
        except Dividir as d:
            self._reportar(lambda: self.fila.dividir(tid, d.partes))
            LOG.info("Tarefa #%s dividida em %d parte(s)", tid, len(d.partes))
        except FalhaDefinitiva as exc:
            self._reportar(lambda: self.fila.falhar(tid, exc, repetir=False))
            LOG.warning("Tarefa #%s falhou sem nova tentativa: %s", tid, exc)
        except Exception as exc:
            self._reportar(lambda: self.fila.falhar(tid, exc, repetir=True))
            LOG.warning("Tarefa #%s falhou (pode repetir): %s", tid, exc)
        finally:
            with self.trava:
                self.ativas.pop(tid, None)

    def _reportar(self, chamada, tentativas=5):
        espera = 2
        for n in range(tentativas):
            try:
                return chamada()
            except ErroApi as exc:
                if exc.http in (404, 409):  # reserva perdida (venceu ou foi para outro): nada a fazer
                    LOG.warning("Resultado recusado pelo servidor: %s", exc)
                    return None
                if n == tentativas - 1:
                    LOG.error("Não consegui informar o resultado: %s", exc)
                    return None
                time.sleep(espera)
                espera = min(30, espera * 2)

    # -- renovação das reservas ---------------------------------------------
    def renovar_reservas(self):
        while not self.parar.wait(max(10, self.cfg.prazo / 3)):
            self._renovar_todas()
        self._renovar_todas()

    def _renovar_todas(self):
        with self.trava:
            ids = list(self.ativas)
        for tid in ids:
            try:
                self.fila.renovar(tid)
            except ErroApi as exc:
                LOG.warning("Não renovou a reserva da tarefa #%s: %s", tid, exc)

    # -- laço principal -------------------------------------------------------
    def ciclo(self):
        """Uma rodada: reserva o que couber e despacha. Retorna quantas tarefas pegou."""
        with self.trava:
            livres = self.cfg.paralelo - len(self.ativas)
        if livres <= 0 or not self.cfg.executores:
            return 0
        tarefas = self.fila.reservar(livres)
        for t in tarefas:
            with self.trava:
                self.ativas[t["id"]] = t
            self.pool.submit(self.executar, t)
        return len(tarefas)

    def rodar(self, uma_vez=False):
        LOG.info("casa-tarefas %s | nó %s | executores %s | filas %s | paralelo %d | %s",
                 VERSAO, self.cfg.nome, ",".join(self.cfg.executores) or "-", ",".join(self.cfg.filas) or "geral",
                 self.cfg.paralelo, self.cfg.base_url)
        renovador = threading.Thread(target=self.renovar_reservas, name="renovador", daemon=True)
        renovador.start()
        espera_erro = self.cfg.intervalo
        try:
            while not self.parar.is_set():
                try:
                    pegou = self.ciclo()
                    espera_erro = self.cfg.intervalo
                    if uma_vez:
                        break
                    if pegou == 0:
                        self.parar.wait(self.cfg.intervalo)
                except ErroApi as exc:
                    LOG.error("Fila indisponível: %s (nova tentativa em %.0f s)", exc, espera_erro)
                    if exc.http == 429:
                        espera_erro = max(espera_erro, 60)  # bloqueio da API v1 dura 5 min
                    if uma_vez or exc.http in (401, 403):
                        if exc.http in (401, 403):
                            LOG.error("Verifique CASA_TAREFAS_CHAVE e o escopo tasks.worker")
                        if uma_vez:
                            break
                    self.parar.wait(espera_erro)
                    espera_erro = min(120, espera_erro * 2)
        finally:
            self.parar.set()
            LOG.info("Encerrando: aguardando %d tarefa(s) em execução", len(self.ativas))
            self.pool.shutdown(wait=True)
            renovador.join(timeout=5)
            LOG.info("casa-tarefas encerrado")


def main(argv=None):
    ap = argparse.ArgumentParser(description="Daemon executor da fila de tarefas do CASA/JARVIS")
    ap.add_argument("--config", default="/etc/casa-tarefas.env", help="arquivo de configuração (padrão /etc/casa-tarefas.env)")
    ap.add_argument("--uma-vez", action="store_true", help="faz uma rodada, espera as tarefas e sai")
    ap.add_argument("--verificar", action="store_true", help="mostra a configuração e os executores e sai")
    args = ap.parse_args(argv)

    logging.basicConfig(level=os.getenv("CASA_TAREFAS_LOG", "INFO").upper(),
                        format="%(asctime)s %(levelname)s %(threadName)s %(message)s")
    carregar_env(args.config)
    cfg = Config()
    erros = cfg.validar()
    if args.verificar:
        print(json.dumps({"versao": VERSAO, "nome": cfg.nome, "base_url": cfg.base_url, "executores": cfg.executores,
                          "filas": cfg.filas, "paralelo": cfg.paralelo, "prazo": cfg.prazo,
                          "chave_configurada": bool(cfg.chave), "erros": erros}, ensure_ascii=False, indent=2))
        return 1 if erros else 0
    if erros:
        for e in erros:
            LOG.error(e)
        return 2

    daemon = Daemon(cfg)

    def sinal(signum, _frame):
        LOG.info("Sinal %s recebido: parando de pegar tarefas", signum)
        daemon.parar.set()

    signal.signal(signal.SIGTERM, sinal)
    signal.signal(signal.SIGINT, sinal)
    daemon.rodar(uma_vez=args.uma_vez)
    return 0


if __name__ == "__main__":
    sys.exit(main())
