import configparser
import hashlib
import importlib.util
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('updater', Path(__file__).resolve().parents[1] / 'casa-cluster-update.py')
u = importlib.util.module_from_spec(spec)
spec.loader.exec_module(u)


def sh(*args, cwd=None):
    return subprocess.run(list(args), cwd=cwd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout.decode().strip()


class GitUpdateTests(unittest.TestCase):
    """Usa um repositório Git local como 'origin' para testar o ciclo real (sem rede)."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.origin = self.root / 'origin'
        self.origin.mkdir()
        sh('git', 'init', '-q', '-b', 'master', str(self.origin))
        sh('git', 'config', 'user.email', 'ci@casa', cwd=self.origin)
        sh('git', 'config', 'user.name', 'ci', cwd=self.origin)
        sh('git', 'config', 'uploadpack.allowFilter', 'true', cwd=self.origin)
        self.write('clusters/visao/espcam/processa_imagem.py', 'print("v1")\n')
        self.write('clusters/visao/espcam/config.json', '{"segredo": 1}\n')
        self.write('clusters/visao/espcam/tests/test_x.py', 'x = 1\n')
        self.write('android/App.kt', 'fun main() {}\n')
        self.c1 = self.commit('v1')

        self.cfg = u.load_config(self.root / 'config.cfg')
        self.cfg['update']['state_dir'] = str(self.root / 'state')
        self.cfg['update']['repo_url'] = 'file://' + str(self.origin)
        self.cfg['update']['device_token'] = 'tok'
        for name in u.CATALOG:
            self.cfg[name] = {'root': str(self.root / 'nao-instalado' / name), 'service': ''}
        self.app = self.root / 'app'
        self.app.mkdir()
        (self.app / 'config.json').write_text('{"local": true}')
        self.cfg['espcam'] = {'root': str(self.app), 'service': ''}
        self.reports = []
        self.policy = {'branch': 'master', 'force_seq': 0}

    def write(self, rel, text):
        path = self.origin / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)

    def commit(self, msg):
        sh('git', 'add', '-A', cwd=self.origin)
        sh('git', 'commit', '-q', '-m', msg, cwd=self.origin)
        return sh('git', 'rev-parse', 'HEAD', cwd=self.origin)

    def fake_api(self, cfg, action, payload=None):
        if action == 'policy':
            return {'status': 'ok', 'policy': dict(self.policy)}
        if action == 'node_report':
            self.reports.append(payload)
            return {'status': 'ok'}
        raise AssertionError(action)

    def run_poll(self, **kw):
        with patch.object(u, 'api', side_effect=self.fake_api):
            return u.poll(self.cfg, **kw)

    def state(self):
        return json.loads((self.root / 'state' / 'state.json').read_text())

    def test_installs_latest_commit_from_git_and_reports(self):
        r = self.run_poll()
        self.assertEqual(r['status'], 'updated')
        self.assertEqual(r['commit'], self.c1)
        self.assertEqual((self.app / 'processa_imagem.py').read_text(), 'print("v1")\n')
        # Configuração/dados e testes nunca são instalados nem sobrescritos.
        self.assertEqual((self.app / 'config.json').read_text(), '{"local": true}')
        self.assertFalse((self.app / 'tests').exists())
        st = self.state()
        self.assertEqual(st['commit'], self.c1)
        self.assertEqual(st['components']['espcam']['status'], 'updated')
        self.assertEqual(st['components']['tts']['status'], 'not_installed')
        self.assertEqual(self.reports[-1]['commit'], self.c1)
        self.assertEqual(self.reports[-1]['result'], 'updated')

    def test_new_commit_on_master_is_applied_and_same_commit_is_skipped(self):
        self.run_poll()
        self.assertEqual(self.run_poll()['status'], 'up-to-date')
        self.write('clusters/visao/espcam/processa_imagem.py', 'print("v2")\n')
        c2 = self.commit('v2')
        check = self.run_poll(check=True)
        self.assertEqual(check['status'], 'available')
        self.assertEqual(check['remote'], c2)
        r = self.run_poll()
        self.assertEqual(r['commit'], c2)
        self.assertEqual((self.app / 'processa_imagem.py').read_text(), 'print("v2")\n')

    def test_unchanged_files_are_not_rewritten(self):
        self.run_poll()
        self.write('android/App.kt', 'fun main() { println() }\n')
        self.commit('so android')
        self.run_poll()
        self.assertEqual(self.state()['components']['espcam']['status'], 'unchanged')

    def test_force_from_panel_reinstalls_once(self):
        self.run_poll()
        (self.app / 'processa_imagem.py').write_text('editado localmente')
        self.policy['force_seq'] = 3
        r = self.run_poll()
        self.assertTrue(r['forced'])
        self.assertEqual((self.app / 'processa_imagem.py').read_text(), 'print("v1")\n')
        self.assertEqual(self.state()['force_handled'], 3)
        self.assertEqual(self.reports[-1]['force_handled'], 3)
        self.assertEqual(self.run_poll()['status'], 'up-to-date')

    def test_syntax_error_keeps_previous_version_and_retries(self):
        self.run_poll()
        self.write('clusters/visao/espcam/processa_imagem.py', 'def quebrado(:\n')
        self.commit('quebrado')
        with self.assertRaises(RuntimeError):
            self.run_poll()
        self.assertEqual((self.app / 'processa_imagem.py').read_text(), 'print("v1")\n')
        st = self.state()
        self.assertEqual(st['commit'], self.c1)
        self.assertEqual(st['components']['espcam']['status'], 'failed')
        self.assertEqual(self.reports[-1]['result'], 'failed')

    def test_api_offline_still_updates_from_git(self):
        def offline(cfg, action, payload=None):
            raise OSError('sem rede')
        with patch.object(u, 'api', side_effect=offline):
            r = u.poll(self.cfg)
        self.assertEqual(r['commit'], self.c1)

    def test_paths_and_config_rejected(self):
        for target in ('../evil.py', '/tmp/evil.py', 'config.cfg', 'a.env/test.py', 'dados.json', 'x.txt'):
            self.assertFalse(u.safe_file(target), target)
        self.assertTrue(u.safe_file('sub/modulo.py'))

    def test_config_preserved_byte_for_byte(self):
        path = self.root / 'outro.cfg'
        path.write_text('[update]\ndevice_token=keep%secret\n')
        before = path.read_bytes()
        self.assertEqual(u.load_config(path)['update']['device_token'], 'keep%secret')
        self.assertEqual(path.read_bytes(), before)


if __name__ == '__main__':
    unittest.main()
