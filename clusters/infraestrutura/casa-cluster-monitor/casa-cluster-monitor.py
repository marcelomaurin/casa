#!/usr/bin/env python3
"""
CASA / JARVIS - Cluster Monitor Daemon (Python)
Monitoramento em background dos nos do cluster ARM sob padrao invertido:
 - O no envia heartbeat a cada 20s.
 - Se em 40s nao responder, o no e considerado offline no banco.
"""

import os
import sys
import time
import json
import logging
from datetime import datetime

logging.basicConfig(
    level=logging.INFO,
    format="[%(asctime)s] [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S"
)
logger = logging.getLogger("casa-cluster-monitor")

TIMEOUT_SEC = int(os.environ.get("HEARTBEAT_TIMEOUT_SEC", 40))
INTERVAL_SEC = int(os.environ.get("CHECK_INTERVAL_SEC", 5))

DB_HOST = os.environ.get("JARVIS_DB_HOST", "localhost")
DB_PORT = int(os.environ.get("JARVIS_DB_PORT", 3306))
DB_NAME = os.environ.get("JARVIS_DB_NAME", "u820932905_casadb")
DB_USER = os.environ.get("JARVIS_DB_USER", "u820932905_root")
DB_PASS = os.environ.get("JARVIS_DB_PASS", "")

def get_connection():
    # Tenta pymysql ou mysql.connector
    try:
        import pymysql
        return pymysql.connect(
            host=DB_HOST,
            port=DB_PORT,
            user=DB_USER,
            password=DB_PASS,
            database=DB_NAME,
            charset="utf8mb4",
            autocommit=True
        )
    except ImportError:
        pass

    try:
        import mysql.connector
        return mysql.connector.connect(
            host=DB_HOST,
            port=DB_PORT,
            user=DB_USER,
            password=DB_PASS,
            database=DB_NAME,
            autocommit=True
        )
    except ImportError:
        pass

    return None

def check_nodes_cycle(conn):
    with conn.cursor() as cur:
        # Obter timestamp atual do servidor MySQL
        cur.execute("SELECT UNIX_TIMESTAMP()")
        now_ts = cur.fetchone()[0]

        # 1. Checar dispositivos_cluster
        sql = """
            SELECT id, device_id, nome, status, UNIX_TIMESTAMP(ultimo_heartbeat) AS last_seen
            FROM dispositivos_cluster
            WHERE (tipo = 'linux-arm' OR tipo = 'arm-agent' OR capabilities LIKE '%arm-agent%')
              AND credential_revoked_at IS NULL
        """
        cur.execute(sql)
        rows = cur.fetchall()

        for row in rows:
            dev_id_db, did, nome, curr_status, last_seen = row[0], row[1], row[2], str(row[3] or "").lower(), row[4]
            diff = (now_ts - last_seen) if last_seen is not None else None

            if diff is None or diff > TIMEOUT_SEC:
                if curr_status == 'online':
                    cur.execute("UPDATE dispositivos_cluster SET status = 'offline' WHERE id = %s", (dev_id_db,))
                    sec_str = f"{diff}s" if diff is not None else "sem registro"
                    logger.warning(f"Nó '{nome}' ({did}) CAIU! Sem heartbeat há {sec_str} (limite {TIMEOUT_SEC}s).")
            else:
                if curr_status != 'online':
                    cur.execute("UPDATE dispositivos_cluster SET status = 'online' WHERE id = %s", (dev_id_db,))
                    logger.info(f"Nó '{nome}' ({did}) está ONLINE (heartbeat há {diff}s).")

        # 2. Checar arm_nodes legados
        try:
            sql_arm = "SELECT id, device_id, hostname, status, UNIX_TIMESTAMP(ultimo_ping) FROM arm_nodes"
            cur.execute(sql_arm)
            arm_rows = cur.fetchall()
            for a in arm_rows:
                aid, adid, host, a_status, a_last = a[0], a[1], a[2], str(a[3] or "").lower(), a[4]
                diff = (now_ts - a_last) if a_last is not None else None
                if diff is None or diff > TIMEOUT_SEC:
                    if a_status == 'online':
                        cur.execute("UPDATE arm_nodes SET status = 'offline' WHERE id = %s", (aid,))
                else:
                    if a_status != 'online':
                        cur.execute("UPDATE arm_nodes SET status = 'online' WHERE id = %s", (aid,))
        except Exception:
            pass

def main():
    logger.info(f"Iniciando CASA Cluster Monitor (Timeout: {TIMEOUT_SEC}s, Intervalo: {INTERVAL_SEC}s)...")
    while True:
        try:
            conn = get_connection()
            if conn:
                check_nodes_cycle(conn)
                conn.close()
            else:
                logger.error("Driver MySQL (pymysql / mysql-connector-python) nao instalado.")
                time.sleep(15)
        except Exception as e:
            logger.error(f"Erro no ciclo do monitor: {e}")
        time.sleep(INTERVAL_SEC)

if __name__ == "__main__":
    main()
