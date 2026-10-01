# Instalar e operar o atualizador CASA

O `casa-cluster-update` mantém os integradores de cada Raspberry/nó Linux iguais à branch `master` do GitHub. Ele busca os fontes direto do Git, sem etapa manual de publicação.

## 1. API central

Publique `site/var/www/html` (deploy da Hostinger). A tabela `cluster_update_nodes`, que guarda o estado de cada nó, é criada automaticamente na primeira consulta.

Opcional: para acompanhar outra branch, crie em `configuracoes_sistema` a chave `cluster_update_branch` com o nome da branch (padrão: `master`).

## 2. Instalar em cada Raspberry ou nó Linux

Requisitos: Python 3.8+, systemd, `git` (o instalador instala via apt se faltar) e acesso HTTPS a `github.com` e à API central.

```bash
git clone --depth 1 https://github.com/marcelomaurin/casa.git /tmp/casa
cd /tmp/casa/clusters/infraestrutura/casa-cluster-update
sudo bash install.sh
sudo nano /etc/casa/cluster-update/config.cfg
```

Preencha a seção `[update]` com as **mesmas credenciais do agente ARM deste nó** (o `device_id` e o token usados pelo `casa-node-agent`):

```ini
[update]
api_url = https://maurinsoft.com.br/casa/api/v1/updates.php
device_id = ID_REAL_DESTE_NO
device_token = CREDENCIAL_REAL_DESTE_NO
repo_url = https://github.com/marcelomaurin/casa.git
branch = master
state_dir = /var/lib/casa-cluster-update
```

Confira as seções dos integradores: `root` é a pasta real de instalação e `service` a unidade systemd. Somente integradores cuja pasta existe são atualizados; os demais aparecem como `not_installed`. Configurações próprias (`.env`, `.ini`, `.json` etc.) ficam nos locais atuais e nunca são sobrescritas.

Nós que já tinham a versão 1.x (releases) só precisam rodar o `install.sh` novamente: ele atualiza o programa, o timer (agora a cada 2 minutos) e mantém o `config.cfg`. Acrescente `repo_url` e `branch` se quiser valores diferentes do padrão.

## 3. Conferir

```bash
sudo casa-cluster-update --check      # {"status": "available" | "up-to-date", ...}
sudo systemctl start casa-cluster-update.service
sudo casa-cluster-update --status
systemctl list-timers casa-cluster-update.timer
sudo journalctl -u casa-cluster-update.service -n 50 --no-pager
```

No painel, **Segurança › Atualizações** deve mostrar o nó com o commit instalado e o estado ATUALIZADO.

## 4. Forçar uma atualização

Pelo painel: **Segurança › Atualizações › FORÇAR ATUALIZAÇÃO** (um nó) ou **FORÇAR TODOS**. O nó atende na próxima execução do timer (até ~2 min): reinstala todos os arquivos de código dos integradores presentes e reinicia os serviços que estavam ativos.

No próprio nó: `sudo casa-cluster-update --force`.

## 5. Diagnosticar falhas

| Situação no painel / log | Verificação |
|---|---|
| SEM ATUALIZADOR | O serviço não está instalado ou nunca conseguiu falar com a API (device_id/token) |
| SEM CONTATO | Nó desligado, sem rede ou timer parado (`systemctl list-timers`) |
| FALHOU | Veja o erro no card e `journalctl -u casa-cluster-update`; o integrador com falha fica na versão anterior |
| `git ls-remote falhou` | Rede/DNS/certificado até github.com, relógio do equipamento |
| 401/403 da API | ID e token do nó, credencial revogada; o nó precisa ser linux-arm ou ter capability arm-agent/update-agent |
| Serviço falhou após atualizar | `journalctl` da unidade do integrador; os arquivos anteriores são restaurados |
| Python antigo | Python 3.8+ obrigatório |

Sem a API o nó continua atualizando pelo Git; só deixa de receber pedidos forçados e de aparecer atualizado no painel.

Dependências (`pip`/`apt`), unidades systemd e binários não são distribuídos automaticamente; mudanças nesses itens exigem implantação própria. A validação confere sintaxe e se o serviço voltou ativo, mas não substitui o teste funcional de cada integrador.

## 6. Pausar e retomar

```bash
sudo systemctl disable --now casa-cluster-update.timer
# Para retomar:
sudo systemctl enable --now casa-cluster-update.timer
```
