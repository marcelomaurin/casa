#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]

checks = {
    "site/var/www/html/api/planejador.php": [
        "te_begin(",
        "planner_call_json($demanda,$taskContext)",
        "'task_context' => te_public_context($taskContext)",
        "'correlation_id' => $taskContext['correlation_id']",
        "INSERT INTO jarvis_tarefas (id_plano,correlation_id",
    ],
    "site/var/www/html/api/jarvis_site.php": [
        "require_once(__DIR__ . '/task_engine.php')",
        "te_begin(",
        "te_add_subtask(",
        "te_attach_context(",
        "te_finish(",
    ],
    "site/var/www/html/api/google_home.php": [
        "require_once(__DIR__.'/task_engine.php')",
        "te_begin(",
        "te_add_subtask(",
        "'task_context'=>te_public_context($taskContext)",
        "te_attach_context(",
    ],
    "site/var/www/html/api/agente_internet.php": [
        "te_context_from_input(",
        "te_begin(",
        "'task_context' => $GLOBALS['taskContext']",
        "te_attach_context(",
    ],
    "site/var/www/html/api/jarvis.php": [
        "te_context_from_input(",
        "te_begin(",
        "'task_context'=>te_public_context($taskContext)",
        "te_attach_context(",
    ],
}

errors = []
for rel, needles in checks.items():
    path = ROOT / rel
    if not path.exists():
        errors.append(f"{rel}: arquivo ausente")
        continue
    text = path.read_text(encoding="utf-8")
    for needle in needles:
        if needle not in text:
            errors.append(f"{rel}: contrato ausente: {needle}")

task_engine = (ROOT / "site/var/www/html/api/task_engine.php").read_text(encoding="utf-8")
if "array_key_exists('root_created',$ctx) && !$ctx['root_created']" not in task_engine:
    errors.append("task_engine.php: subchamada ainda pode encerrar plano raiz")

if errors:
    print("Task context contract FAILED")
    for err in errors:
        print(" -", err)
    sys.exit(1)

print("Task context contract OK: entradas e subchamadas preservam plano, tarefa raiz e correlation_id.")
