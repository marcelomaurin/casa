# Geracao de codigo com IA - fluxo assistido

O worker pode gerar um manifesto JSON de alteracoes para revisao humana. **Gerar nao significa publicar**.

## Requisitos
- Tarefa de projeto aprovada, vinculada ao Jarvis e reservada para o trabalhador correto na fila `github`.
- Repositorio incluido em `CASA_GITHUB_REPOS_PERMITIDOS`.
- `CASA_GITHUB_WORKER_ENABLED=1`, `CASA_GITHUB_AI_ENABLED=1`, `CASA_GITHUB_WORKER_NAME` configurado.
- Modelo ativo configurado na tabela `ia_modelos`.

## Gerar e revisar
```bash
php site/var/www/html/workers/github_worker.php --tarefa 123 --executar --gerar-ia > /tmp/casa-tarefa-123.json
php -r '$x=json_decode(file_get_contents("/tmp/casa-tarefa-123.json"),true,32,JSON_THROW_ON_ERROR); echo count($x)," arquivos\\n";'
```

Leia e revise integralmente o JSON antes de aplicar. O modelo pode produzir codigo incorreto, inseguro ou nao relacionado a tarefa. Nao cole segredos no prompt. O gerador nao executa testes nem acessa o repositorio para ler o codigo-fonte atual.

## Aplicar apos revisao
```bash
CASA_GITHUB_WRITE_ENABLED=1 php site/var/www/html/workers/github_worker.php --tarefa 123 --executar --alteracoes /tmp/casa-tarefa-123.json
```

Exige ainda `CASA_GITHUB_TOKEN` e reserva vigente. O worker cria ou reutiliza branch, grava arquivos e abre PR em rascunho. Nao faz merge automatico. O consumidor `github_queue_once.php` reserva uma tarefa e conclui apenas a etapa de preparacao da branch, registrando `aguardando_geracao_e_revisao` no resultado Jarvis. Isso evita que a mesma tarefa seja reservada indefinidamente. **A conclusao na fila nao equivale a codigo implementado, PR criado ou aprovado.** A geracao e a revisao continuam manuais; ainda nao existe daemon continuo.

## Validacao local
```bash
php tests/test_projetos_github_manifesto.php
```
O workflow `casa-github-worker-php.yml` verifica sintaxe e testes do manifesto no GitHub Actions. O resultado precisa ser conferido no repositorio.
