#!/usr/bin/env python3
"""Testes do daemon casa-tarefas com uma fila falsa (sem rede nem banco)."""
import importlib.util
import json
import os
import stat
import tempfile
import threading
import time
import unittest
from pathlib import Path

AQUI = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("casa_tarefas", AQUI.parent / "casa-tarefas.py")
ct = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ct)


class FilaFalsa:
    def __init__(self, tarefas):
        self.pendentes = list(tarefas)
        self.eventos = []
        self.trava = threading.Lock()
        self.recusar_concluir = set()

    def reservar(self, limite):
        with self.trava:
            pegar, self.pendentes = self.pendentes[:limite], self.pendentes[limite:]
            return pegar

    def renovar(self, tid):
        self.eventos.append(("renovar", tid))

    def concluir(self, tid, resultado):
        if tid in self.recusar_concluir:
            raise ct.ErroApi(409, "reserva perdida")
        self.eventos.append(("concluir", tid, resultado))

    def falhar(self, tid, erro, repetir=True):
        self.eventos.append(("falhar", tid, str(erro), repetir))

    def liberar(self, tid):
        self.eventos.append(("liberar", tid))

    def dividir(self, tid, partes):
        self.eventos.append(("dividir", tid, partes))

    def do_tipo(self, tipo):
        return [e for e in self.eventos if e[0] == tipo]


def cfg(**extra):
    os.environ.update({"CASA_TAREFAS_CHAVE": "k", "CASA_TAREFAS_NOME": "teste", "CASA_TAREFAS_PARALELO": "3"})
    c = ct.Config()
    for k, v in extra.items():
        setattr(c, k, v)
    return c


def tarefa(tid, executor, payload=None, **extra):
    t = {"id": tid, "executor": executor, "titulo": f"T{tid}", "payload": payload or {}, "tentativa": 1, "max_tentativas": 3}
    t.update(extra)
    return t


class TesteDaemon(unittest.TestCase):
    def rodar(self, tarefas, executores, **c):
        fila = FilaFalsa(tarefas)
        conf = cfg(executores=list(executores), **c)
        d = ct.Daemon(conf, fila=fila, executores=executores)
        while fila.pendentes:
            d.ciclo()
        d.pool.shutdown(wait=True)
        return fila, d

    def test_conclui_falha_e_divide(self):
        def ok(t, ctx): return {"valor": t["payload"]["n"] * 2}
        def quebra(t, ctx): raise RuntimeError("temporário")
        def definitiva(t, ctx): raise ct.FalhaDefinitiva("dados ruins")
        def divide(t, ctx): raise ct.Dividir([{"titulo": "a"}, {"titulo": "b", "depende_da_parte": 1}])
        fila, _ = self.rodar(
            [tarefa(1, "ok", {"n": 21}), tarefa(2, "quebra"), tarefa(3, "definitiva"), tarefa(4, "divide")],
            {"ok": ok, "quebra": quebra, "definitiva": definitiva, "divide": divide})
        concl = fila.do_tipo("concluir")
        self.assertEqual(concl[0][1], 1)
        self.assertEqual(concl[0][2]["valor"], 42)
        self.assertEqual(concl[0][2]["trabalhador"], "teste")
        falhas = {e[1]: e[3] for e in fila.do_tipo("falhar")}
        self.assertEqual(falhas, {2: True, 3: False})
        self.assertEqual(len(fila.do_tipo("dividir")[0][2]), 2)

    def test_executor_inexistente_e_reserva_perdida(self):
        fila = FilaFalsa([tarefa(7, "ok")])
        fila.recusar_concluir.add(7)
        d = ct.Daemon(cfg(executores=["ok"]), fila=fila, executores={"ok": lambda t, c: {"x": 1}})
        d.ciclo(); d.pool.shutdown(wait=True)
        self.assertEqual(fila.do_tipo("concluir"), [])  # 409 é aceito em silêncio, sem repetir
        fila2 = FilaFalsa([tarefa(8, "sumiu")])
        d2 = ct.Daemon(cfg(executores=["ok"]), fila=fila2, executores={"ok": lambda t, c: {}})
        d2.executores = {"ok": lambda t, c: {}}
        d2.ciclo(); d2.pool.shutdown(wait=True)
        self.assertEqual(fila2.do_tipo("falhar")[0][3], False)

    def test_paralelo_respeitado(self):
        ativos, maximo, trava = [0], [0], threading.Lock()
        def lento(t, ctx):
            with trava:
                ativos[0] += 1; maximo[0] = max(maximo[0], ativos[0])
            time.sleep(0.15)
            with trava:
                ativos[0] -= 1
            return {}
        fila = FilaFalsa([tarefa(i, "lento") for i in range(1, 8)])
        d = ct.Daemon(cfg(executores=["lento"], paralelo=3), fila=fila, executores={"lento": lento})
        d.pool._max_workers = 3
        inicio = time.time()
        while fila.pendentes or d.ativas:
            d.ciclo(); time.sleep(0.02)
            self.assertLess(time.time() - inicio, 5)
        d.pool.shutdown(wait=True)
        self.assertLessEqual(maximo[0], 3)
        self.assertEqual(len(fila.do_tipo("concluir")), 7)

    def test_resumo_da_entrada(self):
        t = tarefa(1, "x", entrada={"titulo": "Pesquisa", "resultado": {"resultados": [{"titulo": "A", "trecho": "b", "url": "u"}]}})
        self.assertIn("- A: b (u)", ct.resumo_entrada(t))
        t2 = tarefa(2, "x", entrada={"titulo": "IA", "resultado": {"resposta": "ok"}})
        self.assertEqual(ct.resumo_entrada(t2), "ok")
        self.assertEqual(ct.resumo_entrada(tarefa(3, "x")), "")

    def test_comando_somente_autorizado(self):
        with tempfile.TemporaryDirectory() as pasta:
            script = Path(pasta) / "soma"
            script.write_text('#!/bin/sh\nread j\necho "log qualquer"\necho \'{"soma": 3}\'\n')
            script.chmod(script.stat().st_mode | stat.S_IXUSR)
            divide = Path(pasta) / "divide"
            divide.write_text('#!/bin/sh\necho \'{"dividir": [{"titulo": "p1"}]}\'\n')
            divide.chmod(divide.stat().st_mode | stat.S_IXUSR)
            parado = Path(pasta) / "sem-permissao"
            parado.write_text('#!/bin/sh\necho oi\n')
            c = cfg(comandos_dir=pasta, executores=["comando"])
            ctx = type("C", (), {"cfg": c})()
            self.assertEqual(ct.executor_comando(tarefa(1, "comando", {"nome": "soma"}), ctx), {"soma": 3})
            with self.assertRaises(ct.Dividir):
                ct.executor_comando(tarefa(2, "comando", {"nome": "divide"}), ctx)
            for nome in ("../../bin/sh", "sem-permissao", "nao-existe", "a;rm -rf /"):
                with self.assertRaises(ct.FalhaDefinitiva, msg=nome):
                    ct.executor_comando(tarefa(3, "comando", {"nome": nome}), ctx)

    def test_extensao_exemplo(self):
        ext = ct.carregar_extensoes(str(AQUI.parent / "executores"))
        self.assertIn("eco", ext)
        self.assertEqual(ext["eco"](tarefa(1, "eco", {"texto": "oi"}), None)["eco"], "oi")
        with self.assertRaises(ct.Dividir) as d:
            ext["eco"](tarefa(2, "eco", {"partes": ["a", "b"]}), None)
        self.assertEqual(d.exception.partes[1]["depende_da_parte"], 1)

    def test_configuracao(self):
        os.environ["CASA_TAREFAS_NOME"] = "servidor"
        c = ct.Config(); c.chave = "k"
        self.assertTrue(any("reservado" in e for e in c.validar()))
        os.environ["CASA_TAREFAS_NOME"] = "pi-sala"
        c = ct.Config(); c.chave = ""
        self.assertTrue(any("CASA_TAREFAS_CHAVE" in e for e in c.validar()))


if __name__ == "__main__":
    unittest.main(verbosity=2)
