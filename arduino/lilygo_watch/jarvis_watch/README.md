# JARVIS Watch — LILYGO T-Watch 2020 V3

[![Mode](https://img.shields.io/badge/mode-standalone-blue.svg)]()
[![Gateway](https://img.shields.io/badge/gateway-planned-lightgrey.svg)]()
[![Status](https://img.shields.io/badge/status-Experimental-yellow.svg)]()

## Visão geral

O **JARVIS Watch** é o cliente vestível do projeto CASA/JARVIS.

A versão atual foi simplificada para **modo standalone**, removendo temporariamente toda dependência BLE do firmware principal. O objetivo é validar primeiro o hardware do relógio, a interface LCARS, RTC, bateria, touch e vibração sem depender de bibliotecas Bluetooth externas.

A arquitetura futura continua prevista como:

```text
JARVIS Watch
     |
     | camada de comunicação isolada
     v
JARVIS Mobile
     |
     | HTTPS / Internet do celular
     v
API externa /api/v1
     |
     v
JARVIS / serviços da casa
```

## Estrutura atual

Arquivo principal:

```text
jarvis_watch.ino
```

Configuração do hardware:

```text
config.h
```

Interface de comunicação:

```text
jarvis_ble.h
```

Implementação atual da comunicação:

```text
jarvis_ble.cpp
```

Nesta versão, `jarvis_ble.cpp` é um **stub standalone**. Ele não inclui `BLEDevice.h`, `NimBLEDevice.h` nem qualquer outra biblioteca BLE.

Isso permite manter a interface de comunicação separada da interface gráfica. Quando a ponte Android for reativada, a implementação poderá ser substituída dentro de `jarvis_ble.cpp` sem reescrever o restante do firmware.

## Interface LCARS

A interface atual mantém:

- hora e data pelo RTC;
- nível de bateria;
- indicação de carga;
- touch;
- vibração;
- layout LCARS claro;
- botão STATUS;
- botão LUZ SALA;
- botão TEMPERAT.;
- botão JARVIS.

No modo standalone, os botões que dependem de comunicação externa apresentam mensagens locais informando que a ponte está desativada.

O botão STATUS apresenta o estado local e a bateria.

## Comunicação

A camada de comunicação expõe apenas estas funções para o sketch principal:

```cpp
void jarvisBleBegin();
void jarvisBleLoop();
bool jarvisBleIsConnected();
bool jarvisBleSendCommand(const String &command);
```

Atualmente:

```text
jarvisBleIsConnected() -> false
jarvisBleSendCommand()  -> false
```

Nenhuma pilha Bluetooth é inicializada.

## Protocolo BLE reservado

Os UUIDs anteriores permanecem reservados em `config.h` para a futura implementação da ponte Android:

```text
Service:
7a9f1000-3a8c-4b62-9e5f-1b0c0e91a001

Watch -> Phone:
7a9f1001-3a8c-4b62-9e5f-1b0c0e91a001

Phone -> Watch:
7a9f1002-3a8c-4b62-9e5f-1b0c0e91a001
```

## Segurança

O relógio não deve conter:

```text
SSID da residência
senha Wi-Fi
URL privada do servidor
token da API da casa
credencial de banco
chave de serviço externo
```

Esses dados permanecem no gateway ou nos serviços apropriados.

## Dependências

Alvo:

```text
LILYGO T-Watch 2020 V3
```

Ambiente atual recomendado:

```text
Placa: ESP32 Dev Module
ESP32 by Espressif Systems: 2.0.14
```

Biblioteca necessária para esta versão standalone:

```text
TTGO_TWatch_Library / LilyGoWatch
```

Não é necessário instalar:

```text
ESP32_BLE_Arduino
NimBLE-Arduino
ArduinoBLE
```

O firmware principal não inclui mais nenhuma dessas bibliotecas.

## Estado

| Recurso | Estado |
|---|---|
| Interface LCARS | Implementada |
| RTC / hora / data | Implementado |
| Bateria | Implementado |
| Touch | Implementado |
| Vibração | Implementado |
| Modo standalone | Implementado |
| Camada de comunicação isolada | Implementada |
| BLE Watch -> Android | Temporariamente desativado |
| Reconexão BLE | Pendente na nova camada |
| Comando texto -> JARVIS | Pendente na nova camada |
| Resposta JARVIS -> Watch | Pendente na nova camada |
| Áudio Watch -> Android | Pendente |
| Reprodução de áudio no Watch | Pendente |

> [!WARNING]
> O firmware ainda precisa ser validado por compilação e teste no hardware físico T-Watch. Esta revisão remove as dependências BLE para reduzir os conflitos de biblioteca e isolar o diagnóstico do relógio.
