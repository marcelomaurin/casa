# Especificação de Requisitos e Arquitetura: ESP32-CAM (CASA / JARVIS)

**Projeto:** Sistema Integrado de Câmeras e Visão Computacional Residencial (CASA)  
**Data:** 19/10/2026  
**Status:** Implementado e Homologado  
**Repositório:** [https://github.com/marcelomaurin/casa.git](https://github.com/marcelomaurin/casa.git)  

---

## 1. Visão Geral

Este documento formaliza todos os requisitos arquiteturais, de segurança, conectividade e operabilidade definidos para as câmeras **ESP32-CAM** dentro do ecossistema **CASA / JARVIS**.

O sistema garante que as câmeras operem de forma segura (zero exposição de credenciais por HTTP), com seleção dinâmica da melhor rede Wi-Fi residencial, redundância de daemons (Active/Standby), auditoria contínua de intensidade de sinal gravada minuto a minuto na nuvem e visualização centralizada via interface LCARS.

---

## 2. Arquitetura de Comunicação e Topologia

```
+-------------------------------------------------------------------------+
|                              NUVEM CASA                                 |
|                       (maurinsoft.com.br/casa)                          |
|                                                                         |
|  - Cadastro de Redes Wi-Fi da Casa (redes_wifi_casa)                   |
|  - Cofre de Tokens de Câmeras (camera_tokens.json / DB)                 |
|  - Gravação Histórica de Sinal Wi-Fi (camera_wifi_survey)               |
|  - Cálculo Dinâmico de Status Online (ultimo_heartbeat <= 90s)          |
|  - Visão LCARS: Câmeras & Propriedades com Medidores de Sinal           |
+------------------------------------+------------------------------------+
                                     ^
                    HTTPS / REST API | (Token Register, Heartbeat,
                                     |  Wi-Fi Sync & Survey Upload)
                                     v
+------------------------------------+------------------------------------+
|                      DAEMON DE VISÃO ATIVO (CASA)                       |
|                          (espcam_daemon.py)                             |
|                                                                         |
|  * Regra de Alta Disponibilidade: APENAS 1 DAEMON ATIVO por residência  |
|  * Demais daemons permanecem em Standby (backup automático)             |
|  * Busca redes Wi-Fi da nuvem e repassa para as câmeras                 |
|  * Coleta frames e publica snapshots na nuvem                           |
|  * Coleta survey de Wi-Fi a cada 1 minuto e grava na nuvem              |
+------------------------------------+------------------------------------+
                                     ^
                      LAN HTTP / TCP | (X-Camera-Token Obrigatório)
                                     v
+------------------------------------+------------------------------------+
|                         CÂMERA ESP32-CAM                                |
|                        (jarvis_espcam.ino)                              |
|                                                                         |
|  * Token Físico Obtido Exclusivamente via Serial (gravafirmware)        |
|  * Token Criptográfico Forte e Não-Repetível (MAC + eFuse + HW RNG)     |
|  * Escaneia e conecta na MELHOR rede Wi-Fi da casa (maior RSSI)         |
|  * Mede força das redes durante a transmissão de vídeo MJPEG            |
|  * Envia Heartbeat autônomo a cada 30 segundos para o site              |
+------------------------------------+------------------------------------+
                                     ^
                       Serial Física | (UART 115200 baud)
                                     v
+------------------------------------+------------------------------------+
|                      APLICATIVO GRAVAFIRMWARE                           |
|               (Lazarus / CHATGPT Lib + Python Alternativo)              |
|                                                                         |
|  * Grava binário compilado (jarvis_espcam.ino.merged.bin em 0x0)        |
|  * Captura token físico imediatamente pela Serial                       |
|  * Registra token no Site CASA e no Daemon da Casa                      |
+-------------------------------------------------------------------------+
```

---

## 3. Matriz de Requisitos Detalhada

### R01. Daemons de Visão em Alta Disponibilidade (Active/Standby)
- **R01.1:** Na residência, apenas **um daemon de visão** pode estar ativo e responsável por puxar os frames das câmeras.
- **R01.2:** Os outros daemons instalados na casa atuam como **backup (standby)** e não devem interagir diretamente com as câmeras simultaneamente para evitar concorrência e degradação de banda.
- **R01.3:** O daemon ativo é quem se comunica com o site central e com as câmeras.

### R02. Segurança Físico-Serial do Token da Câmera (Zero HTTP Exposure)
- **R02.1:** Para acessar os streams ou comandos da ESP32-CAM, é estritamente obrigatório apresentar o token de segurança (`X-Camera-Token: <token>` ou parâmetro `?token=<token>`).
- **R02.2:** Qualquer requisição HTTP sem autenticação recebe `401 Unauthorized`.
- **R02.3:** O token **nunca** é transmitido ou exibido pela câmera via HTTP; ele é configurado ou obtido **apenas fisicamente pela interface Serial física** através do comando `GET_TOKEN`, `SET_TOKEN` ou no boot pela mensagem:
  ```text
  CAM_SECRET:DEVICE_ID=<id>:MAC=<mac>:TOKEN=<token>:IP=<ip>:VER=<ver>
  ```

### R03. Entropia e Não-Repetição do Token
- **R03.1:** O token do ESP32-CAM deve ser único, aleatório e não-repetível entre reinicializações e entre diferentes placas.
- **R03.2:** Implementado no firmware combinando chip eFuse MAC (`ESP.getEfuseMac()`), contador de ciclo de clock do processador (`ESP.getCycleCount()`), temporizador de microsegundos (`micros()`) e números aleatórios gerados por hardware (`esp_random()`), formatado como hash de 128 bits (32 caracteres hexadecimais).

### R04. Aplicativo Desktop `gravafirmware`
- **R04.1:** Aplicativo desktop construído para gravar o firmware no ESP32-CAM e gerenciar o ciclo de inicialização.
- **R04.2:** Após a gravação, captura automaticamente pela Serial o token e metadados da câmera.
- **R04.3:** Envia o token capturado diretamente para o site central CASA:
  `POST /casa/api/camera_discovery.php?acao=register_token`
- **R04.4:** Envia o token para a porta de controle IPC do daemon local (`http://127.0.0.1:8088/token`) e persiste no cofre local `~/.casa/camera_tokens.json`.
- **R04.5:** Versão nativa desenvolvida em **Lazarus / Free Pascal** integrando o componente reutilizável `TAIFirmwareFlasher` da biblioteca CHATGPT (`openai_hardware.lpk`), com botão "Gerar Novo Token".
- **R04.6:** Versão alternativa em **Python** (`gravafirmware.py`) mantida e sincronizada.

### R05. Status Real em Tempo Real (Fim do Status Chumbado)
- **R05.1:** O status da câmera na interface **nunca** pode ser fixo/chumbado.
- **R05.2:** O backend (`camera_discovery.php?acao=listar`) calcula dinamicamente o status comparando `ultimo_heartbeat` com o horário atual:
  - Se `ultimo_heartbeat` foi registrado nos últimos **90 segundos**, o status é `'online'`.
  - Se `ultimo_heartbeat` é nulo ou superior a 90 segundos, o status é `'offline'`.
- **R05.3:** O firmware do ESP32-CAM envia heartbeat a cada 30 segundos com RSSI, IP e MAC para `api/camera_discovery.php?acao=heartbeat`.
- **R05.4:** A interface web realiza auto-probe local na abertura da página e disponibiliza botão de teste manual imediato com indicador de tempo decorrido (*ex: "Há 12s"*, *"Nunca comunicou"*).

### R06. Distribuição Automática das Redes Wi-Fi da Casa
- **R06.1:** A ESP32-CAM deve receber todas as redes Wi-Fi da residência (SSID e senhas) provenientes do site central CASA.
- **R06.2:** O daemon da casa busca a lista atualizada de redes no site:
  `GET /casa/api/camera_discovery.php?acao=get_house_wifis`
- **R06.3:** O daemon repassa as redes para a câmera via HTTP autenticado:
  `POST http://<ip_camera>/config/wifis` (com `X-Camera-Token`).
- **R06.4:** A ESP32-CAM armazena o conjunto de redes em memória não-volátil (NVS).

### R07. Seleção Dinâmica da Melhor Rede Wi-Fi antes de Conectar
- **R07.1:** Antes de se associar a uma rede (no boot ou após desconexão), a câmera executa um escaneamento do ambiente (`WiFi.scanNetworks()`).
- **R07.2:** Cruza os SSIDs encontrados com a lista de redes da casa registradas.
- **R07.3:** **Conecta automaticamente na rede que apresentar o maior RSSI (sinal mais forte)**, evitando pontos cegos e conexões em roteadores distantes.
- **R07.4:** Caso nenhuma rede case no escaneamento, executa fallback sequencial nas redes cadastradas.

### R08. Medição de Intensidade de Sinal durante a Transmissão
- **R08.1:** Durante a transmissão contínua do fluxo de vídeo MJPEG, a câmera deve medir a intensidade (RSSI em dBm) das redes da casa.
- **R08.2:** Injeta nos quadros de stream os cabeçalhos `X-WiFi-SSID` e `X-WiFi-RSSI`.
- **R08.3:** Disponibiliza o endpoint autenticado `GET /wifi_survey`, retornando a rede conectada, o RSSI atual e a lista de medições de cada rede da casa.

### R09. Coleta e Gravação Periódica pelo Daemon (1 vez por minuto)
- **R09.1:** Uma vez por minuto (a cada 60 segundos), o daemon de visão ativo consulta o `/wifi_survey` de cada ESP32-CAM conectada.
- **R09.2:** O daemon repassa o relatório para o site central CASA:
  `POST /casa/api/camera_discovery.php?acao=report_wifi_scan`
- **R09.3:** O site registra a medição na tabela `camera_wifi_survey` para histórico e auditoria, e atualiza `dispositivos_cluster.sinal_rssi` e `metadata->wifi_survey`.

### R10. Visão de Propriedades da Câmera no Site
- **R10.1:** A informação dos Wi-Fis e suas intensidades deve ser visualizada em uma **visão dedicada de Propriedades da Câmera** no site (`item=cameras`), aberta pelo botão `ℹ PROPRIEDADES`.
- **R10.2:** O painel de propriedades exibe em destaque:
  - Rede Wi-Fi atualmente conectada e sua potência em dBm.
  - Tabela visual com todas as redes da residência, exibindo barras gráficas coloridas:
    - 🟢 **Verde** (> -65 dBm): Sinal Excelente.
    - 🔵 **Azul** (-65 a -75 dBm): Sinal Bom.
    - 🟠 **Laranja** (-75 a -85 dBm): Sinal Regular.
    - 🔴 **Vermelho** (< -85 dBm): Sinal Fraco / Fora de alcance.
  - Tag indicando se a rede está `CONECTADA (ATIVA)`.
- **R10.3:** Controles integrados:
  - `🔄 MEDIR SINAL AGORA`: Dispara requisição direta de medição ao vivo para a câmera.
  - `📊 HISTÓRICO`: Exibe as medições gravadas minuto a minuto pelo daemon.
  - `⚙ WI-FI DA CASA`: Modal para adicionar, editar e excluir redes da residência.
- **R10.4:** Dados técnicos completos: Device ID, IP Local com porta, Endereço MAC físico, Modelo, Token Serial (com máscara), URLs de Stream e Snapshot.

---

## 4. Estrutura de Banco de Dados

```sql
-- 1. Redes Wi-Fi da Casa (distribuidas pelo daemon para as cameras)
CREATE TABLE IF NOT EXISTS redes_wifi_casa (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ssid VARCHAR(64) NOT NULL,
  senha VARCHAR(64) NOT NULL,
  prioridade INT NOT NULL DEFAULT 1,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_redes_wifi_ssid (ssid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Historico de Gravacao de Sinal Wi-Fi (enviado pelo daemon 1x por minuto)
CREATE TABLE IF NOT EXISTS camera_wifi_survey (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id VARCHAR(120) NOT NULL,
  ip_address VARCHAR(45) NULL,
  wifi_conectado VARCHAR(64) NULL,
  rssi_conectado INT NULL,
  redes_detectadas JSON NOT NULL,
  coletado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cws_device (device_id, coletado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 5. Endpoints de API Implementados

| Método | Ação / Rota | Origem | Destino | Descrição |
|---|---|---|---|---|
| `GET` | `acao=listar` | Navegador | Site | Retorna lista com status dinâmico calculado por heartbeat |
| `GET` | `acao=heartbeat` | Câmera/Daemon | Site | Atualiza sinal de vida (`ultimo_heartbeat = NOW()`) |
| `POST` | `acao=register_token` | gravafirmware | Site | Registra token capturado pela Serial física |
| `GET` | `acao=get_tokens` | Daemon | Site | Sincroniza tokens autorizados da nuvem |
| `GET` | `acao=get_house_wifis` | Daemon/Web | Site | Retorna todas as redes Wi-Fi da casa para distribuição |
| `POST` | `acao=save_house_wifi` | Web | Site | Cadastra ou altera uma rede Wi-Fi da residência |
| `POST` | `acao=delete_house_wifi` | Web | Site | Remove uma rede Wi-Fi da residência |
| `POST` | `acao=report_wifi_scan` | Daemon | Site | Grava survey de força de Wi-Fi coletado a cada 1 minuto |
| `GET` | `acao=get_wifi_survey` | Web | Site | Retorna histórico de medições de sinal gravadas |
| `POST` | `/config/wifis` | Daemon | Câmera | Repassa credenciais das redes da casa para o ESP32-CAM |
| `GET` | `/wifi_survey` | Daemon/Web | Câmera | Retorna medição instantânea das forças de sinal |

---

## 6. Histórico de Versões e Commits

- **v1.0.0:** Criação da aplicação desktop `gravafirmware` em Lazarus (pacote `openai_hardware.lpk` / `TAIFirmwareFlasher`) e versão alternativa em Python.
- **v1.1.0:** Implementação do cofre de tokens no site (`acao=register_token`, `acao=get_tokens`), sincronização pelo daemon e isolamento total de token por HTTP.
- **v1.2.0:** Geração de tokens verdadeiramente aleatórios e não-repetíveis por entropia mista de hardware e cycle counter.
- **v1.3.0:** Fim do status chumbado; cálculo dinâmico de status por heartbeat (`<= 90s`), auto-probe local e rotina de heartbeat a cada 30 segundos no firmware.
- **v1.4.0:** Sincronização das redes Wi-Fi da casa, seleção automática da melhor rede por RSSI no boot, medição contínua durante o streaming, coleta a cada 1 minuto pelo daemon e visão dedicada de Propriedades da Câmera no site.
