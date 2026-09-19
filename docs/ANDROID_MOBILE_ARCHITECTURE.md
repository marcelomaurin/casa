# JARVIS Mobile — arquitetura após decomposição

A refatoração mantém o protocolo CASA/1.0 e o comportamento funcional existente, mas reduz a concentração de responsabilidades em `MainActivity` e `JarvisConnectionService`.

## UI e navegação

- `MainActivity.kt`: host Compose, ciclo de vida, permissões e composição das telas.
- `MobileRoute.kt`: rotas do aplicativo.
- `MobileAppState.kt`: estado de sessão, configuração, conectividade e fila.
- `AppStrings.kt`: textos e seleção de idioma da interface.
- `SpeechInputController.kt`: reconhecimento de voz.
- `JarvisAudioPlayer.kt`: download autenticado e reprodução de áudio.

A Activity não implementa mais diretamente reconhecimento de fala, player de áudio, mapas de tradução ou regras de atualização do estado de conexão.

## Autenticação e API

- `MobileAuth.kt`: login, validação, logout e persistência de sessão.
- `JarvisApi.kt`: comunicação geral com JARVIS.
- `ControlPlaneApi.kt`: comandos do Control Plane.
- `DeviceProvisionApi.kt`: provisionamento.
- `ScenesApi.kt`, `FamilyApi.kt`, `WatchApi.kt`: domínios específicos.

## Serviço de conexão

- `JarvisConnectionService.kt`: lifecycle do foreground service, loop de conexão e coordenação.
- `JarvisNetworkMonitor.kt`: monitoramento de rede, Wi-Fi, Internet e SSID.
- `WatchCommandDispatcher.kt`: decide entre transporte Watch local e Command Bus remoto.
- `WatchEventProcessor.kt`: interpreta eventos do relógio e encaminha ações de domínio.
- `PhoneSensorProvider.kt`: acesso a sensores do telefone, atualmente GPS.
- `WatchClient.kt`: transporte TCP local do Watch.
- `BleBridge.kt`: bridge BLE.

`WatchEventProcessor.Actions` e `JarvisNetworkMonitor.Listener` formam limites injetáveis que permitem testar o processamento sem depender diretamente do Android Service.

## Atualização

`UpdateManager.kt` e `UpdateDownloadReceiver.kt` continuam responsáveis pelo ciclo de atualização automática, sem responsabilidade na Activity ou no serviço de conexão.

## Fluxo

```text
MainActivity
   ↓
MobileAppState
   ↓
MobileAuth / JarvisApi
   ↓
JarvisConnectionService
   ├── JarvisNetworkMonitor
   ├── WatchCommandDispatcher
   └── WatchEventProcessor
          ↓
       WatchApi / FamilyApi / JARVIS
```

O protocolo CASA/1.0 não foi alterado nesta refatoração.
