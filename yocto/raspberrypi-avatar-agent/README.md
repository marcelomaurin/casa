# CASA Avatar Agent — Yocto / Raspberry Pi 4 1 GB

Imagem Yocto dedicada a um terminal multimodal do CASA/JARVIS.

## Objetivo

O Raspberry Pi funciona como agente físico/visual, não como servidor de LLM:

1. Kinect Xbox 360 captura voz, RGB e depth.
2. O agente envia fala/imagem ao servidor CASA/JARVIS.
3. O JARVIS usa as IAs cadastradas no servidor.
4. A resposta aparece no avatar.
5. O áudio é reproduzido no speaker.
6. O terminal mantém heartbeat no Control Plane.

```text
Kinect 360
  ├─ microfone -> ALSA -> WAV -> STT remoto
  ├─ RGB ------> libfreenect -> visão remota
  └─ depth ----> libfreenect -> contexto/sensoriamento
                    |
                    v
             CASA / JARVIS
                    |
             Router multi-IA
                    |
                    v
              resposta/TTS
                    |
           Raspberry + Avatar
                    |
                  speaker
```

## Hardware alvo

Padrão: Raspberry Pi 4 64-bit, inclusive versão de 1 GB.

```text
MACHINE = "raspberrypi4-64"
```

Para outro Raspberry altere `MACHINE` no `conf/local.conf`.

## Build

A configuração usa a série Yocto LTS `scarthgap`.

```bash
cd yocto/raspberrypi-avatar-agent
sh ./setup-env.sh
source build-env
bitbake casa-avatar-image
```

A imagem será gerada em `build/tmp/deploy/images/raspberrypi4-64/`.

## Configuração

Edite `/etc/casa-avatar.env` no Raspberry:

```text
CASA_BASE_URL=https://casa.maurinsoft.com.br
CASA_API_TOKEN=
CASA_DEVICE_ID=avatar-rpi-01
CASA_DEVICE_TOKEN=
AUDIO_CAPTURE_DEVICE=default
AUDIO_PLAYBACK_DEVICE=default
LISTEN_SECONDS=5
VISION_ENABLED=1
```

Nunca versionar tokens reais.

## Kinect 360

O projeto usa libfreenect. RGB e depth são capturados por `kinect-snapshot`.
O microfone é acessado por ALSA; confira com `arecord -l`.

Algumas revisões do Kinect exigem firmware para o subsistema de áudio. Este projeto não redistribui firmware proprietário.

## Diagnóstico físico

Depois do primeiro boot, execute:

```bash
casa-avatar-diagnostics
```

Ele testa USB/Kinect, captura RGB/depth, ALSA de entrada, speaker, acesso ao CASA e tokens configurados. Use este resultado antes de testar a IA.

## Critério de aceite físico

```text
Pessoa fala
 -> Kinect grava
 -> STT retorna texto
 -> JARVIS recebe
 -> IA responde
 -> avatar fala
 -> speaker reproduz
```

Para visão:

```text
Kinect captura RGB/depth
 -> imagem vai ao servidor
 -> IA visual descreve
 -> descrição entra no contexto da pergunta
```
