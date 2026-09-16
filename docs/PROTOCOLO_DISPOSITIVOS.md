# Protocolo distribuído de dispositivos CASA/JARVIS

Endpoint lógico oficial:

```text
https://casa.maurinsoft.com.br
```

Todos os clientes e nós distribuídos que precisam do sistema central devem usar o domínio oficial. IPs privados, endereços de provedores de IA e URLs de serviços internos não devem ser incorporados aos clientes.

## CASA/1.0

O protocolo lógico dos nós é identificado como:

```text
CASA/1.0
```

O Control Plane usa a API v1, com dois fluxos principais:

```text
COMMANDS -> device_commands
EVENTS   -> device_events
```

Comandos representam solicitações de execução. Eventos representam fatos observados pelo nó.

## Identidade mínima

Cada nó possui:

- `device_id` único;
- `device_token` exclusivo e revogável;
- lista de `capabilities`;
- estado/heartbeat;
- versão de firmware/software;
- versão de protocolo quando disponível;
- gateway responsável, quando aplicável.

Cabeçalhos recomendados:

```http
Authorization: Bearer <TOKEN_INDIVIDUAL>
X-Device-Token: <TOKEN_INDIVIDUAL>
X-Device-Id: <DEVICE_ID>
```

Tokens reais não pertencem ao Git.

## API universal de device

### Heartbeat

```text
POST /api/v1/device.php?acao=heartbeat
```

### Buscar comandos

```text
GET /api/v1/device.php?acao=commands&device_id=<DEVICE_ID>
```

### Confirmar recebimento

```text
POST /api/v1/device.php?acao=command_ack
```

### Publicar resultado

```text
POST /api/v1/device.php?acao=command_result
```

### Publicar evento

```text
POST /api/v1/device.php?acao=event
```

Controladores como Mobile, Site e JARVIS enfileiram operações através de:

```text
POST /api/v1/control.php?acao=enqueue
```

## Envelope lógico de comando

A persistência atual usa as colunas de `device_commands`, mas o significado lógico é:

```json
{
  "protocol": "CASA/1.0",
  "id": 123,
  "correlation_id": "cmd_xxx",
  "target": "tv-sala",
  "command": "media.play",
  "payload": {},
  "priority": "normal",
  "ttl_seconds": 300
}
```

Os nomes de comandos devem preferencialmente ser qualificados por domínio:

- `ble.scan`
- `wifi.http_request`
- `media.play`
- `media.pause`
- `media.cast`
- `light.power`
- `relay.set`
- `mqtt.publish`
- `modbus.write`
- `mobile.notify`
- `mobile.camera`
- `mobile.gps`

## Eventos

Eventos devem usar nomes descritivos e não representar solicitações de ação.

Exemplos:

- `device.discovery`
- `device.online`
- `device.offline`
- `sensor.temperature`
- `light.state_changed`
- `watch.sos`
- `watch.inactivity`
- `family.message`

Exemplo:

```json
{
  "device_id": "gateway-sala",
  "type": "device.discovery",
  "priority": "normal",
  "data": {
    "protocol": "ble",
    "devices": []
  }
}
```

## Capacidades

Exemplos padronizados:

- `gateway`
- `gpio`
- `relay`
- `mqtt`
- `serial`
- `rs485`
- `modbus`
- `ble`
- `wifi`
- `http`
- `camera`
- `vision`
- `microphone`
- `speaker`
- `voice`
- `tts`
- `stt`
- `llm`
- `scheduler`
- `temperature`
- `humidity`
- `gps`
- `telemetry`
- `notification`
- `discovery`
- `media.cast`

A IA pode interpretar a intenção do usuário, mas a execução física deve ser resolvida deterministicamente pelo registro de dispositivos, suas capacidades e adapters autorizados.

## Topologia

```text
                         casa.maurinsoft.com.br
                      API v1 + Command/Event Bus
                                |
             +------------------+------------------+
             |                  |                  |
        Raspberry/ARM      Servidor IA        Android/TV
        adapters locais    LLM/TTS/STT          clientes
             |
        ESP32/ESP8266
        sensores/gateways
```

O domínio coordena identidade, persistência, comandos, eventos, descoberta lógica e roteamento. O processamento pesado e o controle físico permanecem distribuídos.

## Control Plane x Data Plane

A CASA deve transportar comandos, estados e metadados. Fluxos grandes de mídia devem preferencialmente permanecer na rede local.

Exemplo:

```text
CASA -> "media.cast URL X" -> gateway
                               |
                               +---- controle
Servidor local ---------------------> TV
                  stream de mídia
```

Vídeo, áudio e transcodificação não devem atravessar a API central sem necessidade.

## Adapters

Protocolos específicos devem ficar em adapters/drivers, não dentro do núcleo de IA.

Exemplos:

```text
adapters/
  ble
  http
  mqtt
  cast
  dlna
  matter
  modbus
  gpio
  ir
```

Um adapter só deve executar operações explicitamente suportadas pelo dispositivo.

## Watch

O fluxo oficial atual é:

```text
Provisionamento:
Watch -> BLE -> JARVIS Mobile -> CASA

Operação:
Watch -> Wi-Fi/HTTPS -> CASA
```

Depois do provisionamento, o relógio possui identidade própria e comunica-se diretamente com a CASA quando há Wi-Fi disponível.

O Mobile continua podendo fornecer capacidades que pertencem ao telefone, por exemplo:

```text
watch -> CASA -> mobile.gps
watch -> CASA -> mobile.camera
watch -> CASA -> mobile.notification
```

BLE permanece como mecanismo de provisionamento, recuperação e integrações locais específicas, não como caminho obrigatório para toda comunicação do relógio.

## Resiliência

Nós podem manter automações essenciais localmente quando a Internet estiver indisponível e sincronizar eventos e estados quando a conexão retornar.

Comandos devem possuir TTL e nunca ser executados indefinidamente depois de expirados.

## Regras de segurança

1. HTTPS obrigatório fora da LAN.
2. Token individual por nó; nunca compartilhar token mestre.
3. Não gravar senha de Wi-Fi ou token real no repositório.
4. MySQL/MariaDB não é acessado diretamente pelos devices; devices usam a API.
5. Cada token deve possuir escopo compatível com as capacidades do nó.
6. O servidor deve validar tamanho de payload, origem lógica, escopo e rate limit.
7. Certificados TLS devem ser validados pelos clientes.
8. Comandos físicos devem ser limitados a adapters conhecidos.
9. Serviços de IA e credenciais de provedores não devem ficar expostos em Mobile, TV, Watch ou firmware.
10. Comandos críticos devem produzir ACK, resultado e trilha de auditoria.

## Provisionamento e passagem de chaves pelo celular

O JARVIS Mobile é o instrumento de autorização e passagem de credenciais para novos dispositivos. Dispositivos novos não devem depender de chaves estáticas gravadas no firmware.

Detalhamento:

`docs/PROTOCOLO_PASSAGEM_CHAVES_CELULAR.md`

## Estado atual da migração

- Android Mobile: usa CASA API v1 e domínio central.
- Android TV: usa CASA API v1; não depende mais diretamente de RunPod.
- Raspberry/ARM Agent: identidade, token e capacidades configuráveis.
- ESP32 Bridge: migrado para `device_commands` / `device_events`.
- ESP-01 DHT: domínio CASA, identidade e token individual.
- ESP32 Voice: domínio CASA, identidade e capacidades.
- ESP8266 Cadeira/Piscina: comunicação pelo domínio CASA.
- LilyGo Watch: BLE no provisionamento e Wi-Fi/HTTPS na operação normal.
- ESP32-CAM: deve convergir para a mesma identidade e API universal de devices.
