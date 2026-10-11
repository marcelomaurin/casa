# CASA Cluster Monitor Daemon

Monitoramento contínuo em segundo plano da saúde e disponibilidade dos nós do cluster Linux ARM (Raspberry Pi, Cubieboard, Orange Pi, etc.).

## Princípio Arquitetural: Padrão Invertido (Dead Man's Snitch)

1. **Heartbeat Ativo do Nó (20 segundos):**
   - Cada nó ARM executa localmente o serviço `casa-node-agent.service` (`casa-node-agent.py`).
   - A cada 20 segundos (`HEARTBEAT_INTERVAL = 20`), o nó realiza push para o endpoint central `/api/v1/device.php?acao=heartbeat`, atualizando o timestamp de `ultimo_heartbeat`.

2. **Detecção e Atualização por Serviço Daemon (40 segundos):**
   - O serviço **não depende do site nem da abertura da interface pelo usuário**.
   - O `casa-cluster-monitor` roda como um daemon de sistema (systemd) ou via cron no servidor.
   - A cada 5 segundos, ele compara `NOW() - ultimo_heartbeat`:
     - Se `diff > 40` segundos (ou sem contato): o nó **caiu**. O status no banco de dados (`dispositivos_cluster` e `arm_nodes`) é imediatamente atualizado para `'offline'` e um evento `NODE_OFFLINE` é registrado.
     - Se `diff <= 40` segundos: o status é assegurado como `'online'`.

## Como Executar

### 1. Como Daemon Contínuo (PHP CLI)
```bash
php casa-cluster-monitor.php --daemon
```

### 2. Como Serviço Systemd no Linux (Recomendado)
```bash
sudo bash install.sh
```

Comandos úteis do serviço:
```bash
sudo systemctl status casa-cluster-monitor
sudo journalctl -u casa-cluster-monitor -f
```

### 3. Via Cron (Para hospedagens sem systemd)
Adicione ao crontab (`crontab -e`):
```cron
* * * * * /usr/bin/php /var/www/html/casa/clusters/infraestrutura/casa-cluster-monitor/casa-cluster-monitor.php --once >/dev/null 2>&1
```
