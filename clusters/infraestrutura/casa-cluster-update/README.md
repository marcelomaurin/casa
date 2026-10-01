# Atualização dos integradores CASA

O serviço consulta ativamente `api/v1/updates.php?acao=current` a cada cinco minutos. Um commit novo na master não provoca atualização. Somente uma release cadastrada e depois explicitamente ativada pelo publicador pode ser aplicada. A API exige autenticação do dispositivo e capability `update-agent`; publicação exige um token com scope `updates.publish`.

## Instalação inicial

Requer Linux com systemd e Python 3.8+. Na pasta deste integrador, execute `sudo bash install.sh`. A instalação cria `/etc/casa/cluster-update/config.cfg` somente se não existir. Edite `device_id` e `device_token` com as credenciais do nó cadastrado. Configure destinos e nomes de serviços nas seções de cada integrador quando a instalação local usar caminhos diferentes. Autorize a capability `update-agent` no cadastro desse dispositivo. Consulte `journalctl -u casa-cluster-update.service` para acompanhar falhas.

O `config.cfg` contém toda a configuração do atualizador. A configuração própria dos integradores existentes continua sendo lida por eles nos arquivos atuais (`.env`, `.ini`, etc.), que o atualizador preserva. Não é necessário migrar essas credenciais para instalar uma release. Nenhum arquivo de configuração ou dados pode entrar no manifesto.

## Publicação e liberação

A migração 1.31 cria o catálogo de releases, o canal stable e os relatórios. O mecanismo existente em `api/db.php` aplica essa migração. Nenhuma release inicial é ativada automaticamente.

1. Faça commit dos arquivos na master.
2. Gere o manifesto: `python3 build_manifest.py --repo /caminho/casa --commit SHA_COMPLETO --version 1.0.0 --output /tmp/release.json`. O SHA precisa existir no repositório e identifica conteúdo imutável.
3. Envie `POST /api/v1/updates.php?acao=register`, com Bearer de publicador e corpo `{"manifest": <conteúdo do arquivo>}`. Isso apenas cadastra.
4. Quando quiser indicar a atualização, envie `POST /api/v1/updates.php?acao=activate`, corpo `{"version":"1.0.0"}`. A API impede reutilizar uma versão com outro conteúdo e impede retroceder o canal.
5. Cada nó consulta a versão liberada e atualiza somente os integradores cujos diretórios já existem. Use `GET ...?acao=status` com scope `updates.read` para consultar os relatórios.

O catálogo cobre ARM, scheduler, Google Home, TTS, ESPCam, avatar, pesquisa web, túnel, RunPod, portal do cluster, SSH e o próprio atualizador. A atualização do updater vale na execução seguinte. Serviços inativos não são iniciados automaticamente. Releases atualizam código; alterações de dependências, unidades systemd e recursos binários exigem instalação explícita e não entram no manifesto.

## Verificações e recuperação

Todos os arquivos são baixados de `raw.githubusercontent.com/marcelomaurin/casa/<SHA>/...`, com conferência de tamanho e SHA-256. Código Python e shell passa por validação de sintaxe antes da instalação. Os destinos não podem escapar da pasta do integrador; arquivos são substituídos atomicamente e os anteriores são restaurados se a instalação/reinício falhar. Serviços anteriormente ativos são reiniciados e sua atividade é conferida. Isso não substitui um teste funcional de cada aplicação. Uma interrupção elétrica no meio de vários arquivos ainda pode deixar atualização parcial; mantenha backup operacional.

Há bloqueio contra execuções concorrentes e estado por integrador em `/var/lib/casa-cluster-update/state.json`. Relatórios pendentes são persistidos e reenviados. Falhas retornam código diferente de zero para systemd. Não há `git pull`, `reset --hard`, comandos remotos no manifesto ou opção de contornar a liberação.

```bash
sudo casa-cluster-update --check
sudo casa-cluster-update --apply
sudo casa-cluster-update --status
systemctl list-timers casa-cluster-update.timer
python3 -m unittest discover -s tests -v
```
