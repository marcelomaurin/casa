"""Atualizacao agendada do checkout Casa, sem copiar codigo ou alterar politicas do Windows."""
import argparse
import csv
from datetime import datetime, timedelta, timezone
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import urllib.request
import xml.etree.ElementTree as ET

REPO = "marcelomaurin/casa"
REMOTE = "https://github.com/" + REPO + ".git"
WORKFLOWS = {".github/workflows/integration-control-plane.yml", ".github/workflows/site-auto-update.yml"}


def run(argv, **kwargs):
    env = os.environ.copy()
    env.update(GIT_TERMINAL_PROMPT="0", GCM_INTERACTIVE="Never")
    kwargs.setdefault("env", env)
    kwargs.setdefault("stdin", subprocess.DEVNULL)
    if os.name == "nt":
        kwargs["creationflags"] = subprocess.CREATE_NO_WINDOW
    return subprocess.run(argv, capture_output=True, text=True, timeout=180, **kwargs)


def git(root, *args):
    proc = run(["git", "-c", "safe.directory=" + str(root).replace("\\", "/"), "-C", str(root), *args])
    if proc.returncode:
        raise RuntimeError("Git nao concluiu a operacao: " + (proc.stderr or proc.stdout)[-1000:].strip())
    return proc.stdout.strip()


def github_json(route):
    req = urllib.request.Request("https://api.github.com/repos/" + REPO + route,
                                 headers={"User-Agent": "Casa-Site-Updater", "Accept": "application/vnd.github+json"})
    with urllib.request.urlopen(req, timeout=30) as response:
        raw = response.read(4000001)
    if len(raw) > 4000000:
        raise RuntimeError("Resposta do GitHub excessiva")
    return json.loads(raw)


def ci_state(commit):
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("Commit invalido")
    runs = github_json("/actions/runs?head_sha=" + commit + "&event=push&per_page=100").get("workflow_runs", [])
    if not runs:
        return None
    found = {}
    for item in runs:
        path = item.get("path")
        if path in WORKFLOWS and path not in found and item.get("head_sha") == commit:
            found[path] = item.get("status") == "completed" and item.get("conclusion") == "success"
    return set(found) == WORKFLOWS and all(found.values())


def ci_passed(commit):
    for _ in range(10):
        state = ci_state(commit)
        if state is not None:
            return state
        info = github_json("/commits/" + commit)
        files = info.get("files", [])
        parents = info.get("parents", [])
        if not files or len(files) >= 300 or len(parents) != 1:
            return False
        for item in files:
            path = item.get("filename", "")
            if item.get("status") == "renamed" or not (re.fullmatch(r"(?:site/var/www/html/)?bin/android/[A-Za-z0-9_.-]+\.apk", path) or
                                                       path in ("bin/versions.json", "site/var/www/html/bin/versions.json")):
                return False
        commit = parents[0].get("sha", "")
    return False


def save_status(path, result):
    result["checked_at"] = datetime.now(timezone.utc).isoformat()
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(tmp, path)
    return result


def update(root, check_ci=ci_passed):
    root = Path(root).resolve()
    web = root / "site/var/www/html"
    if not (web / "index.php").is_file():
        raise ValueError("Raiz Casa sem site/var/www/html/index.php")
    if git(root, "config", "--get", "remote.origin.url") not in (REMOTE, "git@github.com:" + REPO + ".git"):
        raise ValueError("Repositorio remoto diferente do Casa autorizado")
    if git(root, "branch", "--show-current") != "master":
        raise RuntimeError("Branch de trabalho preservada; atualizacao automatica requer master")
    if git(root, "status", "--porcelain", "--untracked-files=no"):
        raise RuntimeError("Alteracoes locais preservadas; nenhuma edicao foi descartada")
    state = Path(git(root, "rev-parse", "--absolute-git-dir")) / "casa-site-update"
    state.mkdir(exist_ok=True)
    lock = (state / "update.lock").open("a+b")
    try:
        if os.name == "nt":
            import msvcrt
            if (state / "update.lock").stat().st_size == 0:
                lock.write(b"0");lock.flush()
            lock.seek(0)
            try:
                msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
            except OSError:
                return {"status": "ocupado"}
        else:
            import fcntl
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return {"status": "ocupado"}
        try:
            git(root, "fetch", "--no-tags", "origin", "refs/heads/master:refs/remotes/origin/master")
            before = git(root, "rev-parse", "HEAD")
            after = git(root, "rev-parse", "refs/remotes/origin/master")
            result = {"status": "atualizado", "commit": before, "web_root": str(web)}
            if before != after:
                paths = git(root, "ls-tree", "-r", "--name-only", after, "--", "site/var/www/html").splitlines()
                for path in paths:
                    if re.search(r"(^|/)(config\.local\.php|[^/]+\.secret\.php|\.env)$", path, re.I) or re.match(
                            r"site/var/www/html/(uploads?|data|cache|tmp|logs?|audios?|sessions)/", path, re.I):
                        raise RuntimeError("Versao remota contem configuracao privada ou dados locais; atualizacao bloqueada")
                git(root, "merge-base", "--is-ancestor", before, after)
                site_commit = git(root, "log", "-1", "--format=%H", after, "--", "site")
                if not check_ci(site_commit):
                    result = {"status": "aguardando_testes", "available_commit": after, "site_commit": site_commit, "web_root": str(web)}
                else:
                    git(root, "merge", "--ff-only", "refs/remotes/origin/master")
                    installed = git(root, "rev-parse", "HEAD")
                    if installed != after:
                        raise RuntimeError("Commit instalado nao corresponde a versao verificada")
                    result.update(commit=installed, previous_commit=before)
            return save_status(state / "status.json", result)
        except Exception as exc:
            save_status(state / "status.json", {"status": "erro", "error": str(exc)})
            raise
    finally:
        lock.close()


def install_task(root, interval):
    if os.name != "nt":
        raise RuntimeError("Instalacao de tarefa requer Windows")
    root = Path(root).resolve()
    script = root / "site/tools/update_site_windows.py"
    if not script.is_file() or not (root / "site/var/www/html/index.php").is_file():
        raise ValueError("Atualizador ou raiz web ausente")
    # pythonw evita abrir janelas; usar a instalacao permanente que executa este instalador.
    python = Path(sys.executable).with_name("pythonw.exe")
    if not python.is_file():
        raise RuntimeError("pythonw.exe nao encontrado na instalacao Python")
    who = run(["whoami", "/user", "/fo", "csv", "/nh"])
    if who.returncode:
        raise RuntimeError("Nao foi possivel identificar o usuario da tarefa")
    user = next(csv.reader(io.StringIO(who.stdout)))[1]
    if not re.fullmatch(r"S-[0-9-]+", user):
        raise RuntimeError("Identidade Windows invalida")
    ns = "http://schemas.microsoft.com/windows/2004/02/mit/task"
    ET.register_namespace("", ns)
    def child(parent, name, text=None):
        item = ET.SubElement(parent, "{" + ns + "}" + name)
        if text is not None:
            item.text = text
        return item
    task = ET.Element("{" + ns + "}Task", {"version": "1.2"})
    registration = child(task, "RegistrationInfo")
    child(registration, "Description", "Casa: atualiza master aprovada no CI sem copiar codigo. Raiz: " + str(root / "site/var/www/html"))
    triggers = child(task, "Triggers")
    logon = child(triggers, "LogonTrigger");child(logon, "Enabled", "true");child(logon, "UserId", user)
    trigger = child(triggers, "TimeTrigger")
    repetition = child(trigger, "Repetition");child(repetition, "Interval", "PT" + str(interval) + "M");child(repetition, "StopAtDurationEnd", "false")
    child(trigger, "StartBoundary", (datetime.now() + timedelta(minutes=1)).isoformat(timespec="seconds"));child(trigger, "Enabled", "true")
    principals = child(task, "Principals");principal = child(principals, "Principal");principal.set("id", "Author")
    child(principal, "UserId", user);child(principal, "LogonType", "InteractiveToken");child(principal, "RunLevel", "LeastPrivilege")
    settings = child(task, "Settings")
    for key, value in {"MultipleInstancesPolicy": "IgnoreNew", "DisallowStartIfOnBatteries": "false", "StopIfGoingOnBatteries": "false",
                       "StartWhenAvailable": "true", "Enabled": "true", "Hidden": "true", "ExecutionTimeLimit": "PT10M"}.items():
        child(settings, key, value)
    actions = child(task, "Actions");actions.set("Context", "Author");action = child(actions, "Exec")
    child(action, "Command", str(python));child(action, "Arguments", subprocess.list2cmdline([str(script), "--repo-root", str(root)]));child(action, "WorkingDirectory", str(root))
    fd, tmp = tempfile.mkstemp(suffix=".xml", prefix="casa-site-task-")
    try:
        with os.fdopen(fd, "wb") as file:
            file.write(ET.tostring(task, encoding="utf-16", xml_declaration=True))
        result = run(["schtasks", "/Create", "/TN", "Casa-Site-AutoUpdate", "/XML", tmp, "/F"])
        if result.returncode:
            raise RuntimeError("Falha ao registrar tarefa: " + result.stderr[-1000:])
        result = run(["schtasks", "/Run", "/TN", "Casa-Site-AutoUpdate"])
        if result.returncode:
            raise RuntimeError("Tarefa registrada mas nao iniciada: " + result.stderr[-1000:])
        return {"status": "agendado", "task": "Casa-Site-AutoUpdate", "minutes": interval, "user_sid": user, "web_root": str(root / "site/var/www/html")}
    finally:
        os.unlink(tmp)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo-root", default=str(Path(__file__).resolve().parents[2]))
    parser.add_argument("--install", action="store_true")
    parser.add_argument("--interval-minutes", type=int, choices=range(5, 61), default=5)
    args = parser.parse_args()
    try:
        result = install_task(args.repo_root, args.interval_minutes) if args.install else update(args.repo_root)
        if sys.stdout:
            print(json.dumps(result, ensure_ascii=False, indent=2))
    except Exception as exc:
        if sys.stderr:
            print("Atualizacao Casa: " + str(exc), file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
