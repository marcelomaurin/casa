# Agentes GitHub: fila, geracao, draft PR e revisao humana

## Comportamento implementado

O projeto deve ser aprovado por uma pessoa no painel ou em `POST /api/v1/projetos_execucao.php`. Agentes com executor `github` recebem tarefas na fila `github`. Tanto o painel quanto a API exigem `CASA_GITHUB_DISPATCH_ENABLED=1` e repositorio autorizado.

`github_queue_once.php` processa uma etapa de uma tarefa. `github_queue_daemon.php` repete o consumo a cada cinco segundos, registra heartbeat e aceita SIGTERM/SIGINT no Linux. A fila continua responsavel por reservas, dependencias e tentativas.

1. `gerar`: descobre a principal (`main` ou `master`), cria/reutiliza a branch deterministica `casa/projeto-ID/tarefa-ID` e le um snapshot por SHA. Envia a IA caminhos e ate oito arquivos relevantes (180 KB no total). Cite caminhos concretos na descricao. Arquivos privados, ocultos, symlinks e submodulos nao entram no contexto.
2. `publicar`: valida de um a cinco arquivos (100 KB por arquivo), sintaxe PHP e JSON, impede sobrescrever arquivos existentes que a IA nao leu e grava um unico commit pela API Git. Nunca executa codigo ou comandos gerados no servidor Casa. A atualizacao da branch usa `force=false`; concorrencia cancela a escrita.
3. `abrir_pr`: abre um PR **draft**, com criterio de aceite e limites da validacao. Busca PRs abertos e fechados antes de criar para evitar duplicacao em retomadas.
4. `acompanhar`: consulta CI, statuses e reviews do HEAD atual. Exige os checks configurados, todos com sucesso. Checks ausentes, pendentes, ignorados ou com falha bloqueiam a conclusao. Aprovacao deve vir de pessoa colaboradora, membro ou proprietaria, diferente do autor do PR, no commit atual. Bots, aprovacoes antigas e pedidos de alteracao nao satisfazem a revisao.
5. Somente um merge ja realizado por uma pessoa, com CI e aprovacao validos, conclui a tarefa e libera dependencias. PR fechado sem merge ou merge sem evidencias obrigatorias termina em erro. Aprovacao isolada nao conclui a tarefa.

**O worker nao aprova reviews, nao promove draft, nao habilita auto-merge e nao chama endpoints de merge.** Uma pessoa revisa o diff, solicita ajustes ou atualiza a branch, remove o draft e efetua o merge no GitHub. O acompanhamento observa o novo HEAD e invalida evidencias antigas. O agente nao reescreve automaticamente uma branch sob revisao.

Os checkpoints ficam em `jarvis_tarefas.resultado`, incluindo manifesto antes da publicacao, SHA do commit antes de mover a branch, URL do PR e evidencias. Interrupcoes depois do commit ou PR podem ser retomadas sem duplicacao. Esperas por CI/revisao reagendam a tarefa, sem gastar tentativas ou liberar dependencias. Falhas de processamento usam o backoff e limite existentes da fila. Reserva, projeto aprovado e plano sao revalidados antes de cada chamada remota; GET_LOCK impede execucoes sobrepostas da mesma tarefa. Logs operacionais omitem codigo e manifesto.

## Configuracao

Requer PHP 8.1+ com curl, mbstring e pdo_mysql; Linux com pcntl permite encerramento gracioso. Banco Casa atualizado ate schema 1.37, acesso GitHub e modelo ativo em `ia_modelos` sao necessarios. Nenhuma migracao nova de schema e exigida.

Configure no ambiente privado do worker, nunca em tarefas ou no Git:

```ini
CASA_GITHUB_DISPATCH_ENABLED=1
CASA_GITHUB_WORKER_ENABLED=1
CASA_GITHUB_AI_ENABLED=1
CASA_GITHUB_WRITE_ENABLED=1
CASA_GITHUB_WORKER_NAME=casa-github-01
CASA_GITHUB_REPOS_PERMITIDOS=marcelomaurin/casa
CASA_GITHUB_REQUIRED_CHECKS=integration,validate
CASA_GITHUB_TOKEN=<token privado>
JARVIS_DB_HOST=<host privado>
JARVIS_DB_NAME=<banco Casa>
JARVIS_DB_USER=<usuario do worker>
JARVIS_DB_PASS=<senha privada>
```

`CASA_GITHUB_REQUIRED_CHECKS` usa nomes dos checks/jobs que realmente executam testes no repositorio de destino. Ajuste por instalacao. Sem nomes configurados o consumidor recusa iniciar; suites ausentes nunca contam como aprovadas. O token precisa de Contents e Pull requests para escrita e Checks/Commit statuses para leitura somente nos repositorios permitidos. Use token de instalacao GitHub App ou credencial equivalente que dispare o CI; commits com `GITHUB_TOKEN` de Actions podem nao disparar novos workflows. Renove credenciais expiradas pelo mecanismo da instalacao. Mantenha o auto-merge desabilitado no GitHub e configure protecao da principal com checks e revisao humana.

```bash
# Uma etapa, apropriada para cron
php site/var/www/html/workers/github_queue_once.php

# Consumo continuo, apropriado para um servico supervisionado
php site/var/www/html/workers/github_queue_daemon.php
```

Exemplo de unidade systemd, adaptando usuario, caminho e arquivo de ambiente:

```ini
[Unit]
Description=Casa GitHub AI worker (human review required)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=casa-github
WorkingDirectory=/opt/casa
EnvironmentFile=/etc/casa/github-worker.env
ExecStart=/usr/bin/php /opt/casa/site/var/www/html/workers/github_queue_daemon.php
Restart=on-failure
RestartSec=10
TimeoutStopSec=600
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true

[Install]
WantedBy=multi-user.target
```

## Tarefas preparadas pela versao anterior

O consumidor antigo marcou somente a preparacao da branch como `CONCLUIDA`. Essas tarefas nao sao consumidas de novo automaticamente. Para uma tarefa aprovada elegivel, com repositorio permitido:

```bash
php site/var/www/html/workers/github_queue_retomar.php --tarefa 123 --confirmar
```

O comando reabre somente uma preparacao com resultado `preparacao_branch/aguardando_geracao_e_revisao`. Recusa outras tarefas e dependentes que ja iniciaram, pois exigem reconciliacao humana. Nao altera branches ou PRs.

## Validacao e limites

```bash
php tests/test_projetos_github_manifesto.php
php tests/test_projetos_github_fluxo.php
# Somente em banco isolado casa_test; usa as variaveis JARVIS_DB_* do teste.
JARVIS_DB_NAME=casa_test php .github/tests/test_github_queue.php
```

O primeiro workflow verifica sintaxe e regressao offline. `integration-control-plane.yml` testa a fila com MariaDB real e APIs GitHub/IA simuladas, incluindo checkpoints, espera humana e dependencias. Validacao PHP/JSON nao substitui os testes funcionais do projeto: eles rodam no CI do repositorio, apos o draft PR, e a revisao humana continua obrigatoria. Falhas de testes ou alteracoes solicitadas ficam visiveis no resultado e no link do PR no painel/API. Nenhum teste simulado comprova credenciais ou disponibilidade de um servidor de producao.

O CLI `github_worker.php` permanece disponivel para preparacao, geracao de manifesto e aplicacao assistida por operador com reserva vigente. O fluxo continuo usa o consumidor acima. Limites de arvore, contexto, resposta, paginacao e tentativas falham de forma explicita; trabalhos maiores devem ser divididos em tarefas menores.
