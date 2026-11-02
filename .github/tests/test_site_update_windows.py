"""Atualizador Windows: Git real em repositorios descartaveis e CI simulado."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("site_update", ROOT / "site/tools/update_site_windows.py")
up = importlib.util.module_from_spec(spec)
spec.loader.exec_module(up)


class UpdaterTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="casa update test ")
        self.base = Path(self.temp.name)
        self.assertEqual(self.base.resolve().parent, Path(tempfile.gettempdir()).resolve())
        self.addCleanup(self.temp.cleanup)
        self.source = self.base / "source"
        self.bare = self.base / "origin.git"
        self.local = self.base / "installed"
        self.source.mkdir();self.bare.mkdir()
        up.git(self.source, "init", "--initial-branch=master")
        up.git(self.source, "config", "user.name", "Updater test")
        up.git(self.source, "config", "user.email", "test@example.invalid")
        self.web = self.source / "site/var/www/html"
        self.web.mkdir(parents=True)
        (self.source / ".gitignore").write_text("config.local.php\n", encoding="utf-8")
        self.publish("one")
        up.git(self.bare, "init", "--bare")
        up.git(self.source, "push", str(self.bare), "master")
        up.git(self.base, "clone", "--branch", "master", str(self.bare), str(self.local))
        up.git(self.local, "remote", "set-url", "origin", up.REMOTE)
        # Somente o fixture usa transporte local em vez de rede.
        up.git(self.local, "config", "url." + self.bare.as_posix() + ".insteadOf", up.REMOTE)
        self.installed = self.local / "site/var/www/html"
        (self.installed / "config.local.php").write_text("privado", encoding="utf-8")
        (self.installed / "arquivo-local.txt").write_text("preservar", encoding="utf-8")

    def publish(self, version):
        (self.web / "index.php").write_text("<?php echo '" + version + "';", encoding="utf-8")
        up.git(self.source, "add", ".")
        up.git(self.source, "commit", "-m", version)
        if (self.bare / "HEAD").exists():
            up.git(self.source, "push", str(self.bare), "master")
        return up.git(self.source, "rev-parse", "HEAD")

    def test_ci_gate_fast_forward_and_local_data(self):
        first = up.git(self.local, "rev-parse", "HEAD")
        second = self.publish("two")
        self.assertEqual(up.update(self.local, lambda _: False)["status"], "aguardando_testes")
        self.assertEqual(up.git(self.local, "rev-parse", "HEAD"), first)
        r = up.update(self.local, lambda _: True)
        self.assertEqual(r["commit"], second)
        self.assertIn("two", (self.installed / "index.php").read_text())
        self.assertEqual((self.installed / "config.local.php").read_text(), "privado")
        self.assertEqual((self.installed / "arquivo-local.txt").read_text(), "preservar")
        self.assertEqual(up.update(self.local, lambda _: self.fail("Nao consultar CI sem nova versao"))["status"], "atualizado")

    def test_modified_files_and_working_branch_preserved(self):
        (self.installed / "index.php").write_text("edicao local", encoding="utf-8")
        self.publish("two")
        with self.assertRaisesRegex(RuntimeError, "Alteracoes locais"):
            up.update(self.local, lambda _: True)
        self.assertEqual((self.installed / "index.php").read_text(), "edicao local")
        up.git(self.local, "restore", "--", "site/var/www/html/index.php")
        up.git(self.local, "switch", "-c", "feature-local")
        with self.assertRaisesRegex(RuntimeError, "Branch de trabalho"):
            up.update(self.local, lambda _: True)
        self.assertEqual(up.git(self.local, "branch", "--show-current"), "feature-local")

    def test_divergent_history_never_forced(self):
        up.git(self.local, "config", "user.name", "Updater test")
        up.git(self.local, "config", "user.email", "test@example.invalid")
        (self.installed / "local-commit.txt").write_text("local", encoding="utf-8")
        up.git(self.local, "add", "site/var/www/html/local-commit.txt")
        up.git(self.local, "commit", "-m", "local")
        before = up.git(self.local, "rev-parse", "HEAD")
        self.publish("remote")
        with self.assertRaises(RuntimeError):
            up.update(self.local, lambda _: True)
        self.assertEqual(up.git(self.local, "rev-parse", "HEAD"), before)

    def test_private_file_in_remote_cannot_clobber_ignored_config(self):
        (self.web / "config.local.php").write_text("remoto", encoding="utf-8")
        up.git(self.source, "add", "-f", "site/var/www/html/config.local.php")
        up.git(self.source, "commit", "-m", "private test file")
        up.git(self.source, "push", str(self.bare), "master")
        with self.assertRaisesRegex(RuntimeError, "configuracao privada"):
            up.update(self.local, lambda _: True)
        self.assertEqual((self.installed / "config.local.php").read_text(), "privado")

    def test_other_origin_rejected(self):
        up.git(self.local, "remote", "set-url", "origin", "https://example.invalid/other.git")
        with self.assertRaisesRegex(ValueError, "remoto diferente"):
            up.update(self.local)

    def test_latest_ci_attempt_must_pass(self):
        sha = "a" * 40
        runs = [{"path": path, "head_sha": sha, "head_branch": "master", "status": "completed", "conclusion": "success"} for path in up.WORKFLOWS]
        class Response:
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, size): return json.dumps({"workflow_runs": runs}).encode()
        with patch.object(up.urllib.request, "urlopen", return_value=Response()):
            self.assertTrue(up.ci_passed(sha))
            for item in runs:
                item["head_branch"] = "codex/tested-before-main"
            self.assertTrue(up.ci_passed(sha))
            runs.insert(0, dict(runs[0], conclusion="failure"))
            self.assertFalse(up.ci_passed(sha))
            runs.clear()
            self.assertFalse(up.ci_passed(sha))

    @unittest.skipUnless(os.name == "nt", "Registro de tarefas Windows")
    def test_task_xml_uses_pythonw_user_and_no_password(self):
        script = self.local / "site/tools/update_site_windows.py"
        script.parent.mkdir(parents=True)
        script.write_text("# fixture", encoding="utf-8")
        python = self.base / "python.exe"
        python.with_name("pythonw.exe").write_text("fixture", encoding="utf-8")
        calls = []
        def mocked(argv, **kwargs):
            calls.append(argv)
            if argv[0] == "whoami":
                return SimpleNamespace(returncode=0, stdout='"DOMAIN\\User","S-1-5-21-1-2-3-1000"\n', stderr="")
            if "/Create" in argv:
                tree = ET.parse(argv[argv.index("/XML") + 1])
                ns = {"t": "http://schemas.microsoft.com/windows/2004/02/mit/task"}
                self.assertTrue(tree.find(".//t:Command", ns).text.endswith("pythonw.exe"))
                self.assertEqual(tree.find(".//t:Interval", ns).text, "PT5M")
                self.assertEqual(tree.find(".//t:LogonType", ns).text, "InteractiveToken")
                self.assertEqual(tree.find(".//t:RunLevel", ns).text, "LeastPrivilege")
                self.assertIn("--repo-root", tree.find(".//t:Arguments", ns).text)
                self.assertNotIn("Password", ET.tostring(tree.getroot()).decode())
            return SimpleNamespace(returncode=0, stdout="ok", stderr="")
        with patch.object(up.sys, "executable", str(python)), patch.object(up, "run", side_effect=mocked):
            self.assertEqual(up.install_task(self.local, 5)["status"], "agendado")
        self.assertEqual(len(calls), 3)

    def test_only_apk_publication_can_inherit_parent_ci(self):
        sha, parent = "a" * 40, "b" * 40
        meta = {"parents": [{"sha": parent}], "files": [{"filename": "site/var/www/html/bin/android/CasaMobile-debug.apk", "status": "modified"}]}
        def response(route):
            if route.startswith("/commits/"):
                return meta
            return {"workflow_runs": [] if "head_sha=" + sha in route else [
                {"path": p, "head_sha": parent, "status": "completed", "conclusion": "success"} for p in up.WORKFLOWS]}
        with patch.object(up, "github_json", side_effect=response):
            self.assertTrue(up.ci_passed(sha))
            meta["files"][0]["status"] = "renamed"
            self.assertFalse(up.ci_passed(sha))
            meta["files"][0] = {"filename": "site/var/www/html/index.php", "status": "modified"}
            self.assertFalse(up.ci_passed(sha))
        with patch.object(up, "ci_state", return_value=False), patch.object(up, "github_json") as api:
            self.assertFalse(up.ci_passed(sha))
            api.assert_not_called()


if __name__ == "__main__":
    unittest.main(verbosity=2)
