# Atualizacao automatica do site Casa

A fonte do site e **marcelomaurin/casa**, branch **master**, subpasta **site/var/www/html**. A pasta Windows correspondente e `C:\projetos\maurinsoft\casa\site\var\www\html`. O site publicado e a copia Windows usam mecanismos adequados a cada ambiente, sem copiar codigo a cada versao.

A atualizacao instala somente codigo ja presente na master e aprovado nos workflows `Integration Control Plane` e `Site Auto Update`. Ela nunca aprova PRs, nunca faz merge de PRs e nao usa branches de agentes como fonte de producao.

## Hostinger/PHP: instalacao de arquivo unico pelo navegador

1. Enviar **atualizar_casa.php** para a raiz publicada do Casa, no mesmo diretorio de index.php, usando o Gerenciador de Arquivos da Hostinger. E a unica copia inicial necessaria.
2. Entrar no Casa com uma conta administradora e abrir `https://maurinsoft.com.br/casa/atualizar_casa.php` (ajustar o dominio ou subpasta se a instalacao usar outro endereco).
3. Clicar em **Instalar e ativar atualizacao automatica**. O script baixa o atualizador e o restante do site direto do GitHub. Nao requer SSH, Git, API key do GitHub ou edicao manual do codigo.

A pagina so aceita a sessao administrativa existente, verifica CSRF e usa a fonte fixa do Casa. Nao cria uma conta administrativa nova e nao e um instalador de banco vazio. Requer PHP 8.1+ com curl e permissao de gravacao nos arquivos do site e no diretorio privado de atualizacao. Nunca enviar senhas ou tokens no arquivo publico.

Depois de habilitada, a atualizacao funciona de forma semelhante ao WP-Cron: acessos ao site e a API disparam uma verificacao em segundo plano, no maximo a cada cinco minutos. No PHP-FPM, a resposta do usuario e encerrada antes do trabalho. Se o site nao receber acessos, a verificacao acontece no proximo acesso. O painel oferece **Verificar agora**, **Atualizar agora** e **Pausar atualizacao automatica**.

A pasta Windows indicada e o fonte do site no repositorio. Na hospedagem, o script descobre a raiz pela propria localizacao; nao usa o caminho C:\projetos para gravar no Linux.

### Integridade, preservacao e recuperacao

O servidor consulta a ultima alteracao de `site/var/www/html`, exige os dois workflows aprovados no commit da master e baixa somente essa subpasta por HTTPS, usando o SHA imutavel. Valida tamanho, hash Git e sintaxe PHP antes de instalar. Todas as alteracoes sao preparadas antes da troca; backup e journal ficam fora da raiz publica. Novas paginas recebem manutencao temporaria durante a troca. Uma interrupcao e recuperada na proxima verificacao, inclusive pelo proximo acesso ao site.

O publicador Android gera commits `[skip ci]` contendo apenas APKs e o manifesto de versoes. Exclusivamente nesse caso, sem nenhuma execucao de CI no commit, o atualizador aceita os testes aprovados do codigo anterior (ate dez commits de distancia). A lista de arquivos e conferida integralmente: qualquer alteracao em PHP, JavaScript, workflows ou outro codigo exige seus proprios testes. CI existente com falha ou pendente nunca e ignorado. O estado instalado registra tambem `ci_commit`, identificando o codigo validado.

Sempre preserva `api/config.local.php`, arquivos privados e de ambiente, uploads, dados, audio, sessoes, caches e logs. Arquivos desconhecidos nao sao apagados. Remove somente arquivos que o proprio atualizador instalou e que sairam do pacote, se nao tiverem sido alterados localmente. Edicoes locais em arquivos gerenciados bloqueiam a atualizacao em vez de serem descartadas.

A localizacao privada padrao e calculada dois niveis acima da raiz, com nome `.casa-site-update-<identificador>`. Para `.../dominio/public_html/casa`, fica em `.../dominio`, fora de public_html. Caso a hospedagem tenha outro layout, definir `CASA_SITE_UPDATE_STATE_DIR` para um diretorio privado gravavel e fora de qualquer raiz publica. Configuracoes de banco, migracoes e dados nao sao revertidos pelo atualizador de arquivos.

O painel mostra o commit instalado e a ultima verificacao. Se aparecer `aguardando_testes`, a versao atual fica no ar. O instalador nao aceita repositorio, URL, comando shell ou destino arbitrario de uma requisicao.

### Cron opcional na Hostinger

Para verificar mesmo sem visitantes, cadastrar no painel Hostinger uma tarefa PHP a cada cinco minutos executando o caminho absoluto de `workers/site_update.php`, mostrado no painel de atualizacao. A hospedagem fornece o executavel PHP correto. O cron e opcional quando a atualizacao por acessos esta habilitada.

Operacoes CLI para administradores:

```bash
php /CAMINHO_PUBLICADO/casa/workers/site_update.php --enable
php /CAMINHO_PUBLICADO/casa/workers/site_update.php --check
php /CAMINHO_PUBLICADO/casa/workers/site_update.php
php /CAMINHO_PUBLICADO/casa/workers/site_update.php --status
php /CAMINHO_PUBLICADO/casa/workers/site_update.php --disable
```

A rota HTTP de `workers/site_update.php` recusa execucao; a interface web autenticada e `atualizar_casa.php`. O bootstrap ZIP e apenas uma alternativa para administradores que prefiram enviar os componentes juntos; o arquivo PHP unico ja consegue baixa-los.

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
