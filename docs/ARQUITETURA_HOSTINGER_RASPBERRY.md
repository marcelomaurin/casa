# Arquitetura CASA/JARVIS — Hostinger x Raspberry Pi

Este documento define o que roda na hospedagem pública da Hostinger e o que permanece dentro da rede local da residência.

## Regra geral

- **Hostinger**: interface web pública, API pública e banco MySQL.
- **Raspberry Pi / rede local**: integração física, automação, sensores, câmeras, voz, agentes e serviços locais.
- **Celular / relógio / TV**: clientes do sistema. Falam com a API pública e, quando aplicável, com serviços locais.

```text
Internet
   |
   v
https://casa.maurinsoft.com.br
   |
   +-- Site LCARS
   +-- Login
   +-- API /api/v1
   +-- MySQL
           ^
           |
      HTTPS autenticado
           |
   +-------+------------------------------+
   |                                      |
Raspberry Pi 1                       Raspberry Pi 2...
Gateway / automação                  câmera / sensores
   |                                      |
   +-- MQTT / GPIO / serial / BLE         +-- ESP32-CAM
   +-- scheduler                           +-- análise local
   +-- agentes                             +-- dispositivos
   +-- áudio/TTS                           +-- atuadores
```

## HOSTINGER

### Deve ficar na Hostinger

```text
site/var/www/html/
```

Inclui:

- `index.php` e interface web LCARS;
- `login.php`;
- APIs PHP;
- `/api/v1/`;
- autenticação de usuários;
- autenticação de dispositivos/clientes;
- recepção de eventos enviados pelos Raspberry Pi;
- fila de comandos destinados aos nós locais;
- notificações para celular, TV e relógio;
- banco MySQL/MariaDB;
- histórico, telemetria e estados persistentes.

### Não deve ficar na Hostinger

- llama.cpp;
- modelos GGUF;
- TTS pesado;
- captura direta de câmera local;
- GPIO;
- serial/RS485;
- drivers USB locais;
- MQTT broker interno, quando usado somente dentro da casa;
- processos Python que precisem controlar hardware;
- credenciais locais de Wi-Fi;
- chaves privadas dos nós.

## RASPBERRY PI

Os Raspberry Pi são os **nós de execução física** da casa.

### Serviço-base em cada Raspberry

O serviço principal recomendado é:

```text
servicos/arm-agent/
```

Responsabilidades:

- identificar o nó;
- autenticar-se na API pública;
- enviar heartbeat;
- informar sensores e estado do hardware;
- buscar comandos pendentes;
- executar apenas comandos autorizados para aquele nó;
- devolver resultado da execução;
- manter operação local básica quando a internet cair;
- reconectar automaticamente à API.

### Serviços que podem rodar nos Raspberry

| Serviço | Uso |
|---|---|
| `servicos/arm-agent/` | Agente principal do nó Raspberry |
| `servicos/casa-scheduler/` | Agendamentos que precisem continuar sem internet |
| `servicos/espcam/` | Recepção/processamento de câmeras locais |
| `servicos/tts/` | Voz local quando o Raspberry tiver capacidade suficiente |
| `servicos/web-agent/` | Nó destinado a agentes/pesquisa |
| `servicos/casa-tunnel/` | Comunicação segura quando necessária |

### Hardware controlado pelos Raspberry

- relés e iluminação;
- sensores ambientais e presença;
- portas e portões;
- GPIO;
- serial e RS485/Modbus;
- Bluetooth/BLE;
- câmeras locais;
- microfones e alto-falantes;
- ESP32/ESP8266;
- interfaces Nextion;
- outros controladores locais.

## Organização recomendada

### Raspberry Gateway

- `arm-agent`;
- comunicação Hostinger ↔ casa;
- MQTT local;
- descoberta dos dispositivos;
- comandos para ESP32/ESP8266;
- automação crítica local;
- fila local em caso de perda de internet.

### Raspberry Câmeras

- ESP32-CAM/câmeras USB/IP;
- captura local;
- pré-processamento;
- detecção de eventos;
- envio somente dos eventos/metadados necessários para a Hostinger.

Vídeos privados não devem ser enviados continuamente para a Hostinger sem necessidade.

### Raspberry Voz

- microfone ambiente;
- wake word;
- STT local ou encaminhamento ao servidor de IA;
- TTS;
- reprodução de respostas.

### Servidor de IA local

Modelos grandes não precisam rodar no Raspberry. O Raspberry pode chamar o servidor local com GPU pela rede interna.

```text
Raspberry
   |
   +--> servidor IA local / llama.cpp
   |
   +--> Hostinger API para estado e histórico
```

## Fluxo de comando

```text
Celular / TV / Web / Relógio
          |
          v
Hostinger /api/v1
          |
          | comando pendente
          v
Raspberry arm-agent
          |
          v
relé / ESP / dispositivo físico
          |
          v
Raspberry envia resultado
          |
          v
Hostinger atualiza estado/histórico
```

A Hostinger não deve acessar diretamente IP privado do Raspberry. O Raspberry inicia a comunicação HTTPS de saída para `casa.maurinsoft.com.br`.

## Funcionamento sem internet

Os Raspberry devem manter funções essenciais locais quando a Hostinger estiver temporariamente inacessível:

- alarmes e timers locais;
- regras básicas de automação;
- sensores;
- automações de segurança;
- scheduler local;
- fila de eventos para sincronização posterior.

Ao recuperar a conexão:

1. o agente reconecta;
2. envia eventos pendentes;
3. sincroniza estados;
4. busca novos comandos;
5. volta à operação normal.

## Segurança

- cada Raspberry deve possuir token próprio;
- tokens não devem ser gravados no Git;
- comunicação com Hostinger deve ser HTTPS;
- MySQL não deve ser exposto aos Raspberry pela Internet;
- Raspberry conversa com o banco somente através da API;
- serviços locais devem permanecer protegidos pela rede/firewall;
- comandos devem possuir escopo/autorização por nó.

## Implantação Hostinger

A publicação deve enviar apenas o conteúdo web necessário para o diretório público da hospedagem. Serviços Python, firmwares, builds Android e arquivos locais não fazem parte do pacote do site.

A configuração de banco deve ser fornecida no ambiente de produção, nunca versionada.

Variáveis esperadas pelo PHP:

```text
JARVIS_DB_HOST
JARVIS_DB_PORT=3306
JARVIS_DB_NAME
JARVIS_DB_USER
JARVIS_DB_PASS
JARVIS_DB_CHARSET=utf8mb4
JARVIS_SYSTEM_API_TOKEN
```

## Fonte oficial

O GitHub continua sendo a fonte oficial do projeto. Alterações de produção devem sair de uma versão identificável do repositório, evitando edições manuais permanentes diretamente na hospedagem.
