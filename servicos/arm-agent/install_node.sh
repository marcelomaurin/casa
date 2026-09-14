#!/usr/bin/env bash
# Instalador do Agente Distribuido CASA/JARVIS para Raspberry Pi e outros nos ARM.
# O runtime usa https://casa.maurinsoft.com.br como ponto central de coordenacao.
# O codigo do agente e obtido da fonte oficial do projeto no GitHub.

set -euo pipefail

REPO_RAW="https://raw.githubusercontent.com/marcelomaurin/casa/master/servicos/arm-agent"
INSTALL_DIR="/opt/casa-node-agent"
ENV_FILE="/etc/casa-node-agent.env"

echo "=== Instalando Agente de No ARM CASA/JARVIS ==="

apt-get update -y
apt-get install -y python3 python3-pip alsa-utils ca-certificates curl

mkdir -p "$INSTALL_DIR"

curl --fail --silent --show-error --location \
  "$REPO_RAW/casa-node-agent.py" \
  -o "$INSTALL_DIR/casa-node-agent.py"
chmod 0755 "$INSTALL_DIR/casa-node-agent.py"

curl --fail --silent --show-error --location \
  "$REPO_RAW/casa-node-agent.service" \
  -o /etc/systemd/system/casa-node-agent.service

if [ ! -f "$ENV_FILE" ]; then
  cat > "$ENV_FILE" <<'EOF'
# Configuracao privada deste no. Nao versionar este arquivo.
JARVIS_MASTER_URL=https://casa.maurinsoft.com.br/api/crud.php
JARVIS_API_BASE=https://casa.maurinsoft.com.br/api/v1
JARVIS_DEVICE_ID=raspberry-NOME-DO-NO
JARVIS_DEVICE_TOKEN=COLOQUE_O_TOKEN_INDIVIDUAL_DESTE_NO
JARVIS_CAPABILITIES=gpio,mqtt,serial,rs485,ble,scheduler,audio
AGENT_PORT=8098
HEARTBEAT_INTERVAL=20
EOF
  chmod 0600 "$ENV_FILE"
  echo "Criado $ENV_FILE. Edite DEVICE_ID, DEVICE_TOKEN e CAPABILITIES antes do uso em producao."
fi

systemctl daemon-reload
systemctl enable casa-node-agent.service
systemctl restart casa-node-agent.service

echo "Agente instalado."
echo "Dominio central: https://casa.maurinsoft.com.br"
echo "Configuracao privada: $ENV_FILE"
systemctl status casa-node-agent.service --no-pager
