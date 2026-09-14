# JARVIS Watch — LILYGO T-Watch 2020 V3

O firmware trata o T-Watch como um smartwatch JARVIS completo, com mostradores, launcher de aplicativos, voz, alarme, atividade, automação residencial, assistência familiar e integração com celular/site.

## Hardware alvo

- LILYGO T-Watch 2020 V3
- ESP32 Dev Module / core ESP32 2.0.14
- TFT ST7789 240x240
- touch FT6336
- RTC PCF8563
- PMIC AXP202
- acelerômetro BMA423
- motor de vibração
- microfone PDM SPM1423
- Wi-Fi do ESP32

O T-Watch 2020 V3 não possui câmera nem receptor GPS integrados. Essas funções usam o celular como extensão.

## Navegação

```text
esquerda / direita  -> troca de skin
para cima            -> abre aplicativos
para baixo           -> retorna
```

O firmware separa tap de swipe para não disparar botões durante um gesto.

## Skins

- CLASSIC
- ANA-DIGI
- AVIATION

O skin escolhido é persistido em `Preferences`.

## Launcher atual

```text
VOZ     ALM     CAM     GPS
CASA    PASSOS  STATUS  CONFIG
```

A camada de assistência/família foi adicionada em módulos próprios e será ligada ao launcher após a validação de compilação do transporte novo.

## Assistência familiar / uso com idosos

Arquivos:

```text
jarvis_assistance.h
jarvis_assistance.cpp
```

Recursos implementados na camada de assistência:

- telemetria periódica de passos/movimento;
- contador de minutos sem movimento;
- alerta de inatividade prolongada;
- check-in manual;
- SOS manual;
- abertura de chamada familiar;
- preferência persistente para habilitar/desabilitar monitoramento;
- limite configurável de inatividade;
- envio preferencial pelo celular/BLE e fallback por Wi-Fi.

Importante: o relógio **não diagnostica queda, desmaio ou emergência médica**. A ausência de movimento gera um alerta de verificação, que precisa ser confirmado pela família/usuário.

## Canal Família CASA

O site possui um canal lógico comum para:

```text
site <-> celular <-> relógio
```

Endpoint externo:

```text
/casa/api/v1/family.php
```

Console web autenticado:

```text
/casa/familia.php
```

O canal suporta:

- presença online;
- mensagens em broadcast;
- alertas do JARVIS;
- eventos de assistência;
- início e encerramento de chamadas;
- sinalização WebRTC;
- conversa de site para celular/relógio.

## Videochamada familiar

O site usa WebRTC e o banco apenas como camada de sinalização.

No T-Watch:

- pode iniciar/aceitar a chamada;
- pode usar o microfone como origem de voz quando o transporte de áudio for concluído;
- não possui câmera própria.

Para vídeo, o celular pareado atua como câmera/tela/gateway do relógio. Assim, uma chamada iniciada pelo relógio pode abrir a experiência de vídeo no telefone da pessoa usando o Watch.

## Wi-Fi de contingência

Arquivos:

```text
jarvis_wifi.h
jarvis_wifi.cpp
```

O relógio pode armazenar até cinco redes domésticas previamente provisionadas pelo aplicativo. Quando o celular não estiver disponível:

1. o Watch faz scan das redes próximas;
2. considera somente SSIDs previamente autorizados;
3. compara o RSSI;
4. conecta à rede conhecida de melhor sinal;
5. usa HTTPS para comunicar diretamente com CASA/JARVIS.

O relógio **não tenta redes desconhecidas** e não escolhe apenas pelo nome/sinal sem possuir credencial válida.

O Watch deve usar somente um token individual com escopos mínimos. Nunca deve receber token mestre, senha de banco ou chave RunPod.

### Segurança TLS

A implementação Wi-Fi atual usa `WiFiClientSecure` e possui `setInsecure()` somente como etapa de transição para teste. Antes de produção deve ser instalado o CA correspondente e usado `setCACert()`.

## Alarme

Funciona localmente no relógio, sem celular:

- hora e minuto configuráveis;
- ativar/desativar;
- persistência;
- RTC local;
- vibração e wake da tela.

## Voz / JARVIS

Fluxo esperado:

```text
T-Watch -> celular -> STT -> JARVIS/IA -> resposta
```

O microfone PDM já possui captura/detecção de atividade de voz. O streaming completo de áudio depende do transporte Watch <-> Android.

## Câmera e GPS

Câmera e GPS são fornecidos pelo celular pareado.

```text
camera_capture
 gps_request
```

O relógio funciona como controle e visualização resumida; não há câmera ou GPS físicos no T-Watch.

## Economia de energia

Perfis:

- NORMAL
- ECO
- ULTRA

O modo ULTRA reduz brilho, aumenta o intervalo do loop, desliga o display por inatividade e pode entrar em deep sleep usando o BMA423 como wake-up.

## Banco de dados / assistência

Migração:

```text
database/family_assistance.sql
```

Estruturas:

- `family_channels`
- `family_presence`
- `family_messages`
- `family_calls`
- `family_call_signals`
- `assistencia_eventos`

A API também cria essas tabelas com `CREATE TABLE IF NOT EXISTS` para instalações existentes.

## Estado atual

| Recurso | Estado |
|---|---|
| Skins | Implementado |
| Swipe | Implementado |
| Launcher | Implementado |
| Alarme local | Implementado |
| RTC / bateria | Implementado |
| Passos BMA423 | Implementado |
| Perfis de energia | Implementado |
| Microfone PDM | Implementado; validar hardware |
| Canal Família no site/API | Implementado |
| WebRTC no site | Implementado; validar navegador/NAT |
| API Android Família | Implementada |
| Assistência/SOS/inatividade | Módulo implementado; falta ligar ao launcher/loop principal |
| Wi-Fi melhor rede conhecida | Módulo implementado; falta provisionamento BLE e ligar ao fluxo principal |
| Voz -> IA | Transporte Android pendente |
| Câmera remota | Android preparado; integração Watch BLE pendente |
| GPS via celular | Android preparado; integração Watch BLE pendente |
| BLE Watch -> Android | Precisa ser invertido para Watch periférico / Android central |

> Compile e valide no T-Watch físico. Microfone, deep sleep, wake-up por movimento, Wi-Fi, consumo, WebRTC e sensibilidade dos gestos precisam de teste real antes de uso cotidiano.
