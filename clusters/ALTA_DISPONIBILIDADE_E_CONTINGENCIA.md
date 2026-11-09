# Alta Disponibilidade (HA), Distribuição e Contingência de Daemons

[Catálogo](README.md) · [Operação](OPERACAO.md) · [Inventário](INVENTARIO.md)

Este documento estabelece a arquitetura de alta disponibilidade, a matriz de distribuição de carga entre os nós ARM e o roteiro de pendências para garantir que **pelo menos uma instância de cada daemon essencial esteja sempre ativa**, com failover automático em caso de contingência.

---

## 1. Topologia Atual dos Nós do Cluster

| Dispositivo / Hostname | IP Local | Hardware / Arquitetura | Papel no Cluster | Status Operacional |
|---|---|---|---|---|
| **Raspberry Pi 4B** (`raspberrypi`) | `192.168.2.13` | Quad-core ARMv8 64-bit, 4 GB RAM, 117 GB SSD/SD | Primário de IA, Mídia, Visão e Voz Neural | **Online** (`mmm`), modo texto, daemons atualizados. |
| **Raspberry Pi 3B+** (`raspberrypi`) | `192.168.2.16` | Quad-core ARMv8 64-bit, 1 GB RAM, 59 GB SD | Primário de Automação/Scheduler e Reserva de Mídia | **Online** (`mmm`), modo texto, daemons atualizados. |
| **Cubieboard 2** (`cubieboard`) | `192.168.2.14` | Dual-core Cortex-A7 ARMv7l 32-bit, 1 GB RAM, NAND 4GB + MicroSD 32GB (`/mnt/dados`) | Banco MySQL Local, Árbitro de Quorum e Reserva Leve | **Online** (`linaro`), `sata_power_en=1`, 27GB livres em `/mnt/dados`. |
| **Cubieboard 2** (`cubier`) | `192.168.2.15` | Dual-core Cortex-A7 ARMv7l 32-bit, 1 GB RAM, MAC `02:01:08:82:c5:d8` | Nó de Banco MySQL / Contingência | **Kernel ativo (ping OK), portas 22/3306 recusadas** (pendente intervenção física). |

---

## 2. Classificação dos Daemons

### 2.1 Daemons de Nó (Execução Local Obrigatória em Todos os Nós)
Estes daemons devem rodar obrigatoriamente em **todas as máquinas** ativas, pois realizam o monitoramento da saúde física do respectivo hospedeiro:
- `casa-node-agent` (`casa-node-agent.service` ou `/etc/init.d/casa-node-agent`): Coleta telemetria de CPU, memória, temperatura e recebe comandos remotos.
- `casa-cluster-site` (`casa-cluster-site.service`): Portal web local na porta 8080 para diagnóstico do nó.
- `casa-cluster-update` (`casa-cluster-update.timer`): Sincronizador de código com a branch `master` do Git.

### 2.2 Daemons Singleton do Cluster (Exigem Exatamente 1 Instância Ativa + Failover)
Estes serviços **não podem rodar concorrentemente** sob risco de duplicidade de chamadas, conflito de rede ou inconsistência de agendamento:

| Daemon | Descrição | Nó Primário | Nó Secundário (Failover 1) | Nó Terciário (Failover 2) |
|---|---|---|---|---|
| **`casa-scheduler`** | Agendador de tarefas cron e regras recorrentes | **RPi 3B+** (`.16`) | **RPi 4B** (`.13`) | **Cubieboard** (`.14`) |
| **`casa-tarefas`** | Executor da fila distribuída (`/api/v1/fila.php`) | **RPi 4B** (`.13`) | **RPi 3B+** (`.16`) | **Cubieboard** (`.14`) |
| **`espcam_daemon`** | Ingestão e polling de snapshots das câmeras ESP32 | **RPi 4B** (`.13`) | **RPi 3B+** (`.16`) | — |
| **`casa-google-home`**| Integração com Chromecast / caixas Google Home | **RPi 4B** (`.13`) | **RPi 3B+** (`.16`) | — |
| **`casa-tts`** | Síntese de voz neural | **RPi 4B** (`.13`) | **RPi 3B+** (`.16`) | — |
| **`jarvis-web-agent`**| Pesquisa e extração de páginas web | **RPi 4B** (`.13`) | **RPi 3B+** (`.16`) | — |
| **`casa-tunnel`** | Túnel reverso seguro | **RPi 3B+** (`.16`) | **RPi 4B** (`.13`) | — |
| **`casa-runpod-agent`**| Gerenciador de instâncias GPU na nuvem | **RPi 3B+** (`.16`) | **RPi 4B** (`.13`) | — |

---

## 3. Mecanismo de Eleição e Failover (Distributed Lease)

Para evitar dependências de orquestradores pesados que consumiriam a RAM dos nós ARM, adota-se o padrão **Distributed Leader Lease**:

1. **Tabela de Leases (`cluster_service_leases`):**
   ```sql
   CREATE TABLE IF NOT EXISTS cluster_service_leases (
       service_name VARCHAR(64) PRIMARY KEY,
       leader_node_id VARCHAR(64) NOT NULL,
       leader_ip VARCHAR(45) NOT NULL,
       lease_expires_at DATETIME NOT NULL,
       last_renewed_at DATETIME NOT NULL,
       status ENUM('active', 'transitioning') DEFAULT 'active'
   );
   ```

2. **Renovação de Liderança (Heartbeat):**
   O nó que detém a liderança ativa renova o lease a cada 10 segundos, definindo `lease_expires_at = NOW() + 25s`.

3. **Assunção por Contingência (Failover Atômico):**
   Os nós reservas verificam o lease a cada 10 segundos. Se o lease expirar (`lease_expires_at < NOW()`), o próximo nó da fila de prioridade executa:
   ```sql
   UPDATE cluster_service_leases 
   SET leader_node_id = 'rpi3', leader_ip = '192.168.2.16', lease_expires_at = DATE_ADD(NOW(), INTERVAL 25 SECOND)
   WHERE service_name = 'casa-tarefas' 
     AND (lease_expires_at < NOW() OR leader_node_id = 'rpi3');
   ```
   Se a consulta retornar sucesso (1 linha alterada), o nó reserva inicializa a unidade systemd localmente (`systemctl start casa-tarefas`).

4. **Auto-Fencing (Prevenção de Split-Brain):**
   Se o nó primário perder conexão com o banco por mais de 20 segundos, ele interrompe seu próprio serviço local preventivamente (`systemctl stop ...`).

---

## 4. Passos Pendentes por Dispositivo

### 4.1 Cubieboard 1 (`192.168.2.14`)
- [x] **Redução de uso da memória NAND:** Limpeza de caches e restrição do `logrotate`.
- [x] **Armazenamento de expansão:** Partição `/dev/mmcblk0p3` criada e montada de forma persistente em `/mnt/dados` com 27 GB livres.
- [x] **Configuração de baixo nível SATA:** Script `script.bin` da partição `nanda` recompilado com `sata_power_en = port:PB08<1><default><default><1>`.
- [ ] **Hardware SSD:** Conectar fisicamente o cabo de dados SATA e o conector de força de 5V (ao lado da porta SATA) para permitir que o barramento suba como `/dev/sda`.
- [ ] **MySQL Server:**
  - Instalar o pacote `mysql-server` apontando para os repositórios `old-releases.ubuntu.com/ubuntu/ quantal`.
  - Configurar `/etc/mysql/my.cnf` com `datadir = /mnt/dados/mysql` e `bind-address = 0.0.0.0`.
  - Criar usuário `mmm` com senha `226468` com permissões remotas e banco `casadb`.

### 4.2 Cubieboard 2 (`192.168.2.15` — `cubier`)
- [x] **Detecção de Rede:** IP `192.168.2.15` e MAC `02:01:08:82:c5:d8` confirmados online no roteador.
- [!] **Diagnóstico:** A pilha de rede do kernel responde a ping, porém conexões TCP nas portas 22 (SSH) e 3306 (MySQL) retornam `ECONNREFUSED`.
- [ ] **Ação física de destravamento:**
  - Reiniciar a placa (desligar e religar a alimentação).
  - Caso não suba o SSH, conectar monitor HDMI ou cabo serial para verificar se o boot está parado em checagem de sistema de arquivos (`fsck`) ou modo de manutenção devido a falta de espaço na NAND.
  - Liberar espaço em `/` e iniciar os serviços `/etc/init.d/ssh start` e `/etc/init.d/mysql start`.
  - Atualizar o `casa-node-agent` para a versão mais recente (`v1.2.0`).

### 4.3 Raspberry Pi 3B+ (`192.168.2.16`) e Raspberry Pi 4B (`192.168.2.13`)
- [x] Modo texto configurado permanentemente (`multi-user.target`).
- [x] `lightdm` mascarado e `gpu_mem=16` configurado no boot (economizando 60 MB de RAM em cada placa).
- [x] Limitação do `systemd-journald` aplicada (`SystemMaxUse=50M` / `100M`), recuperando mais de 3 GB de disco.
- [ ] **Unidades de Contingência:** Copiar os arquivos `.service` dos daemons que hoje rodam apenas em um nó para o outro (em estado `disabled`), de modo que o orquestrador de failover possa iniciá-los rapidamente quando necessário.

### 4.4 Central Web e Banco de Dados (Hostinger / Nuvem)
- [ ] **GitHub Secrets:** Cadastrar `HOSTINGER_SSH_PRIVATE_KEY` e `HOSTINGER_SSH_PASSWORD` nos Secrets do repositório GitHub para viabilizar o workflow automático `deploy-hostinger.yml`.
- [ ] **Tabela de Leases:** Executar a migração DDL para criar a tabela `cluster_service_leases` no banco de dados central (`casadb`).
- [ ] **API de Leases:** Implementar o endpoint `/api/v1/cluster_lease.php` para operações atômicas de acquire, renew e release.

