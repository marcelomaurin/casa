#!/bin/bash
set -euo pipefail
/usr/bin/python3 -c 'import sys; assert sys.version_info >= (3,8), "Python 3.8+ obrigatorio"'
# O atualizador busca os fontes direto do GitHub: git é obrigatório.
if ! command -v git >/dev/null 2>&1; then
  if command -v apt-get >/dev/null 2>&1; then apt-get install -y git; else echo "Instale o git" >&2; exit 1; fi
fi
TASK_SOURCE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
install -d -m 755 /opt/casa/cluster-update
install -m 755 "$TASK_SOURCE/casa-cluster-update.py" /opt/casa/cluster-update/
install -m 755 "$TASK_SOURCE/casa-cluster-update.sh" /usr/local/bin/casa-cluster-update
install -m 644 "$TASK_SOURCE/casa-cluster-update.service" "$TASK_SOURCE/casa-cluster-update.timer" /etc/systemd/system/
/usr/bin/python3 /opt/casa/cluster-update/casa-cluster-update.py --init-config
systemctl daemon-reload
systemctl enable --now casa-cluster-update.timer
systemctl restart casa-cluster-update.timer
printf '%s\n' 'Configure device_id e device_token em /etc/casa/cluster-update/config.cfg (mesmas credenciais do agente ARM deste nó).'
