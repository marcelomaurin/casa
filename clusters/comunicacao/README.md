# Comunicação com o site

[Voltar ao catálogo](../README.md)

## [arm-agent](arm-agent/)

Agente principal Linux ARM: envia heartbeat, consulta comandos e publica resultados pela API v1. Instale com `arm-agent/install_node.sh`; destino de execução: `/opt/casa-node-agent`.

## [tuya-wifi-daemon](tuya-wifi-daemon/)

Daemon de controle de dispositivos e sensores Tuya Wi-Fi: escuta broadcasts UDP 6666/6667, decodifica temperatura e umidade Smart Life e expõe API REST na porta 8101.
