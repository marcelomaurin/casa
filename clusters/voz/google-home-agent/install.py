#!/usr/bin/env python3
"""Instalacao no Raspberry; reutiliza credenciais privadas sem exibi-las."""
import argparse
import datetime
import json
import os
from pathlib import Path
import pwd
import secrets
import shutil
import subprocess
import sys

def env_file(path):
    values = {}
    if path.is_file():
        for line in path.read_text().splitlines():
            if line.strip() and not line.startswith('#') and '=' in line:
                key, value = line.split('=', 1)
                values[key.strip()] = value.strip().strip('"').strip("'")
    return values

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--credentials', default='/etc/casa-tarefas.env')
    args = parser.parse_args()
    if os.geteuid() != 0 or sys.version_info < (3, 11):
        raise RuntimeError('Instalacao requer administrador e Python 3.11+')
    user = pwd.getpwnam('mmm')
    source = Path(__file__).resolve().parent
    target = Path('/home/mmm/servicos/google-home-agent')
    conf = Path('/etc/casa/google-home-agent.env')
    service = Path('/etc/systemd/system/casa-google-home.service')
    worker = Path('/opt/casa-tarefas/casa-tarefas.py')
    if not worker.is_file():
        raise RuntimeError('Instale primeiro o consumidor casa-tarefas, usado como motor de reservas')
    cfg = env_file(conf)
    existing = env_file(Path(args.credentials))
    key = cfg.get('CASA_TAREFAS_CHAVE') or existing.get('CASA_TAREFAS_CHAVE')
    if not key:
        raise RuntimeError('Credencial tasks.worker ausente no arquivo privado')
    cfg.setdefault('CASA_BASE_URL', existing.get('CASA_BASE_URL', 'https://maurinsoft.com.br/casa'))
    cfg['CASA_TAREFAS_CHAVE'] = key
    cfg.setdefault('CASA_GOOGLE_HOME_API_TOKEN', secrets.token_urlsafe(32))
    cfg.setdefault('CASA_GOOGLE_HOME_PUBLIC_BASE_URL', 'http://192.168.2.13:8100')
    cfg.setdefault('CASA_GOOGLE_HOME_DEFAULT_DEVICE', '')
    cfg.setdefault('CASA_GOOGLE_HOME_KNOWN_HOSTS', '')
    cfg.setdefault('CASA_TAREFAS_WORKER_FILE', str(worker))
    cfg.setdefault('CASA_TAREFAS_AGENTE_WORKSPACE', '/var/lib/casa-tarefas/workspaces')
    names = ['google_home_agent.py','queue_bridge.py','requirements.txt','casa-google-home.service','install.py']
    for name in ['google_home_agent.py','queue_bridge.py']:
        compile((source/name).read_text(), name, 'exec')
    backup = Path('/var/backups/casa-google-home') / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, mode=0o700)
    for name in names:
        if (target/name).is_file():
            shutil.copy2(target/name, backup/name)
    for path, name in [(conf,'private.env'),(service,'installed.service')]:
        if path.is_file():
            shutil.copy2(path, backup/name)
            (backup/name).chmod(0o600)
    target.mkdir(parents=True, exist_ok=True)
    os.chown(target,user.pw_uid,user.pw_gid)
    if not (target/'venv/bin/python').is_file():
        subprocess.run(['runuser','-u','mmm','--','python3','-m','venv',str(target/'venv')],check=True)
    # Dependencias antes de interromper uma instalacao existente.
    subprocess.run(['runuser','-u','mmm','--',str(target/'venv/bin/python'),'-m','pip','install','-r',str(source/'requirements.txt')],check=True,timeout=300)
    active = subprocess.run(['systemctl','is-active','--quiet','casa-google-home.service']).returncode == 0
    subprocess.run(['systemctl','stop','casa-google-home.service'],check=False)
    try:
        for name in names:
            if (source/name).resolve() != (target/name).resolve():
                shutil.copyfile(source/name,target/name)
            (target/name).chmod(0o644)
            os.chown(target/name,user.pw_uid,user.pw_gid)
        conf.parent.mkdir(mode=0o750,parents=True,exist_ok=True)
        os.chown(conf.parent,0,user.pw_gid)
        temp=conf.with_suffix('.new')
        with temp.open('w',opener=lambda path,flags:os.open(path,flags,0o600)) as stream:
            for key,value in cfg.items():
                if '\n' in value or '\r' in value:
                    raise RuntimeError('Valor de configuracao invalido')
                stream.write(key+'='+value+'\n')
        os.chown(temp,0,user.pw_gid);temp.chmod(0o640);os.replace(temp,conf)
        shutil.copyfile(source/'casa-google-home.service',service)
        subprocess.run(['systemctl','daemon-reload'],check=True)
        subprocess.run(['systemctl','enable','--now','casa-tts.service'],check=True)
        subprocess.run(['systemctl','enable','--now','casa-google-home.service'],check=True)
    except Exception:
        for name in names:
            if (backup/name).exists():shutil.copy2(backup/name,target/name)
        for path,name in [(conf,'private.env'),(service,'installed.service')]:
            if (backup/name).exists():shutil.copy2(backup/name,path)
        subprocess.run(['systemctl','daemon-reload'])
        if active:subprocess.run(['systemctl','start','casa-google-home.service'])
        raise
    print(json.dumps({'installed':True,'backup':str(backup),'service':'casa-google-home.service','credentials_printed':False}))

if __name__ == '__main__':
    main()
