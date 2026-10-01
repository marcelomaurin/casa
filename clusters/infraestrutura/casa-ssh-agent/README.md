# CASA Cluster SSH Maintenance Agent

Agente especializado de controle e manutencao remota dos clusters via SSH, projetado especificamente para ser controlado pela IA (JARVIS / Agentes Autonomos / Command Bus) e por operadores.

## Recursos

1. **API REST Local (Porta 8095)**:
   - `GET /health` ou `GET /api/ssh/status`: Status e saude do agente.
   - `GET /api/ssh/nodes`: Inventario completo com ping e latencia em tempo real.
   - `POST /api/ssh/exec`: Executa comando shell em um no especifico com captura de stdout, stderr e exit code.
   - `POST /api/ssh/batch`: Execucao paralela em varios nos simultaneamente.
   - `POST /api/ssh/upload`: Envio de scripts e arquivos via SFTP.
   - `GET /api/ssh/history`: Log de auditoria de operacoes executadas pela IA.

2. **Resolucao Flexivel de Nos**:
   - Por ID: `cubieboard-arm-07`, `raspberry-pi-local`, `raspberry-pi-node-08`, `raspberry-pi-node-13`
   - Por IP: `192.168.2.7`, `192.168.2.13`, `192.168.2.8`
   - Por Grupo: `all`, `hub`, `arm`

3. **Seguranca e Auditoria**:
   - Tratamento seguro de elevacao com `sudo`.
   - Timeout automatico (evita travamento de comandos).
   - Gravacao de todas as acoes em `/opt/casa/ssh-agent/audit.jsonl` e log do sistema.

## Exemplos de Uso

### 1. Via Python SDK (para uso por scripts de IA):
```python
from client import ClusterSSHClient

ssh = ClusterSSHClient("http://127.0.0.1:8095")

# Executa comando de manutencao no Cubieboard
res = ssh.exec("cubieboard-arm-07", "df -h /", sudo=False)
print("Saida:", res["stdout"])

# Reinicia servico com sudo em outro no
res = ssh.exec("raspberry-pi-node-08", "systemctl restart casa-node-agent", sudo=True)
print("Resultado:", res["ok"])

# Execucao em todos os clusters
batch_res = ssh.batch("all", "uptime")
for node, out in batch_res["results"].items():
    print(node, "->", out["stdout"])
```

### 2. Via CLI (`casa-ssh-cli`):
```bash
# Listar nos
casa-ssh-cli nodes

# Executar comando no Cubieboard
casa-ssh-cli exec -n cubieboard-arm-07 -c "uptime"

# Executar com sudo no no 08
casa-ssh-cli exec -n raspberry-pi-node-08 -c "apt-get update" --sudo

# Executar em todos os nos simultaneamente
casa-ssh-cli batch --nodes all -c "uname -a"
```
