# JARVIS Residencial — Guia de Instalação para Agente Automatizado

## Objetivo

Este documento orienta um agente/bot de instalação a preparar o JARVIS Residencial e seus componentes sem depender de informações sensíveis armazenadas no repositório.

> **Regra obrigatória:** nunca gravar senhas, tokens, chaves de API, cookies, certificados privados, credenciais de banco, credenciais Wi-Fi ou URLs privadas contendo segredos no Git, logs, documentação ou código-fonte.

## Princípios de segurança

1. Considere todo segredo fornecido durante a instalação como confidencial.
2. Nunca mostre o valor completo de um segredo em console ou log. Quando necessário, informe apenas que ele está configurado.
3. Nunca faça commit de arquivos `.env`, credenciais, chaves privadas ou arquivos de configuração preenchidos com segredos.
4. Use placeholders como `<DB_PASSWORD>`, `<API_TOKEN>`, `<PUBLIC_API_URL>` e `<WIFI_PASSWORD>` na documentação e nos exemplos.
5. Gere tokens aleatórios no ambiente de instalação. Não use tokens de exemplo em produção.
6. Armazene tokens da API no banco somente na forma de hash quando o componente suportar esse modelo.
7. Para acesso externo, exigir HTTPS. Não orientar exposição direta de PostgreSQL, serviços internos de IA, TTS ou portas administrativas à Internet.
8. Aplique o princípio do menor privilégio: cada dispositivo/app recebe token próprio e somente os escopos necessários.
9. Em caso de dúvida sobre um segredo, peça ao operador para fornecê-lo por mecanismo seguro ou configurá-lo diretamente no servidor. Não invente valores.

## Pré-requisitos

O agente deve verificar, sem alterar destrutivamente uma instalação existente:

- Linux compatível com o projeto;
- Git;
- Apache/PHP conforme os módulos utilizados pelo projeto;
- PostgreSQL;
- Python 3 para os serviços Python;
- acesso ao repositório do projeto;
- Android é opcional no servidor, pois o APK pode ser produzido pelo GitHub Actions;
- acesso HTTPS público configurado pelo operador quando o aplicativo for usado fora da rede local.

O agente deve detectar versões instaladas antes de instalar ou atualizar pacotes.

## Fluxo recomendado

### 1. Obter o projeto

Clonar ou atualizar o repositório em diretório escolhido pelo operador. Antes de atualizar uma instalação existente, verificar alterações locais e preservar arquivos de configuração externos ao Git.

### 2. Configuração de segredos

Os valores abaixo são exemplos de **nomes de configuração**, nunca valores reais:

```text
DB_HOST=<DB_HOST>
DB_NAME=<DB_NAME>
DB_USER=<DB_USER>
DB_PASSWORD=<DB_PASSWORD>
PUBLIC_API_URL=https://<HOST_PUBLICO>/api/v1
MOBILE_API_TOKEN=<TOKEN_GERADO_PARA_O_CELULAR>
```

Preferir variáveis de ambiente, arquivos fora da árvore versionada com permissões restritas ou mecanismo de secrets do sistema operacional/orquestrador.

Arquivos contendo segredos devem ter acesso mínimo necessário. Em Linux, quando aplicável, restringir leitura ao usuário do serviço.

### 3. Banco de dados

Antes de executar migrations:

1. confirmar banco e usuário de destino;
2. realizar backup quando a instalação já possuir dados;
3. aplicar somente migrations ainda não aplicadas;
4. não colocar senha na linha de comando quando ela puder aparecer no histórico/process list;
5. verificar sucesso de cada migration antes de continuar.

Para o hardening da API v1, aplicar a migration versionada:

```text
database/api_v1_security.sql
```

O agente deve executar o arquivo usando autenticação segura configurada no ambiente. Não deve substituir placeholders deste documento e fazer commit do resultado.

### 4. API externa

A aplicação Android deve utilizar a API pública HTTPS da instalação, terminando em `/api/v1`.

Exemplo não sensível:

```text
https://<HOST_PUBLICO>/api/v1
```

O agente deve validar:

- HTTPS válido;
- endpoint de status acessível;
- acesso sem token negado;
- token inválido negado;
- token válido aceito somente nos escopos autorizados;
- rate limit funcionando;
- limite de payload funcionando;
- ausência de segredos na URL;
- logs de segurança ativos.

Nunca desabilitar autenticação para “facilitar o teste”.

### 5. Tokens e escopos

Criar um token diferente para cada cliente: Android, relógio, ESP, câmera ou integração externa.

Para o aplicativo Android, conceder somente os escopos efetivamente necessários, por exemplo:

```text
status.read
jarvis.command
mobile.read
mobile.write
devices.read
sensors.read
```

Não reutilizar token administrativo ou chave mestre no APK.

O valor bruto do token deve ser entregue ao dispositivo apenas no momento da configuração. Não registrar o token bruto em Git ou log.

### 6. Serviços internos

Serviços internos, como IA local, TTS, STT, processamento de câmera e agentes auxiliares, devem preferencialmente escutar em `127.0.0.1` quando só forem consumidos pelo servidor.

Se um serviço precisar ser acessado por outro host da rede, aplicar firewall e autenticação apropriados. Não expor diretamente esses serviços à Internet quando a API principal puder atuar como gateway.

### 7. Aplicativo Android

O código está em:

```text
android/JarvisMobile/
```

O APK produzido pelo pipeline fica versionado em:

```text
bin/android/JarvisMobile-debug.apk
```

Na primeira configuração do aplicativo, o operador informa:

- URL pública HTTPS da API;
- token individual do celular.

O agente não deve embutir esses valores no código ou gerar APK contendo credenciais reais.

Após configurar, testar:

1. abertura do Splash;
2. tela de Configuração;
3. teste de conexão com `/api/v1`;
4. Operações manuais;
5. interface de Voz;
6. recebimento de notificações;
7. mudança Wi-Fi/rede móvel;
8. comunicação BLE com o relógio, quando disponível.

### 8. Dispositivos IoT

Cada dispositivo deve possuir identidade/token próprios. Firmware versionado deve conter somente placeholders.

Para ESP8266/ESP32, não fazer commit de SSID, senha Wi-Fi ou token preenchido. Se for necessário gerar firmware específico para um equipamento, produzir a configuração localmente e manter o arquivo sensível fora do Git.

### 9. Firewall e exposição de portas

Regra geral para instalação externa:

- publicar somente o serviço HTTPS necessário;
- PostgreSQL deve permanecer restrito;
- portas de IA/TTS/STT devem permanecer internas salvo necessidade explícita e proteção adicional;
- painel administrativo não deve ser exposto sem autenticação forte;
- manter firewall ativo;
- não criar regra ampla `0.0.0.0/0` para serviços internos.

### 10. Validação pós-instalação

O agente deve produzir um relatório final **sem segredos**, contendo apenas:

```text
[OK/ERRO] Banco acessível
[OK/ERRO] Migrations aplicadas
[OK/ERRO] API HTTPS disponível
[OK/ERRO] Requisição sem autenticação bloqueada
[OK/ERRO] Token do aplicativo validado
[OK/ERRO] Escopos validados
[OK/ERRO] Rate limit ativo
[OK/ERRO] Serviço JARVIS ativo
[OK/ERRO] TTS ativo
[OK/ERRO] APK disponível
[OK/ERRO] Teste Android -> API -> JARVIS
```

Nunca incluir no relatório senhas, tokens, hashes de autenticação reutilizáveis, conteúdo de arquivos de segredo ou cabeçalhos Authorization.

## Comportamento esperado do bot instalador

O agente deve trabalhar de forma idempotente: verificar antes de criar, instalar ou alterar. Não apagar banco, tabelas, configurações ou dados existentes sem autorização explícita do operador.

Antes de uma operação potencialmente destrutiva, deve explicar o impacto e solicitar confirmação.

Se faltar uma credencial, domínio, endereço do banco ou decisão de infraestrutura, deve interromper apenas a etapa dependente e solicitar a informação ao operador. Não deve adivinhar dados de produção.

Quando encontrar credenciais já versionadas no projeto, deve tratá-las como potencialmente comprometidas: não reproduzi-las na saída, recomendar rotação e migrar a configuração para armazenamento seguro antes de remover o segredo do código.

## O que nunca deve entrar no Git

```text
.env preenchido
senhas de banco
Authorization: Bearer <valor-real>
tokens de dispositivo
SSID/senha Wi-Fi
chaves OpenAI/Google/Brave/outros provedores
service-account JSON
certificados/chaves privadas
cookies ou sessões
backup de banco com dados privados
logs contendo segredos
```

O `.gitignore` deve ser revisado antes da implantação para cobrir arquivos locais de segredo usados pela instalação.

## Critério de conclusão

A instalação só deve ser considerada concluída quando os componentes solicitados estiverem operacionais, a API externa exigir autenticação, os testes básicos passarem e nenhum segredo tiver sido incluído em commits ou logs produzidos pelo agente.
