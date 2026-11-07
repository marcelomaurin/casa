#!/usr/bin/env python3
"""Teste isolado da coleta read-only de serviços systemd no node-agent."""
import importlib.util
import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[2]
SOURCE=ROOT / "clusters/comunicacao/arm-agent/casa-node-agent.py"
spec=importlib.util.spec_from_file_location("casa_node_agent_diagnostics", SOURCE)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class Diagnosticos(unittest.TestCase):
    def setUp(self):
        self.old=module.run_cmd
        module._DIAGNOSTIC_CACHE={"at":0,"value":None}

    def tearDown(self):
        module.run_cmd=self.old

    def test_collects_actual_running_units_and_caches(self):
        calls=[]
        def run(argv,timeout=10):
            calls.append((list(argv),timeout))
            if argv[1]=="list-units":
                return 0,("ssh.service loaded active running SSH Server\n"
                          "casa-tarefas.service loaded active running Task worker\n"
                          "stopped.service loaded inactive dead Stopped\n"),""
            if argv[1]=="show":
                return 0,"LoadState=loaded\nActiveState=active\nSubState=running\nMainPID=1234\n",""
            if argv[0]=="journalctl":
                return 0,"Erro ao conectar: token=secret123\nFalha curta\n",""
            return -1,"","unknown"
        module.run_cmd=run
        result=module.collect_daemon_diagnostics()
        self.assertEqual(result["running_status"],"ok")
        self.assertEqual(result["running_count"],2)
        self.assertEqual(result["running_services"],["casa-tarefas.service","ssh.service"])
        self.assertEqual(result["critical_services"]["casa-tarefas.service"]["ActiveState"],"active")
        self.assertNotIn("secret123",str(result))
        count=len(calls)
        self.assertEqual(module.collect_daemon_diagnostics(),result)
        self.assertEqual(len(calls),count,"must cache expensive systemd calls")

    def test_missing_systemd_never_claims_everything_stopped(self):
        module.run_cmd=lambda argv,timeout=10:(-1,"","systemctl unavailable")
        r=module.collect_daemon_diagnostics()
        self.assertEqual(r["running_status"],"indisponivel")
        self.assertIsNone(r["running_count"])
        self.assertEqual(r["running_services"],[])

    def test_no_permission_to_journal_is_explicit(self):
        def run(argv,timeout=10):
            if argv[1]=="list-units":return 0,"casa-tarefas.service loaded active running worker\n",""
            if argv[0]=="journalctl":return 1,"","permission denied"
            return 0,"LoadState=loaded\nActiveState=inactive\nSubState=dead\n",""
        module.run_cmd=run
        r=module.collect_daemon_diagnostics()
        self.assertEqual(r["critical_services"]["casa-tarefas.service"]["logs_status"],"sem_acesso")
        self.assertNotIn("log_errors",r["critical_services"]["casa-tarefas.service"])

if __name__=="__main__":
    unittest.main()
