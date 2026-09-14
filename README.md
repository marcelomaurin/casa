# CASA — JARVIS Residential AI & IoT Platform

[![Platform](https://img.shields.io/badge/platform-Linux%20%7C%20Android%20%7C%20ESP32%20%7C%20ESP8266-blue.svg)]()
[![Backend](https://img.shields.io/badge/backend-PHP%20%7C%20Python-orange.svg)]()
[![Database](https://img.shields.io/badge/database-PostgreSQL-blue.svg)]()
[![Mobile](https://img.shields.io/badge/mobile-Android%20Kotlin-green.svg)]()
[![Status](https://img.shields.io/badge/status-Experimental-yellow.svg)]()

## Visão geral

**CASA / JARVIS** é uma plataforma residencial experimental de automação, inteligência artificial e IoT. O projeto integra servidor Linux, APIs PHP, serviços Python, PostgreSQL, aplicativo Android, ESP32/ESP8266, câmeras, voz, sensores e agentes de IA em uma arquitetura única.

O objetivo é permitir que o JARVIS receba comandos em linguagem natural, consulte informações da residência, converse por voz, controle dispositivos, receba eventos de sensores e câmeras e execute tarefas imediatas ou planejadas.

> [!WARNING]
> O projeto controla e observa recursos físicos reais. Uma compilação bem-sucedida não comprova automaticamente funcionamento de hardware, rede, câmera, áudio, serviços externos ou dispositivos instalados. Valide cada integração no ambiente real antes de utilizá-la para segurança ou automação crítica.

## Estado atual

| Área | Implementação | Estado |
|---|---|---|
| API JARVIS | PHP + API REST | Em desenvolvimento |
| API externa | `/api/v1` com autenticação e escopos | Em desenvolvimento |
| Banco | PostgreSQL | Ativo |
| IA | Modelos locais e serviços configuráveis | Em desenvolvimento |
| Planejador | Divisão de demandas em múltiplas tarefas | Experimental |
| Web Agent | Pesquisa e coleta de conteúdo web | Experimental |
| TTS | Serviço Python/FastAPI | Experimental |
| Android | Kotlin / Jetpack Compose | Experimental |
| ESP32-CAM | Captura e envio de imagens | Experimental |
| ESP8266/ESP-01 | Sensores ambientais | Experimental |
| LILYGO Watch | Backend/API preparado | Firmware pendente |
| BLE | Ponte Android ↔ relógio | Experimental |

## Arquitetura

```text
                       INTERNET
                           |
                       HTTPS/API
                           |
                    +------v------+
                    |   API v1    |
                    +------+------+ 
                           |
              +------------+------------+
              |                         |
        +-----v------+            +-----v------+
        |   JARVIS   |            | Planejador |
        |     IA     |            |  / Agentes |
        +-----+------+            +------------+
              |
     +--------+---------+----------------+
     |                  |                |
+----v----+        +----v----+      +----v----+
|PostgreSQL|       | TTS/STT |      |Web Agent|
+---------+        +---------+      +---------+
     |
     +----------------------+-----------------------+
                            |
          +-----------------+-----------------+
          |                 |                 |
     +----v----+       +----v----+       +----v----+
     | Android |       | ESP32   |       | ESP8266 |
     | Mobile  |       | Camera  |       | Sensores|
     +----+----+       +---------+       +---------+
          |
          | BLE
     +----v----+
     |  Watch  |
     +---------+
```

## Estrutura do repositório

| Diretório | Finalidade |
|---|---|
| `android/` | Aplicativos Android, incluindo JARVIS Mobile |
| `apis/` | Integrações e recursos relacionados a APIs |
| `arduino/` | Firmwares ESP32, ESP8266 e outros microcontroladores |
| `bin/` | Binários gerados e artefatos distribuíveis |
| `database/` | Estrutura e migrations PostgreSQL |
| `docs/` | Documentação técnica e instalação |
| `mysql/` | Estruturas legadas/auxiliares MySQL |
| `nextion/` | Interfaces e recursos Nextion |
| `servicos/` | Serviços Python e processos auxiliares |
| `site/` | Aplicação web e APIs PHP |
| `srv/` | Recursos de servidor |

## Componentes principais

### JARVIS

O JARVIS é o núcleo lógico do projeto. Recebe solicitações, utiliza IA local ou configurada, integra serviços internos e pode encaminhar demandas para planejamento, pesquisa ou automação.

### Planejador

O planejador permite decompor uma demanda em múltiplas tarefas. Uma tarefa pode ser imediata, agendada ou condicional, podendo possuir dependências e estado de execução.

### API externa

O acesso externo deve ocorrer por HTTPS através da API versionada:

```text
https://<HOST_PUBLICO>/api/v1
```

A API utiliza autenticação e foi estruturada para suportar tokens individuais, escopos, rate limiting, limites de payload e registros de segurança.

> [!IMPORTANT]
> Nunca grave tokens reais, senhas, credenciais Wi-Fi, chaves privadas ou credenciais de serviços externos no repositório.

### JARVIS Mobile

O aplicativo Android está em:

```text
android/JarvisMobile/
```

Principais objetivos:

- interface de operações manuais;
- interface de voz;
- configuração da API externa;
- funcionamento tolerante à perda de conexão;
- fila local de comandos durante indisponibilidade;
- reconexão automática;
- notificações do JARVIS;
- ponte BLE com dispositivos vestíveis.

APK de desenvolvimento versionado:

```text
bin/android/JarvisMobile-v2.1.0-debug.apk
```

Consulte `android/JarvisMobile/VERSIONAMENTO.md` para as regras de versão.

### ESP32-CAM

O firmware de câmera permite integrar módulos ESP32-CAM ao JARVIS, incluindo provisionamento Wi-Fi e envio de imagens para processamento no servidor.

A análise facial implementada deve ser entendida como **detecção de faces**, não como identificação biométrica de pessoas.

### Sensores ESP8266 / ESP-01

O projeto contém firmware para envio de temperatura e umidade ao servidor, utilizando autenticação individual por dispositivo.

### Voz

A arquitetura possui serviços de síntese e reconhecimento de voz. Serviços internos devem permanecer protegidos e, quando possível, acessíveis somente pelo servidor principal.

## Segurança

A residência não deve depender apenas de ocultação de endereço ou de um único token compartilhado. O desenho recomendado utiliza múltiplas camadas:

- HTTPS para acesso externo;
- token individual por cliente/dispositivo;
- armazenamento seguro de segredos;
- escopos de autorização;
- rate limiting;
- bloqueio temporário de abuso;
- limite de payload;
- logs de segurança;
- firewall;
- serviços internos não expostos diretamente à Internet.

A migration de hardening da API está em:

```text
database/api_v1_security.sql
```

A orientação para instalação automatizada está em:

```text
docs/INSTALACAO_BOT.md
```

## Regra para informações sensíveis

Não devem ser versionados:

```text
.env preenchido
senhas
credenciais de banco
tokens reais
Authorization Bearer real
SSID e senha Wi-Fi
chaves de APIs externas
service-account JSON
chaves privadas
cookies/sessões
backups contendo dados privados
```

Use placeholders nos exemplos e forneça os valores reais apenas no ambiente de implantação.

## Banco de dados

O projeto utiliza PostgreSQL como banco principal das funcionalidades atuais. As migrations ficam em:

```text
database/
```

Entre as estruturas adicionadas ao projeto estão dados de sensores, eventos de câmera, notificações de dispositivos móveis, planejamento de tarefas, pesquisas de agentes e segurança da API.

Antes de aplicar migrations em uma instalação existente, faça backup e verifique quais alterações já foram aplicadas.

## Instalação

A instalação completa depende dos módulos que serão utilizados. O bot/agente instalador deve seguir:

```text
docs/INSTALACAO_BOT.md
```

O procedimento deve detectar componentes existentes, evitar alterações destrutivas, solicitar somente os segredos necessários no ambiente de implantação e validar cada serviço após a instalação.

## Desenvolvimento Android

Projeto:

```text
android/JarvisMobile/
```

A compilação automatizada utiliza GitHub Actions. Builds de desenvolvimento usam um identificador separado da aplicação oficial para evitar conflitos de assinatura durante os testes.

Controle de versão:

```text
MAJOR.MINOR.PATCH
```

O `versionCode` deve sempre crescer a cada versão distribuída.

## Limitações atuais

- diversas integrações ainda dependem de validação no hardware real;
- o firmware completo do relógio ainda não está concluído;
- agentes e planejamento continuam experimentais;
- builds Android debug não substituem uma distribuição oficial assinada com keystore permanente;
- disponibilidade do JARVIS depende dos serviços configurados no servidor;
- comandos físicos enfileirados offline somente podem ser confirmados depois que o servidor responder.

## Filosofia do projeto

O projeto prioriza integração real. Recursos simulados, respostas inventadas e estados fictícios não devem ser utilizados para representar hardware ou serviços como funcionais.

Quando uma integração não estiver disponível, o sistema deve informar a indisponibilidade e preservar a operação possível, em vez de fabricar uma resposta.

## Autor

**Marcelo Maurin Martins**

Projeto desenvolvido como plataforma experimental de automação residencial, IoT e inteligência artificial.

## Documentação

Documentos técnicos adicionais estão disponíveis em:

```text
docs/
```

A documentação deve acompanhar a evolução do código e distinguir claramente recursos funcionais, experimentais e ainda pendentes.
