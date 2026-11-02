# Atualizacao automatica do site Casa

A fonte do site e **marcelomaurin/casa**, branch **master**, subpasta **site/var/www/html**. A pasta Windows correspondente e `C:\projetos\maurinsoft\casa\site\var\www\html`. O site publicado e a copia Windows usam mecanismos adequados a cada ambiente, sem copiar codigo a cada versao.

A atualizacao instala somente codigo ja presente na master e aprovado nos workflows `Integration Control Plane` e `Site Auto Update`. Ela nunca aprova PRs, nunca faz merge de PRs e nao usa branches de agentes como fonte de producao.

## Site publicado: o proprio servidor busca o codigo

`workers/site_update.php` e executado pelo cron da hospedagem. Consulta a ultima alteracao da subpasta do site, confere o CI e baixa arquivos dessa versao por HTTPS, usando o commit imutavel. Nao precisa de Git, token GitHub ou SSH para as atualizacoes futuras. Exige PHP 8.1+ com curl e permissao do usuario da hospedagem para atualizar os arquivos do proprio site.

O atualizador valida tamanho, hash Git de cada arquivo e sintaxe PHP antes de instalar. Guarda backup e journal fora da raiz publica, bloqueia novas paginas durante a troca, substitui arquivos e registra o commit instalado. Falhas de instalacao disparam recuperacao; uma interrupcao e reconciliada na proxima execucao. Alteracoes locais em arquivos gerenciados bloqueiam a atualizacao para revisao, sem serem descartadas.

Sempre preserva `api/config.local.php`, arquivos privados e de ambiente, uploads, dados, audio, sessoes, caches e logs. Arquivos desconhecidos nao sao apagados. Remove somente arquivos que o proprio atualizador instalou e que foram removidos do pacote, se nao tiverem sido alterados localmente. Backups nao sao publicados e nao ha endpoint HTTP de instalacao.

### Ativacao inicial pela administracao

O acesso inicial a hospedagem e necessario uma vez para instalar o bootstrap e cadastrar o cron. Depois o servidor faz as atualizacoes sozinho; o usuario nao precisa copiar novas versoes.

No diretorio publicado do Casa, instalar `api/site_self_update.php`, `api/site_update_guard.php` e `workers/site_update.php`. O pacote bootstrap gerado a partir do repositorio contem esses arquivos. O restante do site e buscado automaticamente pelo primeiro ciclo.

A localizacao privada padrao e calculada dois niveis acima da raiz do site, com nome `.casa-site-update-<identificador>`. Para uma hospedagem com raiz `.../dominio/public_html/casa`, fica em `.../dominio`, fora de `public_html`. Caso o layout seja diferente, definir `CASA_SITE_UPDATE_STATE_DIR` para uma pasta privada gravavel e fora de qualquer raiz publica.

Exemplo com o caminho real obtido no painel da hospedagem:

```bash
php /CAMINHO_PUBLICADO/casa/workers/site_update.php --enable
php /CAMINHO_PUBLICADO/casa/workers/site_update.php --check
php /CAMINHO_PUBLICADO/casa/workers/site_update.php
php /CAMINHO_PUBLICADO/casa/workers/site_update.php --status
```

Cadastrar o terceiro comando em cron a cada cinco minutos (`*/5 * * * *`). Usar o executavel PHP e caminho absolutos fornecidos pela hospedagem. O primeiro comando habilita apenas a configuracao do atualizador; cadastrar o cron e indispensavel para execucao continua.

Para pausar: `php .../workers/site_update.php --disable`. Para diagnosticar: `--status`, incluindo estado `aguardando_testes`, commit instalado, ultima verificacao e erro. O status nao comprova instalacao se o cron nao estiver ativo. Configuracao de banco, migracoes e dados nao sao revertidos pelo atualizador de arquivos.

## Windows: atualizacao agendada do checkout

O Windows usa o Git e o Python ja instalados. A raiz web continua dentro do checkout original; nao existe uma segunda pasta para copiar. O atualizador nao depende da execucao de scripts PowerShell e nao muda politicas do Windows.

Instalar uma vez com o executavel permanente do Python instalado (ajustar a versao se necessario):

```powershell
& "$env:LOCALAPPDATA\Programs\Python\Python313\python.exe" C:\projetos\maurinsoft\casa\site\tools\update_site_windows.py --repo-root C:\projetos\maurinsoft\casa --install
```

A tarefa `Casa-Site-AutoUpdate` roda a cada cinco minutos e no login do usuario. Usa pythonw para nao abrir janelas, permissao limitada e nenhuma senha armazenada. Executa somente enquanto esse usuario estiver conectado ao Windows. Nao instala IIS, Apache ou PHP e nao inicia um servidor web que nao existia.

O script `site/tools/update_site_windows.py` busca a master, confere o CI da versao do site e aplica somente fast-forward. Preserva a branch de trabalho, commits divergentes, edicoes locais e configuracoes privadas. Recusa a versao remota se ela tentar versionar configuracoes privadas ou dados locais na raiz web. Arquivos nao versionados permanecem no lugar e conflitos sao recusados pelo Git. O estado fica em `.git/casa-site-update/status.json`.

Para executar manualmente ou verificar o resultado:

```powershell
& "$env:LOCALAPPDATA\Programs\Python\Python313\python.exe" C:\projetos\maurinsoft\casa\site\tools\update_site_windows.py --repo-root C:\projetos\maurinsoft\casa
Get-ScheduledTask -TaskName Casa-Site-AutoUpdate
Get-ScheduledTaskInfo -TaskName Casa-Site-AutoUpdate
Get-Content C:\projetos\maurinsoft\casa\.git\casa-site-update\status.json
```

Nenhum mecanismo descarta alteracoes locais ou faz force push. Se houver erro ou CI pendente, a versao atual fica instalada. Se o usuario mudar a branch ou editar arquivos versionados, o atualizador recusa alterar o checkout; consulte tambem LastTaskResult no agendador para falhas antes da consulta de versao.

## Testes

`Site Auto Update` verifica o atualizador PHP no Linux e o fluxo Git no Windows. Os testes usam arquivos reais, rollback, interrupcao, hashes invalidos, CI falho, exclusao mutua, configuracoes privadas, uploads, historico divergente e mudancas locais. A integracao geral tambem roda para qualquer alteracao em `site/**`.

```bash
php .github/tests/test_site_update.php
```

```powershell
python .github/tests/test_site_update_windows.py
```
