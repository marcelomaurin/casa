# Task Context universal do JARVIS

Toda pergunta externa ao JARVIS deve possuir um único contexto rastreável.

## Contrato

A primeira entrada cria:

```text
jarvis_planos
    ↓
jarvis_tarefas (tarefa raiz)
```

e devolve:

```json
{
  "id_plano": 123,
  "id_tarefa_raiz": 456,
  "task_context": {
    "id_plano": 123,
    "id_tarefa_raiz": 456,
    "origem": "API_V1",
    "modulo": "computer"
  }
}
```

Qualquer módulo chamado internamente deve reutilizar esse contexto. Ele pode criar subtarefas, mas não outro plano.

## Fluxo

```text
Pergunta externa
      ↓
te_begin()
      ↓
Plano + tarefa raiz
      ↓
Módulo A
      ↓ task_context
Módulo B
      ↓ task_context
Módulo C
      ↓
Subtarefas/evidências
      ↓
Resposta final
```

## Regras

1. `te_begin()` cria o plano somente quando não existe contexto válido.
2. Chamadas internas propagam `te_public_context()`.
3. Cada etapa significativa cria uma subtarefa.
4. Resultado/evidência de cada etapa fica em `resultado`.
5. Falhas usam `te_fail_with_plan()`.
6. A resposta pública usa `te_attach_context()`.
7. UI consulta progresso em `/api/v1/tasks.php?acao=status&id_plano=<id>`.
8. Planejador reutiliza o plano existente; tarefas imediatas, agendadas e condicionais pertencem ao mesmo `id_plano`.

## Status derivado

`te_plan_snapshot()` calcula:

- `EM_EXECUCAO`;
- `AGUARDANDO`;
- `DEGRADADO` quando existe falha não terminal;
- `CONCLUIDO`;
- `ERRO`.

Também retorna percentual de progresso, contadores, tarefas e ações.

## Integrações atuais

- `jarvis.php`;
- `planejador.php`;
- `agente_internet.php`;
- `telemetria_ia.php`;
- API v1 de comando;
- API v1 de acompanhamento de tarefas.

Novos módulos cognitivos devem seguir o mesmo contrato.
