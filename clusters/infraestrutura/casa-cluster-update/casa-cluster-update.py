#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CASA / JARVIS - Servico e Script de Atualizacao do Cluster
Compativel com Python 3.4+ (Raspberry Pi, Cubieboard, Linux ARM/x86).
"""

import os
import sys
import json
import time
import shutil
import socket
import argparse
import subprocess
import urllib.request
import urllib.parse
from datetime import datetime

DEFAULT_REPO_PATHS = [
    "/home/mmm/projetos/maurinsoft/casa",
    "/home/pi/projetos/maurinsoft/casa",
    "/root/projetos/maurinsoft/casa",
    "/opt/casa/repo",
]

LAST_UPDATE_FILE = "/opt/casa/site/last_update.json"
PROGRESS_FILE = "/tmp/casa_update_progress.json"
ENV_FILE = "/etc/casa-node-agent.env"

def run_cmd(cmd, cwd=None):
    try:
        p = subprocess.Popen(
            cmd,
            cwd=cwd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            universal_newlines=True
        )
        out, err = p.communicate()
        return p.returncode, (out or "").strip(), (err or "").strip()
    except Exception as e:
        return 1, "", str(e)

def find_repo_dir(custom_path=None):
    if custom_path and os.path.isdir(os.path.join(custom_path, ".git")):
        return custom_path
    for p in DEFAULT_REPO_PATHS:
        if os.path.isdir(os.path.join(p, ".git")):
            return p
    cur = os.path.abspath(os.getcwd())
    while len(cur) > 3:
        if os.path.isdir(os.path.join(cur, ".git")):
            return cur
        parent = os.path.dirname(cur)
        if parent == cur:
            break
        cur = parent
    return None

def get_env_map():
    env = {}
    if os.path.exists(ENV_FILE):
        try:
            with open(ENV_FILE, "r", encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, v = line.split("=", 1)
                        env[k.strip()] = v.strip().strip("'\"")
        except Exception:
            pass
    return env

def set_progress(step, msg, pct=0, error=None):
    data = {
        "step": step,
        "message": msg,
        "percent": pct,
        "error": error,
        "updated_at": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
    }
    try:
        with open(PROGRESS_FILE, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2)
    except Exception:
        pass
    print("[{0}%] {1}".format(pct, msg))

def get_git_info(repo_dir):
    rc, head, _ = run_cmd(["git", "rev-parse", "HEAD"], cwd=repo_dir)
    rc2, subj, _ = run_cmd(["git", "log", "-1", "--format=%s"], cwd=repo_dir)
    rc3, branch, _ = run_cmd(["git", "rev-parse", "--abbrev-ref", "HEAD"], cwd=repo_dir)
    return {
        "commit": head if rc == 0 else "unknown",
        "subject": subj if rc2 == 0 else "",
        "branch": branch if rc3 == 0 else "master"
    }

def check_remote_updates(repo_dir):
    run_cmd(["git", "fetch", "origin", "master"], cwd=repo_dir)
    rc1, local_head, _ = run_cmd(["git", "rev-parse", "HEAD"], cwd=repo_dir)
    rc2, remote_head, _ = run_cmd(["git", "rev-parse", "origin/master"], cwd=repo_dir)
    if rc1 != 0 or rc2 != 0:
        return False, local_head, remote_head
    return (local_head != remote_head), local_head, remote_head

def sync_components(repo_dir):
    updated = []
    
    # 1. clusters/site -> /opt/casa/site
    site_src = os.path.join(repo_dir, "clusters", "site")
    site_dst = "/opt/casa/site"
    if os.path.isdir(site_src):
        os.makedirs(site_dst, exist_ok=True)
        for fname in ["index.html", "server.py"]:
            src = os.path.join(site_src, fname)
            if os.path.exists(src):
                dst = os.path.join(site_dst, fname)
                shutil.copy2(src, dst)
        run_cmd(["chmod", "+x", os.path.join(site_dst, "server.py")])
        updated.append("casa-cluster-site")

    # 2. clusters/comunicacao/arm-agent -> /opt/casa-node-agent
    agent_src = os.path.join(repo_dir, "clusters", "comunicacao", "arm-agent", "casa-node-agent.py")
    agent_dst = "/opt/casa-node-agent"
    if os.path.exists(agent_src):
        os.makedirs(agent_dst, exist_ok=True)
        shutil.copy2(agent_src, os.path.join(agent_dst, "casa-node-agent.py"))
        run_cmd(["chmod", "+x", os.path.join(agent_dst, "casa-node-agent.py")])
        updated.append("casa-node-agent")

    # 3. clusters/infraestrutura/runpod-agent -> /opt/casa/servicos/runpod-agent
    runpod_src = os.path.join(repo_dir, "clusters", "infraestrutura", "runpod-agent", "casa-runpod-agent.py")
    runpod_dst = "/opt/casa/servicos/runpod-agent"
    if os.path.exists(runpod_src) and os.path.isdir(runpod_dst):
        shutil.copy2(runpod_src, os.path.join(runpod_dst, "casa-runpod-agent.py"))
        updated.append("casa-runpod-agent")

    # 4. clusters/automacao/casa-scheduler -> /opt/casa-scheduler
    sched_src = os.path.join(repo_dir, "clusters", "automacao", "casa-scheduler", "casa-scheduler.py")
    sched_dst = "/opt/casa-scheduler"
    if os.path.exists(sched_src) and os.path.isdir(sched_dst):
        shutil.copy2(sched_src, os.path.join(sched_dst, "casa-scheduler.py"))
        updated.append("casa-scheduler")

    # 5. clusters/infraestrutura/casa-tunnel -> /opt/casa-tunnel
    tun_src = os.path.join(repo_dir, "clusters", "infraestrutura", "casa-tunnel", "casa-tunnel.py")
    tun_dst = "/opt/casa-tunnel"
    if os.path.exists(tun_src) and os.path.isdir(tun_dst):
        shutil.copy2(tun_src, os.path.join(tun_dst, "casa-tunnel.py"))
        updated.append("casa-tunnel")

    # 6. clusters/infraestrutura/casa-cluster-update -> /opt/casa/cluster-update
    up_src = os.path.join(repo_dir, "clusters", "infraestrutura", "casa-cluster-update")
    up_dst = "/opt/casa/cluster-update"
    if os.path.isdir(up_src):
        os.makedirs(up_dst, exist_ok=True)
        for f in os.listdir(up_src):
            s = os.path.join(up_src, f)
            d = os.path.join(up_dst, f)
            if os.path.isfile(s):
                shutil.copy2(s, d)
        run_cmd(["chmod", "+x", os.path.join(up_dst, "casa-cluster-update.py")])
        updated.append("casa-cluster-update")

    return updated

def restart_services():
    restarted = []
    run_cmd(["systemctl", "daemon-reload"])

    for comp in ["casa-cluster-site", "casa-node-agent", "casa-runpod-agent", "casa-scheduler", "casa-tunnel"]:
        rc, out, _ = run_cmd(["systemctl", "is-enabled", comp])
        if rc == 0 or out.strip() == "enabled":
            print("Reiniciando servico {0}...".format(comp))
            run_cmd(["systemctl", "restart", comp])
            restarted.append(comp)

    return restarted

def notify_central_server(commit_info, restarted):
    env = get_env_map()
    base_url = env.get("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
    token = env.get("DEVICE_TOKEN", env.get("JARVIS_DEVICE_TOKEN", "casa_sec_a4c5a1a2fea405d668edf5934f67d9eb9df23d4c89c5c616"))
    dev_id = env.get("DEVICE_ID", env.get("JARVIS_DEVICE_ID", socket.gethostname()))

    payload = {
        "status": "online",
        "device_id": dev_id,
        "event": "cluster_updated",
        "commit": commit_info.get("commit"),
        "subject": commit_info.get("subject"),
        "restarted_services": restarted,
        "timestamp": int(time.time()),
        "iso_time": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
    }

    url = "{0}/api/v1/device.php".format(base_url)
    headers = {
        "Content-Type": "application/json",
        "X-Device-Id": dev_id,
        "X-Device-Token": token,
        "Authorization": "Bearer {0}".format(token),
        "User-Agent": "ClusterUpdate-Agent/1.0"
    }

    try:
        req = urllib.request.Request(url, data=json.dumps(payload).encode("utf-8"), headers=headers, method="POST")
        with urllib.request.urlopen(req, timeout=10) as resp:
            return True, resp.read().decode("utf-8")
    except Exception as e:
        return False, str(e)

def perform_update(repo_dir, force=False):
    set_progress("init", "Iniciando processo de atualizacao do cluster...", 5)
    
    info_before = get_git_info(repo_dir)
    set_progress("git_fetch", "Verificando atualizacoes no repositorio origin/master...", 20)
    has_updates, loc_h, rem_h = check_remote_updates(repo_dir)

    if not has_updates and not force:
        msg = "Cluster ja se encontra atualizado no commit {0}".format(loc_h[:7])
        set_progress("done", msg, 100)
        return {"status": "up-to-date", "commit": loc_h, "message": msg}

    set_progress("git_pull", "Atualizando arvore de trabalho (git pull origin master)...", 40)
    rc, out, err = run_cmd(["git", "pull", "--ff-only", "origin", "master"], cwd=repo_dir)
    if rc != 0:
        run_cmd(["git", "fetch", "origin", "master"], cwd=repo_dir)
        rc, out, err = run_cmd(["git", "reset", "--hard", "origin/master"], cwd=repo_dir)
        if rc != 0:
            err_msg = "Falha no git pull: " + err
            set_progress("error", err_msg, 100, error=err_msg)
            return {"status": "error", "error": err_msg}

    info_after = get_git_info(repo_dir)

    set_progress("sync_files", "Copiando modulos atualizados para /opt/casa/...", 65)
    updated_comps = sync_components(repo_dir)

    set_progress("restart_services", "Recarregando systemd e reiniciando servicos do cluster...", 85)
    restarted = restart_services()

    set_progress("notify", "Notificando servidor central e registrando status...", 95)
    notif_ok, notif_res = notify_central_server(info_after, restarted)

    last_record = {
        "status": "success",
        "timestamp": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ"),
        "commit_before": info_before["commit"],
        "commit_after": info_after["commit"],
        "commit_subject": info_after["subject"],
        "updated_components": updated_comps,
        "restarted_services": restarted,
        "notified_central": notif_ok
    }

    try:
        os.makedirs(os.path.dirname(LAST_UPDATE_FILE), exist_ok=True)
        with open(LAST_UPDATE_FILE, "w", encoding="utf-8") as f:
            json.dump(last_record, f, indent=2)
    except Exception:
        pass

    set_progress("done", "Atualizacao concluida com sucesso no commit {0}!".format(info_after["commit"][:7]), 100)
    return last_record

def main():
    parser = argparse.ArgumentParser(description="CASA Cluster Update Utility")
    parser.add_argument("--repo", default=None, help="Caminho do repositorio Git")
    parser.add_argument("--check", action="store_true", help="Apenas verifica se existem atualizacoes")
    parser.add_argument("--apply", action="store_true", help="Aplica a atualizacao completa")
    parser.add_argument("--force", action="store_true", help="Forca reinstalacao e reinicio de servicos")
    parser.add_argument("--status", action="store_true", help="Exibe informacoes da ultima atualizacao")
    args = parser.parse_args()

    if args.status:
        if os.path.exists(LAST_UPDATE_FILE):
            with open(LAST_UPDATE_FILE, "r", encoding="utf-8") as f:
                print(f.read())
        else:
            print(json.dumps({"status": "unknown", "message": "Nenhuma atualizacao registrada anteriormente."}))
        return

    repo_dir = find_repo_dir(args.repo)
    if not repo_dir:
        print("ERRO: Repositorio Git nao encontrado. Especifique com --repo /caminho/do/casa")
        sys.exit(1)

    if args.check:
        has_up, loc_h, rem_h = check_remote_updates(repo_dir)
        info = get_git_info(repo_dir)
        res = {
            "has_updates": has_up,
            "local_commit": loc_h,
            "remote_commit": rem_h,
            "subject": info["subject"]
        }
        print(json.dumps(res, indent=2))
        return

    res = perform_update(repo_dir, force=args.force)
    print("Resultado:", json.dumps(res, indent=2))

if __name__ == "__main__":
    main()
