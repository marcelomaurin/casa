# CASA Cluster Update Service

Serviço centralizado de atualização de clusters ARM/x86 da plataforma CASA / JARVIS.

## Funcionalidades

1. **Sincronização com GitHub (`origin/master`)**:
   - `git fetch` e verificação de novidades.
   - `git pull` automático com tratamento de consistência.

2. **Sincronização de Componentes**:
   - `clusters/site/` -> `/opt/casa/site/` (Portal Web LCARS)
   - `clusters/comunicacao/arm-agent/` -> `/opt/casa-node-agent/` (Agente de telemetria)
   - `clusters/infraestrutura/runpod-agent/` -> `/opt/casa/servicos/runpod-agent/`
   - `clusters/automacao/casa-scheduler/` -> `/opt/casa-scheduler/`
   - `clusters/infraestrutura/casa-tunnel/` -> `/opt/casa-tunnel/`

3. **Recarga e Reinicialização Controlada**:
   - `systemctl daemon-reload`
   - Reinicia serviços ativos sem derrubar a execução do updater (rodando em unidade `Type=oneshot`).

4. **Notificação e Histórico**:
   - Registra log e resultado em `/opt/casa/site/last_update.json`.
   - Envia evento `cluster_updated` e heartbeat para `https://maurinsoft.com.br/casa/api/v1/device.php`.

## Como usar via Terminal

```bash
# Verificar se há atualizações pendentes
casa-cluster-update --check

# Aplicar atualização (se houver novo commit)
sudo casa-cluster-update --apply

# Forçar atualização completa mesmo sem novo commit
sudo casa-cluster-update --force

# Consultar status da última atualização
casa-cluster-update --status
```

## Como usar via Portal Web

Acesse a página do cluster em `http://<IP-DO-CLUSTER>:8080/` e clique no botão **"ATUALIZAR CLUSTER"** no card de Atualizações.
