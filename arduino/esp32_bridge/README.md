# CASA Bridge ESP32

Gateway ESP32 para integrar o servidor/site CASA com dispositivos locais via Wi-Fi e Bluetooth Low Energy (BLE).

## Objetivo

O Bridge funciona como um agente da rede local. O servidor CASA envia comandos para o ESP32, e o ESP32 executa o driver correspondente no dispositivo local. Ele tambem publica heartbeat e dispositivos BLE descobertos.

Fluxo:

`Site CASA -> API CASA -> ESP32 Bridge -> Wi-Fi/BLE -> dispositivo`

Para video/Chromecast/Google Cast, o ESP32 deve atuar somente como coordenador. Espelhamento e transcodificacao devem ser executados por um Bridge Linux/Raspberry Pi/mini-PC, pois exigem memoria, codecs e pilhas de protocolo muito maiores.

## Recursos da versao 0.1

- Wi-Fi Station.
- Credenciais persistentes em NVS (`Preferences`).
- Identificador unico derivado do chip ESP32.
- Heartbeat para `/api/bridge/heartbeat`.
- Polling de comandos em `/api/bridge/command/next`.
- Resultado em `/api/bridge/command/result`.
- Descoberta BLE com NimBLE.
- Publicacao de descoberta em `/api/bridge/discovery`.
- API local `/health`.
- Comando BLE `scan`.
- Driver Wi-Fi HTTP GET/POST inicial.
- Arquitetura preparada para drivers de dispositivos.

## Dependencias

Arduino IDE/PlatformIO com ESP32 Arduino Core e:

- ArduinoJson 7.x
- NimBLE-Arduino

## Configuracao

As seguintes chaves sao lidas do namespace NVS `casa-bridge`:

| Chave | Descricao |
|---|---|
| `ssid` | SSID Wi-Fi |
| `wifi_pass` | senha Wi-Fi |
| `base_url` | URL da API CASA, por exemplo `http://192.168.1.10` |
| `token` | token Bearer do Bridge |
| `bridge_id` | opcional; se vazio e gerado pelo ESP32 |

Na proxima etapa a configuracao pode ser feita por captive portal, evitando gravar senha no fonte.

## Contrato sugerido da API CASA

### Heartbeat

`POST /api/bridge/heartbeat`

```json
{
  "bridge_id": "esp32-ABCD12345678",
  "firmware": "0.1.0",
  "platform": "esp32",
  "ip": "192.168.1.50",
  "rssi": -48,
  "free_heap": 180000,
  "bluetooth": true,
  "wifi": true,
  "video_streaming": false
}
```

### Proximo comando

`GET /api/bridge/command/next?bridge_id=esp32-ABCD12345678`

```json
{
  "command": {
    "id": "1234",
    "protocol": "ble",
    "action": "scan"
  }
}
```

ou:

```json
{
  "command": {
    "id": "1235",
    "protocol": "wifi",
    "action": "http_request",
    "method": "GET",
    "url": "http://192.168.1.70/status"
  }
}
```

### Resultado

`POST /api/bridge/command/result`

```json
{
  "bridge_id": "esp32-ABCD12345678",
  "command_id": "1234",
  "success": true,
  "message": "BLE scan executado"
}
```

## Drivers futuros

A ideia e nao tentar criar um driver universal que escreva arbitrariamente em qualquer dispositivo. Cada familia deve implementar um driver: BLE GATT, HTTP/REST, MQTT, Wake-on-LAN, IR (com hardware adicional), Matter e protocolos especificos de televisores.

Google Home/Google Cast devem ficar em um modulo separado. O ESP32 pode disparar uma solicitacao para um Bridge Linux, enquanto o Linux executa descoberta Cast, sessao de media e streaming.

## Seguranca

Nao exponha a API local do ESP32 diretamente na Internet. O Bridge deve permanecer na LAN, usar autenticacao com token para o servidor CASA e, em producao, HTTPS/TLS quando a infraestrutura permitir.
