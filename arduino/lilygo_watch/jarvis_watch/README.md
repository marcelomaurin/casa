# JARVIS Watch — LILYGO T-Watch 2020 V3

O firmware trata o T-Watch como um relógio JARVIS completo, com prioridade para voz, baixo consumo, interação rápida, skins trocáveis e operação vestível.

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

## Gestos

A navegação agora diferencia toque simples de gesto completo. O comando só é executado depois que o dedo é solto, evitando que um swipe atravesse um botão e dispare a função por acidente.

### Horizontal — troca de skin

Na tela do relógio:

```text
< deslizar para a esquerda/direita >
```

troca entre três mostradores persistentes:

1. `CLASSIC` — analógico escuro, inspirado em cronógrafos clássicos;
2. `ANA-DIGI` — combinação de submostradores analógicos com módulos digitais;
3. `AVIATION` — mostrador técnico com escala e relógio digital central.

O skin escolhido é salvo em `Preferences` e volta automaticamente após reiniciar.

### Vertical

```text
SWIPE PARA CIMA  -> CONFIG / funções adicionais
SWIPE PARA BAIXO -> voltar
```

Quando o usuário está no painel de configuração, deslizar para baixo retorna diretamente ao mostrador. Nas outras telas internas, o swipe para baixo retorna para a tela anterior.

## Tela principal / mostrador

A tela HOME agora é o mostrador propriamente dito, em vez de um menu permanente. Isso deixa o relógio com aparência de relógio convencional durante o uso normal.

No mostrador, um toque abre a interface de voz/IA. Os atalhos e funções continuam disponíveis por navegação e gestos.

## Voz / IA — função principal

A tela `FALAR` inicializa o microfone PDM nativo e mede/captura atividade de voz a 16 kHz. O fluxo visual possui estados `TOQUE PARA FALAR`, `OUVINDO`, `VOZ DETECTADA` e falha/ausência de voz.

Arquitetura:

```text
microfone T-Watch -> detecção/captura -> celular -> STT/IA JARVIS -> resposta
```

A captura/detecção local do microfone está implementada. A transmissão de áudio e o reconhecimento STT completo dependem da reativação do transporte Watch <-> Android.

## Detecção do celular

A interface `jarvis_ble` permanece desacoplada. A UI já diferencia `CELULAR OK`, `SEM CELULAR`, `CELULAR DETECTADO` e `CELULAR NAO DETECTADO`.

## Economia de energia

Há três perfis persistentes:

- `NORMAL`: desempenho e brilho normais;
- `ECO`: padrão recomendado, reduz brilho e atividade do loop;
- `ULTRA`: brilho limitado, timeout agressivo e deep sleep após inatividade.

O backlight é desligado no timeout. Em `ULTRA`, o ESP32 entra em deep sleep e utiliza o BMA423/INT GPIO39 como fonte preparada de wake-up por movimento.

## Funções de smartwatch

- hora e data via RTC;
- três skins de relógio;
- swipe horizontal para skins;
- swipe vertical para configuração/voltar;
- ajuste de hora/minuto/dia/mês;
- bateria e carga;
- brilho configurável;
- vibração configurável;
- timeout de tela;
- wake por movimento/pulso;
- contador de passos BMA423;
- meta visual de 6.000 passos;
- uptime e diagnóstico;
- configurações persistentes;
- atalhos CASA;
- voz/IA como função prioritária.

## Segurança

O relógio não armazena senha Wi-Fi, token mestre da CASA, credenciais de banco ou chave RunPod. A inferência remota deve passar pelo aplicativo/gateway autorizado.

## Estado

| Recurso | Estado |
|---|---|
| Touch + gestos | Implementado |
| Skins persistentes | Implementado |
| Swipe lateral | Implementado |
| Swipe cima/baixo | Implementado |
| RTC / bateria | Implementado |
| Perfis NORMAL/ECO/ULTRA | Implementado |
| Timeout/backlight | Implementado |
| Deep sleep | Implementado |
| BMA423 / passos | Implementado |
| Wake por movimento | Preparado/necessita validação física |
| Microfone PDM | Implementado/necessita validação física |
| Detecção de atividade de voz | Implementado |
| STT/IA por voz | Fluxo preparado; transporte Android pendente |
| BLE Watch -> Android | Transporte do relógio ainda desativado |
| Controles CASA | UI pronta; depende do transporte |

> O firmware precisa ser compilado e validado no T-Watch físico após esta alteração. Em especial, limiar do swipe, PDM e wake-up por BMA423 devem ser ajustados conforme o comportamento real do touch e do hardware.
