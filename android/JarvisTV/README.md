# JARVIS TV — tela de exibição da casa

[![Platform](https://img.shields.io/badge/platform-Android%20TV-blue.svg)]()
[![Language](https://img.shields.io/badge/language-Java-orange.svg)]()
[![Status](https://img.shields.io/badge/status-Experimental-yellow.svg)]()

## Objetivo

O **JARVIS TV** transforma uma TV com Android TV em uma **tela de exibição da casa**, pensada para áreas comuns (sala, cozinha). Ela mostra:

- **câmeras** da casa (ESP32-CAM e câmeras IP), uma de cada vez, com troca pelo controle remoto ou pelo painel;
- **alertas** pendentes (assistência e notificações);
- **mensagens do canal da família**;
- **emergências** (SOS e localização enviados pela família), em destaque vermelho.

A TV **não conversa** e **não controla a casa**: a chave dela é só de leitura (`camera.read`, `home.read`, `alerts.read`, `family.read`, mais o necessário para receber comandos de exibição). Assim, ninguém reconfigura a segurança da residência pela TV, como definido em `docs/PROTOCOLO_PASSAGEM_CHAVES_CELULAR.md` (§4.5).

> A versão 1.0.0 trazia também um assistente de voz lateral (ouvir "Jarvis", painel sobre outros apps e abrir Netflix/YouTube). Esse código nunca chegou a funcionar (o serviço nem estava declarado no manifesto) e saiu na 1.1.0.

## Como funciona

```text
TV (JARVIS TV)                                  CASA (/api/v1)
--------------                                  --------------
TvWatchService (sempre ligado) ---- heartbeat ---> device.php
        |                       <--- comandos ---- device_commands
        |   refresh / camera.next / emergency.*
        v
MainActivity (tela) ------------- painel -------> tv.php?acao=dashboard
        |
        +--> imagem da câmera direto na rede da casa (http://<ip>/capture)
```

- **TvWatchService** roda em primeiro plano o tempo todo, inclusive com a TV em outro aplicativo, e liga sozinho quando a TV inicia. A cada 10 s ele manda o sinal de vida e executa os comandos da fila.
- **MainActivity** é a tela: atualiza o painel a cada 5 s e a imagem da câmera a cada 2 s, e mantém a tela ligada (sem descanso de tela).
- **Emergência:** o serviço guarda o evento e traz a tela do JARVIS para a frente. O Android só permite isso com a permissão **"Sobrepor a outros apps"**; sem ela, fica uma notificação e o alerta aparece assim que a tela for aberta. O aviso só some com `emergency.cancelled`.

### Comandos aceitos

| Comando | Efeito |
|---|---|
| `refresh`, `dashboard.refresh`, `tv.refresh` | Atualiza o painel |
| `camera.next`, `tv.camera.next` | Próxima câmera |
| `emergency.sos`, `emergency.location`, `tv.emergency` | Mostra a emergência (traz a tela para a frente) |
| `emergency.cancelled` | Encerra o aviso de emergência |

## Instalação e pareamento

1. Instale o APK (`bin/android-tv/JarvisTV-debug.apk`) e abra **JARVIS TV**.
2. Confira a **URL CASA** (padrão `https://maurinsoft.com.br/casa`) e toque em **AUTORIZAR TV**.
3. A TV mostra um código; autorize no **JARVIS Mobile**. A TV recebe a própria credencial do site (o celular só autoriza, não transporta a chave).
4. Toque em **PERMITIR ALERTA SOBRE OUTROS APPS**. Se a TV não abrir essa tela, use pelo computador:

```bash
adb shell appops set br.com.maurinsoft.jarvistv.debug SYSTEM_ALERT_WINDOW allow
```

## Requisitos e limitações

- Android TV 8.0 (API 26) ou mais novo.
- A imagem das câmeras é buscada direto na câmera, por HTTP, na rede da casa: fora de casa a TV mostra o painel, mas não a imagem.
- Alguns fabricantes encerram serviços em segundo plano para economizar energia; se a TV parar de receber alertas, libere o JARVIS TV nas configurações de energia do aparelho.

## Estrutura

```text
android/JarvisTV/app/src/main/java/br/com/maurinsoft/jarvistv/
├── MainActivity.java          tela de exibição
├── TvWatchService.java        sinal de vida, comandos e emergências
├── BootReceiver.java          liga o serviço quando a TV inicia
├── TvBus.java                 canal entre o serviço e a tela
├── TvControlPlaneClient.java  cliente de /api/v1/device.php
├── TvProvisioningClient.java  pareamento (/api/v1/provision.php)
└── TvConfig.java              endereço padrão e chaves de configuração
```

## Versão

```text
versionName = 1.1.0
versionCode = 110
```

A build de depuração usa o sufixo de pacote `.debug`. O build automático do GitHub gera o APK em todo envio; só grava em `bin/android-tv/` quando roda na `master`.

## Autor

**Marcelo Maurin Martins** — parte do projeto CASA / JARVIS Residencial.
