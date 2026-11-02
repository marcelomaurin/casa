"""Reserva, renovacao e confirmacao pelo motor oficial da fila do Casa."""
import importlib.util
import logging
import os
from pathlib import Path
import re
import socket
import threading
import requests
LOG = logging.getLogger("casa-google-home.queue")

class QueueBridge:
    def __init__(self, speak, status, worker_module=None):
        if worker_module is None:
            source = Path(os.getenv("CASA_TAREFAS_WORKER_FILE", "/opt/casa-tarefas/casa-tarefas.py"))
            if not source.is_file():
                source = Path(__file__).resolve().parents[3] / "clusters/automacao/casa-tarefas/casa-tarefas.py"
            spec = importlib.util.spec_from_file_location("google_home_queue_worker", source)
            worker_module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(worker_module)
        self.worker, self.speak, self.status = worker_module, speak, status
        cfg = worker_module.Config()
        cfg.nome = os.getenv("CASA_GOOGLE_HOME_WORKER_NAME", "google-home-" + socket.gethostname().split(".")[0])
        cfg.executores, cfg.filas, cfg.paralelo = ["google_home"], [], 1
        cfg.mordomo_intervalo, cfg.jarvis_modo, cfg.agente_enabled = 0, "central", False
        cfg.intervalo, cfg.prazo = max(5, cfg.intervalo), 300
        errors = cfg.validar()
        if errors:
            raise RuntimeError("; ".join(errors))
        self.cfg = cfg
        self.daemon = worker_module.Daemon(cfg, executores={"google_home": self.execute})
        self.thread = threading.Thread(target=self.daemon.rodar, name="google-home-fila", daemon=True)
        self.heartbeat_thread = threading.Thread(target=self.heartbeat, name="google-home-heartbeat", daemon=True)

    def execute(self, task, context):
        data = task.get("payload") or {}
        if not isinstance(data, dict) or data.get("modo", "falar") != "falar":
            raise self.worker.FalhaDefinitiva("Tarefa Google Home deve conter modo=falar")
        text, speaker = str(data.get("texto") or "").strip(), str(data.get("speaker") or "padrao")
        if not text or len(text) > 4000 or not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", speaker):
            raise self.worker.FalhaDefinitiva("Texto ou voz invalida")
        context.renovar()
        return self.speak(text, data.get("device"), speaker)

    def start(self):
        self.thread.start()
        self.heartbeat_thread.start()

    def alive(self):
        return self.thread.is_alive() and not self.daemon.parar.is_set()

    def heartbeat(self):
        while not self.daemon.parar.is_set():
            try:
                data = self.status()
                data.update(acao="heartbeat", trabalhador=self.cfg.nome)
                response = requests.post(self.cfg.base_url + "/api/v1/google_home.php", json=data,
                    headers={"X-API-Key": self.cfg.chave}, timeout=(5, 15))
                response.raise_for_status()
            except Exception as error:
                LOG.warning("Heartbeat Google Home indisponivel: %s", type(error).__name__)
            self.daemon.parar.wait(30)

    def stop(self):
        self.daemon.parar.set()
        self.thread.join(timeout=120)
        self.heartbeat_thread.join(timeout=20)
