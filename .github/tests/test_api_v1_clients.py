#!/usr/bin/env python3
"""Falha o CI quando clientes CASA voltam a consumir endpoints públicos legados."""

from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[2]
TARGETS = [
    ROOT / "android",
    ROOT / "arduino",
    ROOT / "yocto",
    ROOT / "servicos" / "arm-agent",
]
EXTENSIONS = {".kt", ".java", ".ino", ".cpp", ".h", ".hpp", ".py", ".c"}

# Detecta apenas caminhos CASA do tipo /api/<rota>. Hosts de terceiros como
# https://api.github.com não casam com esta expressão.
LEGACY_API = re.compile(r"/api/(?!v1(?:/|[\"'?#]))", re.IGNORECASE)

violations = []
for base in TARGETS:
    if not base.exists():
        continue
    for path in base.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in EXTENSIONS:
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        for lineno, line in enumerate(text.splitlines(), 1):
            if LEGACY_API.search(line):
                violations.append((path.relative_to(ROOT), lineno, line.strip()))

if violations:
    print("Endpoints públicos CASA fora de /api/v1 encontrados:")
    for path, lineno, line in violations:
        print(f"  {path}:{lineno}: {line}")
    print("\nMigre o cliente para /api/v1 ou documente um wrapper v1 antes do merge.")
    sys.exit(1)

print("API v1 guard OK: clientes externos não usam endpoints CASA legados.")
