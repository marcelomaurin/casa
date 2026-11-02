# Integracao GitHub dos agentes

O fluxo completo implementado esta documentado em [CASA_GITHUB_IA_FLUXO.md](CASA_GITHUB_IA_FLUXO.md).

- Aprovar o plano publica tarefas GitHub na fila apropriada, com allowlist e chave de habilitacao.
- O consumidor prepara snapshot, gera codigo, valida manifesto/sintaxe, publica commit atomico e abre draft PR.
- Checkpoints, reservas e backoff permitem retomadas sem branches ou PRs duplicados.
- CI e aprovacao humana sao vinculados ao HEAD atual; dependencias aguardam merge realizado por uma pessoa.
- O agente nunca aprova ou mescla PRs e nunca executa comandos de tarefas no servidor Casa.

`projetos_github.php` fornece validacao de repositorio e branch deterministica; `projetos_github_fluxo.php` implementa a maquina de estados e o transporte restrito. O endpoint `GET /api/v1/projetos_github_contexto.php?tarefa_id=123` entrega contexto a clientes com escopo `tasks.worker`, sem tokens. O contexto nao substitui a verificacao de reserva. `github_queue_runtime.php` conecta as etapas a fila e revalida projeto/plano e lease antes dos efeitos remotos.

Os utilitarios `projetos_github_operacoes.php`, `projetos_github_cliente.php`, `projetos_github_arquivos.php` e o CLI assistido continuam disponiveis para operadores. As antigas notas de fases descreviam etapas incompletas e foram substituidas pelo contrato operacional atual.
