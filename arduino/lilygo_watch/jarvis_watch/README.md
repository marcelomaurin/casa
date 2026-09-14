# JARVIS Watch — LILYGO T-Watch 2020 V3

[![Bridge](https://img.shields.io/badge/bridge-BLE-blue.svg)]()
[![Gateway](https://img.shields.io/badge/gateway-Android-green.svg)]()
[![Status](https://img.shields.io/badge/status-Experimental-yellow.svg)]()

## Visão geral

O **JARVIS Watch** é o cliente vestível do projeto CASA/JARVIS. O relógio não precisa conhecer Wi-Fi, URL pública da casa ou token da API. Toda comunicação externa passa pelo aplicativo **JARVIS Mobile** no celular.

```text
JARVIS Watch
     |
     | BLE
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

## Princípio de operação

O celular funciona como gateway. Isso permite que o relógio continue simples e não carregue informações sensíveis da infraestrutura da residência.

O relógio envia uma solicitação JSON pelo BLE. O Android recebe, executa a solicitação usando a API da casa e devolve a resposta pelo BLE.

Se o celular estiver temporariamente sem conexão com o servidor, o Android pode colocar comandos em sua fila offline e informar o estado ao relógio.

## Protocolo BLE

Versão atual:

```text
1.0
```

Service UUID:

```text
7a9f1000-3a8c-4b62-9e5f-1b0c0e91a001
```

Watch -> Phone:

```text
7a9f1001-3a8c-4b62-9e5f-1b0c0e91a001
```

Phone -> Watch:

```text
7a9f1002-3a8c-4b62-9e5f-1b0c0e91a001
```

As respostas do Android são delimitadas por `\n`. O relógio acumula os fragmentos BLE até receber o delimitador e então processa o JSON completo.

## Mensagens principais

Ping:

```json
{"type":"ping"}
```

Status:

```json
{"type":"status","source":"watch"}
```

Comando ao JARVIS:

```json
{
  "type":"jarvis",
  "text":"Informe o status da casa",
  "source":"watch",
  "protocol":"1.0"
}
```

Exemplo de resposta:

```json
{
  "ok": true,
  "type": "jarvis_result",
  "text": "Resposta do JARVIS",
  "queued": false,
  "protocol": "1.0"
}
```

Quando o Android estiver offline, o comando pode ser salvo no celular:

```json
{
  "ok": false,
  "type": "jarvis_result",
  "queued": true,
  "text": "Sem conexão. Comando salvo na fila para envio automático."
}
```

## Segurança

O relógio não deve conter:

```text
SSID da residência
senha Wi-Fi
URL privada do servidor
token da API da casa
credencial PostgreSQL
chave de serviço externo
```

Esses dados permanecem somente onde forem necessários no ambiente de implantação.

## Firmware

Arquivo principal:

```text
jarvis_watch.ino
```

Configuração não sensível:

```text
config.h
```

O firmware procura pelo serviço BLE do JARVIS Mobile, conecta automaticamente e tenta reconectar quando a conexão é perdida.

## Interface atual

O firmware inclui quatro operações básicas de teste:

- consultar estado da conexão;
- alternar a luz da sala;
- consultar temperatura;
- solicitar status da casa.

Essas operações servem para validar o caminho completo:

```text
Watch -> Android -> API -> JARVIS -> Android -> Watch
```

## Voz

A arquitetura foi preparada para que o **celular seja o gateway de voz e rede**. A transferência de áudio bruto do microfone do T-Watch para o Android exige protocolo de streaming/chunks adicional e permanece como próxima etapa experimental.

Até essa extensão ser concluída, o protocolo funcional desta versão trabalha com comandos JSON/texto enviados pelo relógio ao celular.

## Dependências

Alvo inicial:

```text
LILYGO T-Watch 2020 V3
```

Biblioteca:

```text
LilyGoWatch
```

Também utiliza BLE do ESP32 e ArduinoJson.

## Estado

| Recurso | Estado |
|---|---|
| BLE Watch -> Android | Implementado |
| Reconexão BLE | Implementado |
| Comando texto -> JARVIS | Implementado |
| Estado online/offline | Implementado |
| Fila offline no Android | Implementado no gateway |
| Resposta JARVIS -> Watch | Implementado |
| Áudio Watch -> Android | Pendente |
| Reprodução de áudio JARVIS no Watch | Pendente |

> [!WARNING]
> O firmware precisa ser validado no hardware físico T-Watch. Código compilável e protocolo definido não comprovam funcionamento elétrico, áudio ou BLE em todos os ambientes.
