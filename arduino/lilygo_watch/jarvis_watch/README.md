# JARVIS Watch — LILYGO T-Watch 2020 V3

O firmware passa a tratar o T-Watch como um relógio JARVIS completo, com prioridade para voz, baixo consumo, interação rápida e operação vestível.

## Hardware alvo

- LILYGO T-Watch 2020 V3
- ESP32 Dev Module / core ESP32 2.0.14
- TFT ST7789 240x240
- touch FT6336
- RTC PCF8563
- PMIC AXP202
- acelerômetro BMA423
- motor de vibração
- microfone digital PDM SPM1423 (DATA GPIO2, CLK GPIO0)

O firmware principal continua sem dependência de `ESP32_BLE_Arduino`, `NimBLE-Arduino` ou `ArduinoBLE`.

## Design LCARS vestível

A tela inicial foi simplificada para quatro ações grandes, adequadas ao uso no pulso:

```text
FALAR        CASA
ATIVIDADE    STATUS
```

O rodapé mostra `CONFIG` e o estado do celular. A linguagem visual mantém LCARS claro, alto contraste, áreas grandes de toque e informação curta.

## Voz / IA — função principal

`FALAR` abre a tela dedicada de voz. O relógio inicializa o microfone PDM nativo e mede/captura atividade de voz a 16 kHz. O fluxo visual possui estados `TOQUE PARA FALAR`, `OUVINDO`, `VOZ DETECTADA` e falha/ausência de voz.

A arquitetura prevista é:

```text
microfone T-Watch -> detecção/captura -> celular -> STT/IA JARVIS -> resposta
```

Nesta revisão a captura/detecção local do microfone foi implementada. A transmissão de áudio e o reconhecimento STT completo dependem da reativação do transporte Watch <-> Android. O firmware não finge reconhecimento local quando o gateway não está disponível.

## Detecção do celular

A interface de transporte `jarvis_ble` permanece desacoplada. A UI já diferencia `CELULAR OK`, `SEM CELULAR`, `CELULAR DETECTADO` e `CELULAR NAO DETECTADO`. O Android já possui o GATT server `JARVIS-PHONE`; a próxima camada é religar o transporte no relógio sem voltar à biblioteca BLE conflitante.

## Economia de energia

Há três perfis persistentes:

- `NORMAL`: resposta e brilho normais;
- `ECO`: padrão do relógio, reduz brilho e frequência do loop;
- `ULTRA`: brilho limitado, timeout agressivo e entrada em deep sleep após inatividade.

O backlight é desligado no timeout. Em `ULTRA`, depois de um período apagado o ESP32 entra em deep sleep e utiliza o BMA423/INT GPIO39 como fonte de wake-up. A opção `PULSO ON/OFF` controla a preparação dos recursos de movimento para acordar o relógio.

## Funções de relógio

- hora e data via RTC;
- ajuste de hora/minuto/dia/mês pelo touch;
- bateria e carga;
- brilho configurável;
- vibração configurável;
- timeout de tela;
- wake por movimento/pulso preparado pelo BMA423;
- contador de passos BMA423;
- meta visual de 6.000 passos;
- uptime e diagnóstico;
- configurações persistentes em `Preferences`;
- atalhos da casa e cenas;
- interface de voz prioritária.

## Segurança

O relógio não armazena senha Wi-Fi, token mestre da CASA, credenciais de banco ou chave RunPod. A inferência remota deve passar pelo aplicativo/gateway autorizado.

## Estado

| Recurso | Estado |
|---|---|
| LCARS touch | Implementado |
| RTC / bateria | Implementado |
| Perfis NORMAL/ECO/ULTRA | Implementado |
| Timeout/backlight | Implementado |
| Deep sleep | Implementado |
| BMA423 / passos | Implementado |
| Wake por movimento | Preparado/necessita validação física |
| Microfone PDM | Implementado/necessita validação física |
| Detecção de atividade de voz | Implementado |
| STT/IA por voz | Fluxo preparado; transporte Android pendente |
| Detecção visual do celular | Integrada à interface de transporte |
| BLE Watch -> Android | Transporte do relógio ainda desativado |
| Controles CASA | UI pronta; depende do transporte |

> O firmware precisa ser compilado e validado no T-Watch físico. Em especial, PDM, wake-up por BMA423 e consumo em deep sleep precisam de teste no hardware antes de serem considerados validados.
