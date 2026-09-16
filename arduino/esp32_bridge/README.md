# CASA Bridge ESP32

Gateway ESP32 para integrar a CASA API v1 com dispositivos locais via Wi-Fi e Bluetooth Low Energy (BLE).

## Papel na arquitetura

O Bridge nao possui API paralela propria no servidor. Ele e um **device comum do Control Plane CASA** e usa o mesmo Command/Event Bus dos demais nos.

Fluxo:

```text
Site / Mobile / JARVIS
        |
        v
CASA API v1
        |
        v
device_commands
        |
        v
ESP32 Bridge
   |         |
  BLE       Wi-Fi
```

Para video, Google Cast, DLNA, transcodificacao e protocolos pesados, o ESP32 atua somente como coordenador/discovery. A execucao deve ficar em Raspberry Pi, mini-PC ou outro gateway Linux.

## Versao 0.2

- Wi-Fi Station.
- Credenciais persistentes em NVS (`Preferences`).
- `device_id` unico derivado do chip ESP32.
- Heartbeat na API universal de devices.
- Polling do `device_commands`.
- ACK e resultado no mesmo Command Bus.
- Eventos de descoberta via `device_events`.
- Descoberta BLE com NimBLE.
- API local `/health`.
- Comando `ble.scan`.
- Adapter inicial `wifi.http_request`.
- Compatibilidade com os comandos antigos `scan` e `http_request`.

## Configuracao NVS

Namespace:

```text
casa-bridge
```

| Chave | Descricao |
|---|---|
| `ssid` | SSID Wi-Fi |
| `wifi_pass` | senha Wi-Fi |
| `base_url` | URL raiz da CASA; padrao `https://casa.maurinsoft.com.br` |
| `token` | token individual e revogavel do Bridge |
| `bridge_id` | opcional; se vazio, e gerado pelo ESP32 |

O provisionamento deve preferencialmente ser feito pelo JARVIS Mobile, evitando credenciais fixas no firmware.

## Endpoints usados

### Heartbeat

```text
POST /api/v1/device.php?acao=heartbeat
```

Exemplo:

```json
{
  "device_id": "esp32-ABCD12345678",
  "transport": "wifi",
  "local_ip": "192.168.1.50",
  "rssi": -48,
  "health": "ok",
  "firmware_version": "0.2.0",
  "protocol_version": "CASA/1.0",
  "capabilities": [
    "gateway",
    "ble",
    "wifi",
    "http",
    "discovery"
  ],
  "data": {
    "platform": "esp32",
    "free_heap": 180000,
    "video_streaming": false
  }
}
```

### Receber comandos

```text
GET /api/v1/device.php?acao=commands&device_id=<DEVICE_ID>&limit=5
```

O servidor retorna itens de `device_commands`.

Exemplo logico:

```json
{
  "id": 1234,
  "comando": "ble.scan",
  "payload": {},
  "prioridade": "normal",
  "correlation_id": "cmd_..."
}
```

### ACK

```text
POST /api/v1/device.php?acao=command_ack
```

```json
{
  "device_id": "esp32-ABCD12345678",
  "id": 1234
}
```

### Resultado

```text
POST /api/v1/device.php?acao=command_result
```

```json
{
  "device_id": "esp32-ABCD12345678",
  "id": 1234,
  "status": "success",
  "result": {
    "message": "BLE scan executado"
  }
}
```

### Descoberta

Descobertas sao eventos, nao comandos:

```text
POST /api/v1/device.php?acao=event
```

```json
{
  "device_id": "esp32-ABCD12345678",
  "type": "device.discovery",
  "priority": "normal",
  "data": {
    "protocol": "ble",
    "gateway_device_id": "esp32-ABCD12345678",
    "devices": []
  }
}
```

## Adapters

O Bridge nao deve escrever arbitrariamente em dispositivos desconhecidos. Cada familia deve possuir um adapter/driver explicito.

Nomes de comandos recomendados:

- `ble.scan`
- `ble.gatt.*`
- `wifi.http_request`
- `mqtt.publish`
- `ir.send`

Google Cast, DLNA, Matter pesado, video e audio devem ser executados por um gateway Linux, mas continuam recebendo comandos pelo mesmo `device_commands`.

## Seguranca

- nao exponha a API local do ESP32 diretamente na Internet;
- use token individual por dispositivo;
- mantenha senhas e tokens fora do Git;
- use HTTPS para a CASA;
- valide certificados TLS em producao;
- limite comandos a adapters conhecidos;
- nao permita URL/protocolo arbitrario para dispositivos nao autorizados.
