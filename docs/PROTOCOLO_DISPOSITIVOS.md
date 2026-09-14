# Protocolo distribuído de dispositivos CASA/JARVIS

Endpoint lógico oficial:

```text
https://casa.maurinsoft.com.br
```

Todos os clientes e nós distribuídos que precisam do sistema central devem usar o domínio, nunca um IP residencial fixo.

## Identidade mínima

Cada nó possui:

- `device_id` único;
- `device_token` exclusivo e revogável;
- lista de `capabilities`;
- estado/heartbeat;
- versão de firmware/software quando disponível.

Cabeçalhos recomendados:

```http
Authorization: Bearer <TOKEN_INDIVIDUAL>
X-Device-Token: <TOKEN_INDIVIDUAL>
X-Device-Id: <DEVICE_ID>
X-Device-Capabilities: gpio,mqtt,serial,rs485,ble,scheduler
```

Tokens reais não pertencem ao Git.

## Capacidades

Exemplos padronizados:

- `gpio`
- `relay`
- `mqtt`
- `serial`
- `rs485`
- `modbus`
- `ble`
- `camera`
- `vision`
- `microphone`
- `speaker`
- `voice`
- `tts`
- `stt`
- `llm`
- `scheduler`
- `temperature`
- `humidity`
- `gps`
- `telemetry`

## Topologia

```text
                    casa.maurinsoft.com.br
                         API + MySQL
                              |
             +----------------+----------------+
             |                |                |
        Raspberry          Servidor IA     Android/TV
        GPIO/MQTT          LLM/TTS/STT      clientes
             |
        ESP32/ESP8266
        sensores/reles
```

O domínio funciona como coordenador, identidade, persistência e troca de mensagens. O processamento e o controle físico permanecem distribuídos.

## Resiliência

Os nós podem manter automações essenciais localmente quando a Internet estiver indisponível e sincronizar estado/eventos quando a conexão retornar.

## Regras de segurança

1. HTTPS obrigatório fora da LAN.
2. Token individual por nó; nunca compartilhar token mestre.
3. Não gravar senha de Wi-Fi ou token real no repositório.
4. MySQL não é acessado diretamente pelos devices; devices usam a API.
5. Cada token deve possuir escopo compatível com as capacidades do nó.
6. O servidor deve validar tamanho de payload, origem lógica, escopo e rate limit.
7. Certificados TLS devem ser validados pelos clientes sempre que o firmware/plataforma permitir. `setInsecure()` nos protótipos ESP é compatibilidade transitória e deve ser substituído por CA confiável/provisionada antes de considerar o firmware endurecido para produção.

## Estado atual da migração

- Android Mobile: domínio CASA como padrão.
- Android TV: domínio CASA como padrão.
- Raspberry/ARM Agent: domínio CASA, identidade, token e capacidades configuráveis.
- ESP-01 DHT: domínio CASA, identidade e token individual.
- ESP32 Voice: domínio CASA, identidade e capacidades.
- ESP8266 Cadeira: removidos IP e credenciais reais; comandos via domínio CASA.
- ESP8266 Piscina: removidos IP e credenciais reais; heartbeat via domínio CASA.
- LilyGo Watch: usa o Android como gateway BLE; não precisa conhecer URL/token da casa.
- ESP32-CAM: deve usar o mesmo domínio e token individual; firmware legado ainda exige migração completa de TLS/configuração sem alterar a lógica de câmera/BLE.
