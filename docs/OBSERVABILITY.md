# Observabilidade e correlation_id

O CASA usa um `correlation_id` raiz para reconstruir uma solicitação ponta a ponta.

## Cadeia

```text
Solicitação
   ↓ correlation_id
JARVIS
   ↓
Plano
   ↓
Tarefas
   ↓
Ações
   ↓
Command Bus
   ↓
Device
   ↓
Eventos / ACK / Resultado
   ↓
Auditoria / Telemetria
```

O mesmo identificador pode aparecer em vários comandos, ações, eventos e runs. Os IDs próprios de cada tabela distinguem as etapas individuais.

## Origem do correlation_id

- Perguntas do JARVIS recebem `req_<hex>` no `te_begin()`.
- Chamadas internas reutilizam `task_context.correlation_id`.
- APIs externas podem fornecer `X-Correlation-ID` ou `correlation_id` quando aplicável.
- Se nenhum ID for fornecido, a API gera um e reutiliza o mesmo valor durante toda a requisição.

## Persistência

O correlation id é gravado em:

- `jarvis_planos`;
- `jarvis_tarefas`;
- `jarvis_acoes`;
- `device_commands`;
- `device_command_audit`;
- `device_events`;
- `telemetria_operacional`;
- `api_v1_security_log`;
- runs de regras e cenas quando pertencentes ao mesmo fluxo.

## Consulta

```text
GET /api/v1/trace.php?correlation_id=<id>
```

O endpoint devolve:

- timeline consolidada;
- plano;
- tarefas;
- ações;
- comandos;
- auditoria de lifecycle;
- eventos;
- telemetria;
- logs de segurança;
- runs de regras/cenas;
- erros encontrados por estágio.

## Segurança

Logs passam por redaction antes de persistência/exposição. Chaves com nomes como `token`, `password`, `senha`, `secret`, `api_key`, `wifi`, `authorization` e `credential` são substituídas por `[REDACTED]`.

Bearer tokens em texto também são redigidos.

Nunca use o correlation id como credencial; ele é apenas identificador de rastreamento.
