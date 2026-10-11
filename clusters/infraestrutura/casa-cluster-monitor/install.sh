#!/usr/bin/env bash
set -e

echo "=== Instalador do CASA Cluster Monitor Daemon ==="

SERVICE_FILE="casa-cluster-monitor.service"
TARGET="/etc/systemd/system/${SERVICE_FILE}"

if [ ! -f "${SERVICE_FILE}" ]; then
    echo "Erro: ${SERVICE_FILE} nao encontrado no diretorio atual."
    exit 1
fi

sudo cp "${SERVICE_FILE}" "${TARGET}"
sudo systemctl daemon-reload
sudo systemctl enable "${SERVICE_FILE}"
sudo systemctl restart "${SERVICE_FILE}"

echo "Servico instalado e iniciado com sucesso!"
sudo systemctl status "${SERVICE_FILE}" --no-pager
