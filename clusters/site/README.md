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
   - Antes dos serviços, o quadro **Processos da aplicação (nó local)** lista PID, usuário, estado, CPU total e memória dos processos CASA reconhecidos. A atualização ocorre a cada 3 segundos.
   - É possível selecionar até 20 processos e usar **Matar processo**, com confirmação e autenticação administrativa. O portal e o atualizador não podem ser encerrados pelo quadro.
4. **Mapeamento e Navegação Entre Nós do Cluster**:
   - `192.168.2.7:8080` - Cubieboard ARMv7
   - `192.168.2.12:8080` - Raspberry Pi 4 Cluster Hub
   - `192.168.2.13:8080` - Raspberry Pi Node 13
   - `192.168.2.8:8080` - Raspberry Pi Node 08
5. **Endpoints de API Embutidos**:
   - `GET /api/telemetry` ou `/api/status`: Retorna JSON com telemetria completa, uptime e serviços.
   - `GET /api/cpu`: Retorna `cpu` com `usage_pct`, `cores`, `temp_c`, cargas médias e `history` (`timestamp` em segundos Unix e `usage_pct`). A primeira amostra pode ser `null` até haver dois contadores válidos.
   - `GET /api/processes`: Lista os processos da aplicação e informa se o encerramento está habilitado.
   - `POST /api/processes/terminate`: Solicita SIGTERM para os processos selecionados, com `Authorization: Bearer <token administrativo>` e JSON `{"processes":[{"pid":123,"start_time":"456"}]}`. O campo `start_time` deve ser o valor retornado pela listagem.
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

## Controle de processos

A consulta usa `/proc` e reconhece os pontos de entrada Python dos agentes CASA pelos caminhos de instalação listados em `APPLICATIONS` no servidor ou pelo checkout que contém o portal. Não lista todos os processos do sistema, não inclui programas apenas por mencionar “casa” nos argumentos e não expõe linhas de comando/tokens. Apache, MySQL, processos filhos genéricos e instalações em caminhos não cadastrados não fazem parte desta lista.

Para habilitar o botão, configure **um token administrativo próprio**, sem valor padrão, na variável de ambiente `CLUSTER_SITE_ADMIN_TOKEN` do serviço ou acrescente esta chave ao arquivo local `/etc/casa-node-agent.env`:

```ini
CLUSTER_SITE_ADMIN_TOKEN=<token-aleatorio-exclusivo-do-administrador>
```

Proteja o arquivo contra leitura por usuários não autorizados e não versione o valor. O token é diferente do token de dispositivo/pareamento. Informe-o no campo do quadro ao encerrar processos; a interface não o grava no navegador e limpa o campo após a solicitação. Para acesso fora da rede de administração, use HTTPS por proxy ou túnel seguro; não envie a credencial por HTTP em redes não confiáveis.

Sem configuração, a consulta continua disponível e o encerramento permanece desabilitado. A API exige autenticação, rejeita origem de navegador diferente do portal e valida a identidade PID + instante de início antes de sinalizar. Em Linux/Python com suporte a `pidfd`, o sinal fica vinculado à instância do processo; em kernels ARM antigos, há revalidação imediata antes do sinal, mas não a mesma garantia atômica contra reutilização de PID.

O botão envia **SIGTERM**, permitindo limpeza pela aplicação. A mensagem “Encerramento solicitado” confirma o envio do sinal, não garante saída imediata; processos que ignoram o sinal podem continuar aparecendo. Serviços configurados com reinício automático podem reaparecer com novo PID. A seleção anterior nunca é transferida ao novo processo. O portal e o atualizador são protegidos para preservar acesso e atualizações em andamento.

Testes sem encerramento de processos reais:

```bash
python3 clusters/site/tests/test_processes.py
node .github/tests/test_cluster_processes_ui.js
```
