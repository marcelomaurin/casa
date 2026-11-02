# Casa — módulo Projetos e Agentes (fase 1)

O banco central do Casa é **MySQL/MariaDB**, não PostgreSQL. Esta fase usa a migração `api/migrations/1.36.sql` para manter compatibilidade com a instalação existente.

## API
Rota: `/api/v1/projetos.php`. Envie `Authorization: Bearer <token>` ou `X-API-Key`. Leituras aceitam `tasks.worker` ou `projects.manage`; escritas exigem `projects.manage` ou chave mestra. Nunca coloque tokens no repositório.

- `GET ?acao=listar`: até 100 projetos.
- `GET ?acao=detalhes&id=1`: projeto, agentes e tarefas.
- `POST ?acao=criar`: `{"nome":"Meu projeto","objetivo":"Implementar recurso X","repositorio":"marcelomaurin/casa"}`.
- `POST ?acao=agente`: `{"projeto_id":1,"nome":"Testador","especialidade":"testes","executor":"jarvis"}`.
- `POST ?acao=tarefa`: `{"projeto_id":1,"titulo":"Criar testes","descricao":"Cobrir os casos principais","agente_id":1,"prioridade":2,"criterio_aceite":"Testes passando"}`. Dependências opcionais via `depende_de`.

## Segurança e limites
Esta API **apenas planeja e persiste**; não dispara comandos, não publica commits e não executa automaticamente tarefas. O campo executor é metadado para integração futura com `jarvis_tarefas`/`fila.php`, sob aprovação explícita. Chaves de escrita precisam receber o novo escopo `projects.manage` (ou usar chave mestra existente).

## Próximas fases
1. Integrar geração de plano por IA com revisão humana e validação das dependências.
2. Integrar aprovação e publicação de tarefas na fila existente.
3. Adicionar painel de projetos, métricas, histórico de execuções e agentes.
4. Criar testes de API e integração de banco em ambiente isolado.

## Verificação
Executar em ambiente de testes antes do deploy: `php -l site/var/www/html/api/v1/projetos.php`, migração MySQL 1.36, testes de token sem escopo (403), vínculos cruzados entre projetos (400) e rollback.
