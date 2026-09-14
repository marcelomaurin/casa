# JARVIS Watch — LILYGO T-Watch 2020 V3

O firmware passa a tratar o T-Watch como um smartwatch JARVIS completo, com mostradores, launcher de aplicativos, voz, alarme, atividade, automação residencial e integração com o celular.

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

O T-Watch 2020 V3 não possui câmera nem receptor GPS integrados. Por isso essas duas funções são implementadas como extensão do celular pareado.

## Navegação

Na tela do relógio:

```text
esquerda / direita  -> troca de skin
para cima            -> abre aplicativos
```

No launcher:

```text
para baixo           -> volta ao mostrador
para cima             -> configurações
```

O firmware separa tap de swipe para não disparar botões durante um gesto.

## Skins

- CLASSIC
- ANA-DIGI
- AVIATION

O skin escolhido é persistido em `Preferences`.

## Launcher

O launcher possui:

```text
VOZ     ALM     CAM     GPS
CASA    PASSOS  STATUS  CONFIG
```

### Alarme

Funciona localmente no relógio, sem celular.

- hora e minuto configuráveis pelo touch;
- ligar/desligar;
- configuração persistente;
- usa o RTC local;
- acorda a tela e aciona vibração quando dispara.

### Voz / JARVIS

O microfone PDM do T-Watch é usado para detectar/capturar atividade de voz.

Fluxo planejado:

```text
T-Watch -> celular -> STT -> JARVIS/IA -> resposta
```

A interface já possui os estados de escuta e detecção de voz. O reconhecimento completo depende da reativação do transporte Watch <-> Android.

### Câmera

Funciona como controle remoto da câmera do celular.

O relógio oferece a ação `FOTOGRAFAR`. Quando o transporte Android estiver ativo, o comando `camera_capture` será enviado ao telefone.

Não há câmera física no T-Watch 2020 V3.

### GPS

A localização é obtida pelo celular pareado.

O relógio envia `gps_request` para solicitar a posição ao telefone. A UI diferencia claramente ausência do celular e solicitação de GPS.

Não há receptor GPS físico integrado ao T-Watch 2020 V3.

### CASA

Atalhos para iluminação, portão e cenas da residência.

### Atividade

- contador de passos pelo BMA423;
- meta diária;
- barra de progresso.

### Status

- bateria;
- celular;
- modo de energia;
- uptime.

## Economia de energia

Perfis:

- NORMAL
- ECO
- ULTRA

O modo ULTRA reduz brilho, aumenta o intervalo do loop, desliga o display por inatividade e pode entrar em deep sleep usando o BMA423 como fonte de wake-up.

## Segurança

O relógio não armazena senha Wi-Fi, token mestre da CASA, credenciais de banco ou chave RunPod. Câmera, GPS e IA remota devem ser acessados pelo gateway Android autorizado.

## Estado atual

| Recurso | Estado |
|---|---|
| Skins | Implementado |
| Swipe horizontal/vertical | Implementado |
| Launcher de apps | Implementado |
| Alarme local | Implementado |
| RTC / bateria | Implementado |
| Passos BMA423 | Implementado |
| Perfis de energia | Implementado |
| Microfone PDM | Implementado, validar no hardware |
| Voz -> IA | Fluxo preparado; transporte Android pendente |
| Câmera remota | UI/comando preparado; transporte Android pendente |
| GPS via celular | UI/comando preparado; transporte Android pendente |
| CASA | UI pronta; transporte pendente |
| BLE Watch -> Android | Transporte do relógio ainda desativado |

> Compile e valide no T-Watch físico. Microfone, deep sleep, wake-up por movimento e sensibilidade dos gestos precisam de teste real no dispositivo.
