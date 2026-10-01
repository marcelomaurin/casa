import configparser
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('updater', Path(__file__).resolve().parents[1] / 'casa-cluster-update.py')
u = importlib.util.module_from_spec(spec)
spec.loader.exec_module(u)

class UpdateTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.cfg = u.load_config(self.root / 'config.cfg')
        self.cfg['update']['state_dir'] = str(self.root / 'state')
        self.cfg['espcam'] = {'root': str(self.root / 'app'), 'service': ''}
        (self.root / 'app').mkdir()
        self.data = b'print("new")\n'
        self.item = {'source': 'clusters/visao/espcam/processa_imagem.py', 'target': 'processa_imagem.py', 'size': len(self.data), 'sha256': hashlib.sha256(self.data).hexdigest()}
        self.manifest = {'schema': 1, 'version': '1.0.0', 'source_commit': 'a' * 40, 'components': {'espcam': {'files': [self.item]}}}
        self.release = {'id': 1, 'manifest': self.manifest}

    def test_config_preserved_byte_for_byte(self):
        path = self.root / 'config.cfg'
        path.write_text('[update]\ndevice_token=keep%secret\n')
        before = path.read_bytes()
        self.assertEqual(u.load_config(path)['update']['device_token'], 'keep%secret')
        self.assertEqual(path.read_bytes(), before)

    def test_paths_and_config_rejected(self):
        for target in ('../evil.py', '/tmp/evil.py', 'config.cfg', 'a.env/test.py'):
            self.item['target'] = target
            with self.assertRaises(ValueError):
                u.validate(self.manifest)

    def test_no_release_does_not_download_or_install(self):
        with patch.object(u, 'api', return_value={'release': None}), patch.object(u.urllib.request, 'urlopen') as download:
            self.assertEqual(u.poll(self.cfg)['status'], 'no-release')
            download.assert_not_called()

    def test_hash_failure_leaves_original(self):
        path = self.root / 'app' / self.item['target']
        path.write_text('original')
        with patch.object(u, 'api', return_value={'release': self.release}), patch.object(u.urllib.request, 'urlopen') as download:
            download.return_value.__enter__.return_value.read.return_value = b'bad'
            with self.assertRaises(ValueError):
                u.poll(self.cfg)
        self.assertEqual(path.read_text(), 'original')

    def test_success_preserves_config_and_does_not_reapply(self):
        config = self.root / 'app' / 'config.cfg'
        config.write_text('local credential')
        def api(cfg, action, payload=None):
            return {'release': self.release} if action == 'current' else {'status': 'ok'}
        with patch.object(u, 'api', side_effect=api), patch.object(u.urllib.request, 'urlopen') as download:
            download.return_value.__enter__.return_value.read.return_value = self.data
            self.assertEqual(u.poll(self.cfg)['status'], 'success')
            self.assertEqual((self.root / 'app' / self.item['target']).read_bytes(), self.data)
            self.assertEqual(config.read_text(), 'local credential')
            u.poll(self.cfg)
            self.assertEqual(download.call_count, 1)

    def test_restart_failure_rolls_back(self):
        target = self.root / 'app' / self.item['target']
        target.write_text('old')
        stage = self.root / 'stage'
        stage.mkdir()
        (stage / self.item['sha256']).write_bytes(self.data)
        with patch.object(u.subprocess, 'run') as run, patch.object(u, 'command', side_effect=[RuntimeError('restart failed'), None]):
            run.return_value.returncode = 0
            with self.assertRaises(RuntimeError):
                u.install_component(target.parent, [self.item], stage, 'test-service', '1.0.0')
        self.assertEqual(target.read_text(), 'old')

    def test_symlink_outside_is_rejected(self):
        outside = self.root / 'outside'
        outside.mkdir()
        (self.root / 'app' / 'link').symlink_to(outside)
        item = dict(self.item, target='link/evil.py')
        with self.assertRaises(ValueError):
            u.install_component(self.root / 'app', [item], self.root, '', '1.0.0')

    def test_reports_retry_without_reinstall(self):
        def api(cfg, action, payload=None):
            if action == 'report':
                raise OSError('offline')
            return {'release': self.release}
        with patch.object(u, 'api', side_effect=api), patch.object(u.urllib.request, 'urlopen') as download:
            download.return_value.__enter__.return_value.read.return_value = self.data
            self.assertEqual(u.poll(self.cfg)['pending_reports'], 1)
        state = json.loads((self.root / 'state/state.json').read_text())
        self.assertEqual(state['pending_reports'][0]['status'], 'updated')
        with patch.object(u, 'api', side_effect=lambda cfg, action, payload=None: {'release': self.release} if action == 'current' else {'status': 'ok'}), patch.object(u.urllib.request, 'urlopen') as download:
            self.assertEqual(u.poll(self.cfg)['pending_reports'], 0)
            download.assert_not_called()

if __name__ == '__main__':
    unittest.main()
