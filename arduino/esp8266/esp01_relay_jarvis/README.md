# ESP-01 Relay Shield - JARVIS CASA

Firmware para ESP-01/ESP8266 com shield de relé de 1 canal.

## Hardware de referência

- ESP-01 / ESP8266
- Shield de relé 1 canal para ESP-01
- `RELAY_PIN = GPIO0`
- acionamento padrão `LOW` (ativo em nível baixo)

Se a revisão da shield usar outro pino ou lógica, altere somente `RELAY_PIN`, `RELAY_ACTIVE_LEVEL` e `RELAY_INACTIVE_LEVEL` no início do sketch.

## Provisionamento

1. Sem Wi-Fi configurado, o módulo cria `CASA-RELE-xxxxxx`.
2. Conecte o celular a essa rede e abra `http://192.168.4.1`.
3. Informe SSID, senha e URL do CASA.
4. O ESP entra na rede e solicita pareamento ao servidor CASA.
5. No JARVIS Mobile, confira modelo/capacidades/código e autorize.
6. O servidor emite a credencial permanente; o ESP grava a credencial localmente.
7. O celular não injeta token nem `device_id`.

## Comandos do Control Plane

- `relay.on` - liga o relé
- `relay.off` - desliga o relé
- `relay.toggle` - inverte o estado
- `relay.status` - retorna o estado sem alterar a saída

Também são aceitos os aliases `relay_on`, `relay_off`, `relay_toggle` e `status`.

O firmware usa o ciclo `command_ack -> command_start -> command_result` e envia heartbeat periódico com estado do relé e RSSI.

## Segurança / fail-safe

O relé inicia desligado. Se a conexão Wi-Fi for perdida, o firmware desliga o relé e retorna ao modo AP de configuração. A credencial operacional só é obtida após autorização do pareamento no Mobile.
