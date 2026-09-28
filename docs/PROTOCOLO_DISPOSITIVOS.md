# Protocolo distribuído de dispositivos CASA/JARVIS

Endpoint lógico oficial:

```text
https://maurinsoft.com.br/casa
```

Todos os clientes e nós distribuídos que precisam do sistema central devem usar o domínio oficial. IPs privados, endereços de provedores de IA e URLs de serviços internos não devem ser incorporados aos clientes.

## CASA/1.0

O protocolo lógico dos nós é identificado como `CASA/1.0`.

O Control Plane usa a API v1, com `COMMANDS -> device_commands` e `EVENTS -> device_events`. Comandos representam solicitações de execução. Eventos representam fatos observados pelo nó.

## Identidade mínima

Cada nó possui `device_id` único, `device_token` exclusivo e revogável, capacidades, estado/heartbeat e versão de firmware/software. Tokens reais não pertencem ao Git.

## Provisionamento Wi-Fi de ESP32 e ESP-01

Esta regra é específica para **ESP32 e ESP-01**. Ela não deve ser aplicada automaticamente ao Watch ou a outros tipos de dispositivo.

Um ESP32 ou ESP-01 novo, sem rede válida ou que não consiga mais conectar à rede configurada deve entrar em **modo Access Point (AP)** e disponibilizar sua própria rede Wi-Fi temporária de configuração.

Fluxo obrigatório:

```text
ESP32 / ESP-01
      |
      | cria Wi-Fi próprio (AP de configuração)
      v
JARVIS Mobile conecta ao AP
      |
      | envia SSID + senha da rede definitiva + URL CASA
      v
ESP salva configuração e muda para STA
      |
      +-- conexão falhou --> volta ao AP de configuração
      |
      +-- conexão OK ------> inicia pareamento com CASA
                                  |
                                  v
                         Mobile autoriza o device
                                  |
                                  v
                         CASA gera device_id/token
                                  |
                                  v
                         device passa a operar
```

Regras de recuperação:

1. O AP de configuração existe para garantir que o equipamento possa sempre ser reconfigurado localmente.
2. Credenciais Wi-Fi inválidas não podem deixar o ESP inacessível: após falha de conexão ele retorna ao AP.
3. O celular fornece no AP somente os parâmetros necessários para o ESP alcançar a rede e o CASA; ele não deve fabricar `device_id` nem `device_token` operacional.
4. Depois de alcançar a rede, o próprio ESP solicita pareamento ao CASA e aguarda autorização no Mobile.
5. O servidor CASA é responsável por gerar a identidade e a credencial permanente do device após a aprovação.
6. O Watch fica explicitamente fora deste mecanismo de AP: ele usa sua própria interface/fluxo de configuração.

## API universal de device

- `POST /api/v1/device.php?acao=heartbeat`
- `GET /api/v1/device.php?acao=commands&device_id=<DEVICE_ID>`
- `POST /api/v1/device.php?acao=command_ack`
- `POST /api/v1/device.php?acao=command_start`
- `POST /api/v1/device.php?acao=command_result`
- `POST /api/v1/device.php?acao=event`

Controladores enfileiram operações através de `POST /api/v1/control.php?acao=enqueue`.

## Capacidades

Exemplos: `gateway`, `gpio`, `relay`, `mqtt`, `serial`, `rs485`, `modbus`, `ble`, `wifi`, `http`, `camera`, `vision`, `microphone`, `speaker`, `voice`, `tts`, `stt`, `llm`, `scheduler`, `temperature`, `humidity`, `gps`, `telemetry`, `notification`, `discovery`, `media.cast`.

A IA pode interpretar a intenção do usuário, mas a execução física deve ser resolvida deterministicamente pelo registro de dispositivos, suas capacidades e adapters autorizados.

## Watch

O Watch não usa o AP de configuração definido para ESP32/ESP-01. O relógio mantém seu fluxo próprio de configuração e, depois de provisionado/autorizado, possui identidade própria e comunica-se diretamente com a CASA quando há Wi-Fi disponível.

## Control Plane x Data Plane

A CASA transporta comandos, estados e metadados. Fluxos grandes de mídia devem preferencialmente permanecer na rede local.

## Resiliência

Nós podem manter automações essenciais localmente quando a Internet estiver indisponível e sincronizar eventos e estados quando a conexão retornar. Comandos devem possuir TTL e nunca ser executados indefinidamente depois de expirados.

## Regras de segurança

1. HTTPS obrigatório fora da LAN.
2. Token individual por nó; nunca compartilhar token mestre.
3. Não gravar senha de Wi-Fi ou token real no repositório.
4. MySQL/MariaDB não é acessado diretamente pelos devices; devices usam a API.
5. Cada token deve possuir escopo compatível com as capacidades do nó.
6. O servidor deve validar tamanho de payload, origem lógica, escopo e rate limit.
7. Certificados TLS devem ser validados pelos clientes.
8. Comandos físicos devem ser limitados a adapters conhecidos.
9. Serviços de IA e credenciais de provedores não devem ficar expostos em Mobile, TV, Watch ou firmware.
10. Comandos críticos devem produzir ACK, START, resultado e trilha de auditoria.

## Estado atual da migração

- Android Mobile: CASA API v1 e autorização de novos devices.
- Android TV: CASA API v1.
- Raspberry/ARM Agent: identidade, token e capacidades configuráveis.
- ESP32/ESP-01: devem usar AP local para configuração de rede e depois pareamento CASA.
- LilyGo Watch: fluxo próprio; fora do AP ESP32/ESP-01.
- ESP32-CAM: como membro da família ESP32, deve usar o fluxo de AP local e depois identidade/API universal de devices.
