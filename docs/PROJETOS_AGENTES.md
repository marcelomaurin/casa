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

## Planejamento em lote (fase 2)

O ChatGPT pode produzir um JSON estruturado e enviá-lo por `POST /api/v1/projetos.php?acao=planejar` usando um token com `projects.manage`. O endpoint valida todo o plano antes de persistir, cria agentes e tarefas numa transação e retorna IDs. **Não dispara a fila nem executa comandos.** O projeto deve existir, estar em `planejamento` e não possuir agentes ou tarefas cadastrados.

```json
{
  "projeto_id": 1,
  "agentes": [
    {"nome":"Desenvolvedor","especialidade":"PHP e APIs","executor":"jarvis"},
    {"nome":"Testador","especialidade":"Testes automatizados","executor":"jarvis"}
  ],
  "tarefas": [
    {"titulo":"Implementar API","descricao":"Criar endpoint autenticado","criterio_aceite":"Endpoint validado","prioridade":2,"agente_indice":1},
    {"titulo":"Testar API","descricao":"Executar testes de autenticacao e erros","criterio_aceite":"Testes passando","prioridade":2,"agente_indice":2,"depende_da_tarefa":1}
  ]
}
```

Índices de agente e dependência começam em 1; dependências devem apontar para tarefas anteriores no mesmo lote. Limites: 12 agentes e 60 tarefas. A API rejeita replanejamento que poderia duplicar tarefas. Para obter resultados, use `GET ?acao=detalhes&id=1`.

**Pendente:** integração com gerador de plano LLM, aprovação, despacho na fila, sincronização de estados e painel visual. Esta etapa não pressupõe que o ChatGPT possa acessar a API privada sem uma conexão autorizada.

## Aprovação e fila distribuída (fase 3)

Após cadastrar e planejar o projeto, uma chamada **explícita** de aprovação pode publicar as tarefas na fila de execução já existente:

```http
POST /api/v1/projetos_execucao.php?acao=aprovar
Authorization: Bearer <token-com-projects.manage>
Content-Type: application/json

{"projeto_id":1,"confirmar":true}
```

A aprovação cria um `jarvis_planos` e tarefas em `jarvis_tarefas` com dependências preservadas e associação por IDs, tudo numa transação. A API rejeita aprovação duplicada. Apenas executores `jarvis` e `web` são aceitos nesta fase: scripts, comandos e dispositivos não são liberados por esse caminho. **Aprovar inicia o despacho automático aos daemons ativos**; revisar antes de enviar.

Consultar estado e resultados (token com `projects.manage`):

`GET /api/v1/projetos_execucao.php?acao=status&projeto_id=1`

A resposta inclui o status de cada tarefa na fila, tentativas, trabalhador, erro e resultado. O status agregado do projeto ainda não é recalculado automaticamente quando todas as tarefas terminam; consultar o status das tarefas para saber o resultado final.

**Pendente:** testes integrados no servidor, interface de aprovação, geração automática de plano pela IA, cancelamento e sincronização de estado agregado. Não presumir deploy concluído só porque o código foi integrado à `master`.

## Painel web (fase 4)

Acesse `/casa/projetos.php` com a sessão autenticada do Casa. Permite criar projetos, adicionar agentes, cadastrar tarefas com dependências e visualizar estado da fila. Os formulários usam token CSRF de sessão e PDO parametrizado; nenhum token externo é exposto ao navegador.

A aprovação permanece no endpoint autenticado `projetos_execucao.php`; o painel não a executa automaticamente. A página não gera planos por IA nem exibe os detalhes completos dos resultados; estas integrações ficam pendentes. Acesso pela URL direta até integrar navegação principal. Validar em PHP/MySQL antes de liberar para produção.
