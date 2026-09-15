# JARVIS Watch — LILYGO T-Watch 2020 V3

O firmware trata o T-Watch como smartwatch JARVIS com mostradores, launcher, voz, alarme, atividade, automação residencial, Wi-Fi de contingência e integração com celular/site.

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
- Wi-Fi ESP32
- BMA423 (INT GPIO39)
- motor de vibração (GPIO4)
- microfone PDM SPM1423 (DATA GPIO2 / CLK GPIO0)
- MAX98357A / saída I2S (BCK GPIO26 / WS GPIO25 / DOUT GPIO33)
- transmissor infravermelho (GPIO13 / RMT)
- sem dependência externa de NimBLE/ESP32_BLE_Arduino nesta build

O transporte BLE permanece isolado em `jarvis_ble.*`, mas está desativado nesta
build compacta para reduzir o firmware e evitar a dependência da biblioteca BLE.

## Partição de firmware

O firmware atual ultrapassa o limite padrão de aplicação do perfil `ESP32 Dev Module`
(~1,3 MiB). O sketch contém um `partitions.csv` próprio com uma partição de
aplicação de 3 MiB:

```text
NVS     20 KiB
APP0     3 MiB
SPIFFS 960 KiB
```

O repositório contém `partitions.csv` na pasta do sketch, porém alguns ambientes
do Arduino IDE continuam usando o esquema selecionado no menu da placa. O sintoma é
a compilação mostrar `Maximum is 1310720 bytes`.

Para este projeto, use explicitamente:

```text
Tools > Board             : ESP32 Dev Module
Tools > Flash Size        : 16MB (128Mb), quando disponível
Tools > Partition Scheme  : Huge APP (3MB No OTA/1MB SPIFFS)
Tools > PSRAM             : Enabled
```

Com `Huge APP`, o limite esperado deve ficar próximo de `3145728 bytes`. Se a
saída ainda mostrar `1310720 bytes`, o IDE continua usando o esquema padrão e a
seleção de `Partition Scheme` precisa ser refeita.

A placa T-Watch 2020 V3 possui flash maior que essa tabela, mas usamos
deliberadamente uma tabela compatível com 4 MiB neste momento para evitar erro
de gravação causado por uma seleção incorreta de `Flash Size`. Quando o OTA do
relógio for implementado, a tabela será migrada para duas partições OTA usando a
capacidade total do hardware.

O T-Watch 2020 V3 usado neste projeto não fornece câmera nem GPS próprios. O firmware usa o microfone PDM local e agora também implementa a saída de áudio pelo MAX98357A. Câmera e GPS continuam sendo fornecidos pelo celular.

## Arquitetura por eventos e máquinas de estado

A revisão atual passa a usar um controlador central orientado a eventos.

```text
hardware / RTC / touch / botão / comunicação
                    |
                    v
             JarvisEventQueue
                    |
                    v
            JarvisController
             /      |       \
            v       v        v
      PowerSM    AlarmSM   NetworkSM
            \       |        /
             \      |       /
                    v
                 UI atual
```

Arquivos:

```text
jarvis_events.h/.cpp
jarvis_power_sm.h/.cpp
jarvis_alarm_sm.h/.cpp
jarvis_network_sm.h/.cpp
jarvis_controller.h/.cpp
```

A fila suporta prioridades `LOW`, `NORMAL`, `HIGH` e `CRITICAL`. Eventos críticos podem substituir eventos menos importantes caso a fila esteja cheia. O controlador processa no máximo 12 eventos por ciclo para impedir starvation de touch e renderização.

Eventos já previstos incluem:

```text
BUTTON_SHORT / BUTTON_LONG
TOUCH_TAP
SWIPE_LEFT / RIGHT / UP / DOWN
SCREEN_TIMEOUT
ALARM_TRIGGER / ALARM_STOP
WIFI_SCAN_REQUEST / SCAN_DONE / CONNECTED / FAILED
BLE_CONNECTED / BLE_DISCONNECTED
VOICE_START / STOP / RESULT
CALL_START / INCOMING / ACCEPT / END
SOS / CHECKIN
INTERNAL_ERROR
```

O ISR do botão físico apenas levanta uma flag. O evento real é criado no `loop()`, evitando manipulação de `String`, display, PMIC ou fila dentro da interrupção.

## Estabilidade / prevenção de travamentos

A revisão atual remove operações bloqueantes do caminho principal sempre que possível:

- scan de Wi-Fi assíncrono;
- conexão Wi-Fi por `NetworkStateMachine`, com deadline e sem espera longa na UI;
- captura PDM processada em pequenas leituras no `loop()`;
- alarme por `AlarmStateMachine`, sem sequência longa de `delay()`;
- controle de display/deep sleep pela `PowerStateMachine`;
- timeouts curtos para HTTPS;
- validação de ponteiros de hardware antes de uso;
- botão físico com debounce;
- touch ignorado com tela apagada;
- botão físico configurado como fonte de wake;
- fila de eventos com contador de eventos descartados, exibido na tela STATUS.

Não existe `try/catch` útil para a maior parte do firmware Arduino porque a configuração embarcada normalmente compila C++ sem exceções. O tratamento é feito por retorno de erro, timeout, isolamento de drivers e máquinas de estado.

O `loop()` agora tem responsabilidade reduzida: lê hardware, gera eventos, atualiza módulos e retorna rapidamente. Não deve existir `while` aguardando rede nem `delay` longo em uma máquina de estado.

## PowerStateMachine

Estados:

```text
SCREEN_ON
SCREEN_OFF
PREPARE_SLEEP
DEEP_SLEEP
```

Responsabilidades:

- botão físico alterna tela ON/OFF;
- touch apenas renova atividade com tela ligada;
- timeout apaga a tela;
- `ALARME`, `CALL_INCOMING` e `SOS` acordam a tela;
- modo ULTRA entra em deep sleep após período com tela apagada.

## AlarmStateMachine

Estados:

```text
IDLE
WAITING
RINGING
SNOOZE
```

O RTC gera `EVT_TICK_1S`. Ao chegar ao horário configurado, a máquina gera `EVT_ALARM_TRIGGER` de prioridade alta. O padrão de vibração avança por tempo, sem bloquear o loop. O usuário confirma com `EVT_ALARM_STOP`.

## NetworkStateMachine

Estados:

```text
OFFLINE
IDLE
SCANNING
SELECTING
CONNECTING
CONNECTED
ERROR
```

A busca e a associação Wi-Fi agora pertencem à máquina de rede. A UI solicita a operação e recebe eventos de conclusão/erro.

```text
UI -> WIFI_SCAN_REQUEST
        |
        v
     SCANNING
        |
        +--> WIFI_SCAN_DONE -> lista de SSIDs

senha salva
        |
        v
    CONNECTING
      /     \
     v       v
CONNECTED  ERROR
```

## Voice e Call

O controlador já possui estados comuns para voz e chamada:

```text
VOICE: IDLE -> LISTENING -> SENDING -> WAITING -> PLAYING / ERROR
CALL : IDLE -> DIALING -> RINGING -> CONNECTING -> ACTIVE -> ENDING / ERROR
```

Nesta etapa eles já recebem eventos e refletem estado no controlador, mas a migração completa do transporte de voz e da videochamada para classes próprias será feita depois da validação desta base no hardware.

## Botão físico

O botão PEK/AXP202 é a chave principal da tela:

```text
pressionar -> EVT_BUTTON_SHORT -> PowerStateMachine
                               -> apaga/acende tela
```

O firmware usa `AXP202_PEK_SHORTPRESS_IRQ`, conforme o mecanismo oficial da biblioteca do T-Watch. No modo ULTRA o botão também pode acordar o ESP32 do deep sleep.

## Navegação

```text
esquerda / direita  -> sem troca de mostrador
para cima            -> abre aplicativos
para baixo           -> retorna
botão físico          -> tela ON/OFF
```

Gestos também geram eventos, mesmo enquanto a UI existente continua tratando a navegação. Isso permite migrar a interface para `UiStateMachine` sem reescrever tudo de uma vez.

## Launcher

```text
VOZ     ALM     CAM     GPS
CASA    PASSOS  STATUS  CONFIG
```

## Wi-Fi no próprio relógio

Em `CONFIG -> WIFI`:

1. toque em `BUSCAR`;
2. a UI gera `EVT_WIFI_SCAN_REQUEST`;
3. `NetworkStateMachine` faz scan assíncrono;
4. `EVT_WIFI_SCAN_DONE` atualiza a lista;
5. toque no SSID;
6. abre teclado touch;
7. digite a senha;
8. `SALVAR` persiste o perfil e coloca a máquina em `CONNECTING`;
9. a UI continua responsiva até `WIFI_CONNECTED` ou `WIFI_FAILED`.

Até cinco redes conhecidas podem ser armazenadas. Redes desconhecidas nunca são conectadas automaticamente.

## Voz / JARVIS

A captura de voz PDM não bloqueia a interface inteira. O controlador recebe `EVT_VOICE_START`, `EVT_VOICE_STOP` e `EVT_VOICE_RESULT`.

A tela permite escolher:

- `CELULAR`: resposta falada no smartphone;
- `TEXTO`: somente texto/feedback visual;
- `AMBOS`: áudio no celular e texto no Watch.

Fluxo:

```text
Watch microfone -> celular -> STT -> JARVIS/IA -> celular/Watch
```

## Alarme

O alarme local usa RTC e funciona sem Internet. Padrões:

- `CURTO`;
- `DUPLO`;
- `URGENTE`;
- `CELULAR`.

Os três primeiros são padrões de vibração. `CELULAR` solicita toque sonoro no telefone quando o transporte estiver ativo.

Quando está tocando, o botão da tela de alarme passa a mostrar `PARAR ALARME`.

## Câmera e videochamada

A tela `CAMERA / VIDEO` oferece:

- `FOTOGRAFAR`: usa a câmera do celular;
- `VIDEO FAMILIA`: cria/inicia chamada no canal Família CASA.

A ação de chamada já gera `EVT_CALL_START` e atualiza o estado da chamada no controlador. Como o Watch não tem câmera, o vídeo vem do celular/site.

## GPS

O GPS é fornecido pelo celular pareado. O Watch envia `gps_request` e exibe o resultado resumido quando o transporte estiver ativo.

## Economia de energia

Perfis:

- NORMAL
- ECO
- ULTRA

O modo ULTRA limita brilho, apaga o display e pode entrar em deep sleep. A decisão agora pertence à `PowerStateMachine`.

## Canal Família e assistência

O site mantém:

```text
/casa/familia.php
/casa/api/v1/family.php
```

para presença, broadcast, assistência e sinalização de chamadas.

A camada de assistência continua separada em `jarvis_assistance.*`. Alertas de inatividade são alertas de verificação, não diagnóstico médico.

## Estado atual

| Recurso | Estado |
|---|---|
| EventQueue prioritária | Implementada |
| JarvisController | Implementado |
| PowerStateMachine | Implementada e integrada |
| AlarmStateMachine | Implementada e integrada |
| NetworkStateMachine | Implementada e integrada |
| Voice state | Estrutura integrada; migração completa pendente |
| Call state | Estrutura integrada; migração completa pendente |
| UiStateMachine separada | Próxima etapa após validação |
| Mostrador JARVIS AVIATION / swipe / launcher | Implementado |
| Botão físico tela ON/OFF | Agora passa pela PowerSM; validar hardware |
| Seleção de padrão do alarme | Implementada |
| Microfone PDM não bloqueante | Implementado; PCM real em I2S0 |
| MAX98357A / I2S1 | Implementado |
| Som local de alarme | Implementado |
| Vibração GPIO4 | Implementada e inicializada |
| Infravermelho GPIO13 | Implementado com RMT / NEC / RAW |
| Busca Wi-Fi | Gerenciada pela NetworkSM |
| Teclado de senha Wi-Fi | Implementado |
| Videochamada Família | Pedido via BLE ou Wi-Fi/site implementado |
| Foto remota | UI implementada; depende do transporte Android |
| GPS | depende do transporte Android |
| BLE Watch -> Android | transporte GATT estável do Watch ainda pendente |
| Assistência/SOS | módulo disponível; integração completa ao EventQueue ainda pendente |

> Esta revisão precisa ser compilada no ambiente ESP32 2.0.14 e validada no T-Watch físico antes da próxima migração. Prioridade do teste: botão, timeout, alarme, touch, Wi-Fi e estabilidade por pelo menos 30 minutos.


## Mostrador atual

O mostrador `ANA-DIGI`, que exibia dois submostradores circulares, foi removido.
O firmware mantém somente o mostrador unificado `JARVIS AVIATION`, reduzindo código
gráfico e memória de programa.


## Áudio local / MAX98357A

A saída de áudio local foi implementada diretamente com `driver/i2s.h`, sem biblioteca
de áudio adicional. O objetivo é manter o firmware pequeno.

Arquitetura:

```text
I2S_NUM_0 -> RX/PDM -> SPM1423
  CLK  GPIO0
  DATA GPIO2

I2S_NUM_1 -> TX -> MAX98357A
  BCK  GPIO26
  WS   GPIO25
  DOUT GPIO33
```

O módulo `jarvis_audio.*` fornece:

- tom não bloqueante;
- padrões de alarme no próprio relógio;
- reprodução de PCM mono 16-bit/16 kHz para futura resposta TTS;
- ativação do domínio de áudio AXP202 LDO4;
- desligamento do áudio antes de deep sleep.

A tela de alarme toca uma prévia ao trocar entre CURTO, DUPLO e URGENTE. O modo
CELULAR continua encaminhando o som ao telefone quando o transporte estiver disponível.

O motor de vibração é inicializado explicitamente com `motor_begin()` e usa o GPIO4.

## Microfone

O microfone PDM SPM1423 usa `I2S_NUM_0` em RX/PDM, 16 kHz e 16 bits. O firmware
já lê amostras PCM reais e calcula atividade de voz. O envio dessas amostras para
STT externo continua separado da captura local: com o BLE desativado nesta build,
o transporte de áudio até o celular/servidor ainda precisa ser conectado a uma
camada de rede existente.


## Infravermelho

O transmissor IR nativo do T-Watch 2020 V3 está implementado no GPIO13 usando
diretamente o periférico RMT do ESP32, sem biblioteca externa.

Arquivos:

```text
jarvis_ir.h
jarvis_ir.cpp
```

Recursos:

- portadora padrão de 38 kHz;
- envio NEC padrão;
- envio NEC com endereço estendido;
- envio RAW com sequência MARK/SPACE;
- repetição NEC não bloqueante;
- processamento por `jarvisIrLoop()`;
- desligamento do RMT antes do deep sleep.

Exemplos de uso:

```cpp
// NEC padrão: endereço 0x10, comando 0x20
jarvisIrSendNec(0x10, 0x20);

// Um frame + 2 repeats
jarvisIrSendNec(0x10, 0x20, 2);

// NEC estendido
jarvisIrSendNecExtended(0x1234, 0x20);

// RAW, começando por MARK
const uint32_t raw[] = {9000, 4500, 560, 560, 560, 1690, 560};
jarvisIrSendRaw(raw, sizeof(raw) / sizeof(raw[0]), 38000, 33);
```

O firmware não transmite nenhum código IR automaticamente na inicialização. Os códigos
de cada TV, ar-condicionado ou outro equipamento devem ser cadastrados conforme o
aparelho a ser controlado.


## Indicadores de conectividade

O Watch mostra dois indicadores pequenos nos cantos superiores:

```text
canto superior esquerdo -> casa: servidor CASA acessível por HTTPS
canto superior direito  -> Wi-Fi: associação Wi-Fi ativa
```

O servidor verificado é a URL CASA configurada, cujo padrão é:

```text
https://maurinsoft.com.br/casa
```

A verificação ocorre após conectar ao Wi-Fi e periodicamente, com timeouts curtos.
A casinha só aparece quando há uma resposta HTTP/HTTPS válida do servidor.

Na tela de configuração de Wi-Fi:

- a senha numérica digitada é exibida diretamente na tela;
- durante a associação aparece `CONECTANDO...`;
- quando a rede é aceita aparece `WIFI CONECTADO` em destaque;
- o estado do servidor é mostrado como `CASA: VERIFICANDO...`,
  `CASA: CONECTADO` ou `CASA: SEM RESPOSTA`;
- após a conexão a senha digitada é limpa da RAM da interface.
