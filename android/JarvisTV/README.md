# JARVIS TV — Assistente lateral para Android TV

[![Platform](https://img.shields.io/badge/platform-Android%20TV-blue.svg)]()
[![Language](https://img.shields.io/badge/language-Java-orange.svg)]()
[![Voice](https://img.shields.io/badge/voice-SpeechRecognizer-green.svg)]()
[![Status](https://img.shields.io/badge/status-Experimental-yellow.svg)]()

## Visão geral

**JARVIS TV** é o cliente de televisão do projeto CASA. Ele foi desenhado para permanecer discretamente no lado esquerdo da tela, ouvir o usuário e expandir um menu somente quando for chamado.

Fluxo principal:

```text
Usuário
  |
  | voz: "Jarvis..."
  v
JARVIS TV
  |
  +--> comando de casa ------> API externa /api/v1 ------> JARVIS
  |
  +--> abrir aplicativo -----> Android PackageManager
  |
  +--> menu lateral ----------> overlay pequeno sobre a TV
```

O painel fica recolhido enquanto aguarda a palavra **Jarvis**. Ao reconhecer a chamada, ele se expande por alguns segundos, mostra o estado/comando/resposta e volta ao modo compacto automaticamente.

> [!WARNING]
> O Android não permite que um aplicativo comum force arbitrariamente Netflix, Prime Video ou qualquer outro aplicativo de terceiros a renderizar dentro de um retângulo pertencente ao JARVIS. A incorporação entre aplicativos exige que a aplicação alvo autorize Activity Embedding. Se ela não autorizar, a atividade ocupa a tarefa inteira. O JARVIS mantém seu próprio overlay lateral quando o sistema permitir.

## Modos de apresentação

| Modo | Uso | Estado |
|---|---|---|
| Overlay lateral | Menu e respostas do JARVIS sobre outros apps | Implementado |
| Conteúdo interno do JARVIS | Pode ocupar o retângulo lateral | Planejado |
| Activity Embedding | Apps de terceiros que explicitamente autorizarem incorporação | Planejado/condicional |
| Picture-in-Picture | Apps que oferecerem PiP no Android TV compatível | Dependente do app/sistema |
| App externo normal | Netflix, YouTube etc. quando não há embedding | Implementado como fallback |

## Comportamento do assistente

1. o usuário inicia o JARVIS TV e concede microfone + permissão de sobreposição;
2. o serviço permanece em primeiro plano;
3. o painel fica recolhido no lado esquerdo;
4. o reconhecimento de voz aguarda a palavra `Jarvis`;
5. ao ouvir a chamada, o painel expande;
6. se a frase já contiver um comando, ele é processado imediatamente;
7. após alguns segundos o painel volta ao estado compacto.

Exemplos:

```text
Jarvis, informe o status da casa
Jarvis, desligue a luz da sala
Jarvis, abra Netflix
Jarvis, abra YouTube
```

## API da casa

A TV usa a mesma API externa versionada:

```text
https://<HOST_PUBLICO>/api/v1
```

A configuração é feita localmente na TV. O código-fonte contém somente placeholders e nunca deve conter URL privada com segredo ou token real.

## Segurança

Use token exclusivo da TV com somente os escopos necessários. Nunca reutilize chave administrativa ou token do celular.

Segredos não devem ser colocados no Git:

```text
token real
senha
chave privada
credenciais Wi-Fi
Authorization Bearer real
```

## Limitações do reconhecimento contínuo

O projeto utiliza `SpeechRecognizer` do Android com preferência por reconhecimento offline. A disponibilidade real depende do mecanismo de reconhecimento instalado na TV/OEM.

Em Android 14/15, uso contínuo de microfone em segundo plano exige serviço em primeiro plano iniciado enquanto a atividade está visível e permissões adequadas. Por isso a primeira inicialização precisa ser feita pelo usuário.

Para uma palavra de ativação completamente local e independente do serviço de reconhecimento do sistema, a evolução recomendada é adicionar um engine dedicado de wake-word com modelo local.

## Câmera

A câmera foi declarada como recurso opcional da plataforma, mas a versão 1.0.0 não faz captura automática em segundo plano. Essa decisão evita ativação silenciosa e também respeita as restrições mais fortes de câmera/microfone do Android recente.

A integração futura deverá usar a câmera somente com indicação visual clara e finalidade configurada.

## Aplicativos externos

O mapeamento inicial inclui:

```text
Netflix
YouTube
Prime Video
Disney+
Globoplay
```

Pacotes instalados variam conforme fabricante e região. O `AppLauncher` informa quando o aplicativo solicitado não existe.

## Estrutura

```text
android/JarvisTV/
├── README.md
├── build.gradle.kts
├── settings.gradle.kts
├── gradle.properties
└── app/
    └── src/main/
        ├── AndroidManifest.xml
        ├── java/br/com/maurinsoft/jarvistv/
        │   ├── MainActivity.java
        │   ├── AssistantOverlayService.java
        │   ├── JarvisApiClient.java
        │   └── AppLauncher.java
        └── res/
```

## Versão

```text
versionName = 1.0.0
versionCode = 100
```

A build debug usa o sufixo de pacote `.debug` para evitar conflito com uma futura versão oficial assinada.

## Próximas evoluções

- wake-word local dedicado;
- painel de conteúdo interno com vídeo/informações;
- Activity Embedding para aplicações que permitirem;
- PiP quando suportado pelo app alvo;
- câmera com contexto visual controlado;
- sincronização de notificações do JARVIS;
- controle por controle remoto/D-pad além da voz;
- assinatura oficial estável.

## Autor

**Marcelo Maurin Martins**

Parte do projeto CASA / JARVIS Residencial.
