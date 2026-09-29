# Inventário dos programas

[Catálogo](README.md) · [Operação](OPERACAO.md)

Os destinos abaixo são os declarados nas unidades do repositório, não uma confirmação de instalação ou execução.

| Área / programa | Função | Entrada | Serviço / destino |
|---|---|---|---|
| [Comunicação / ARM](comunicacao/arm-agent/) | Heartbeat, comandos e resultados | casa-node-agent.py | casa-node-agent.service; /opt/casa-node-agent |
| [Automação / scheduler](automacao/casa-scheduler/) | Tarefas recorrentes e únicas | casa-scheduler.py | casa-scheduler.service; /opt/casa-scheduler |
| [Voz / Google Home](voz/google-home-agent/README.md) | Descoberta Cast e reprodução de respostas | google_home_agent.py | casa-google-home.service; /home/mmm/servicos/google-home-agent |
| [Voz / TTS](voz/tts/) | Síntese e clonagem de voz | server_tts.py | casa-tts.service; /home/mmm/servicos/tts |
| [Visão / ESPCam](visao/espcam/) | Processamento e análise de imagens | processa_imagem.py; analisa_cena_ia.py | Sem unidade própria |
| [Visão / Avatar](visao/raspberrypi-avatar-agent/README.md) | Terminal Raspberry Pi/Kinect | casa_avatar.py na receita Yocto | casa-avatar.service; instalado pela imagem Yocto |
| [Pesquisa / Web](pesquisa/web-agent/) | Pesquisa e extração de páginas | web_agent.py | jarvis-web-agent.service; /opt/jarvis-web-agent |
| [Infraestrutura / túnel](infraestrutura/casa-tunnel/) | Conectividade externa | casa-tunnel.py | casa-tunnel.service; /opt/casa-tunnel |
| [Infraestrutura / RunPod](infraestrutura/runpod-agent/) | Gerencia GPU remota e SSH | casa-runpod-agent.py | casa-runpod-agent.service; /opt/casa/servicos/runpod-agent |

## Dependências e configuração

| Programa | Preparação e configuração identificadas no código |
|---|---|
| ARM | Python 3; instalador apt/systemd com curl, certificados e ALSA. Configuração privada em /etc/casa-node-agent.env; veja Operação. |
| Scheduler | Python, psycopg2 e PostgreSQL. Variáveis JARVIS_DB_HOST, JARVIS_DB_NAME, JARVIS_DB_USER, JARVIS_DB_PASS e JARVIS_SYSTEM_API_TOKEN. |
| Google Home | Bibliotecas de requirements.txt, acesso aos dispositivos Cast e arquivo /etc/casa/google-home-agent.env. Siga o README próprio. |
| TTS | Python, servidor ASGI, psycopg2 e dependências de áudio/modelos presentes no fonte. Revise o ambiente existente; a pasta não contém instalador completo nem requirements.txt. |
| ESPCam | Dependências de imagem dos scripts; JARVIS_VISION_* controla limites e provedor de IA. Confira os argumentos dos scripts antes da integração. |
| Avatar | Build Yocto, Raspberry Pi, Kinect, ALSA e libfreenect. Configuração /etc/casa-avatar.env; siga o README específico. |
| Web | Ambiente Python com requirements.txt. BRAVE_SEARCH_API_KEY, JARVIS_WEB_TIMEOUT, JARVIS_WEB_MAX_PAGE_CHARS e JARVIS_WEB_USER_AGENT. |
| Túnel | Python, psycopg2, PostgreSQL e ferramenta de túnel utilizada pelo fonte. Configuração legada no código: revisar para o ambiente real antes de instalar. |
| RunPod | Python, API RunPod e paramiko para SSH. Variáveis RUNPOD_API_KEY, SSH_HOST, SSH_PORT, SSH_USER, SSH_PASS, AGENT_HOST e AGENT_PORT, ou .env privado ao lado do programa. |

Este inventário não substitui um arquivo completo de dependências. Não utilize valores de exemplo como credenciais de produção. RunPod gerencia recursos remotos; não fornece inferência GPU local no ARM.

## Instalação e diagnóstico por serviço

Exceto ARM e a imagem Yocto, não há um procedimento único de instalação pronto nesta pasta. Prepare dependências, confira usuário e caminhos na unidade .service e copie os fontes para o destino esperado, ou adapte a unidade ao seu ambiente.

Para consultar um serviço instalado:

```bash
sudo systemctl status NOME-DA-UNIDADE --no-pager
sudo journalctl -u NOME-DA-UNIDADE -n 100 --no-pager
```

Substitua NOME-DA-UNIDADE pelo nome da tabela. ESPCam contém scripts chamados por outros componentes, sem unidade própria.

## Presença e comunicação

ARM e Avatar possuem heartbeat v1. Google Home possui heartbeat específico. Scheduler, TTS, ESPCam, Web, túnel e RunPod são auxiliares e não enviam heartbeat ARM próprio. Estar nesta pasta não basta para aparecer online no painel.
