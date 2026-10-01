#!/usr/bin/env python3
"""Atualizador ativo. Somente releases explicitamente liberadas na API são aplicadas."""
import argparse
import configparser
import fcntl
import hashlib
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request
from pathlib import Path

CATALOG = {
    'arm-agent': ('clusters/comunicacao/arm-agent/', '/opt/casa-node-agent', 'casa-node-agent'),
    'scheduler': ('clusters/automacao/casa-scheduler/', '/opt/casa-scheduler', 'casa-scheduler'),
    'google-home': ('clusters/voz/google-home-agent/', '/home/mmm/servicos/google-home-agent', 'casa-google-home'),
    'tts': ('clusters/voz/tts/', '/home/mmm/servicos/tts', 'casa-tts'),
    'espcam': ('clusters/visao/espcam/', '/opt/casa/espcam', ''),
    'avatar': ('clusters/visao/raspberrypi-avatar-agent/meta-casa-avatar/recipes-casa/casa-avatar/files/', '/opt/casa-avatar', 'casa-avatar'),
    'web-agent': ('clusters/pesquisa/web-agent/', '/opt/jarvis-web-agent', 'jarvis-web-agent'),
    'tunnel': ('clusters/infraestrutura/casa-tunnel/', '/opt/casa-tunnel', 'casa-tunnel'),
    'runpod': ('clusters/infraestrutura/runpod-agent/', '/opt/casa/servicos/runpod-agent', 'casa-runpod-agent'),
    'cluster-site': ('clusters/site/', '/opt/casa/site', 'casa-cluster-site'),
    'ssh-agent': ('clusters/infraestrutura/casa-ssh-agent/', '/opt/casa/ssh-agent', 'casa-ssh-agent'),
    'update-agent': ('clusters/infraestrutura/casa-cluster-update/', '/opt/casa/cluster-update', 'casa-cluster-update'),
}

def safe_file(value):
    return isinstance(value, str) and len(value) <= 240 and all(re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]*', p) for p in value.split('/')) and bool(re.search(r'\.(py|html|css|js|sh)$', value))

def validate(manifest):
    if manifest.get('schema') != 1 or not re.fullmatch(r'(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})', str(manifest.get('version', ''))):
        raise ValueError('Manifesto/versao invalido')
    if not re.fullmatch(r'[a-f0-9]{40}', str(manifest.get('source_commit', ''))):
        raise ValueError('Commit fixo obrigatorio')
    components = manifest.get('components')
    if not isinstance(components, dict) or not 1 <= len(components) <= len(CATALOG):
        raise ValueError('Integradores invalidos')
    total = 0
    for name, component in components.items():
        if name not in CATALOG or not isinstance(component, dict):
            raise ValueError('Integrador desconhecido')
        files = component.get('files')
        if not isinstance(files, list) or not 1 <= len(files) <= 32:
            raise ValueError('Lista de arquivos invalida')
        targets = set()
        for item in files:
            source, target = item.get('source'), item.get('target')
            size = item.get('size')
            if not safe_file(source) or not safe_file(target) or not source.startswith(CATALOG[name][0]) or target in targets:
                raise ValueError('Caminho proibido ou repetido')
            # Configuracao e dados nunca fazem parte de uma release.
            if any(re.search(r'\.(cfg|env|ini|onnx|wav|db|sqlite|json)$', p, re.I) for p in (source + '/' + target).split('/')):
                raise ValueError('Configuracao/dados protegidos')
            if type(size) is not int or not 1 <= size <= 5242880 or not re.fullmatch(r'[a-f0-9]{64}', str(item.get('sha256', ''))):
                raise ValueError('Hash/tamanho invalido')
            targets.add(target)
            total += size
    if total > 52428800:
        raise ValueError('Release excede 50 MB')

def load_config(path):
    path = Path(path)
    if not path.exists():
        path.parent.mkdir(parents=True, exist_ok=True)
        cfg = configparser.ConfigParser(interpolation=None)
        cfg['update'] = {'api_url': 'https://maurinsoft.com.br/casa/api/v1/updates.php', 'device_id': socket.gethostname(), 'device_token': '', 'state_dir': '/var/lib/casa-cluster-update'}
        for name, (_, root, service) in CATALOG.items():
            cfg[name] = {'root': root, 'service': service}
        # Criação exclusiva: nunca sobrescrever configuração existente, inclusive em corrida.
        try:
            fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        except FileExistsError:
            pass
        else:
            with os.fdopen(fd, 'w') as handle:
                cfg.write(handle)
    cfg = configparser.ConfigParser(interpolation=None)
    with path.open() as handle:
        cfg.read_file(handle)
    if not cfg.has_section('update'):
        raise ValueError('Secao update obrigatoria no config.cfg')
    return cfg

def atomic_json(path, value):
    path = Path(path)
    fd, tmp = tempfile.mkstemp(dir=str(path.parent))
    try:
        with os.fdopen(fd, 'w') as handle:
            json.dump(value, handle, indent=2)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(tmp, 0o644)
        os.replace(tmp, str(path))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def api(cfg, action, payload=None):
    settings = cfg['update']
    url = settings['api_url']
    if not url.startswith('https://') or not settings.get('device_token'):
        raise ValueError('Configure HTTPS e device_token no config.cfg')
    url += ('&' if '?' in url else '?') + 'acao=' + action
    request = urllib.request.Request(url, data=None if payload is None else json.dumps(payload).encode(), headers={'Content-Type': 'application/json', 'X-Device-Id': settings['device_id'], 'X-Device-Token': settings['device_token']})
    with urllib.request.urlopen(request, timeout=30) as response:
        result = json.loads(response.read(1048577))
    if result.get('status') != 'ok':
        raise RuntimeError('API rejeitou a operacao')
    return result

def command(args):
    subprocess.run(args, check=True, timeout=60, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

def install_component(root, files, stage, service, version):
    root = Path(root).resolve()
    backup = []
    was_active = bool(service) and subprocess.run(['systemctl', 'is-active', '--quiet', service]).returncode == 0
    try:
        for item in files:
            target = root / item['target']
            if target.is_symlink() or root not in target.resolve().parents:
                raise ValueError('Destino fora da instalacao ou link simbolico')
            target.parent.mkdir(parents=True, exist_ok=True)
            old = stage / ('backup-' + str(len(backup)))
            existed = target.exists()
            if existed:
                shutil.copy2(str(target), str(old))
            backup.append((target, old if existed else None))
            fd, temp = tempfile.mkstemp(dir=str(target.parent))
            os.close(fd)
            try:
                shutil.copyfile(str(stage / item['sha256']), temp)
                os.chmod(temp, target.stat().st_mode & 0o777 if existed else (0o755 if target.suffix == '.sh' else 0o644))
                if existed:
                    stat = target.stat()
                    os.chown(temp, stat.st_uid, stat.st_gid)
                os.replace(temp, str(target))
            finally:
                if os.path.exists(temp):
                    os.unlink(temp)
        # O updater oneshot se atualiza para a proxima execução, sem reiniciar a si mesmo.
        if was_active and service != 'casa-cluster-update':
            command(['systemctl', 'restart', service])
            time.sleep(2)
            command(['systemctl', 'is-active', '--quiet', service])
    except Exception:
        for target, old in reversed(backup):
            if old is None:
                target.unlink(missing_ok=True)
            else:
                os.replace(str(old), str(target))
        if was_active and service != 'casa-cluster-update':
            command(['systemctl', 'restart', service])
        raise

def poll(cfg, check=False):
    state_dir = Path(cfg['update']['state_dir'])
    state_dir.mkdir(parents=True, exist_ok=True)
    os.chmod(str(state_dir), 0o755)
    with (state_dir / 'update.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state_file = state_dir / 'state.json'
        state = json.loads(state_file.read_text()) if state_file.exists() else {'components': {}, 'pending_reports': []}
        # Persistir relatorios pendentes permite reenvio apos falha de rede.
        pending = []
        for report in state.get('pending_reports', []):
            try:
                api(cfg, 'report', report)
            except Exception:
                pending.append(report)
        state['pending_reports'] = pending
        atomic_json(state_file, state)
        release = api(cfg, 'current').get('release')
        if release is None:
            return {'status': 'no-release'}
        manifest = release['manifest']
        validate(manifest)
        if check:
            return {'status': 'available', 'release_id': release['id'], 'version': manifest['version']}
        failures = []
        with tempfile.TemporaryDirectory(dir=str(state_dir)) as temp:
            stage = Path(temp)
            # Validar todos os downloads antes de tocar em qualquer integrador.
            selected = []
            for name, component in manifest['components'].items():
                default_root, default_service = CATALOG[name][1:]
                root = cfg.get(name, 'root', fallback=default_root)
                service = cfg.get(name, 'service', fallback=default_service)
                previous = state['components'].get(name, {})
                if previous.get('release_id') == release['id']:
                    continue
                if previous.get('version') and tuple(map(int, manifest['version'].split('.'))) <= tuple(map(int, previous['version'].split('.'))):
                    raise ValueError('Downgrade recusado para ' + name)
                if not Path(root).is_dir():
                    state['pending_reports'].append({'release_id': release['id'], 'component': name, 'status': 'not_installed'})
                    continue
                for item in component['files']:
                    url = 'https://raw.githubusercontent.com/marcelomaurin/casa/' + manifest['source_commit'] + '/' + item['source']
                    with urllib.request.urlopen(url, timeout=30) as response:
                        data = response.read(item['size'] + 1)
                    if len(data) != item['size'] or hashlib.sha256(data).hexdigest() != item['sha256']:
                        raise ValueError('Download divergente: ' + item['source'])
                    (stage / item['sha256']).write_bytes(data)
                    if item['target'].endswith('.py'):
                        compile(data, item['target'], 'exec')
                    elif item['target'].endswith('.sh'):
                        command(['bash', '-n', str(stage / item['sha256'])])
                selected.append((name, component, root, service))
            for name, component, root, service in selected:
                report = {'release_id': release['id'], 'component': name}
                try:
                    install_component(root, component['files'], stage, service, manifest['version'])
                    state['components'][name] = {'version': manifest['version'], 'release_id': release['id'], 'commit': manifest['source_commit']}
                    report.update(status='updated', installed_version=manifest['version'])
                except Exception as error:
                    failures.append(name)
                    report.update(status='failed', details={'error_type': type(error).__name__})
                state['pending_reports'].append(report)
                atomic_json(state_file, state)
        remaining = []
        for report in state['pending_reports']:
            try:
                api(cfg, 'report', report)
            except Exception:
                remaining.append(report)
        state['pending_reports'] = remaining
        state['last_update'] = {'status': 'failed' if failures else 'success', 'timestamp': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'commit_after': manifest['source_commit'], 'version': manifest['version'], 'updated_components': [name for name, _, _, _ in selected if name not in failures], 'failed_components': failures}
        atomic_json(state_file, state)
        if failures:
            raise RuntimeError('Falha nos integradores: ' + ', '.join(failures))
        return {'status': 'success', 'version': manifest['version'], 'pending_reports': len(remaining)}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', default='/etc/casa/cluster-update/config.cfg')
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--status', action='store_true')
    parser.add_argument('--init-config', action='store_true')
    args = parser.parse_args()
    try:
        cfg = load_config(args.config)
        if args.init_config:
            print('Configure api_url, device_id e device_token em ' + args.config)
        elif args.status:
            path = Path(cfg['update']['state_dir']) / 'state.json'
            print(path.read_text() if path.exists() else '{}')
        else:
            print(json.dumps(poll(cfg, args.check)))
    except Exception as error:
        # Evita expor credenciais em respostas HTTP ou logs.
        print('Atualizacao falhou: ' + type(error).__name__, file=sys.stderr)
        return 1
    return 0

if __name__ == '__main__':
    sys.exit(main())
