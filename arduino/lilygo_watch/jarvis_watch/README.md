# JARVIS Watch — LILYGO T-Watch 2020 V3

[![UI](https://img.shields.io/badge/UI-LCARS-orange.svg)]()
[![Touch](https://img.shields.io/badge/touch-enabled-green.svg)]()
[![Mode](https://img.shields.io/badge/mode-standalone-yellow.svg)]()

## Visão geral

O **JARVIS Watch** é o cliente vestível do projeto CASA/JARVIS. Nesta etapa o firmware foi reorganizado para funcionar de forma confiável no T-Watch sem depender de bibliotecas BLE externas.

A interface atual é totalmente orientada a toque e usa um layout LCARS claro, com alto contraste para leitura em ambientes iluminados.

## Hardware alvo

```text
LILYGO T-Watch 2020 V3
Placa Arduino: ESP32 Dev Module
ESP32 by Espressif Systems: 2.0.14
```

Bibliotecas utilizadas nesta etapa:

```text
TTGO_TWatch_Library / LilyGoWatch
Preferences (incluída no core ESP32)
```

Não é necessário instalar:

```text
ESP32_BLE_Arduino
NimBLE-Arduino
ArduinoBLE
```

## Interface por toque

A tela inicial possui quatro áreas funcionais:

```text
CASA       SENSORES
JARVIS     STATUS
```

O segmento inferior direito abre o painel `CONFIG`.

### CASA

Painel preparado para ações rápidas:

- Luz da sala
- Luz do quarto
- Portão
- Cena noite

Enquanto a comunicação externa estiver desativada, os botões continuam funcionando como interface e retornam estado `offline` em vez de travar o sistema.

### SENSORES

Exibe informações locais disponíveis no relógio:

- nível da bateria;
- estado de carga;
- estado da comunicação;
- indicação de telemetria remota.

### JARVIS

Tela de comandos rápidos preparada para:

- status da casa;
- estado das luzes;
- temperatura;
- ajuda.

### STATUS

Mostra diagnóstico local:

- T-Watch ativo;
- touch ativo;
- bateria;
- uptime.

## Painel de configuração

A nova tela `CONFIG` permite alterar parâmetros diretamente pelo touch.

### Brilho

Botões `-` e `+` ajustam a iluminação usando `TTGOClass::setBrightness()`.

O valor é gravado na memória persistente do ESP32 através de `Preferences`.

### Vibração

Pode ser ligada ou desligada pelo usuário.

A configuração também é persistida.

### Timeout da tela

Modos disponíveis:

```text
15 s
30 s
60 s
NUNCA
```

Quando o timeout é atingido, o backlight é desligado. Um novo toque acorda a tela sem executar acidentalmente o comando que estava sob o dedo.

### Ajuste do relógio

A tela `RELOGIO` permite ajustar diretamente o RTC:

- hora -;
- hora +;
- minuto -;
- minuto +;
- dia +;
- mês +.

O ajuste usa o RTC interno do T-Watch.

### Restaurar padrão

O botão `PADRAO` retorna para:

```text
Brilho: 180
Vibração: ligada
Timeout: 30 segundos
```

## Navegação

As telas internas possuem botões inferiores:

```text
VOLTAR
HOME
```

Todos os toques válidos podem gerar feedback por vibração, quando esta opção estiver habilitada.

## Estrutura do firmware

```text
jarvis_watch.ino   interface, touch, RTC, bateria e configurações
config.h           configuração do hardware e constantes do projeto
jarvis_ble.h       interface abstrata de comunicação
jarvis_ble.cpp     implementação standalone atual
```

O arquivo principal não inclui nenhuma biblioteca Bluetooth.

## Comunicação externa

A camada externa foi propositalmente desacoplada.

Nesta versão:

```text
Watch UI -> jarvis_ble interface -> standalone/offline
```

Na próxima etapa poderemos implementar novamente:

```text
Watch -> Android -> HTTPS -> CASA/JARVIS
```

sem alterar toda a interface gráfica.

Os UUIDs BLE anteriores permanecem reservados em `config.h` para futura compatibilidade com o aplicativo Android.

## Segurança

O relógio não deve armazenar:

```text
SSID da residência
senha Wi-Fi
token mestre da API
credenciais de banco de dados
chaves RunPod
segredos de serviços externos
```

A arquitetura continua prevendo que credenciais de infraestrutura fiquem fora do firmware do relógio.

## Estado atual

| Recurso | Estado |
|---|---|
| Interface LCARS | Implementado |
| Touch screen | Implementado |
| Navegação entre telas | Implementado |
| Bateria | Implementado |
| RTC / hora e data | Implementado |
| Ajuste de horário | Implementado |
| Controle de brilho | Implementado |
| Vibração configurável | Implementado |
| Timeout de tela | Implementado |
| Configuração persistente | Implementado |
| Controles da casa | Interface pronta, comunicação pendente |
| JARVIS remoto | Interface pronta, comunicação pendente |
| BLE Watch -> Android | Temporariamente desativado |
| Áudio Watch -> Android | Pendente |

> [!WARNING]
> O firmware precisa ser validado no hardware físico após cada alteração. A compilação confirma compatibilidade de API, mas os limites exatos de brilho, sensibilidade do touch e comportamento de energia devem ser conferidos no T-Watch real.
