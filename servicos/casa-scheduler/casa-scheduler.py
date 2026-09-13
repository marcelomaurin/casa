#!/usr/bin/env python3
"""
CASA INTELIGENTE - MOTOR DE AGENDAMENTO DE TAREFAS AUTOMATIZADAS (SCHEDULER DAEMON)
Executa rotinas programadas na residencia (irrigacao, iluminacao, avisos nos satelites ARM)
"""

import os
import sys
import time
import json
import socket
import datetime
import urllib.request
import urllib.error

# Usar psycopg2 ou psql fallback via subprocess se necessario
try:
    import psycopg2
    USE_PSYCOPG2 = True
except ImportError:
    USE_PSYCOPG2 = False
    import subprocess

DB_HOST = "127.0.0.1"
DB_NAME = "casadb"
DB_USER = "casadb_user"
DB_PASS = "casadb_password_2026"

def get_db_connection():
    if USE_PSYCOPG2:
        return psycopg2.connect(
            dbname=DB_NAME,
            user=DB_USER,
            password=DB_PASS,
            host=DB_HOST,
            port=5432
        )
    return None

def query_db(sql, params=None):
    if USE_PSYCOPG2:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute(sql, params or ())
        try:
            res = cur.fetchall()
        except Exception:
            res = []
        conn.commit()
        conn.close()
        return res
    else:
        # Fallback via psql command
        env = os.environ.copy()
        env["PGPASSWORD"] = DB_PASS
        cmd = ["psql", "-h", DB_HOST, "-U", DB_USER, "-d", DB_NAME, "-t", "-A", "-F", "|", "-c", sql]
        out = subprocess.check_output(cmd, env=env).decode("utf-8")
        rows = []
        for line in out.strip().split("\n"):
            if line:
                rows.append(line.split("|"))
        return rows

def exec_db(sql, params=None):
    if USE_PSYCOPG2:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute(sql, params or ())
        conn.commit()
        conn.close()
    else:
        env = os.environ.copy()
        env["PGPASSWORD"] = DB_PASS
        cmd = ["psql", "-h", DB_HOST, "-U", DB_USER, "-d", DB_NAME, "-c", sql]
        subprocess.check_call(cmd, env=env)

def executar_acao(tarefa_id, titulo, tipo_acao, payload, target_node):
    print("[Scheduler] Executando tarefa #{0} '{1}' ({2})...".format(tarefa_id, titulo, tipo_acao))
    resultado = "Executado"

    try:
        if tipo_acao == 'comando_jarvis':
            req = urllib.request.Request(
                "http://127.0.0.1/api/jarvis.php",
                data=json.dumps({"comando": payload}).encode("utf-8"),
                headers={"Content-Type": "application/json", "X-API-Key": "jarvis_secret_token_2026"}
            )
            with urllib.request.urlopen(req, timeout=35) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                resultado = "JARVIS Respondeu: " + str(data.get("resposta", ""))[:120]

        elif tipo_acao == 'aviso_fala':
            if target_node == 'local' or not target_node:
                req = urllib.request.Request(
                    "http://127.0.0.1:8097/falar",
                    data=json.dumps({"texto": payload, "speaker": "padrao", "reproduzir": True}).encode("utf-8"),
                    headers={"Content-Type": "application/json"}
                )
                with urllib.request.urlopen(req, timeout=15) as resp:
                    resultado = "Áudio falado no alto-falante local"
            else:
                # Disparo no Nó Satélite remoto (ex: 192.168.2.6)
                node_url = "http://{0}:8098/falar".format(target_node)
                req = urllib.request.Request(
                    node_url,
                    data=json.dumps({"texto": payload}).encode("utf-8"),
                    headers={"Content-Type": "application/json"}
                )
                with urllib.request.urlopen(req, timeout=15) as resp:
                    resultado = "Áudio transmitido e tocado no satélite {0}".format(target_node)

        elif tipo_acao == 'dispositivo_devpar':
            # Formato iddevice=1&devparname=dev1&valor=1
            import urllib.parse
            params = dict(urllib.parse.parse_qsl(payload))
            iddev = int(params.get("iddevice", 1))
            parname = params.get("devparname", "dev1")
            val = params.get("valor", "1")
            sql_up = "UPDATE devpar SET devvalue = '{0}' WHERE iddevice = {1} AND devparname = '{2}'".format(val, iddev, parname)
            exec_db(sql_up)
            resultado = "Dispositivo {0} alterado para {1}".format(iddev, val)

    except Exception as e:
        resultado = "Erro na execucao: " + str(e)
        print("[Scheduler ERRO]:", e)

    # Registrar log de auditoria
    try:
        sql_log = "INSERT INTO comandos_log (comando, origem, resultado) VALUES ('Agendamento: {0}', 'SCHEDULER', '{1}')".format(
            titulo.replace("'", "''"), resultado.replace("'", "''")
        )
        exec_db(sql_log)
    except Exception as ex:
        print("[Log Error]:", ex)

def verificar_agendamentos():
    now = datetime.datetime.now()
    hora_str = now.strftime("%H:%M")
    dow = str((now.weekday() + 1) % 7) # 0=domingo, 1=segunda ... 6=sabado
    dow_iso = str(now.isoweekday()) # 1=segunda ... 7=domingo

    sql = "SELECT id, titulo, horario, dias_semana, tipo_acao, payload, target_node, ultima_execucao FROM tarefas_agendadas WHERE ativo = TRUE"
    
    try:
        rows = query_db(sql)
    except Exception as e:
        print("[Scheduler] Erro ao consultar tarefas:", e)
        return

    for r in rows:
        tarefa_id = int(r[0])
        titulo = str(r[1])
        horario = str(r[2]).strip()
        dias_semana = str(r[3]).strip()
        tipo_acao = str(r[4]).strip()
        payload = str(r[5]).strip()
        target_node = str(r[6]).strip()
        ult_exec = r[7]

        # Verificar se já rodou no minuto atual
        if ult_exec:
            if isinstance(ult_exec, str):
                try:
                    ult_dt = datetime.datetime.strptime(ult_exec[:16], "%Y-%m-%d %H:%M")
                    if ult_dt.strftime("%Y-%m-%d %H:%M") == now.strftime("%Y-%m-%d %H:%M"):
                        continue
                except Exception:
                    pass
            elif hasattr(ult_exec, "strftime"):
                if ult_exec.strftime("%Y-%m-%d %H:%M") == now.strftime("%Y-%m-%d %H:%M"):
                    continue

        # Validar dia da semana
        if dias_semana != "*" and dias_semana != "":
            dias_permitidos = [d.strip() for d in dias_semana.split(",")]
            if dow not in dias_permitidos and dow_iso not in dias_permitidos:
                continue

        # Validar horário
        disparar = False
        if horario == hora_str:
            disparar = True
        elif horario.startswith("*/"):
            try:
                intervalo = int(horario[2:])
                if now.minute % intervalo == 0:
                    disparar = True
            except Exception:
                pass

        if disparar:
            executar_acao(tarefa_id, titulo, tipo_acao, payload, target_node)
            sql_up = "UPDATE tarefas_agendadas SET ultima_execucao = CURRENT_TIMESTAMP WHERE id = {0}".format(tarefa_id)
            exec_db(sql_up)

def main():
    print("=== JARVIS - MOTOR DE AGENDAMENTO RESIDENCIAL INICIADO ===")
    while True:
        try:
            verificar_agendamentos()
        except Exception as e:
            print("[Scheduler Exception]:", e)
        time.sleep(25)

if __name__ == "__main__":
    main()
