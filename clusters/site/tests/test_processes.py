"""Process fixtures and mocked signals: never terminate real processes."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch
import urllib.error
import urllib.request

spec = importlib.util.spec_from_file_location('process_site', Path(__file__).resolve().parents[1] / 'server.py')
site = importlib.util.module_from_spec(spec)
spec.loader.exec_module(site)


class ProcessTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.processes = site.ApplicationProcesses(str(self.root))
        self.addCleanup(patch.stopall)
        patch.object(site.os, 'readlink', side_effect=lambda path: '/usr/bin/python3' if str(path).endswith('exe') else '/opt/casa-node-agent').start()
        patch.object(site.os, 'sysconf', side_effect=lambda name: 4096 if name == 'SC_PAGE_SIZE' else 100, create=True).start()
        patch.object(site.os, 'cpu_count', return_value=4).start()

    def fixture(self, pid=222, script='/opt/casa-node-agent/casa-node-agent.py', start='12345', ticks=100):
        directory = self.root / str(pid)
        directory.mkdir(exist_ok=True)
        (directory / 'cmdline').write_bytes(('python3\0' + script + '\0--token\0secret-not-for-ui\0').encode())
        fields = ['0'] * 22
        fields[0], fields[11], fields[12], fields[19], fields[21] = 'S', str(ticks), '0', start, '1024'
        (directory / 'stat').write_text(str(pid) + ' (name with ) space) ' + ' '.join(fields))

    def test_only_known_entrypoints_and_no_arguments_leak(self):
        self.fixture()
        self.fixture(223, '/tmp/casa-node-agent.py')
        self.fixture(224, '/tmp/unrelated.py')
        rows = self.processes.snapshot()
        self.assertEqual([row['pid'] for row in rows], [222])
        self.assertEqual(rows[0]['memory_mb'], 4.0)
        self.assertNotIn('secret-not-for-ui', json.dumps(rows))
        self.assertNotIn('ticks', rows[0])

    def test_percentage_uses_interval_and_pid_identity(self):
        self.fixture(ticks=100)
        with patch.object(site.time, 'monotonic', return_value=10):
            self.assertIsNone(self.processes.snapshot()[0]['cpu_pct'])
        self.fixture(ticks=300)
        with patch.object(site.time, 'monotonic', return_value=12):
            self.assertEqual(self.processes.snapshot()[0]['cpu_pct'], 25.0)
        self.fixture(start='999', ticks=400)
        with patch.object(site.time, 'monotonic', return_value=15):
            self.assertIsNone(self.processes.snapshot()[0]['cpu_pct'])

    def test_wrong_identity_unrelated_and_protected_never_signalled(self):
        self.fixture()
        self.fixture(223, '/tmp/unrelated.py')
        self.fixture(224, '/opt/casa/site/server.py')
        self.fixture(225, '/opt/casa/cluster-update/casa-cluster-update.py')
        with patch.object(site.os, 'pidfd_open', return_value=50, create=True), patch.object(site.signal, 'pidfd_send_signal', create=True) as send, patch.object(site.os, 'close'), patch.object(site.os, 'kill') as kill:
            for pid, start in [(222, 'old'), (223, '12345'), (224, '12345'), (225, '12345')]:
                self.assertFalse(self.processes.terminate(pid, start)['ok'])
            send.assert_not_called()
            kill.assert_not_called()

    def test_matching_process_uses_sigterm_with_pidfd(self):
        self.fixture()
        with patch.object(site.os, 'pidfd_open', return_value=50, create=True), patch.object(site.signal, 'pidfd_send_signal', create=True) as send, patch.object(site.os, 'close') as close:
            self.assertTrue(self.processes.terminate(222, '12345')['ok'])
            send.assert_called_once_with(50, site.signal.SIGTERM)
            close.assert_called_once_with(50)

    def test_old_kernel_fallback_checks_identity_again(self):
        row = {'start_time': '12345', 'can_terminate': True}
        changed = {'start_time': '99999', 'can_terminate': True}
        with patch.object(site.os, 'pidfd_open', side_effect=OSError(38, 'not supported'), create=True), patch.object(site.signal, 'pidfd_send_signal', create=True), patch.object(self.processes, 'read', side_effect=[row, changed]), patch.object(site.os, 'kill') as kill:
            self.assertFalse(self.processes.terminate(222, '12345')['ok'])
            kill.assert_not_called()


class ProcessHttpTests(unittest.TestCase):
    def setUp(self):
        self.server = site.ClusterHTTPServer(('127.0.0.1', 0), site.ClusterSiteHandler)
        self.thread = threading.Thread(target=self.server.serve_forever)
        self.thread.daemon = True
        self.thread.start()
        self.url = 'http://127.0.0.1:{0}'.format(self.server.server_port)

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)

    def request(self, payload, token='test-admin', origin=None):
        headers = {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token}
        if origin:
            headers['Origin'] = origin
        req = urllib.request.Request(self.url + '/api/processes/terminate', data=json.dumps(payload).encode(), headers=headers)
        try:
            response = urllib.request.urlopen(req, timeout=3)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.code, json.loads(response.read().decode())

    def test_auth_origin_validation_and_partial_results(self):
        valid = {'processes': [{'pid': 222, 'start_time': '12345'}]}
        with patch.object(site, 'process_admin_token', return_value='test-admin'), patch.object(site.APPLICATION_PROCESSES, 'terminate', return_value={'pid': 222, 'ok': True, 'message': 'requested'}) as terminate:
            self.assertEqual(self.request(valid, token='wrong')[0], 401)
            self.assertEqual(self.request(valid, origin='https://other.example')[0], 403)
            for payload in ({'processes': []}, {'processes': [{'pid': 1, 'start_time': '1'}]}, {'processes': [{'pid': '222;kill', 'start_time': '1'}]}, {'processes': valid['processes'] * 2}):
                self.assertEqual(self.request(payload)[0], 400)
            terminate.assert_not_called()
            code, result = self.request(valid, origin=self.url)
            self.assertEqual(code, 200)
            self.assertTrue(result['results'][0]['ok'])
            terminate.assert_called_once_with(222, '12345')

    def test_disabled_without_config_and_listing_does_not_signal(self):
        with patch.object(site, 'process_admin_token', return_value=''), patch.object(site.APPLICATION_PROCESSES, 'snapshot', return_value=[]), patch.object(site.APPLICATION_PROCESSES, 'terminate') as terminate:
            self.assertEqual(self.request({'processes': [{'pid': 222, 'start_time': '1'}]})[0], 503)
            with urllib.request.urlopen(self.url + '/api/processes', timeout=3) as response:
                self.assertIsNone(response.headers.get('Access-Control-Allow-Origin'))
                self.assertEqual(json.loads(response.read().decode()), {'processes': [], 'timestamp': unittest.mock.ANY, 'termination_enabled': False})
            terminate.assert_not_called()


if __name__ == '__main__':
    unittest.main()
