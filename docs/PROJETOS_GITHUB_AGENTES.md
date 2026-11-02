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
