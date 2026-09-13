#!/usr/bin/env python3
"""
JARVIS RESIDENCIAL - SERVIÇO DE TÚNEL EXTERNO SEGURO (HTTPS)
Gerencia a conexão remota criptografada via Cloudflare Tunnel
"""

import subprocess
import re
import time
import json
import psycopg2
import os

DB_CONFIG = {
    'host': '127.0.0.1',
    'port': 5432,
    'dbname': 'casadb',
    'user': 'casadb_user',
    'password': 'casadb_password_2026'
}

STATUS_FILE = '/var/www/html/api/tunnel_status.json'

def get_db_connection():
    return psycopg2.connect(**DB_CONFIG)

def update_tunnel_status(url, status='online'):
    try:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute("""
            INSERT INTO configuracoes_sistema (chave, valor) 
            VALUES ('external_web_url', %s) 
            ON CONFLICT (chave) DO UPDATE SET valor = %s
        """, (url, url))
        cur.execute("""
            INSERT INTO comandos_log (comando, origem, resultado) 
            VALUES (%s, 'TUNEL_EXTERNO', %s)
        """, (f"Túnel Público Ativo: {url}", status))
        conn.commit()
        cur.close()
        conn.close()
    except Exception as e:
        print(f"[DB ERROR] {e}")

    try:
        with open(STATUS_FILE, 'w') as f:
            json.dump({
                'status': status,
                'url': url,
                'atualizado_em': time.strftime('%Y-%m-%d %H:%M:%S')
            }, f)
        os.chmod(STATUS_FILE, 0o644)
    except Exception as e:
        print(f"[FILE ERROR] {e}")

def get_tunnel_token():
    try:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute("SELECT valor FROM configuracoes_sistema WHERE chave = 'external_tunnel_token' LIMIT 1")
        row = cur.fetchone()
        cur.close()
        conn.close()
        if row and row[0] and len(row[0].strip()) > 10:
            return row[0].strip()
    except Exception:
        pass
    return None

def main():
    print("[CASA-TUNNEL] Iniciando monitoramento do túnel externo seguro...")
    
    while True:
        token = get_tunnel_token()
        if token:
            print(f"[CASA-TUNNEL] Utilizando Token personalizado de Túnel Cloudflare")
            cmd = ['/usr/local/bin/cloudflared', 'tunnel', 'run', '--token', token]
        else:
            print("[CASA-TUNNEL] Utilizando Quick Tunnel Seguro (trycloudflare.com)")
            cmd = ['/usr/local/bin/cloudflared', 'tunnel', '--edge-ip-version', '4', '--protocol', 'http2', '--url', 'http://127.0.0.1:80', '--no-autoupdate']

        process = subprocess.Popen(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            universal_newlines=True,
            bufsize=1
        )

        tunnel_url = None
        url_regex = re.compile(r'https://[a-zA-Z0-9-]+\.trycloudflare\.com')

        for line in process.stdout:
            print(f"[cloudflared] {line.strip()}")
            match = url_regex.search(line)
            if match and not tunnel_url:
                tunnel_url = match.group(0)
                print(f"\n=======================================================")
                print(f"[JARVIS] TÚNEL PÚBLICO HTTPS ESTABELECIDO COM SUCESSO!")
                print(f"[JARVIS] URL EXTERNA: {tunnel_url}")
                print(f"=======================================================\n")
                update_tunnel_status(tunnel_url, 'online')

        process.wait()
        print("[CASA-TUNNEL] Processo cloudflared finalizado. Reiniciando em 5 segundos...")
        update_tunnel_status('', 'offline')
        time.sleep(5)

if __name__ == '__main__':
    main()
