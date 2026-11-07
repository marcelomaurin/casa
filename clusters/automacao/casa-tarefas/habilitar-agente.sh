#!/usr/bin/env bash
# Habilita agente no daemon ja instalado, sem expor ou sobrescrever credenciais.
# Execute no no que possui /opt/casa-tarefas: sudo bash habilitar-agente.sh
set -euo pipefail
CONF=/etc/casa-tarefas.env
SERVICO=casa-tarefas.service
if [ "$(id -u)" -ne 0 ]; then echo "Execute com sudo/root"; exit 1; fi
if [ ! -f "$CONF" ] || [ ! -f /opt/casa-tarefas/casa-tarefas.py ] || [ ! -f /opt/casa-tarefas/casa_tarefas_agente.py ]; then
    echo "Daemon nao instalado. Execute install.sh antes de ativar o agente."
    exit 1
fi
python3 -m py_compile /opt/casa-tarefas/casa-tarefas.py /opt/casa-tarefas/casa_tarefas_agente.py
BACKUP=$(mktemp /etc/casa-tarefas.env.bak.XXXXXXXX)
chmod 600 "$BACKUP"
cp "$CONF" "$BACKUP"
export CASA_AGENT_CONFIG="$CONF"
python3 - <<'PY'
import os
from pathlib import Path
p=Path(os.environ["CASA_AGENT_CONFIG"])
s=p.read_text(encoding="utf-8")
lines=s.splitlines()
values={}
for line in lines:
    if "=" in line and not line.lstrip().startswith("#"):
        k,v=line.split("=",1)
        values[k.strip()]=v.strip().strip("'\"")
if not values.get("CASA_TAREFAS_CHAVE"):
    raise SystemExit("CASA_TAREFAS_CHAVE nao configurada; nenhuma alteracao realizada.")
executores=[x.strip() for x in values.get("CASA_TAREFAS_EXECUTORES","jarvis,web,fala").split(",") if x.strip()]
for item in ("jarvis","agente"):
    if item not in executores: executores.append(item)
novos={
    "CASA_TAREFAS_AGENTE_ENABLED":"1",
    "CASA_TAREFAS_JARVIS_MODO":"agente",
    "CASA_TAREFAS_EXECUTORES":",".join(executores)
}
for k,v in novos.items():
    encontro=False
    for i,line in enumerate(lines):
        if line.startswith(k+"="):
            lines[i]=k+"="+v
            encontro=True
            break
    if not encontro:
        lines.append(k+"="+v)
tmp=p.with_suffix(".env.tmp")
with tmp.open("w",encoding="utf-8") as out:
    out.write("\n".join(lines)+"\n")
os.chmod(tmp,0o640)
os.replace(tmp,p)
PY
chown root:casa-tarefas "$CONF"
chmod 640 "$CONF"
if ! python3 /opt/casa-tarefas/casa-tarefas.py --config "$CONF" --verificar; then
    cp "$BACKUP" "$CONF"
    chmod 640 "$CONF"
    chown root:casa-tarefas "$CONF"
    echo "Configuracao invalida; restaurada."
    exit 1
fi
systemctl daemon-reload
systemctl enable "$SERVICO"
if ! systemctl restart "$SERVICO" || ! systemctl is-active --quiet "$SERVICO"; then
    echo "Daemon nao iniciou. Restaurando arquivo anterior, sem excluir o backup: $BACKUP"
    cp "$BACKUP" "$CONF"
    chmod 640 "$CONF"
    chown root:casa-tarefas "$CONF"
    systemctl restart "$SERVICO" || true
    journalctl -u "$SERVICO" -n 30 --no-pager || true
    exit 1
fi
echo "CASA agente ativado. Backup privado em: $BACKUP"
systemctl --no-pager status "$SERVICO" | head -n 18 || true
