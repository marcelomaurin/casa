# Protocolo de Comunicação e Passagem de Chaves via Celular — CASA / JARVIS

Este documento especifica o **Protocolo de Pareamento e Passagem de Chaves (Zero-Touch Provisioning)** da plataforma CASA / JARVIS. Ele define como novos dispositivos solicitam entrada no cluster residencial e como o aplicativo móvel (**JARVIS Mobile no celular**) atua como a autoridade central que autoriza, vincula e passa as credenciais criptográficas para os dispositivos de borda.

---

## 1. Por que o Celular é o Mecanismo de Comunicação e Passagem de Chaves?

Em uma residência inteligente, **o celular do morador é o dispositivo que possui a identidade, autenticação biométrica e presença física do proprietário**. 

A arquitetura CASA / JARVIS proíbe terminantemente o uso de tokens compartilhados, credenciais genéricas ou senhas gravadas em código-fonte (*hardcoded*). Cada dispositivo físico deve possuir uma identidade única (`device_id`), um token exclusivo (`device_token`) e uma lista estrita de escopos mínimos (*least privilege*).

### Razões Críticas de Segurança:
1. **Zero Trust Residencial**: Nenhum hardware novo tem permissão de executar comandos ou enviar dados até que o morador confirme explicitamente no celular.
2. **Prevenção de Ataques de Injeção e Spoofing**: Se um invasor obtiver acesso à rede Wi-Fi residencial ou plugar um microcontrolador malicioso em uma tomada, ele não conseguirá forjar telemetria nem acionar relés, pois a API exigirá o token individual aprovado pelo celular.
3. **Validação de Proximidade Física (Pairing Code)**: O dispositivo gera um código efêmero de 6 dígitos exibido no display/serial ou transmitido por BLE, que o morador valida na tela do celular antes de autorizar.
4. **Revogação Instantânea na Palma da Mão**: Se um sensor for roubado, perdido ou apresentar comportamento anômalo, o morador pode revogá-lo imediatamente pelo aplicativo JARVIS Mobile, invalidando a credencial sem afetar os demais dispositivos da casa.

---

## 2. Visão Geral do Fluxo de Pareamento

```text
  [ DISPOSITIVO NOVO ]              [ API v1 NUVEM ]              [ CELULAR (JARVIS MOBILE) ]
(ESP32 / ESP8266 / Nó)          (maurinsoft.com.br/casa)             (Morador Autenticado)
          │                                 │                                  │
          │ 1. POST /solicitar_pareamento   │                                  │
          │    (MAC, Tipo, Codigo 6 dig.)   │                                  │
          ├────────────────────────────────►│                                  │
          │                                 │                                  │
          │◄────────────────────────────────┤                                  │
          │    Retorna: request_id          │                                  │
          │                                 │                                  │
          │                                 │ 2. GET /solicitacoes_pendentes   │
          │                                 │◄─────────────────────────────────┤
          │                                 │                                  │
          │                                 ├─────────────────────────────────►│
          │                                 │    Lista dispositivos pendentes  │
          │                                 │                                  │
          │                                 │ 3. [Morador clica "Autorizar"]   │
          │                                 │    POST /autorizar_pareamento    │
          │                                 │    (request_id, nome, comodo)    │
          │                                 │◄─────────────────────────────────┤
          │                                 │                                  │
          │                                 ├─────────────────────────────────►│
          │                                 │    Retorna: Sucesso              │
          │                                 │                                  │
          │ 4. POST /consultar_pareamento   │                                  │
          │    (request_id, MAC, Codigo)    │                                  │
          ├────────────────────────────────►│                                  │
          │                                 │                                  │
          │◄────────────────────────────────┤                                  │
          │    Recebe: device_token único   │                                  │
          │            e device_id          │                                  │
          │                                 │                                  │
          │ 5. Grava token na NVS/EEPROM    │ (Expurga token em texto claro;   │
          │    e transita para operacao     │  mantém apenas o hash SHA-256)   │
          ▼                                 ▼                                  ▼
```

---

## 3. Especificação dos Endpoints da API (`/api/v1/provision.php`)

### 3.1 Solicitação de Pareamento (Dispositivo -> API)
Chamado pelo dispositivo de borda logo após conectar-se ao Wi-Fi ou via softAP. Não exige token prévio, mas possui controle de taxa (*rate limiting*) por IP.

* **Método**: `POST`
* **Rota**: `/api/v1/provision.php?acao=solicitar_pareamento`
* **Payload**:
  ```json
  {
    "mac": "24:6F:28:AB:CD:EF",
    "type": "sensor",
    "model": "ESP01-DHT",
    "firmware_version": "1.1.0",
    "pairing_code": "583921",
    "capabilities": ["temperature", "humidity", "telemetry"]
  }
  ```
* **Resposta da API**:
  ```json
  {
    "status": "ok",
    "status_pareamento": "pending",
    "request_id": "req_03a9856f...",
    "mac": "24:6F:28:AB:CD:EF",
    "pairing_code": "583921",
    "expira_em_minutos": 15,
    "mensagem": "Solicitacao de pareamento registrada. Autorize este dispositivo no aplicativo JARVIS Mobile no seu celular."
  }
  ```

---

### 3.2 Listagem de Solicitações Pendentes (Celular -> API)
O aplicativo no celular consulta periodicamente ou ao abrir a aba "Dispositivos Pendentes".

* **Método**: `GET`
* **Rota**: `/api/v1/provision.php?acao=solicitacoes_pendentes`
* **Header**: `Authorization: Bearer <TOKEN_ADMIN_CELULAR>` (Escopo `mobile.write` ou `devices.provision`)
* **Resposta da API**:
  ```json
  {
    "status": "ok",
    "solicitacoes": [
      {
        "id": 1,
        "request_id": "req_03a9856f...",
        "mac_address": "24:6F:28:AB:CD:EF",
        "device_type": "sensor",
        "model": "ESP01-DHT",
        "firmware_version": "1.1.0",
        "capabilities": ["temperature", "humidity", "telemetry"],
        "pairing_code": "583921",
        "criado_em": "2026-09-15 15:20:00",
        "expira_em": "2026-09-15 15:35:00"
      }
    ]
  }
  ```

---

### 3.3 Autorização do Dispositivo (Celular -> API)
O morador confere o código exibido e confirma a inclusão do equipamento na casa.

* **Método**: `POST`
* **Rota**: `/api/v1/provision.php?acao=autorizar_pareamento`
* **Header**: `Authorization: Bearer <TOKEN_ADMIN_CELULAR>`
* **Payload**:
  ```json
  {
    "request_id": "req_03a9856f...",
    "pairing_code": "583921",
    "name": "Sensor Temperatura Suíte",
    "location": "Suíte Principal"
  }
  ```
* **Ação Interna da API**:
  1. Cria registro definitivo em `dispositivos_cluster` com `device_id` único gerado (ex: `sensor-a1b2c3d4e5f6`).
  2. Gera token seguro `casa_dev_<64_hex_chars>`.
  3. Grava o hash SHA-256 em `api_client_tokens` com os escopos mínimos necessários (ex: `telemetry.write`, `events.write`).
  4. Disponibiliza a credencial temporária para resgate pelo dispositivo.

---

### 3.4 Consulta e Resgate da Chave (Dispositivo -> API)
O dispositivo faz polling na API a cada 3 a 5 segundos aguardando a autorização do celular.

* **Método**: `POST`
* **Rota**: `/api/v1/provision.php?acao=consultar_pareamento`
* **Payload**:
  ```json
  {
    "request_id": "req_03a9856f...",
    "mac": "24:6F:28:AB:CD:EF",
    "pairing_code": "583921"
  }
  ```
* **Resposta quando Autorizado**:
  ```json
  {
    "status": "ok",
    "status_pareamento": "authorized",
    "device_id": "sensor-a1b2c3d4e5f6",
    "device_token": "casa_dev_7f8a9b0c1d2e3f4a...",
    "base_url": "https://maurinsoft.com.br/casa",
    "api_endpoint": "https://maurinsoft.com.br/casa/api/v1",
    "mensagem": "Pareamento concluido com sucesso! Guarde seu device_token na memoria NVS/EEPROM."
  }
  ```
* **Consumo Seguro**: Ao entregar a chave ao dispositivo, a API limpa imediatamente a cópia em texto claro da tabela `device_pairing_requests`, marcando status `completed`.

---

## 4. Necessidade de Aplicação nos Outros Pontos do Ecossistema

Por que **todos** os demais dispositivos e nós precisam obrigatoriamente migrar para esse protocolo de autorização pelo celular?

### 4.1 Câmeras ESP32-CAM
* **Risco**: Câmeras transmitem imagens visuais da privacidade do lar.
* **Necessidade**: Sem a autorização do celular, qualquer câmera invasora na rede Wi-Fi poderia injetar frames forjados no sistema de visão computacional ou interceptar requisições. Com o protocolo, a câmera só recebe o escopo `camera.write` após o morador confirmar o código visual no celular.

### 4.2 LILYGO T-Watch (Relógio Inteligente)
* **Risco**: O relógio possui microfone e exibe notificações familiares confidenciais.
* **Necessidade**: O celular atua como o gateway BLE do relógio. Durante o primeiro vínculo Bluetooth, o relógio exibe o código no display IPS e o celular autoriza a emissão do token com escopos `watch.read`, `watch.write`, `family.read`.

### 4.3 Nós de Atuação Física (Cadeira de Rodas, Portão, Piscina, Iluminação)
* **Risco**: Atuadores controlam motores elétricos, bombas d'água e fechaduras eletromagnéticas. Um acionamento indevido pode causar acidentes físicos ou invasão domiciliar.
* **Necessidade**: O firmware de relés e motores deve permanecer em estado de bloqueio (*failsafe*) até que a chave criptográfica seja assinada e entregue pelo celular do morador.

### 4.4 Raspberry Pi e Gateways Locais (CASA Bridge ESP32)
* **Risco**: Controlam transceptores de rádio, barramentos RS485, Modbus e alto-falantes de áudio da residência.
* **Necessidade**: Devem anunciar suas capacidades (`gpio,audio,tts,rs485`) para a API, permitindo que o morador audite no celular quais permissões aquele gateway terá na infraestrutura local.

### 4.5 Android TV e Painéis de Parede
* **Risco**: Dispositivos fixos em áreas comuns.
* **Necessidade**: Devem receber tokens exclusivamente de visualização (`camera.read`, `home.read`, `alerts.read`), impedindo que alguém pela TV reconfigure a segurança da residência.

---

## 5. Implementações Realizadas

* **Biblioteca C++ Unificada para ESP32 e ESP8266**:
  `arduino/common/CasaDeviceProvisioning.h`
  * Suporta persistência automática em `Preferences` (ESP32) e `EEPROM` (ESP8266).
  * Máquina de estados completa: `UNPROVISIONED` -> `PAIRING_REQUESTED` -> `AUTHORIZED`.
* **Exemplo de Firmware Integrado**:
  `arduino/esp8266/esp01_dht_jarvis/esp01_dht_jarvis.ino`
* **API Backend Reforçada**:
  `site/var/www/html/api/v1/provision.php`
* **Esquemas de Banco de Dados**:
  * `database/api_device_pairing.sql` (MySQL/MariaDB e PostgreSQL).
  * `site/var/www/html/api/schema_mysql.sql` atualizado com a tabela `device_pairing_requests`.
