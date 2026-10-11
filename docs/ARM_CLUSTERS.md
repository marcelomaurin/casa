# Clusters e agentes Linux ARM

A página `index.php?grupo=DISPOSITIVOS&item=nodes` lista e gerencia os clusters e nós Linux ARM através da API `api/arm_nodes.php`, com autenticação da sessão web ou token de API do Control Plane.

## Arquitetura de Monitoramento: Padrão Invertido (Dead Man's Snitch)

1. **Heartbeat Ativo (a cada 20 segundos):**
   - O agente local nos nós (`casa-node-agent.py`) envia um heartbeat ativo com telemetria (CPU, RAM, temperatura, IP) para o endpoint `/api/v1/device.php?acao=heartbeat` a cada 20 segundos (`HEARTBEAT_INTERVAL = 20`).
2. **Queda e Timeout (em 40 segundos):**
   - Se em até **40 segundos** o nó não responder, ele é considerado caído (`offline`).
   - Um serviço em segundo plano (`casa-cluster-monitor.service` em `clusters/infraestrutura/casa-cluster-monitor/`) avalia continuamente a diferença de tempo e atualiza as tabelas `dispositivos_cluster` e `arm_nodes`, registrando logs e eventos de queda (`NODE_OFFLINE`).

## Operações na Interface Web (`?grupo=DISPOSITIVOS&item=nodes`)

- **Inclusão (+ NOVO NÓ):** Permite cadastrar um novo nó manualmente preenchendo Identificador (`device_id`), Nome, IP, Hostname e Papel/Função.
- **Edição (EDITAR):** Permite ajustar Nome, IP, Hostname e Papel de qualquer nó existente através de formulário modal flutuante.
- **Exclusão (EXCLUIR):** Botão direto no card e no rodapé do modal de edição com confirmação prévia, excluindo o registro do banco de dados e do cluster.
- **Acesso SSH e Portal:** Cada nó exibe atalho de comando SSH (`ssh mmm@<IP>`) e link direto para o portal web do nó na porta `:8080` (`http://<IP>:8080/`).

## Nós Mapeados do Cluster

| Device ID | Nome de Exibição | Hostname | Endereço IP | Papel / Função |
|---|---|---|---|---|
| `raspberry-pi-local` | Raspberry Pi 4B (Hub Principal) | `raspberrypi` | `192.168.2.13` (Alias `192.168.0.211`) | Hub Central / Servidor Local / TTS Piper / GPIO |
| `raspberry-pi-node-12` | Raspberry Pi 4B (Cluster Hub) | `rpi4-hub` | `192.168.2.12` | Hub Cluster Primário / Site Porta 8080 |
| `raspberry-pi-node-13` | Raspberry Pi Node 13 | `raspberrypi` | `192.168.2.13` | Rack Servidores / Nó Secundário |
| `cubieboard-arm-07` | Cubieboard 2 ARMv7 | `cubieboard` | `192.168.2.7` | Gateway Automação / Serial RS485 / Allwinner A20 |
| `raspberry-pi-node-08` | Raspberry Pi Node 08 | `raspberrypi-08` | `192.168.2.8` | Quadro de Automação / Relés / Sensores |
