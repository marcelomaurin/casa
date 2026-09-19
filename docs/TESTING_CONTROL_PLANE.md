# Testes ponta a ponta do Control Plane

A suíte principal do Control Plane roda no GitHub Actions usando MariaDB descartável e um servidor PHP local. Nenhum hardware físico é necessário.

## Camadas

`test_control_plane.php` valida invariantes internas de schema, Registry, Command Bus, Task Engine, regras e observabilidade.

`test_control_plane_http.php` valida o protocolo real HTTP da API v1.

## Cenários HTTP obrigatórios

1. provisionamento de um device pela API e heartbeat;
2. enqueue e idempotência;
3. entrega, ACK, START e RESULT com sucesso;
4. RESULT com falha;
5. TTL expirado;
6. capability inexistente;
7. risco 3/4 sem confirmação e com confirmação explícita;
8. evento de device disparando regra permitida;
9. auditoria completa do lifecycle;
10. fluxo arquitetural completo:
   `Request → Plan → Task → Action → Command → Device → Result → Trace`.

O décimo cenário verifica ainda:

- a tarefa muda para `CONCLUIDA` após o resultado do device;
- a ação muda para `DONE`;
- `tasks.php` mostra plano concluído;
- `trace.php` encontra plano, tarefas, ação e comando pelo mesmo `correlation_id`.

## Execução no CI

O workflow `.github/workflows/integration-control-plane.yml` sobe:

```text
MariaDB 11.4
PHP 8.3
PHP built-in HTTP server
```

e executa a suíte contra `http://127.0.0.1:8099/api/v1`.

Qualquer quebra de protocolo retorna código diferente de zero e falha o job de integração.

## Simulador

`servicos/device_simulator.py` continua disponível para testes manuais e ambientes de homologação. Ele suporta heartbeat, eventos, polling, ACK, START, success/failure/timeout e propagação opcional de `correlation_id`.
