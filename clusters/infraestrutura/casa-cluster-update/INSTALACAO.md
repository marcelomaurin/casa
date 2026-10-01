# Instalar e operar o atualizador CASA

## 1. Preparar a API central

Publique a pasta `site/var/www/html/api` da master no servidor da API, usando o procedimento de implantação existente. A versão de schema 1.31 acrescenta as tabelas de releases, canal stable e relatórios, preservando a migração 1.30 anterior.

Com a inicialização automática do banco habilitada, `api/db.php` aplica a migração na conexão. Se essa função estiver desabilitada no servidor, o administrador deve aplicar `site/var/www/html/api/migrations/1.31.sql` no MySQL/MariaDB pelo procedimento habitual. Confira a criação de `cluster_update_releases`, `cluster_update_channels` e `cluster_update_reports`.

No cadastro de cada nó, autorize a capability `update-agent` sem remover suas capacidades atuais. Use o ID e a credencial próprios desse nó. Para publicar versões, utilize uma credencial de cliente da API com scope `updates.publish`; para consultar relatórios, `updates.read`. O token do Raspberry não é uma credencial de publicação.

## 2. Instalar em cada Raspberry ou nó Linux

Requisitos: Python 3.8 ou superior, systemd, acesso HTTPS à API central e a raw.githubusercontent.com. A instalação inicial é executada em cada nó; depois o próprio serviço consulta a API automaticamente.

Se o repositório ainda não existir no equipamento:

```bash
git clone --branch master https://github.com/marcelomaurin/casa.git
cd casa
```

Se já existir, entre na pasta do repositório e atualize sem descartar alterações locais:

```bash
git switch master
git pull --ff-only origin master
```

Se houver conflito ou alterações locais impedindo o pull, resolva antes de continuar. Não use reset para apagar configurações.

Execute a partir da raiz do repositório:

```bash
cd clusters/infraestrutura/casa-cluster-update
sudo bash install.sh
sudo nano /etc/casa/cluster-update/config.cfg
```

Preencha a seção já criada, mantendo as demais seções do arquivo:

```ini
[update]
api_url = https://maurinsoft.com.br/casa/api/v1/updates.php
device_id = ID_REAL_DESTE_NO
device_token = CREDENCIAL_REAL_DESTE_NO
state_dir = /var/lib/casa-cluster-update
```

Não grave credenciais no Git. O instalador cria o config.cfg somente quando não existe e não substitui um arquivo já configurado.

Confira as seções dos integradores: `root` deve apontar para sua pasta real de instalação, e `service` para sua unidade systemd. Os caminhos padrão são sugestões baseadas nas unidades do projeto; ajuste os que forem diferentes no equipamento. Diretórios inexistentes são reportados como `not_installed`. Este serviço atualiza instalações existentes, não instala automaticamente todos os integradores.

As configurações próprias dos integradores, como arquivos .env e .ini, continuam nos locais atuais e são preservadas.

## 3. Conferir a instalação

```bash
sudo casa-cluster-update --check
sudo systemctl start casa-cluster-update.service
sudo casa-cluster-update --status
systemctl list-timers casa-cluster-update.timer
sudo journalctl -u casa-cluster-update.service -n 50 --no-pager
```

Enquanto nenhuma versão estiver liberada, `no-release` é o resultado esperado. O timer faz a primeira consulta aproximadamente dois minutos após iniciar e volta a consultar cinco minutos após a execução anterior terminar, com até 30 segundos de variação. Não depende de um comando recebido do servidor.

## 4. Gerar e cadastrar uma versão

No computador de publicação, com o commit desejado já enviado à master, execute na raiz do repositório:

```bash
RELEASE_COMMIT=$(git rev-parse HEAD)
python3 clusters/infraestrutura/casa-cluster-update/build_manifest.py \
  --repo "$PWD" --commit "$RELEASE_COMMIT" \
  --version 1.0.0 --output /tmp/casa-release.json
python3 - <<'PY'
import json
from pathlib import Path
manifest = json.loads(Path('/tmp/casa-release.json').read_text())
Path('/tmp/casa-register.json').write_text(json.dumps({'manifest': manifest}))
PY
```

O manifesto usa conteúdo do commit fixo, não arquivos alterados sem commit. Confira a lista de integradores e arquivos antes de cadastrar. Para cada nova publicação, use uma versão maior, por exemplo 1.0.1.

Exemplo de envio sem colocar a credencial literalmente no comando ou no histórico:

```bash
read -r -s -p 'Token de publicacao: ' CASA_PUBLISH_TOKEN
printf '\n'
printf 'Authorization: Bearer %s\n' "$CASA_PUBLISH_TOKEN" | \
  curl --fail-with-body --silent --show-error \
  --header @- --header 'Content-Type: application/json' \
  --data-binary @/tmp/casa-register.json \
  'https://maurinsoft.com.br/casa/api/v1/updates.php?acao=register'
```

O cadastro não autoriza a instalação. Uma versão cadastrada não pode ser substituída por outro conteúdo.

## 5. Indicar explicitamente a atualização

Quando quiser liberar a versão cadastrada:

```bash
printf 'Authorization: Bearer %s\n' "$CASA_PUBLISH_TOKEN" | \
  curl --fail-with-body --silent --show-error \
  --header @- --header 'Content-Type: application/json' \
  --data-binary '{"version":"1.0.0"}' \
  'https://maurinsoft.com.br/casa/api/v1/updates.php?acao=activate'
unset CASA_PUBLISH_TOKEN
```

Agora os nós detectam a versão nas próximas consultas. Um novo commit na master, sozinho, não libera atualização. Para antecipar a consulta de um nó, execute `sudo systemctl start casa-cluster-update.service`; isso continua respeitando a versão indicada na API.

Use `GET /api/v1/updates.php?acao=status`, autenticado com scope `updates.read`, para consultar resultados por integrador. O portal local do cluster também consulta o estado instalado, no caminho padrão acima.

## 6. Diagnosticar falhas

| Situação | Verificação |
|---|---|
| 401 ou 403 da API | ID, token, revogação e capability update-agent |
| Falha HTTPS | DNS, rede, certificado e relógio do equipamento |
| not_installed | Caminho root no config.cfg e presença da instalação |
| Download divergente | SHA/tamanho do manifesto e conteúdo do commit publicado |
| Serviço falhou após atualização | journalctl da unidade desse integrador; arquivos anteriores são restaurados |
| pending_reports maior que zero | Comunicação com a API; o serviço tenta reenviar os relatórios |
| Python antigo | Atualize o ambiente para Python 3.8+ antes de instalar |

A validação verifica sintaxe Python/shell, hashes e atividade dos serviços reiniciados. Não substitui um teste funcional de cada integrador. Arquivos de dependências, unidades systemd e recursos binários não são distribuídos pelas releases de código; alterações nesses itens exigem implantação própria.

## 7. Pausar e retomar as consultas

```bash
sudo systemctl disable --now casa-cluster-update.timer
# Para retomar:
sudo systemctl enable --now casa-cluster-update.timer
```

Parar o timer impede novas consultas agendadas e não interrompe uma atualização já em execução.
