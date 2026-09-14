# Família CASA, assistência e JARVIS Watch

## Objetivo

Integrar site, Android e JARVIS Watch em um canal familiar comum, com comunicação, chamadas e alertas de assistência. O recurso foi desenhado para apoio familiar e automação; não substitui serviço de emergência nem dispositivo médico certificado.

## Fluxo de conectividade

```text
JARVIS Watch
   | BLE preferencial
   v
JARVIS Mobile
   | Wi-Fi / 4G / 5G
   v
CASA / canal Família

Fallback:
JARVIS Watch -> melhor Wi-Fi doméstico conhecido -> HTTPS CASA
```

O Android é o BLE central. O Watch é o periférico/GATT server.

## Redes Wi-Fi

O aplicativo provisiona até cinco redes conhecidas no Watch. O relógio faz scan e considera somente SSIDs cadastrados. Entre eles, escolhe o maior RSSI.

A senha Wi-Fi é enviada localmente ao relógio durante provisionamento e fica na NVS do ESP32. Ela não é enviada ao site nem registrada em logs. Para produção, o projeto deve avaliar proteção adicional da NVS/flash e Secure Boot/Flash Encryption quando suportado pela implantação.

O acesso direto ao CASA usa token individual do Watch com escopos mínimos.

## Assistência familiar

Eventos previstos:

- `MOVEMENT_HEARTBEAT`: telemetria periódica de movimento/passos;
- `INACTIVITY`: período configurável sem mudança de passos/movimento;
- `CHECKIN`: confirmação manual de que a pessoa está bem;
- `SOS`: acionamento manual de prioridade crítica.

A ausência de movimento não é classificada automaticamente como queda, desmaio ou emergência médica.

## Canal broadcast

Canal padrão: `familia`.

Participantes:

- navegador/site;
- aplicativo Android;
- JARVIS Watch via Android;
- JARVIS Watch por Wi-Fi de contingência;
- automações CASA/JARVIS.

Rotas:

```text
/casa/familia.php
/casa/api/v1/family.php
```

Tabelas:

```text
family_channels
family_presence
family_messages
family_calls
family_call_signals
assistencia_eventos
```

## Videochamada

O navegador implementa WebRTC e usa o MySQL/PHP apenas para sinalização de `offer`, `answer`, ICE e presença da chamada.

O T-Watch 2020 V3 não possui câmera. Ao iniciar videochamada no relógio, o Android pareado é a câmera e a tela de vídeo. O Watch pode atuar como botão de chamada, controle e fonte de áudio quando o streaming PDM/BLE estiver concluído.

A implantação inicial usa STUN público. Para funcionamento confiável entre redes NAT/firewall diferentes, produção deve usar um servidor TURN próprio ou contratado.

## Segurança e privacidade

- não registrar senha Wi-Fi em logs;
- não armazenar token mestre no relógio;
- não armazenar credenciais de banco em dispositivos;
- usar HTTPS para tráfego externo;
- validar certificado TLS no Watch antes de produção (`setCACert`);
- permitir desativar monitoramento de assistência;
- tornar limiar de inatividade configurável;
- limitar acesso ao canal familiar a usuários/dispositivos autorizados;
- registrar eventos críticos e confirmações.

## Estado de implementação

- banco/API do canal familiar: implementado;
- console web e sinalização WebRTC: implementados;
- cliente Android do canal: implementado (`FamilyApi.kt`);
- Android BLE central: implementado (`WatchClient.kt`), validar em aparelho;
- tela Android de conexão/provisionamento: implementada (`WatchSetupActivity.kt`), validar build;
- serviço Android encaminhando assistência e broadcast: implementado, validar build;
- seleção do melhor Wi-Fi conhecido no Watch: módulo implementado;
- monitor de assistência no Watch: módulo implementado;
- integração desses módulos no `jarvis_watch.ino`: pendente após validação de compilação;
- GATT server BLE no Watch: pendente por causa da incompatibilidade BLE encontrada anteriormente no ambiente Arduino.
