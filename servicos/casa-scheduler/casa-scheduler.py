#!/usr/bin/env python3
"""JARVIS - Scheduler residencial com suporte a tarefas recorrentes e unicas."""
import os
import time
import json
import datetime
import urllib.request
import urllib.parse
import psycopg2

DB_HOST = os.getenv("JARVIS_DB_HOST", "127.0.0.1")
DB_NAME = os.getenv("JARVIS_DB_NAME", "casadb")
DB_USER = os.getenv("JARVIS_DB_USER", "casadb_user")
DB_PASS = os.getenv("JARVIS_DB_PASS", "casadb_password_2026")
SYSTEM_API_TOKEN = os.getenv("JARVIS_SYSTEM_API_TOKEN", "jarvis_secret_token_2026")


def db():
    return psycopg2.connect(host=DB_HOST, dbname=DB_NAME, user=DB_USER, password=DB_PASS)


def query(sql, params=()):
    with db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, params)
            try:
                return cur.fetchall()
            except psycopg2.ProgrammingError:
                return []


def exec_sql(sql, params=()):
    with db() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, params)


def post_json(url, payload, timeout=35):
    req = urllib.request.Request(
        url,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json", "X-API-Key": SYSTEM_API_TOKEN},
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


def marcar_tarefa_plano(id_tarefa_plano, status, resultado=None, erro=None):
    if not id_tarefa_plano:
        return
    exec_sql(
        """UPDATE jarvis_tarefas
           SET status=%s,
               resultado=CASE WHEN %s IS NULL THEN resultado ELSE %s::jsonb END,
               erro=%s,
               iniciado_em=CASE WHEN %s='EXECUTANDO' THEN COALESCE(iniciado_em,CURRENT_TIMESTAMP) ELSE iniciado_em END,
               concluido_em=CASE WHEN %s IN ('CONCLUIDA','ERRO') THEN CURRENT_TIMESTAMP ELSE concluido_em END
           WHERE id=%s""",
        (status, resultado, resultado, erro, status, status, id_tarefa_plano),
    )


def executar_acao(tarefa_id, titulo, tipo_acao, payload, target_node, id_tarefa_plano=None):
    print(f"[Scheduler] Executando #{tarefa_id} {titulo} ({tipo_acao})")
    marcar_tarefa_plano(id_tarefa_plano, "EXECUTANDO")
    resultado = {"status": "ok"}
    erro = None
    try:
        if tipo_acao == "comando_jarvis":
            data = post_json("http://127.0.0.1/api/jarvis.php", {
                "comando": payload,
                "skip_planner": True,
                "origem": "SCHEDULER"
            })
            resultado = {"resposta": data.get("resposta", ""), "acao": data.get("acao")}

        elif tipo_acao == "pesquisa_web":
            data = post_json("http://127.0.0.1/api/agente_internet.php", {
                "acao": "responder",
                "query": payload,
                "max_results": 5
            }, timeout=60)
            resultado = {"resposta": data.get("resposta", ""), "fontes": data.get("fontes", [])}

        elif tipo_acao == "aviso_fala":
            if target_node == "local" or not target_node:
                post_json("http://127.0.0.1:8097/falar", {
                    "texto": payload, "speaker": "padrao", "reproduzir": True
                }, timeout=20)
                resultado = {"mensagem": "Audio reproduzido localmente"}
            else:
                post_json(f"http://{target_node}:8098/falar", {"texto": payload}, timeout=20)
                resultado = {"mensagem": f"Audio enviado a {target_node}"}

        elif tipo_acao == "dispositivo_devpar":
            p = dict(urllib.parse.parse_qsl(payload))
            iddev = int(p.get("iddevice", 1))
            parname = p.get("devparname", "dev1")
            val = p.get("valor", "1")
            exec_sql("UPDATE devpar SET devvalue=%s, atualizado_em=CURRENT_TIMESTAMP WHERE iddevice=%s AND devparname=%s",
                     (val, iddev, parname))
            resultado = {"iddevice": iddev, "parametro": parname, "valor": val}

        elif tipo_acao == "script":
            # Scripts arbitrarios nao sao executados pelo scheduler por seguranca.
            raise RuntimeError("Execucao arbitraria de script bloqueada; use um executor registrado")
        else:
            raise RuntimeError(f"Tipo de acao desconhecido: {tipo_acao}")

        marcar_tarefa_plano(id_tarefa_plano, "CONCLUIDA", json.dumps(resultado, ensure_ascii=False))
    except Exception as exc:
        erro = str(exc)
        resultado = {"status": "erro", "erro": erro}
        marcar_tarefa_plano(id_tarefa_plano, "ERRO", json.dumps(resultado, ensure_ascii=False), erro)
        print("[Scheduler ERRO]", erro)

    try:
        exec_sql("INSERT INTO comandos_log (comando, origem, resultado) VALUES (%s,'SCHEDULER',%s)",
                 (f"Agendamento: {titulo}", json.dumps(resultado, ensure_ascii=False)[:2000]))
    except Exception as exc:
        print("[Scheduler LOG]", exc)
    return erro is None


def _cron_field_match(expr, value, minv, maxv):
    expr = str(expr or "*").strip()
    if expr == "*":
        return True
    for part in expr.split(","):
        part = part.strip()
        if part.startswith("*/"):
            try:
                step = int(part[2:])
                if step > 0 and value % step == 0:
                    return True
            except ValueError:
                pass
        elif "-" in part:
            try:
                a, b = [int(x) for x in part.split("-", 1)]
                if a <= value <= b:
                    return True
            except ValueError:
                pass
        else:
            try:
                if int(part) == value:
                    return True
            except ValueError:
                pass
    return False


def cron_matches(expr, now):
    fields = str(expr or "").strip().split()
    if len(fields) != 5:
        return False
    minute, hour, dom, month, dow = fields
    cron_dow = (now.weekday() + 1) % 7
    return (
        _cron_field_match(minute, now.minute, 0, 59)
        and _cron_field_match(hour, now.hour, 0, 23)
        and _cron_field_match(dom, now.day, 1, 31)
        and _cron_field_match(month, now.month, 1, 12)
        and _cron_field_match(dow, cron_dow, 0, 7)
    )


def tarefa_pode_rodar(depende_de):
    if not depende_de:
        return True
    rows = query("SELECT status FROM jarvis_tarefas WHERE id=%s", (depende_de,))
    return bool(rows and rows[0][0] == "CONCLUIDA")


def verificar_agendamentos():
    now = datetime.datetime.now()
    hora = now.strftime("%H:%M")
    dow = str((now.weekday() + 1) % 7)
    dow_iso = str(now.isoweekday())

    rows = query("""SELECT ta.id, ta.titulo, ta.horario, ta.dias_semana, ta.tipo_acao,
                           ta.payload, ta.target_node, ta.ultima_execucao,
                           COALESCE(ta.modo_agendamento,'RECORRENTE'), ta.executar_em,
                           COALESCE(ta.executar_uma_vez,FALSE), ta.id_tarefa_plano,
                           jt.depende_de, ta.cron_expr
                    FROM tarefas_agendadas ta
                    LEFT JOIN jarvis_tarefas jt ON jt.id=ta.id_tarefa_plano
                    WHERE ta.ativo=TRUE""")

    for r in rows:
        (tid, titulo, horario, dias, tipo, payload, target, ultima,
         modo, executar_em, uma_vez, id_tarefa_plano, depende_de, cron_expr) = r

        if not tarefa_pode_rodar(depende_de):
            continue

        disparar = False
        if str(modo).upper() == "UNICA" or uma_vez:
            disparar = executar_em is not None and executar_em <= now and ultima is None
        else:
            if ultima and ultima.strftime("%Y-%m-%d %H:%M") == now.strftime("%Y-%m-%d %H:%M"):
                continue
            dias = (dias or "*").strip()
            if dias not in ("*", ""):
                permitidos = [x.strip() for x in dias.split(",")]
                if dow not in permitidos and dow_iso not in permitidos:
                    continue
            if cron_expr:
                disparar = cron_matches(cron_expr, now)
            else:
                horario = (horario or "").strip()
                if horario == hora:
                    disparar = True
                elif horario.startswith("*/"):
                    try:
                        intervalo = int(horario[2:])
                        disparar = intervalo > 0 and now.minute % intervalo == 0
                    except ValueError:
                        pass

        if disparar:
            ok = executar_acao(tid, titulo, tipo, payload, target or "local", id_tarefa_plano)
            exec_sql("UPDATE tarefas_agendadas SET ultima_execucao=CURRENT_TIMESTAMP WHERE id=%s", (tid,))
            if str(modo).upper() == "UNICA" or uma_vez:
                exec_sql("UPDATE tarefas_agendadas SET ativo=FALSE WHERE id=%s", (tid,))


def atualizar_planos_concluidos():
    exec_sql("""UPDATE jarvis_planos p
                SET status='CONCLUIDO', concluido_em=CURRENT_TIMESTAMP
                WHERE p.status <> 'CONCLUIDO'
                  AND EXISTS (SELECT 1 FROM jarvis_tarefas t WHERE t.id_plano=p.id)
                  AND NOT EXISTS (
                      SELECT 1 FROM jarvis_tarefas t
                      WHERE t.id_plano=p.id AND t.status NOT IN ('CONCLUIDA','ERRO')
                  )""")


def main():
    print("=== JARVIS SCHEDULER 2.0 ===")
    while True:
        try:
            verificar_agendamentos()
            atualizar_planos_concluidos()
        except Exception as exc:
            print("[Scheduler Exception]", exc)
        time.sleep(15)


if __name__ == "__main__":
    main()
