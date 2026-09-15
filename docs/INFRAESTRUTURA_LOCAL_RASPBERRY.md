# Infraestrutura Local CASA / JARVIS — Raspberry Pi

Este documento registra detalhadamente a topologia, os serviços e os procedimentos da **infraestrutura local** executada no Raspberry Pi, servindo como referência oficial para desenvolvedores e agentes de Inteligência Artificial.

---

## 1. Princípio Arquitetural e Divisão de Responsabilidades

O ecossistema CASA/JARVIS é rigorosamente dividido em três camadas:

```text
               NUVEM (Hostinger)
          https://maurinsoft.com.br/casa/
         ┌────────────────────────────────┐
         │ • Site PHP / Interface LCARS   │
         │ • API v1 Pública (/api/v1/)    │
         │ • Banco Central (MySQL/MariaDB)│
         │ • Coordenação de Nós e Estado  │
         └───────────────▲────────────────┘
                         │ HTTPS / Bearer Token
         ┌───────────────▼────────────────────────────────────────┐
         │ RASPBERRY PI (192.168.2.12 / Alias 192.168.0.211)      │
         │                                                        │
         │ • Apenas scripts NÃO-PHP e serviços de hardware:       │
         │   - casa-node-agent (porta 8098): agente ARM/GPIO/fala │
         │   - casa-tts (porta 8097): síntese neural Piper pt-BR  │
         │   - casa-scheduler: rotinas e agendamentos locais      │
         │   - espcam: processamento de imagens e presença        │
         │   - web-agent: agente autônomo de busca e scraping    │
         │ • PostgreSQL local (casadb) para resiliência offline   │
         └───────────────▲────────────────────────────────────────┘
                         │ Wi-Fi / BLE / RS485 / GPIO
         ┌───────────────▼────────────────────────────────────────┐
         │ HARDWARES & DISPOSITIVOS DE BORDA                      │
         │ • ESP32-CAM, ESP8266, Arduino, Relés, Sensores        │
         └────────────────────────────────────────────────────────┘
```

* **Site Central (`maurinsoft.com.br/casa`)**: Único ponto público oficial com scripts PHP, interface de usuário e API central.
* **Infraestrutura Local (Raspberry Pi)**: Roda **exclusivamente scripts não-PHP** (Python, binários de TTS/IA, controle físico de atuadores). Nunca hospeda o site principal.
* **Hardwares**: Microcontroladores e sensores comunicando-se diretamente via rede local com o Raspberry Pi ou enviando telemetria para a API central.

---

## 2. Identificação do Equipamento & Rede

* **Hardware**: Raspberry Pi 4 Model B Rev 1.2 (4 núcleos ARM64, 4GB RAM)
* **Sistema Operacional**: Debian GNU/Linux 12 (bookworm) / Kernel `6.12.96+rpt-rpi-v8`
* **Usuário SSH**: `mmm`
* **Endereçamento IP**:
  * **IP Primário Atual (DHCP da LAN)**: `192.168.2.12`
  * **IP Alias Secundário**: `192.168.0.211` (configurado em `eth0` para compatibilidade com dispositivos ou referências que buscam essa sub-rede histórica).
* **Portas de Serviços Locais**:
  * `22`: SSH
  * `8098`: `casa-node-agent` (REST local do agente ARM)
  * `8097`: `casa-tts` (FastAPI Piper Neural TTS)
  * `5432`: PostgreSQL local (`casadb`)

---

## 3. Serviços Locais Ativos (Systemd)

### 3.1 `casa-node-agent.service`
* **Arquivo executável**: `/opt/casa-node-agent/casa-node-agent.py`
* **Service unit**: `/etc/systemd/system/casa-node-agent.service`
* **Arquivo de variáveis**: `/etc/casa-node-agent.env`
* **Porta**: `8098`
* **Funções**:
  * Heartbeat contínuo com `https://maurinsoft.com.br/casa/api/crud.php`.
  * Anúncio de capacidades: `gpio,audio,tts,camera,scheduler,arm-agent,hardware-gateway`.
  * Endpoint `GET /status`: telemetria de CPU, memória, carga e temperatura térmica.
  * Endpoint `POST /falar`: recebe comando de fala, sintetiza via TTS local (`localhost:8097`) e reproduz no alto-falante local via `aplay`.
  * Endpoint `POST /exec`: execução de comandos físicos no nó ARM.

### 3.2 `casa-tts.service`
* **Arquivo executável**: `/home/mmm/servicos/tts/server_tts.py` (dentro de venv Python dedicado)
* **Service unit**: `/etc/systemd/system/casa-tts.service`
* **Porta**: `8097`
* **Funções**:
  * Síntese de voz com **Piper Neural TTS** modelo pt-BR via ONNX Runtime.
  * Endpoint `GET /status`: `{"status":"online","model":"Piper Neural TTS pt-BR","engine":"ONNX Runtime"}`.
  * Endpoint `POST /falar`: gera arquivo WAV de voz sintetizada com resposta rápida e natural.

### 3.3 `casa-scheduler.service`
* **Arquivo executável**: `/opt/casa-scheduler/casa-scheduler.py`
* **Service unit**: `/etc/systemd/system/casa-scheduler.service`
* **Funções**:
  * Monitoramento de tarefas agendadas em banco local PostgreSQL (`casadb`).
  * Disparo de rotinas automáticas de irrigação, avisos sonoros e automações programadas.

### 3.4 Serviços Auxiliares em `/home/mmm/servicos/`
* **`espcam/`**: Módulos de recepção e análise de frames enviados por câmeras ESP32 (`processa_imagem.py`, `analisa_cena_ia.py`).
* **`web-agent/`**: Agente autônomo FastAPI para pesquisas na web sob demanda do JARVIS (`web_agent.py`).

---

## 4. Comandos de Operação e Diagnóstico

Qualquer agente ou mantenedor pode verificar a infraestrutura via SSH executando:

```bash
# Verificar se todos os servicos essenciais estao ativos
systemctl is-active casa-node-agent.service casa-tts.service casa-scheduler.service

# Checar status da telemetria do Agente ARM
curl -s http://127.0.0.1:8098/status

# Checar o servico de sintese de voz neural
curl -s http://127.0.0.1:8097/status

# Testar sintese de voz (sem tocar alto-falante)
curl -s -X POST http://127.0.0.1:8097/falar \
  -H "Content-Type: application/json" \
  -d '{"texto":"Sistema operacional JARVIS ativo.","speaker":"padrao","reproduzir":false}'

# Testar comando de fala no alto-falante local
curl -s -X POST http://127.0.0.1:8098/falar \
  -H "Content-Type: application/json" \
  -d '{"texto":"Teste do alto-falante do no ARM."}'

# Inspecionar logs recentes
journalctl -u casa-node-agent -n 30 --no-pager
journalctl -u casa-tts -n 30 --no-pager
```

---

## 5. Regras de Segurança para Futuras IAs

1. **Nunca versionar segredos**: Chaves de API, senhas do banco local, tokens Bearer e certificados devem ficar restritos aos arquivos de ambiente no host (`/etc/casa-node-agent.env`), com permissões `0600`.
2. **Respeito à Divisão**: Não mover scripts PHP para o Raspberry Pi, nem tentar hospedar a API mestre nele. A fonte de verdade das APIs PHP é a hospedagem na nuvem (`https://maurinsoft.com.br/casa/`).
3. **Resiliência Offline**: Se a conexão com a nuvem falhar, os serviços do nó ARM devem registrar o evento e continuar operando a lógica física essencial em modo degradado, sem crashar.
