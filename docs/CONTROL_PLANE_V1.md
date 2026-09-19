# CASA/JARVIS — Control Plane v1

## Visão geral

O Control Plane centraliza descoberta de devices, comandos, eventos, cenas, regras e saúde operacional. O schema atual é identificado em `param` por `VERSAO=1.20`.

## Device Registry

Endpoint de controle: `GET /api/v1/control.php?acao=devices`.

Cada device possui, quando aplicável:

- `device_id`
- `nome`
- `tipo`
- `status`
- `health`
- `manufacturer`
- `model`
- `firmware_version`
- `protocol_version`
- `transport`
- `local_ip`
- `observed_ip`
- `gateway_device_id`
- `battery_pct`
- `sinal_rssi`
- `localizacao`
- `ultimo_heartbeat`
- `capabilities[]`

Um device é considerado online quando seu heartbeat está dentro da janela operacional definida pelo servidor.

### Heartbeat

`POST /api/v1/device.php?acao=heartbeat`

```json
{
  "device_id": "watch_01",
  "transport": "wifi",
  "health": "ok",
  "firmware_version": "1.2.0",
  "protocol_version": "1.0",
  "manufacturer": "LILYGO",
  "model": "T-Watch S3",
  "battery": 82,
  "rssi": -48,
  "uptime_sec": 12345,
  "capabilities": ["display", "notification", "vibration"],
  "data": {"mode": "normal"}
}
```

O heartbeat também executa oportunisticamente o scheduler determinístico, no máximo uma vez a cada 45 segundos para toda a instalação.

## Command Bus

### Estados canônicos

```text
QUEUED -> SENT -> ACKNOWLEDGED -> EXECUTING -> DONE
                                  |             |
                                  +-----------> FAILED
QUEUED/SENT ---------------------------------> EXPIRED
```

O campo legado `status` permanece para compatibilidade. O estado oficial é `lifecycle_status`.

### Enfileirar

`POST /api/v1/control.php?acao=enqueue`

```json
{
  "device_id": "tv_sala",
  "command": "power_on",
  "payload": {},
  "priority": "normal",
  "ttl_seconds": 300,
  "max_retries": 3,
  "required_capability": "power",
  "risk_level": 1
}
```

Pode-se enviar `X-Idempotency-Key`. A mesma chave para o mesmo device não cria execução duplicada.

Ações de risco 3 ou 4 exigem `confirm=true` no controlador. Regras automáticas não podem gerar comandos de risco 3 ou 4.

### Device busca comandos

`GET /api/v1/device.php?acao=commands&device_id=DEVICE_ID`

O servidor atualiza comandos entregues para `SENT` e controla `retry_count`, `max_retries`, `last_attempt_at` e `expira_em`.

### ACK

`POST /api/v1/device.php?acao=command_ack`

```json
{"device_id":"tv_sala","id":123}
```

### Início da execução

`POST /api/v1/device.php?acao=command_start`

```json
{"device_id":"tv_sala","id":123}
```

### Resultado

`POST /api/v1/device.php?acao=command_result`

```json
{
  "device_id": "tv_sala",
  "id": 123,
  "status": "success",
  "result": {"power": "on"}
}
```

Falha:

```json
{
  "device_id": "tv_sala",
  "id": 123,
  "status": "error",
  "error": "Falha do dispositivo"
}
```

## Auditoria

`device_command_audit` registra criação e mudanças de lifecycle. Para ações sensíveis são preservados:

- solicitante (`requested_by`)
- cliente autorizado (`authorized_client`)
- confirmação (`confirmed_by`, `confirmed_at`)
- device executor nas transições executadas pelo device
- correlation id e nível de risco

## Event Bus

`POST /api/v1/device.php?acao=event`

```json
{
  "device_id": "sensor_corredor",
  "type": "sensor.presence",
  "priority": "normal",
  "data": {"presence": true}
}
```

Eventos são gravados em `device_events` e podem disparar regras do tipo `event` sem LLM.

## SSE

`GET /api/v1/events.php`

Canal autenticado Server-Sent Events. Suporta `Last-Event-ID`, keepalive e reconexão. REST continua sendo usado para configuração e envio de comandos.

## Cenas

Endpoint: `/api/v1/scenes.php`.

Ações principais:

- `acao=list`
- `acao=detail`
- `acao=create`
- `acao=update`
- `acao=execute`
- `acao=run_status`

Cada execução gera `scene_runs`, `scene_run_actions` e comandos reais no Command Bus. Uma cena de risco >=3 exige confirmação explícita.

## Regras determinísticas

Endpoints:

- `/api/v1/rules.php`
- `/api/v1/rules_tick.php`

Tipos de gatilho:

- `event`: acionado pelo Event Bus
- `state`: avalia o estado atual do device
- `schedule`: horário e dias da semana

Operadores de condição:

- `eq`
- `neq`
- `gt`
- `gte`
- `lt`
- `lte`
- `contains`
- `in`
- `exists`

Exemplo schedule:

```json
{"hour":22,"minute":30,"days":[1,2,3,4,5,6,7]}
```

Exemplo de condição:

```json
[{"field":"state.battery_pct","op":"lt","value":20}]
```

O scheduler também é executado pelos heartbeats, com trava global em `param.AUTOMATION_LAST_TICK` para evitar execução excessiva.

## Níveis de risco

- `0`: consulta/sem ação física
- `1`: iluminação, mídia e ações de baixo impacto
- `2`: equipamentos comuns
- `3`: portas, portões, alarmes e ações sensíveis
- `4`: ação crítica

Níveis 3 e 4 exigem confirmação humana/controladora e não podem ser disparados pelo motor automático.

## Saúde

- `/api/v1/health.php`: processo HTTP vivo
- `/api/v1/ready.php`: banco/schema/control plane prontos
- `/api/v1/system_health.php`: visão agregada de API, banco, Control Plane e serviços opcionais LLM/TTS/STT/Web Agent

O agregador pode usar as variáveis:

- `JARVIS_LLM_HEALTH_URL`
- `JARVIS_TTS_HEALTH_URL`
- `JARVIS_STT_HEALTH_URL`
- `JARVIS_WEB_AGENT_HEALTH_URL`

## Instalação automática

Na primeira conexão ao banco, `api/db.php`:

1. cria `param` se necessário;
2. verifica `VERSAO`;
3. instala schema-base se necessário;
4. aplica todos os módulos da migration `1.20`;
5. valida as tabelas obrigatórias;
6. grava `VERSAO=1.20` apenas após sucesso completo.

Quando `VERSAO=1.20`, as migrations não são executadas novamente.


## Integração com o Task Engine

Ações físicas originadas por IA ou pelo planejador seguem obrigatoriamente esta cadeia:

```text
jarvis_planos
    ↓
jarvis_tarefas
    ↓
jarvis_acoes
    ↓
device_commands
    ↓
device / adapter
```

`jarvis_acoes` é a fronteira determinística entre planejamento e execução física. O LLM pode propor uma ação estruturada, mas não acessa drivers nem altera diretamente o estado do dispositivo.

Uma ação de dispositivo contém pelo menos:

- `device_id`;
- `command`;
- `payload`;
- `required_capability` quando aplicável;
- `risk_level`;
- `ttl_seconds`.

Antes do enqueue, o Task Engine valida a existência do device, capability habilitada e nível de risco. Ações de risco 3 ou 4 exigem confirmação explícita.

O mesmo `correlation_id` liga `jarvis_acoes` ao `device_commands`. O lifecycle do Command Bus é propagado de volta:

```text
QUEUED / SENT / ACKNOWLEDGED → tarefa AGUARDANDO
EXECUTING                    → tarefa EXECUTANDO
DONE                         → tarefa CONCLUIDA
FAILED / EXPIRED             → tarefa ERRO
```

Isso permite rastrear uma solicitação desde o plano até o resultado efetivamente informado pelo equipamento.
