# Arduino Ethernet — Módulo de Relés de Alta Corrente & Telemetria JARVIS

Este projeto implementa o controle robusto e cabeado de até **4 canais de relés** utilizando um **Arduino Uno ou Mega** acoplado a um **Ethernet Shield W5100 / W5500**, imune a interferências de sinal WiFi.

---

## 1. Conexões de Hardware
- **Placa**: Arduino Uno R3 ou Arduino Mega 2560
- **Shield**: Ethernet Shield W5100 ou W5500 encaixado diretamente sobre a placa Arduino
- **Módulo de Relés (4 Canais)**:
  - `VCC` -> `5V` do Arduino
  - `GND` -> `GND` do Arduino
  - `IN1` -> Pino Digital `4`
  - `IN2` -> Pino Digital `5`
  - `IN3` -> Pino Digital `6`
  - `IN4` -> Pino Digital `7`

---

## 2. API REST Embutida
A placa atua como um micro-servidor HTTP na porta `8080`:
- **Consulta Geral**: `GET http://192.168.2.52:8080/status`
  - Retorna JSON com estado de cada relé e memória RAM livre (em bytes).
- **Ligar Relé 1**: `GET http://192.168.2.52:8080/rele/1/1`
- **Desligar Relé 1**: `GET http://192.168.2.52:8080/rele/1/0`
- **Ligar Relé 2**: `GET http://192.168.2.52:8080/rele/2/1`
- **Desligar Relé 2**: `GET http://192.168.2.52:8080/rele/2/0`

---

## 3. Telemetria e Heartbeat
A cada 20 segundos, o Arduino conecta ao IP `192.168.2.12` e envia um pacote de dados via `POST /api/crud.php?tabela=dispositivos_cluster&acao=heartbeat_iot` contendo:
- Token de autenticação (`X-Device-Token`)
- Estado atual dos 4 relés
- Quantidade exata de memória RAM livre no microcontrolador
