# Portal Web Local do Cluster (`clusters/site`)

Esta pasta contém o portal web leve do nó do cluster, desenvolvido para rodar localmente em cada equipamento que compõe o cluster ARM (como Raspberry Pi 4, Cubieboard ARMv7, etc.) na porta `8080`.

## Funcionalidades
1. **Dashboard Visual LCARS**: Visualização moderna inspirada no sistema LCARS da CASA / JARVIS.
2. **Telemetria em Tempo Real**: Polling contínuo de:
   - Uso de CPU e temperatura do processador
   - Memória RAM (total, usada, percentual)
   - Armazenamento em disco (total, usado, percentual)
   - Uptime do equipamento
   - Ao clicar em **Processador & Carga**, abre o gráfico de utilização total da CPU (0–100%), com grade e área preenchida, inspirado no Gerenciador de Tarefas do Windows.
   - Histórico dos últimos 60 segundos, coletado no nó a cada segundo, mesmo sem o gráfico aberto. O histórico é mantido em memória e reinicia com o serviço.
   - Percentual calculado pela diferença dos contadores de `/proc/stat`, separado da carga média de 1/5/15 minutos. Leituras indisponíveis e falhas de conexão não geram valores fictícios.
3. **Monitoramento de Serviços do Cluster**:
   - `casa-node-agent` (comunicação central com o site)
   - `casa-cluster-site` (servidor web local na porta 8080)
   - `apache2` / `lighttpd`
   - `mosquitto` (broker MQTT)
   - `ssh`
4. **Mapeamento e Navegação Entre Nós do Cluster**:
   - `192.168.2.7:8080` - Cubieboard ARMv7
   - `192.168.2.12:8080` - Raspberry Pi 4 Cluster Hub
   - `192.168.2.13:8080` - Raspberry Pi Node 13
   - `192.168.2.8:8080` - Raspberry Pi Node 08
5. **Endpoints de API Embutidos**:
   - `GET /api/telemetry` ou `/api/status`: Retorna JSON com telemetria completa, uptime e serviços.
   - `GET /api/cpu`: Retorna `cpu` com `usage_pct`, `cores`, `temp_c`, cargas médias e `history` (`timestamp` em segundos Unix e `usage_pct`). A primeira amostra pode ser `null` até haver dois contadores válidos.
   - `POST /api/heartbeat`: Dispara teste manual de sincronização com o site central (`https://maurinsoft.com.br/casa`).
   - `POST /api/falar`: Emite síntese de voz (TTS) localmente no dispositivo.

## Instalação e Execução
### Instalação Automática
Execute em cada nó do cluster:
```bash
sudo bash install-site.sh
```

### Instalação Manual
- **systemd** (Raspberry Pi 4):
  ```bash
  sudo mkdir -p /opt/casa/site
  sudo cp index.html server.py /opt/casa/site/
  sudo cp casa-cluster-site.service /etc/systemd/system/
  sudo systemctl daemon-reload
  sudo systemctl enable --now casa-cluster-site
  ```
- **SysVinit** (Cubieboard Linaro Ubuntu 14.04):
  ```bash
  sudo mkdir -p /opt/casa/site
  sudo cp index.html server.py /opt/casa/site/
  sudo cp casa-cluster-site /etc/init.d/casa-cluster-site
  sudo chmod +x /etc/init.d/casa-cluster-site
  sudo update-rc.d casa-cluster-site defaults
  sudo /etc/init.d/casa-cluster-site restart
  ```

## Acesso Web
Abra no navegador de qualquer dispositivo da rede local:
- `http://<IP_DO_NO>:8080/`

## Verificação do gráfico de CPU

```bash
python3 clusters/site/tests/test_cpu_telemetry.py
node .github/tests/test_cluster_cpu_ui.js
```

Execute na raiz do repositório. Depois de atualizar `index.html` e `server.py` no nó e reiniciar `casa-cluster-site`, abra o cartão **Processador & Carga**. Confira atualização, fechamento por Escape, acesso por teclado e indicação de desconexão. A implantação exige os dois arquivos da mesma versão.
