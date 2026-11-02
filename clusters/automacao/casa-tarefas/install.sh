#!/usr/bin/env bash
# Instala o daemon casa-tarefas como serviço systemd.
# Uso: sudo ./install.sh   (rodar dentro de clusters/automacao/casa-tarefas)
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
DEST=/opt/casa-tarefas
CONF=/etc/casa-tarefas.env
CMDS=/etc/casa-tarefas/comandos.d

if [ "$(id -u)" -ne 0 ]; then echo "Execute com sudo."; exit 1; fi
command -v python3 >/dev/null || { echo "python3 não encontrado."; exit 1; }

id casa-tarefas >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin casa-tarefas
# Acesso ao áudio para o executor "fala", quando o TTS toca localmente.
getent group audio >/dev/null && usermod -aG audio casa-tarefas || true

install -d -m 0755 "$DEST" "$DEST/executores" "$CMDS"
install -m 0755 "$DIR/casa-tarefas.py" "$DEST/casa-tarefas.py"
install -m 0644 "$DIR/casa_tarefas_agente.py" "$DEST/casa_tarefas_agente.py"
install -d -m 0750 -o casa-tarefas -g casa-tarefas /var/lib/casa-tarefas /var/lib/casa-tarefas/workspaces
for f in "$DIR"/executores/*.py; do [ -e "$f" ] && install -m 0644 "$f" "$DEST/executores/"; done

if [ ! -f "$CONF" ]; then
    install -m 0640 -o root -g casa-tarefas "$DIR/casa-tarefas.env.exemplo" "$CONF"
    echo "Configuração criada em $CONF — preencha CASA_TAREFAS_CHAVE antes de iniciar."
fi
# Scripts de exemplo ficam desativados (sem permissão de execução) até você liberar.
for f in "$DIR"/comandos.d/*; do
    [ -e "$f" ] || continue
    [ -e "$CMDS/$(basename "$f")" ] || install -m 0644 "$f" "$CMDS/"
done

install -m 0644 "$DIR/casa-tarefas.service" /etc/systemd/system/casa-tarefas.service
systemctl daemon-reload
systemctl enable casa-tarefas.service

if grep -q '^CASA_TAREFAS_CHAVE=..*' "$CONF"; then
    systemctl restart casa-tarefas.service
    systemctl --no-pager status casa-tarefas.service || true
else
    echo "Serviço habilitado mas NÃO iniciado: falta CASA_TAREFAS_CHAVE em $CONF."
    echo "Depois de preencher: sudo systemctl start casa-tarefas && journalctl -u casa-tarefas -f"
fi
