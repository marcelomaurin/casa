# Clusters — programas e serviços Linux

Esta é a pasta central para identificar os programas executados nos nós Linux do
CASA, incluindo Raspberry Pi/ARM. A separação é por função; requisitos de hardware
e dependências continuam específicos de cada programa. RunPod gerencia recursos
GPU remotos e não significa executar esses recursos no ARM.

```text
clusters/
├── comunicacao/
│   └── arm-agent/                 Agente principal do nó ARM
├── automacao/
│   └── casa-scheduler/            Agendamento e execução de tarefas
├── voz/
│   ├── google-home-agent/         Integração Google Home / Chromecast
│   └── tts/                       Síntese e clonagem de voz
├── visao/
│   ├── espcam/                    Processamento de imagens de câmeras
│   └── raspberrypi-avatar-agent/  Avatar e Kinect, imagem Linux Yocto
├── pesquisa/
│   └── web-agent/                 Pesquisa e extração de páginas web
└── infraestrutura/
    ├── casa-tunnel/               Conectividade via túnel
    └── runpod-agent/              Gerenciamento RunPod e SSH remoto
```

## Localizar por funcionalidade

| Área | Programas | Relação com o site |
|---|---|---|
| [Comunicação](comunicacao/README.md) | arm-agent | Heartbeat e comandos pela API v1; aparece no painel ARM |
| [Automação](automacao/README.md) | casa-scheduler | Serviço auxiliar de agendamento; sem heartbeat próprio ARM |
| [Voz](voz/README.md) | google-home-agent, tts | Google Home usa registro específico; TTS é serviço auxiliar |
| [Visão](visao/README.md) | espcam, raspberrypi-avatar-agent | Avatar usa heartbeat v1; ESPCam contém processadores auxiliares |
| [Pesquisa](pesquisa/README.md) | web-agent | API de pesquisa; sem heartbeat próprio ARM |
| [Infraestrutura](infraestrutura/README.md) | casa-tunnel, runpod-agent | Serviços auxiliares; sem heartbeat próprio ARM |

**Organização do código não é presença online.** Mover um programa para esta pasta
não o instala nem o registra no site. O painel lista os registros e heartbeats
recebidos, como descrito em [Clusters ARM](../docs/ARM_CLUSTERS.md). Programas distintos
devem ter identificadores próprios para aparecer individualmente.

## Instalações existentes

Esta mudança reorganiza o repositório. Caminhos de execução já usados em `/opt/`
e `/home/mmm/servicos/`, ambientes virtuais, nomes das unidades systemd e arquivos
de configuração permanecem os mesmos. Não é necessário mover uma instalação em
funcionamento copiada para um diretório independente só para acompanhar a organização do código.

Se uma unidade systemd executa diretamente arquivos do checkout Git (em vez de uma cópia instalada), atualize o caminho da unidade ou copie os fontes novos para seu destino de instalação antes de reiniciar. Preserve os arquivos privados de configuração e o ambiente virtual; eles não foram migrados por esta alteração.

Para novas instalações, use o código das pastas acima e copie para os destinos
esperados pelos respectivos arquivos `.service`. O instalador do agente ARM já
baixa os fontes do novo caminho. Links antigos para fontes em `servicos/` ou
`yocto/` devem ser atualizados para esta árvore. Um instalador ARM antigo baixado
anteriormente deve ser substituído pela versão atual antes de ser executado.

O simulador de desenvolvimento permanece em `servicos/device_simulator.py`.
Aplicativos Android, firmware Arduino e código do site continuam em suas pastas.
