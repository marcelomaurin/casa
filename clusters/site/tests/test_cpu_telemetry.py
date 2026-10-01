"""CPU measurements and the HTTP contract, without Linux hardware or cloud access."""
import importlib.util
import json
from pathlib import Path
import threading
import unittest
from unittest.mock import mock_open, patch
import urllib.request

spec = importlib.util.spec_from_file_location('cluster_site', Path(__file__).resolve().parents[1] / 'server.py')
site = importlib.util.module_from_spec(spec)
spec.loader.exec_module(site)


class CpuTests(unittest.TestCase):
    def setUp(self):
        self.cpu = site.CpuTelemetry()

    def sample(self, counters, timestamp=100):
        with patch('builtins.open', mock_open(read_data=counters)), patch.object(site.time, 'time', return_value=timestamp):
            self.cpu.sample()

    def snapshot(self, timestamp=100):
        with patch.object(site.time, 'time', return_value=timestamp), patch.object(site, 'get_cpu_temp', return_value=None):
            return self.cpu.snapshot()

    def test_first_sample_unknown_then_real_usage_excludes_guest_double_counting(self):
        self.sample('cpu 100 0 100 700 100 0 0 0 20 0\n', 99)
        self.assertIsNone(self.snapshot(99)['usage_pct'])
        self.sample('cpu 130 0 110 740 120 0 0 0 40 0\n')
        self.assertEqual(self.snapshot()['usage_pct'], 40.0)

    def test_zero_and_full_usage_are_preserved(self):
        self.sample('cpu 10 0 0 10\n', 98)
        self.sample('cpu 10 0 0 20\n', 99)
        self.assertEqual(self.snapshot(99)['usage_pct'], 0.0)
        self.sample('cpu 20 0 0 20\n')
        self.assertEqual(self.snapshot()['usage_pct'], 100.0)

    def test_counter_reset_and_missing_proc_do_not_fabricate_usage(self):
        self.sample('cpu 10 0 0 20\n', 98)
        self.sample('cpu 0 0 0 0\n', 99)
        self.assertIsNone(self.snapshot(99)['usage_pct'])
        with patch('builtins.open', side_effect=OSError('unavailable')):
            self.cpu.sample()
        self.assertIsNone(self.cpu.previous)
        self.sample('cpu 50 0 0 50\n')
        self.assertIsNone(self.snapshot()['usage_pct'])

    def test_history_is_bounded_and_stale_values_are_unavailable(self):
        for i in range(90):
            self.sample('cpu {0} 0 0 {0}\n'.format(i * 10), i)
        snapshot = self.snapshot(89)
        self.assertEqual(len(snapshot['history']), 61)
        self.assertEqual(snapshot['history'][0]['timestamp'], 29)
        self.assertEqual(snapshot['usage_pct'], 50.0)
        self.assertIsNone(self.snapshot(93)['usage_pct'])
        self.assertEqual(self.snapshot(151)['history'], [])

    def test_fast_endpoint_has_no_cloud_dependency_and_no_cache(self):
        expected = {'usage_pct': 42.5, 'history': [], 'timestamp': 100}
        server = site.ClusterHTTPServer(('127.0.0.1', 0), site.ClusterSiteHandler)
        thread = threading.Thread(target=server.serve_forever)
        thread.daemon = True
        thread.start()
        try:
            with patch.object(site.CPU_TELEMETRY, 'snapshot', return_value=expected), patch.object(site, 'get_cluster_nodes_from_registry', side_effect=AssertionError('cloud called')):
                with urllib.request.urlopen('http://127.0.0.1:{0}/api/cpu'.format(server.server_port), timeout=2) as response:
                    self.assertEqual(response.headers['Cache-Control'], 'no-store')
                    self.assertEqual(json.loads(response.read().decode('utf-8')), {'cpu': expected})
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=2)


if __name__ == '__main__':
    unittest.main()
