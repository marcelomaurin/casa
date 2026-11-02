# Integração GitHub dos agentes — contrato inicial

## Segurança e escopo

A fila atual de projetos suporta executores `jarvis` e `web`. **Não** converter tarefas textuais em comandos de shell nem dar push automático na `master`. O worker de desenvolvimento GitHub deve ser separado, com credenciais de escopo mínimo e repositórios explicitamente permitidos pelo administrador.

## Fluxo proposto

1. Após aprovação do plano, selecionar tarefa com dependências concluídas.
2. Validar `owner/repo` contra lista de permissões do servidor; nunca aceitar URL Git arbitrária ou credenciais no payload.
3. Criar branch `casa/tarefa-<id>` a partir de commit conhecido da principal.
4. Gerar alterações em workspace isolado, com limites de CPU, tempo, rede e arquivos; registrar logs sem segredos.
5. Executar testes e produzir evidências, sem acesso aos serviços do servidor principal.
6. Abrir Pull Request com resumo, arquivos alterados e testes. **Não mesclar automaticamente**.
7. Atualizar o resultado da tarefa com URL e identificador do PR; revisão humana é obrigatória.

## Critérios de aceite para implementação

- Token GitHub App com acesso apenas aos repositórios permitidos, armazenado fora do banco de tarefas.
- Idempotência por ID de tarefa para não duplicar branches ou PRs em reexecuções.
- Verificação de escopo por projeto e auditoria por ação.
- Rejeitar comandos perigosos, caminhos fora do workspace, forks desconhecidos e mudanças em segredos.
- Testes com repositório de sandbox antes de ativar workers reais.

**Status:** especificação; worker GitHub ainda não implementado.

## Fundação implementada

`site/var/www/html/api/projetos_github.php` fornece funções para validar `owner/repo`, impor lista permitida configurada via variável de ambiente `CASA_GITHUB_REPOS_PERMITIDOS` (valores separados por vírgula), gerar branch determinística por projeto/tarefa e preparar o contexto da tarefa. **Sem variável configurada, nenhum repositório é autorizado.** Não existem credenciais ou chamadas à API GitHub nesse módulo. Não é um worker executável; conectar com o despachante e criar branches/PRs permanece pendente.

## Endpoint de contexto (fase 10)

`GET /api/v1/projetos_github_contexto.php?tarefa_id=123` exige cliente API v1 com escopo `tasks.worker`. Consulta projeto e tarefa já aprovados e ligados à fila, verifica a lista de repositórios permitidos e retorna JSON com repositório, branch determinística, descrição e critérios de aceite. Não entrega tokens e não cria branches/PRs. O agente externo ainda deve usar `/api/v1/fila.php` para reservar/concluir tarefas; o endpoint de contexto **não prova a posse de uma reserva** e não deve ser usado como autorização para executar operações GitHub sem verificações adicionais.

## Operações preparadas (fase 11)

`api/projetos_github_operacoes.php` oferece `casa_github_plano_branch` e `casa_github_plano_pr`, que produzem descrições estruturadas de requisições para criação de branch e Pull Request **em rascunho**. Validam o repositório contra a lista autorizada e a base `main`/`master`. Não realizam requisições HTTP, não obtêm o SHA base, não editam arquivos e não executam testes. A implementação do worker executor permanece pendente.
