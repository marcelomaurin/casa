#!/usr/bin/env python3
"""Atualizador dos integradores CASA.

Acompanha a branch configurada (master) direto do Git: a cada execução consulta o
commit mais recente com `git ls-remote`; quando muda (ou quando o painel pede uma
atualização forçada), busca o commit num cache Git local, instala nos integradores
já presentes neste nó apenas os arquivos de código alterados, reinicia os serviços
que estavam ativos e informa à API central o commit instalado.
"""
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

UPDATER_VERSION = '2.0.0'
DEFAULT_REPO = 'https://github.com/marcelomaurin/casa.git'
DEFAULT_BRANCH = 'master'
STATE_DIR = '/var/lib/casa-cluster-update'

# integrador -> (pasta no repositório, pasta de instalação padrão, serviço systemd)
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
MAX_FILE = 5 * 1024 * 1024
PROTECTED = re.compile(r'\.(cfg|env|ini|onnx|wav|db|sqlite|json)$', re.I)
SKIP_TARGETS = ('build_manifest.py', 'install.sh')


def safe_file(value):
    """Somente código (.py .html .css .js .sh), caminho relativo simples e nunca configuração/dados."""
    if not isinstance(value, str) or not value or len(value) > 240:
        return False
    parts = value.split('/')
    if not all(re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]*', p) for p in parts):
        return False
    if any(PROTECTED.search(p) for p in parts):
        return False
    return bool(re.search(r'\.(py|html|css|js|sh)$', value))


def load_config(path):
    path = Path(path)
    if not path.exists():
        path.parent.mkdir(parents=True, exist_ok=True)
        cfg = configparser.ConfigParser(interpolation=None)
        cfg['update'] = {
            'api_url': 'https://maurinsoft.com.br/casa/api/v1/updates.php',
            'device_id': socket.gethostname(), 'device_token': '',
            'repo_url': DEFAULT_REPO, 'branch': DEFAULT_BRANCH, 'state_dir': STATE_DIR,
        }
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
        # Legível por outros serviços do nó (agente ARM, portal do cluster). Sem segredos.
        os.chmod(tmp, 0o644)
        os.replace(tmp, str(path))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


# --------------------------------------------------------------------------- API

def api(cfg, action, payload=None):
    settings = cfg['update']
    url = settings.get('api_url', '')
    if not url.startswith('https://') or not settings.get('device_token'):
        raise ValueError('Configure HTTPS e device_token no config.cfg')
    url += ('&' if '?' in url else '?') + 'acao=' + action
    request = urllib.request.Request(
        url, data=None if payload is None else json.dumps(payload).encode(),
        headers={'Content-Type': 'application/json', 'Accept': 'application/json',
                 'X-Device-Id': settings.get('device_id', ''), 'X-Device-Token': settings['device_token']})
    with urllib.request.urlopen(request, timeout=30) as response:
        result = json.loads(response.read(1048577))
    if result.get('status') != 'ok':
        raise RuntimeError('API rejeitou a operacao')
    return result


# --------------------------------------------------------------------------- Git

def git(args, cwd=None, timeout=600):
    env = dict(os.environ, GIT_TERMINAL_PROMPT='0', LC_ALL='C')
    result = subprocess.run(['git'] + list(args), cwd=cwd, env=env, timeout=timeout,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode != 0:
        raise RuntimeError('git ' + args[0] + ' falhou: ' + result.stderr.decode('utf-8', 'replace').strip()[:300])
    return result.stdout


def remote_head(repo_url, branch):
    out = git(['ls-remote', repo_url, 'refs/heads/' + branch], timeout=60).decode()
    match = re.match(r'^([0-9a-f]{40})\s', out)
    if not match:
        raise RuntimeError('Branch ' + branch + ' nao encontrada em ' + repo_url)
    return match.group(1)


def fetch_commit(repo_dir, repo_url, branch):
    """Cache Git bare, raso e sem blobs: só os arquivos instalados são baixados."""
    repo_dir = Path(repo_dir)
    if not (repo_dir / 'HEAD').exists():
        repo_dir.mkdir(parents=True, exist_ok=True)
        git(['init', '--bare', '-q', str(repo_dir)])
    git(['-C', str(repo_dir), 'config', 'remote.origin.url', repo_url])
    ref = '+refs/heads/' + branch + ':refs/remotes/origin/' + branch
    try:
        git(['-C', str(repo_dir), 'config', 'remote.origin.promisor', 'true'])
        git(['-C', str(repo_dir), 'config', 'remote.origin.partialclonefilter', 'blob:none'])
        git(['-C', str(repo_dir), 'fetch', '-q', '--no-tags', '--depth', '1', '--filter=blob:none', 'origin', ref])
    except RuntimeError:
        # Servidor ou git antigo sem clone parcial: busca completa e rasa.
        for key in ('remote.origin.promisor', 'remote.origin.partialclonefilter'):
            subprocess.run(['git', '-C', str(repo_dir), 'config', '--unset-all', key], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        git(['-C', str(repo_dir), 'fetch', '-q', '--no-tags', '--depth', '1', 'origin', ref])
    return git(['-C', str(repo_dir), 'rev-parse', 'refs/remotes/origin/' + branch]).decode().strip()


def component_files(repo_dir, commit, prefix):
    out = git(['-C', str(repo_dir), 'ls-tree', '-r', '--name-only', commit, '--', prefix]).decode()
    files = []
    for source in out.splitlines():
        target = source[len(prefix):]
        if not source.startswith(prefix) or not safe_file(source) or not safe_file(target):
            continue
        if target.startswith('tests/') or target in SKIP_TARGETS:
            continue
        files.append({'source': source, 'target': target})
    return files


def read_blob(repo_dir, commit, source):
    data = git(['-C', str(repo_dir), 'show', commit + ':' + source], timeout=300)
    if len(data) > MAX_FILE:
        raise ValueError('Arquivo grande demais: ' + source)
    return data


# --------------------------------------------------------------------------- instalação

def command(args):
    subprocess.run(args, check=True, timeout=60, stdout=subprocess.PIPE, stderr=subprocess.PIPE)


def check_syntax(target, data, stage_file):
    if target.endswith('.py'):
        compile(data, target, 'exec')
    elif target.endswith('.sh'):
        command(['bash', '-n', str(stage_file)])


def sha256_file(path):
    try:
        return hashlib.sha256(Path(path).read_bytes()).hexdigest()
    except OSError:
        return None


def install_component(root, files, stage, service):
    """Substitui atomicamente; em qualquer falha restaura os arquivos anteriores."""
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
        # O updater (oneshot) vale na próxima execução; não reinicia a si mesmo.
        if was_active and service != 'casa-cluster-update':
            command(['systemctl', 'restart', service])
            time.sleep(2)
            command(['systemctl', 'is-active', '--quiet', service])
        return was_active
    except Exception:
        for target, old in reversed(backup):
            if old is None:
                if target.exists():
                    target.unlink()
            else:
                os.replace(str(old), str(target))
        if was_active and service != 'casa-cluster-update':
            subprocess.run(['systemctl', 'restart', service], timeout=60)
        raise


# --------------------------------------------------------------------------- ciclo

def now_iso():
    return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())


def send_report(cfg, state, result, error=None):
    payload = {
        'hostname': socket.gethostname(), 'updater_version': UPDATER_VERSION,
        'branch': state.get('branch'), 'commit': state.get('commit'), 'remote_commit': state.get('remote_commit'),
        'result': result, 'error': (error or '')[:300], 'force_handled': state.get('force_handled', 0),
        'last_check': state.get('last_check'), 'last_update': (state.get('last_update') or {}).get('timestamp'),
        'components': {k: {'status': v.get('status'), 'commit': v.get('commit'), 'files_changed': v.get('files_changed', 0),
                           'updated_at': v.get('updated_at')} for k, v in state.get('components', {}).items()},
    }
    try:
        api(cfg, 'node_report', payload)
        return True
    except Exception:
        return False


def poll(cfg, check=False, force=False):
    settings = cfg['update']
    state_dir = Path(settings.get('state_dir', STATE_DIR))
    state_dir.mkdir(parents=True, exist_ok=True)
    os.chmod(str(state_dir), 0o755)
    repo_url = settings.get('repo_url', DEFAULT_REPO)
    with (state_dir / 'update.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state_file = state_dir / 'state.json'
        state = json.loads(state_file.read_text()) if state_file.exists() else {}
        state.setdefault('components', {})
        state.pop('pending_reports', None)  # formato antigo (releases)

        # Política central: branch e pedidos de atualização forçada feitos no painel.
        policy, api_error = {}, None
        try:
            policy = api(cfg, 'policy').get('policy') or {}
        except Exception as error:
            api_error = type(error).__name__
        branch = str(policy.get('branch') or settings.get('branch', DEFAULT_BRANCH))
        if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._/-]{0,99}', branch):
            branch = DEFAULT_BRANCH
        force_seq = int(policy.get('force_seq') or 0)
        forced = force or force_seq > int(state.get('force_handled') or 0)

        head = remote_head(repo_url, branch)
        state.update(branch=branch, remote_commit=head, last_check=now_iso())
        if check:
            atomic_json(state_file, state)
            return {'status': 'up-to-date' if head == state.get('commit') else 'available',
                    'installed': state.get('commit'), 'remote': head, 'branch': branch, 'forced_pending': forced}
        if head == state.get('commit') and not forced:
            atomic_json(state_file, state)
            send_report(cfg, state, 'up-to-date', api_error)
            return {'status': 'up-to-date', 'commit': head, 'branch': branch}

        commit = fetch_commit(state_dir / 'repo.git', repo_url, branch)
        failures, updated, unchanged = [], [], []
        with tempfile.TemporaryDirectory(dir=str(state_dir)) as temp:
            stage = Path(temp)
            for name, (prefix, default_root, default_service) in CATALOG.items():
                root = cfg.get(name, 'root', fallback=default_root)
                service = cfg.get(name, 'service', fallback=default_service)
                entry = {'commit': state['components'].get(name, {}).get('commit'), 'updated_at': now_iso()}
                if not Path(root).is_dir():
                    entry.update(status='not_installed')
                    state['components'][name] = entry
                    continue
                try:
                    files = component_files(state_dir / 'repo.git', commit, prefix)
                    changed = []
                    # Baixar e validar tudo antes de tocar no integrador.
                    for item in files:
                        data = read_blob(state_dir / 'repo.git', commit, item['source'])
                        item['sha256'] = hashlib.sha256(data).hexdigest()
                        (stage / item['sha256']).write_bytes(data)
                        check_syntax(item['target'], data, stage / item['sha256'])
                        if forced or sha256_file(Path(root) / item['target']) != item['sha256']:
                            changed.append(item)
                    # Serviços inativos não são iniciados automaticamente.
                    restarted = install_component(root, changed, stage, service) if changed else False
                    entry.update(status='updated' if changed else 'unchanged', commit=commit,
                                 files_changed=len(changed), restarted=restarted)
                    (updated if changed else unchanged).append(name)
                except Exception as error:
                    entry.update(status='failed', error=type(error).__name__ + ': ' + str(error)[:200])
                    failures.append(name)
                state['components'][name] = entry
                atomic_json(state_file, state)

        if not failures:
            state['commit'] = commit
        if forced:
            state['force_handled'] = max(force_seq, int(state.get('force_handled') or 0))
        state['last_update'] = {
            'status': 'failed' if failures else 'success', 'timestamp': now_iso(), 'forced': forced,
            'commit_before': state.get('last_update', {}).get('commit_after'), 'commit_after': commit, 'branch': branch,
            'updated_components': updated, 'unchanged_components': unchanged, 'failed_components': failures,
        }
        atomic_json(state_file, state)
        send_report(cfg, state, 'failed' if failures else 'updated', api_error)
        if failures:
            raise RuntimeError('Falha nos integradores: ' + ', '.join(failures))
        return {'status': 'updated', 'commit': commit, 'branch': branch, 'updated': updated, 'forced': forced}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--config', default='/etc/casa/cluster-update/config.cfg')
    parser.add_argument('--check', action='store_true', help='apenas compara o commit instalado com o da branch')
    parser.add_argument('--apply', action='store_true', help='atualiza se houver commit novo ou pedido do painel')
    parser.add_argument('--force', action='store_true', help='reinstala todos os arquivos e reinicia os serviços ativos')
    parser.add_argument('--status', action='store_true')
    parser.add_argument('--init-config', action='store_true')
    args = parser.parse_args()
    try:
        cfg = load_config(args.config)
        if args.init_config:
            print('Configure device_id e device_token em ' + args.config)
        elif args.status:
            path = Path(cfg['update'].get('state_dir', STATE_DIR)) / 'state.json'
            print(path.read_text() if path.exists() else '{}')
        else:
            print(json.dumps(poll(cfg, check=args.check, force=args.force)))
    except BlockingIOError:
        print('Outra atualizacao ja esta em andamento', file=sys.stderr)
        return 0
    except Exception as error:
        # Mensagem sem credenciais: o token nunca entra em exceções deste programa.
        print('Atualizacao falhou: ' + type(error).__name__ + ': ' + str(error)[:300], file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
