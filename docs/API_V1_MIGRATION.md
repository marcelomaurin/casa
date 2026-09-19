# Migração para API v1

## Regra oficial

Clientes externos do CASA devem usar exclusivamente o prefixo:

```text
https://maurinsoft.com.br/casa/api/v1
```

A raiz configurável do cliente é `https://maurinsoft.com.br/casa`; os módulos acrescentam `/api/v1/...`.

## Clientes cobertos

A regra vale para:

- JARVIS Mobile;
- JARVIS TV;
- Watch;
- ESP32 e ESP8266;
- Arduino Ethernet;
- Raspberry/ARM Agent;
- imagens Yocto do CASA.

O CI executa `.github/tests/test_api_v1_clients.py` e bloqueia novos usos de rotas públicas `/api/*` fora de `/api/v1`.

## Rotas migradas nesta etapa

| Uso | Rota oficial |
| --- | --- |
| heartbeat | `/api/v1/device.php?acao=heartbeat` |
| evento/telemetria genérica | `/api/v1/device.php?acao=event` |
| comandos de device | `/api/v1/device.php?acao=commands` |
| ACK | `/api/v1/device.php?acao=command_ack` |
| resultado | `/api/v1/device.php?acao=command_result` |
| provisionamento | `/api/v1/provision.php` |
| comando JARVIS | `/api/v1/comando` |

## Endpoints legados

Arquivos PHP fora de `api/v1` podem continuar existindo temporariamente apenas como implementação interna, compatibilidade do site ou serviço local. Eles não devem ser incorporados em novos firmwares, aplicativos ou agentes externos.

Chamadas `localhost` entre serviços do próprio servidor não fazem parte do protocolo público e devem ser migradas separadamente quando houver endpoint v1 equivalente.

## Compatibilidade

Configurações antigas que apontem para `https://casa.maurinsoft.com.br` devem ser normalizadas pelo cliente para a raiz canônica `https://maurinsoft.com.br/casa`.

Não remover wrappers legados até confirmar que nenhum cliente implantado depende deles.
