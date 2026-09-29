# CASA / COMPUTER - Agente Google Home

Este serviço integra Google Home / Chromecast ao CASA como saída de voz e como hardware virtual.

## Funções

- Descobre Google Home/Chromecast na rede local via Google Cast.
- Envia comandos ao COMPUTER e reproduz a resposta no Google Home.
- Mantém heartbeat no CASA usando `X-Device-Token`.
- Expõe áudio WAV local para o Chromecast.
- Permite que uma automação/webhook externa encaminhe comandos ao endpoint de hardware do CASA.

## Instalação

```bash
cd /home/mmm/servicos/google-home-agent
python3 -m venv venv
./venv/bin/pip install -r requirements.txt
sudo mkdir -p /etc/casa
sudo cp casa-google-home.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now casa-google-home
```

Configure `/etc/casa/google-home-agent.env` sem versioná-lo:

```ini
CASA_SYSTEM_API_TOKEN=...
CASA_GOOGLE_HOME_DEVICE_TOKEN=...
CASA_GOOGLE_HOME_HARDWARE_URL=https://maurinsoft.com.br/casa/api/google_home_hardware.php
CASA_GOOGLE_HOME_PUBLIC_BASE_URL=http://IP-DO-SERVIDOR:8100
```

O token de hardware pode ser criado na tela **Agentes externos > Google Home / Hardware > Provisionar Hardware**.

## Entrada de comandos pelo Google Home

O Google Home/Nest não fornece o áudio bruto nem uma API local para capturar toda frase falada. Para entrada de comandos, uma automação/bridge do lado Google deve chamar:

```http
POST /casa/api/google_home_hardware.php
X-Device-Token: <token provisionado>
Content-Type: application/json

{"acao":"comando","comando":"ligue a luz da sala","falar_resposta":true}
```

O CASA registra o Google Home como hardware, envia o comando ao COMPUTER e pode reproduzir a resposta pelo agente Cast.
