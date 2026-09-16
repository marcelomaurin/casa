# Arquitetura CASA/JARVIS — Distribuída

Este documento define a arquitetura distribuída do CASA/JARVIS.

## Princípio central

Todos os componentes do ecossistema usam como ponto comum de entrada:

```text
https://casa.maurinsoft.com.br
```

Não existe um mestre local fixo baseado em IP privado. Cada componente é um nó especializado e pode executar em Hostinger, Raspberry Pi, servidor de IA, celular, TV, relógio, ESP32/ESP8266 ou outro equipamento.

A API pública mantém identidade, autenticação, estado, filas, descoberta lógica, telemetria e coordenação. A execução permanece distribuída.

```text
                         +---------------------------+
                         | casa.maurinsoft.com.br    |
                         | Site LCARS + API + MySQL  |
                         +-------------+-------------+
                                       |
                              HTTPS autenticado
                                       |
            +--------------------------+---------------------------+
            |                          |                           |
       Raspberry Pi               Android/TV                 Servidor IA
       automação física            clientes                    GPU/LLM/TTS
            |                          |                           |
      GPIO/MQTT/RS485             BLE/voz/UI                  inferência
            |
       ESP32/ESP8266
```

## HOSTINGER

A Hostinger mantém o núcleo público e persistente:

```text
site/var/www/html/
```

Inclui:

- site LCARS;
- login e autenticação;
- API pública `/api/v1/`;
- cadastro e identidade dos dispositivos;
- filas de comandos e eventos;
- descoberta lógica dos serviços disponíveis;
- telemetria e histórico;
- notificações;
- MySQL/MariaDB;
- configuração de roteamento entre nós.

A Hostinger não executa obrigatoriamente IA, TTS, GPIO ou processamento pesado. Ela coordena os nós que anunciam essas capacidades.

## NÓS DISTRIBUÍDOS

Todo nó deve possuir:

- identificador próprio;
- token próprio e escopos mínimos;
- tipo/capacidades declaradas;
- heartbeat;
- estado online/offline;
- versão do software;
- fila de trabalho ou mecanismo de polling quando aplicável;
- reconexão automática;
- comunicação HTTPS com `casa.maurinsoft.com.br`.

Exemplos de capacidades:

```text
gpio
mqtt
rs485
camera
microphone
speaker
tts
stt
llm
vision
gps
bluetooth
notification
scheduler
```

## RASPBERRY PI

Os Raspberry Pi são nós de execução física e gateways de hardware.

Serviço-base:

```text
servicos/arm-agent/
```

Responsabilidades:

- registrar o nó na API central;
- enviar heartbeat;
- publicar recursos disponíveis;
- receber/buscar tarefas destinadas ao nó;
- executar somente tarefas autorizadas;
- publicar resultados;
- operar hardware local;
- manter uma fila local temporária em falhas de Internet;
- sincronizar novamente ao reconectar.

Serviços que podem rodar nos Raspberry:

| Serviço | Uso |
|---|---|
| `servicos/arm-agent/` | agente principal do nó |
| `servicos/casa-scheduler/` | execução distribuída de agendamentos |
| `servicos/espcam/` | câmera/visão local |
| `servicos/tts/` | síntese local |
| `servicos/web-agent/` | agente de pesquisa/navegação |
| `servicos/casa-tunnel/` | conectividade auxiliar quando necessária |

## SERVIDOR DE IA

O servidor de IA é apenas outro nó distribuído.

Pode anunciar capacidades como:

```text
llm
tts
stt
vision
embedding
rag
```

Fluxo típico:

```text
cliente -> casa.maurinsoft.com.br -> fila/roteamento -> nó IA
                                             |
                                             +-> resultado -> API -> cliente
```

O endereço privado do servidor de IA não deve ficar embutido nos clientes.

## ESP32 / ESP8266 / IoT

Dispositivos capazes de HTTPS podem publicar diretamente em `casa.maurinsoft.com.br` usando token individual.

Dispositivos limitados podem usar um Raspberry como gateway, mas continuam pertencendo à mesma arquitetura distribuída e devem possuir identidade lógica própria.

Credenciais Wi-Fi e tokens não devem ser versionados.

## ANDROID MOBILE / TV

O servidor padrão dos aplicativos é:

```text
https://casa.maurinsoft.com.br
```

Cada instalação recebe token próprio. Os aplicativos não devem depender de IP privado da residência.

## RELÓGIO

O BLE é usado principalmente no provisionamento e recuperação. Depois de provisionado, o relógio possui identidade própria e comunica-se diretamente com a CASA por Wi-Fi/HTTPS quando disponível.

```text
Provisionamento:
Watch -> BLE -> JARVIS Mobile -> CASA

Operação normal:
Watch -> Wi-Fi/HTTPS -> https://casa.maurinsoft.com.br
```

O celular continua podendo fornecer capacidades próprias, como GPS, câmera e notificações, através do Command Bus.

## COMMAND / EVENT BUS

A integração entre os nós utiliza um barramento único:

```text
Controladores -> /api/v1/control.php -> device_commands
Devices       -> /api/v1/device.php  -> ACK/resultados/eventos
```

Comandos representam ações solicitadas. Eventos representam fatos observados. O roteamento deve usar `device_id`, `capabilities`, `gateway_device_id`, prioridade, TTL e `correlation_id`.

Protocolos específicos ficam em adapters executados pelos gateways; o núcleo JARVIS não deve controlar hardware diretamente.

## CONTROL PLANE E DATA PLANE

A API central transporta comandos, estado e metadados. Vídeo, áudio e outros fluxos pesados devem permanecer preferencialmente na LAN entre origem e destino, com a CASA apenas coordenando a sessão.

## FLUXO DISTRIBUÍDO

Exemplo: ligar uma luz.

```text
Celular
   |
   v
casa.maurinsoft.com.br/api/v1
   |
   v
fila/roteamento
   |
   v
Raspberry responsável pelo ambiente
   |
   v
relé / ESP / dispositivo
   |
   v
resultado -> API -> histórico/cliente
```

Exemplo: pergunta para IA.

```text
Celular/TV/Web
      |
      v
CASA API
      |
      v
nó IA disponível
      |
      v
resultado
      |
      v
CASA API -> cliente
```

## Operação com perda de conexão

A arquitetura centralizada logicamente no domínio não significa dependência total de Internet para funções críticas.

Nós locais podem manter regras essenciais em cache, por exemplo:

- alarmes;
- automação de segurança;
- controle local manual;
- timers;
- sensores;
- watchdogs.

Quando a conexão volta, o nó sincroniza eventos e estados.

## Segurança

- HTTPS obrigatório nas comunicações externas;
- token exclusivo por dispositivo/nó;
- escopos de menor privilégio;
- tokens nunca no Git;
- MySQL não é acessado diretamente pelos nós;
- toda persistência externa passa pela API;
- falhas de autenticação devem ser auditadas;
- limites de payload e rate limiting devem ser aplicados;
- serviços locais não devem ser expostos diretamente à Internet sem necessidade.

## Implantação Hostinger

Somente o conteúdo web/API é publicado no diretório público da hospedagem. Os nós distribuídos permanecem em seus equipamentos, mas todos utilizam o domínio central para coordenação.

Configuração de produção esperada:

```text
JARVIS_DB_HOST
JARVIS_DB_PORT=3306
JARVIS_DB_NAME
JARVIS_DB_USER
JARVIS_DB_PASS
JARVIS_DB_CHARSET=utf8mb4
JARVIS_SYSTEM_API_TOKEN
```

## Fonte oficial

O GitHub é a fonte oficial do projeto. Alterações de arquitetura, clientes, firmwares e serviços devem ser versionadas antes da implantação.
