#!/usr/bin/env python3
"""Valida a separacao MySQL/PostgreSQL no repositorio CASA."""

from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[2]
errors = []

# Nenhum SQL deve voltar a raiz database/.
for p in (ROOT / "database").glob("*.sql"):
    errors.append(f"{p.relative_to(ROOT)}: SQL ambiguo na raiz de database/")

pg_signatures = [
    r"\bBIGSERIAL\b", r"\bSERIAL\s+PRIMARY\s+KEY\b", r"\bJSONB\b",
    r"::jsonb", r"\bINET\b", r"\bON\s+CONFLICT\b", r"\\gexec",
    r"\bTIMESTAMP\s+WITHOUT\s+TIME\s+ZONE\b", r"\bBOOLEAN\b",
]
mysql_signatures = [
    r"\bAUTO_INCREMENT\b", r"\bENGINE\s*=\s*InnoDB\b",
    r"\bTINYINT\s*\(", r"\bUNSIGNED\b", r"\bON\s+DUPLICATE\s+KEY\b",
    r"\bSET\s+NAMES\s+utf8mb4\b",
]

def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")

def has_any(text: str, patterns) -> str | None:
    for pat in patterns:
        if re.search(pat, text, re.I):
            return pat
    return None

for base in [ROOT / "database" / "mysql", ROOT / "mysql"]:
    if not base.exists():
        continue
    for p in base.rglob("*.sql"):
        text = read(p)
        sig = has_any(text, pg_signatures)
        if sig:
            errors.append(f"{p.relative_to(ROOT)}: sintaxe PostgreSQL detectada em dominio MySQL ({sig})")

pg_root = ROOT / "database" / "postgres"
if pg_root.exists():
    for p in pg_root.rglob("*.sql"):
        text = read(p)
        sig = has_any(text, mysql_signatures)
        if sig:
            errors.append(f"{p.relative_to(ROOT)}: sintaxe MySQL detectada em dominio PostgreSQL ({sig})")

# O PostgreSQL legado nao pode voltar a carregar credenciais fixas.
for p in (ROOT / "database" / "postgres" / "legacy").glob("*.sql"):
    text = read(p)
    if re.search(r"PASSWORD\s+'[^']+'", text, re.I):
        errors.append(f"{p.relative_to(ROOT)}: senha fixa encontrada em SQL legado")
    if re.search(r"INSERT\s+INTO\s+usuarios\s*\([^)]*senha", text, re.I):
        errors.append(f"{p.relative_to(ROOT)}: bootstrap de usuario/senha encontrado em SQL legado")

# O instalador oficial precisa permanecer MySQL/MariaDB.
official = ROOT / "site" / "var" / "www" / "html" / "api" / "schema_mysql.sql"
if not official.exists():
    errors.append("schema_mysql.sql oficial nao encontrado")
else:
    text = read(official)
    if not re.search(r"ENGINE\s*=\s*InnoDB", text, re.I):
        errors.append("schema_mysql.sql nao parece ser MySQL/MariaDB")
    sig = has_any(text, pg_signatures)
    if sig:
        errors.append(f"schema_mysql.sql contem sintaxe PostgreSQL ({sig})")

if errors:
    print("Falhas na separacao dos dominios de banco:")
    for e in errors:
        print(" -", e)
    sys.exit(1)

print("Database domains OK: MySQL Control Plane e PostgreSQL legado/servicos separados.")
