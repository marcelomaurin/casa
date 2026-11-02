"""O agente deve produzir efeitos verificados, respeitar limites e preservar a fila."""
import importlib.util
import json
import logging
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]

def load(name, file):
    spec = importlib.util.spec_from_file_location(name, ROOT / file)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

ag = load("agente", "casa_tarefas_agente.py")
ct = load("daemon", "casa-tarefas.py")


class TestAgente(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.renovacoes = 0
        self.cfg = SimpleNamespace(agente_workspace=self.temp.name, agente_passos=6, agente_servicos=["casa-tarefas.service"],
                                   nome="no-teste", chave="segredo-do-teste", comandos_dir=self.temp.name + "/cmd",
                                   executores=["jarvis", "agente"], tempo_comando=5)
        self.ctx = SimpleNamespace(cfg=self.cfg, renovar=self.renovar, log=logging.getLogger("teste-agente"))
        self.task = {"id": 17, "titulo": "Criar artefato verificavel", "payload": {"comando": "Criar resultado"}}

    def renovar(self):
        self.renovacoes += 1

    def agent(self, decisions):
        it = iter(decisions)
        def model(prompt):
            self.assertNotIn(self.cfg.chave, prompt)
            return json.dumps(next(it))
        return ag.AgenteLocal(self.task, self.ctx, model, lambda t, c: {"executado": True}, lambda t, c: {"resultados": []})

    def test_executa_grava_confere_e_conclui(self):
        a = self.agent([
            {"tipo": "ferramenta", "nome": "gravar_arquivo", "args": {"path": "resultado.json", "content": '{"ok":true}'}},
            {"tipo": "ferramenta", "nome": "ler_arquivo", "args": {"path": "resultado.json"}},
            {"tipo": "concluir", "resposta": "Arquivo criado e conferido", "evidencias": [1, 2]},
        ])
        r = a.rodar()
        self.assertEqual(json.loads((a.raiz / "resultado.json").read_text()), {"ok": True})
        self.assertTrue(r["evidencias"][0]["resultado"]["verificado"])
        self.assertEqual(r["evidencias"][0]["resultado"]["sha256"], r["evidencias"][1]["resultado"]["sha256"])
        self.assertGreaterEqual(self.renovacoes, 5)
        self.assertEqual(len((a.raiz / ".casa-auditoria.jsonl").read_text().splitlines()), 2)

    def test_nao_aceita_conclusao_inventada(self):
        for ids in ([], [1], [True], ["1"]):
            with self.subTest(ids=ids), self.assertRaises(ag.ErroAgente):
                self.agent([{"tipo": "concluir", "resposta": "Pronto", "evidencias": ids}]).rodar()

    def test_falha_de_ferramenta_nao_e_evidencia(self):
        a = self.agent([{"tipo": "ferramenta", "nome": "shell", "args": {"command": "echo oi"}},
                        {"tipo": "concluir", "resposta": "Pronto", "evidencias": [1]}])
        with self.assertRaises(ag.ErroAgente):
            a.rodar()
        self.assertFalse(a.historico[0]["ok"])

    def test_modelo_pode_corrigir_erro_observado(self):
        a = self.agent([{"tipo": "ferramenta", "nome": "ler_arquivo", "args": {"path": "ausente.txt"}},
                        {"tipo": "ferramenta", "nome": "no_estado", "args": {}},
                        {"tipo": "concluir", "resposta": "Arquivo ausente; estado do no consultado", "evidencias": [2]}])
        self.assertEqual(a.rodar()["evidencias"][0]["id"], 2)

    def test_workspace_e_segredos_protegidos(self):
        a = self.agent([])
        for p in ("../fora.txt", "/etc/passwd", ".env", "x/../a.txt", "x//a.txt", "config.local.php", "secrets.json", "NUL.txt", "x.sh", "a/../../x.py"):
            with self.subTest(path=p), self.assertRaises(ValueError):
                a.caminho(p)
        with self.assertRaises(ValueError):
            a.executar_ferramenta("no_estado", {"comando": "nao"})
        with self.assertRaises(ValueError):
            a.executar_ferramenta("servico_estado", {"servico": "ssh.service; reboot"})

    @unittest.skipIf(os.name == "nt", "Symlinks exigem privilegio no Windows")
    def test_symlink_nao_escapa_do_workspace(self):
        a = self.agent([])
        (a.raiz / "link").symlink_to(Path(self.temp.name), target_is_directory=True)
        with self.assertRaises(ValueError):
            a.caminho("link/x.txt")

    def test_validacao_limites_e_json(self):
        a = self.agent([])
        for content in ("x" * 100001, "\0"):
            with self.assertRaises(ValueError):
                a.executar_ferramenta("gravar_arquivo", {"path": "x.txt", "content": content})
        with self.assertRaises(ValueError):
            a.executar_ferramenta("gravar_arquivo", {"path": "x.json", "content": "nao JSON"})
        self.cfg.agente_passos = 2
        a = self.agent([{"tipo": "ferramenta", "nome": "no_estado", "args": {}}] * 2)
        with self.assertRaisesRegex(ag.ErroAgente, "limite de passos"):
            a.rodar()

    def test_reserva_perdida_bloqueia_efeito(self):
        a = self.agent([{"tipo": "ferramenta", "nome": "gravar_arquivo", "args": {"path": "x.txt", "content": "x"}}])
        def lease():
            self.renovacoes += 1
            if self.renovacoes == 2:
                raise RuntimeError("Reserva perdida")
        self.ctx.renovar = lease
        with self.assertRaisesRegex(RuntimeError, "Reserva perdida"):
            a.rodar()
        self.assertFalse((a.raiz / "x.txt").exists())

    def test_redacao_e_servico_sem_shell(self):
        a = self.agent([])
        with patch.object(ag.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout="ActiveState=active\nBearer segredo-do-teste", stderr="")) as run:
            r = a.executar_ferramenta("servico_estado", {"servico": "casa-tarefas.service"})
        self.assertIsInstance(run.call_args.args[0], list)
        self.assertNotIn("shell", run.call_args.kwargs)
        self.assertNotIn(self.cfg.chave, r["saida"])

    def test_comandos_apenas_cadastrados(self):
        a = self.agent([])
        self.assertNotIn("executar_comando", a.ferramentas)
        with self.assertRaises(ValueError):
            a.executar_ferramenta("executar_comando", {"nome": "qualquer"})

    def test_integracao_executor_jarvis_agente(self):
        self.cfg.agente_enabled = True
        self.cfg.jarvis_modo = "agente"
        self.cfg.base_url = "https://casa.example"
        self.cfg.tempo_http = 30
        decisions = iter([{"tipo": "ferramenta", "nome": "no_estado", "args": {}},
                          {"tipo": "concluir", "resposta": "No observado", "evidencias": [1]}])
        def http(url, data, key, timeout):
            self.assertEqual(url, "https://casa.example/api/v1/comando")
            self.assertTrue(data["skip_planner"])
            self.assertEqual(data["task_context"], self.task["task_context"])
            return {"status": "sucesso", "resposta": json.dumps(next(decisions))}
        self.task["task_context"] = {"id_plano": 5, "current_task_id": 17}
        with patch.object(ct, "http_json", side_effect=http):
            self.assertEqual(ct.executor_jarvis(self.task, self.ctx)["agente"], "local")

    def test_nao_anuncia_conclusao_se_fila_recusar(self):
        os.environ.update(CASA_TAREFAS_CHAVE="k", CASA_TAREFAS_NOME="teste")
        c = ct.Config()
        c.executores = ["teste"]
        queue = SimpleNamespace(concluir=lambda *args: (_ for _ in ()).throw(ct.ErroApi(409, "reserva perdida")))
        d = ct.Daemon(c, fila=queue, executores={"teste": lambda t, ctx: {"resultado": "x"}})
        with self.assertLogs(ct.LOG, level="INFO") as log:
            d.executar({"id": 88, "executor": "teste"})
        d.pool.shutdown()
        self.assertFalse(any("concluída em" in s for s in log.output))
        self.assertTrue(any("conclusao nao confirmada" in s for s in log.output))

    def test_fila_agente_ferramenta_e_resultado_real(self):
        os.environ.update(CASA_TAREFAS_CHAVE="k", CASA_TAREFAS_NOME="teste")
        c = ct.Config()
        c.executores = ["agente"]
        c.agente_enabled = True
        c.agente_workspace = self.temp.name
        calls = []
        queue = SimpleNamespace(renovar=lambda tid: calls.append(("renovar", tid)),
                                concluir=lambda tid, r: calls.append(("concluir", tid, r)))
        d = ct.Daemon(c, fila=queue, executores={})
        decisions = iter([{"tipo": "ferramenta", "nome": "gravar_arquivo", "args": {"path": "prova.txt", "content": "executado"}},
                          {"tipo": "concluir", "resposta": "Arquivo criado", "evidencias": [1]}])
        with patch.object(ct, "http_json", side_effect=lambda *a: {"status": "sucesso", "resposta": json.dumps(next(decisions))}):
            d.executar({"id": 91, "executor": "agente", "titulo": "Criar arquivo"})
        d.pool.shutdown()
        concluded = [e for e in calls if e[0] == "concluir"]
        self.assertEqual(len(concluded), 1)
        self.assertEqual((Path(self.temp.name) / "tarefa-91" / "prova.txt").read_text(), "executado")
        self.assertTrue(concluded[0][2]["evidencias"][0]["resultado"]["verificado"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
