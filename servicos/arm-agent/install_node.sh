#!/usr/bin/env bash
# Script de instalacao do Agente do Cluster ARM para a Casa Inteligente
# Executar em qualquer maquina ARM (Raspberry Pi, Orange Pi, Rock Pi):
# curl -s http://192.168.2.12/servicos/install_node.sh | sudo bash

set -e

echo "=== Instalando Agente de Nó ARM para JARVIS Casa Inteligente ==="

sudo apt-get update -y
sudo apt-get install -y python3 python3-pip alsa-utils

sudo mkdir -p /opt/casa-node-agent
cd /opt/casa-node-agent

# Baixar agente do mestre
curl -s -o /opt/casa-node-agent/casa-node-agent.py http://192.168.2.12/servicos/casa-node-agent.py || true
chmod +x /opt/casa-node-agent/casa-node-agent.py

# Baixar e habilitar servico systemd
curl -s -o /etc/systemd/system/casa-node-agent.service http://192.168.2.12/servicos/casa-node-agent.service || true

systemctl daemon-reload
systemctl enable casa-node-agent.service
systemctl restart casa-node-agent.service

echo "Agente instalado e ativo!"
systemctl status casa-node-agent.service --no-pager
