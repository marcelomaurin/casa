# JARVIS ESP-01 — Temperatura e Umidade

Módulo de telemetria ambiental para o projeto CASA/JARVIS usando **ESP-01 / ESP8266** e sensor **DHT22** ou **DHT11**.

## Hardware

- ESP-01 / ESP8266
- DHT22 (padrão) ou DHT11
- Fonte 3,3 V estável
- resistor de pull-up de 4,7 kΩ a 10 kΩ

## Ligação

| DHT | ESP-01 |
|---|---|
| VCC | 3.3 V |
| GND | GND |
| DATA | GPIO2 |

Instale um resistor de **4,7 kΩ a 10 kΩ** entre DATA e 3,3 V.

> Atenção: GPIO0 e GPIO2 participam do processo de boot do ESP8266. O GPIO2 deve permanecer em nível alto durante a inicialização; o pull-up do DHT ajuda a manter a condição correta.

## Bibliotecas Arduino

- ESP8266 board package
- `DHT sensor library` da Adafruit
- `Adafruit Unified Sensor`, quando solicitado pela biblioteca DHT

## Configuração

Edite no arquivo `esp01_dht_jarvis.ino`:

```cpp
const char* WIFI_SSID = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";
const char* JARVIS_URL = "http://192.168.2.12/api/iot_sensor.php";
const char* DEVICE_TOKEN = "TOKEN_INDIVIDUAL_DO_ESP01";
```

Para DHT11:

```cpp
#define DHT_TYPE DHT11
```

Para DHT22:

```cpp
#define DHT_TYPE DHT22
```

## Funcionamento

A cada 60 segundos o módulo envia JSON ao JARVIS:

```json
{
  "tipo_sensor": "dht22",
  "temperatura_c": 25.42,
  "umidade_pct": 58.31,
  "rssi": -61,
  "uptime_s": 3600,
  "free_heap": 35120
}
```

Endpoint:

```text
POST /api/iot_sensor.php
X-Device-Token: TOKEN_DO_ESP01
Content-Type: application/json
```

## Banco de dados

Execute:

```text
database/iot_sensores.sql
```

As leituras são armazenadas em `iot_leituras`.

Última leitura do dispositivo:

```sql
SELECT
    data_hora,
    temperatura_c,
    umidade_pct,
    rssi
FROM iot_leituras
WHERE id_dispositivo = :id_dispositivo
ORDER BY data_hora DESC
LIMIT 1;
```

Histórico das últimas 24 horas:

```sql
SELECT
    data_hora,
    temperatura_c,
    umidade_pct
FROM iot_leituras
WHERE id_dispositivo = :id_dispositivo
  AND data_hora >= NOW() - INTERVAL '24 hours'
ORDER BY data_hora;
```

## Cadastro no JARVIS

O ESP-01 deve possuir uma entrada em `dispositivos_cluster` com um `device_token` próprio. Não reutilize o token de câmera, relógio ou outro equipamento.

Quando uma leitura é recebida, `/api/iot_sensor.php` também atualiza o heartbeat, IP, RSSI e status online do dispositivo.

## Fluxo

```text
DHT11/DHT22
     |
     v
ESP-01 / ESP8266
     |
     | Wi-Fi + HTTP JSON
     v
/api/iot_sensor.php
     |
     +--> valida X-Device-Token
     +--> atualiza heartbeat
     +--> grava iot_leituras
     |
     v
PostgreSQL / JARVIS
```
