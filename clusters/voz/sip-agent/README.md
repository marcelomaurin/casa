# CASA SIP Agent — conversar com o JARVIS pelo telefone

Daemon Linux em **Object Pascal / Free Pascal**, consumidor do
`TAISIPVoiceAgent` do repositório [CHATGPT](https://github.com/marcelomaurin/CHATGPT).
Atende chamadas autenticadas no ramal **8000**, reconhece a fala, consulta o
JARVIS e devolve voz sintetizada pela mesma chamada SIP/RTP.

## Implementação e validação

- SIP Digest, registro dos telefones e autorização individual por ramal.
- RTP G.711 PCMU/PCMA; PCM mono de 8 kHz para o agente.
- Separação das falas por silêncio, limites de duração e de chamadas simultâneas.
- STT → IA → TTS em workers, mantendo o processamento SIP no thread principal.
- Histórico separado por chamada, encaminhado por `historico_mensagens` à API v1.
- Descarte do resultado de chamadas desligadas e encerramento por SIGTERM/SIGINT.
- Configuração por ambiente, unidade systemd, scripts de compilação e instalação.

Compilado e testado em Linux x86_64 com FPC 3.2.2 e PJSIP 2.17. O teste inclui
o daemon em processo separado, autenticação, bloqueio de destino, RTP bidirecional
e encerramento. **STT, IA e TTS usam fixtures sintéticas nos testes automatizados.**
Qualidade de reconhecimento/síntese, modelos reais, rede da casa, ARM e telefones
físicos ainda precisam de validação. A existência dos arquivos no Git não indica
instalação no servidor da casa.

## Dependências

No Ubuntu/Debian:

```sh
sudo apt-get install fp-compiler fp-units-fcl fp-units-net \
  libcurl4-openssl-dev libssl-dev pkg-config build-essential ca-certificates
```

São necessários os fontes atualizados do CHATGPT e sua ponte nativa SIP.
A versão inicial deste daemon foi validada com o commit
`6e9e3b1ccee8b7a026cdf0eea09613bd0fcd9d66` do CHATGPT (agente de voz SIP).
Compile PJSIP e `libchatgpt_sip_bridge.so` conforme
[CHATGPT/native/sip/README.md](https://github.com/marcelomaurin/CHATGPT/blob/main/native/sip/README.md).
O runtime deve ser compilado na arquitetura do servidor; não use uma `.so` x86_64
em um Raspberry ARM. Não é necessário instalar Lazarus/LCL para compilar o daemon.

## Compilar e instalar

```sh
bash clusters/voz/sip-agent/build.sh /caminho/CHATGPT
sudo bash clusters/voz/sip-agent/install.sh /caminho/CHATGPT
```

A instalação cria o usuário de serviço `casa-sip`, copia executável/runtime para
`/opt/casa/servicos/sip-agent`, instala a unidade e cria apenas as configurações
que ainda não existem:

| Arquivo | Finalidade |
|---|---|
| `/etc/casa/sip-agent.env` | SIP, endpoints e token da IA |
| `/etc/casa/sip-users.ini` | Contas SIP e senhas individuais |
| `/etc/systemd/system/casa-sip-agent.service` | Serviço persistente |

Edite os dois arquivos antes de iniciar. Senhas vazias, com menos de oito
caracteres ou contendo `CHANGE_ME` são recusadas. Não grave as credenciais no Git.
A unidade recebe as variáveis de ambiente; o executável não faz `source` de
arquivos de shell automaticamente.

```sh
sudo sh -c 'set -a; . /etc/casa/sip-agent.env; set +a; exec /opt/casa/servicos/sip-agent/casa-sip-agent --check-config'
sudo systemctl enable --now casa-sip-agent
journalctl -u casa-sip-agent -f
```

`--check-config` verifica a configuração local, sem abrir portas ou chamar modelos.
Os logs operacionais registram IDs e tamanhos de resposta; não registram o texto
das falas ou os tokens. Os serviços STT/JARVIS/TTS podem manter seus próprios logs.

## Configurar o telefone ou softphone

| Campo | Valor |
|---|---|
| Servidor/proxy | IP do servidor Casa |
| Porta / transporte | `5060` / UDP |
| Domínio/realm | `casa.local`, igual a `CASA_SIP_REALM` |
| Usuário | `1001` ou outro usuário criado no INI |
| Senha | A senha individual do usuário |
| Codec | G.711 PCMU ou PCMA |
| Número para conversar | `8000` |

O Digest padrão é SHA-256. Para um equipamento que só suporte MD5, ajuste
`CASA_SIP_DIGEST=md5` e reinicie. Aceitam-se somente usuários configurados e
chamadas para o ramal da IA. A integração não libera discagem externa nem aciona
dispositivos diretamente: comandos de automação passam pela API e Command Bus
existentes do Casa.

Mantenha o SIP/RTP acessível somente pela rede confiável da residência ou VPN.
Esta entrega usa SIP UDP e RTP sem criptografia. Libere no firewall somente os
clientes autorizados para UDP 5060 e para a faixa RTP de acordo com
`CASA_SIP_RTP_PORT` e o número de chamadas. NAT, SIP TLS e SRTP exigem configuração
e validação adicionais; não há descoberta automática de credenciais de telefones.

## Configurar os motores de voz

| Etapa | Variáveis e padrão |
|---|---|
| Reconhecimento | `CASA_SIP_STT_URL=http://127.0.0.1:8092/transcribe` |
| Resposta JARVIS | `CASA_SIP_AI_FORMAT=casa`, `CASA_SIP_AI_URL=.../api/v1/sip/atender`, `CASA_SIP_AI_TOKEN` |
| Síntese Piper existente | `CASA_SIP_TTS_URL=http://127.0.0.1:8097/falar` |
| WAV produzido | `CASA_SIP_AUDIO_BASE_URL=http://127.0.0.1:8097/audio/` |

O STT recebe WAV e retorna JSON com `texto`/`text`. O serviço Parakeet existente
retorna um texto de demonstração quando não há motor real. **Esse texto é
recusado pelo agente.** Configure `PARAKEET_CLI_BIN` com um executável ASR real que
aceite `--audio <wav> --model <modelo>` e escreva somente a transcrição em stdout,
ou configure outro endpoint com o mesmo contrato. Nenhum modelo de voz é baixado
ou apresentado como funcional por este instalador.

O TTS recebe `texto`, `speaker` e `reproduzir=false`. Pode devolver WAV diretamente
ou `audio_url`; neste último caso o download usa exclusivamente a base configurada.
O serviço Piper deve estar instalado com seu modelo de voz. Não use o fallback de
tons do Pocket-TTS como se fosse voz sintetizada real.

Para conversa independente com um LLM local, configure
`CASA_SIP_AI_FORMAT=openai-compatible`, `CASA_SIP_AI_MODEL` e
`CASA_SIP_AI_URL=http://servidor:porta/v1/chat/completions`. Essa opção usa histórico
por chamada, mas não fornece por si só controle dos dispositivos do Casa.

Durante o processamento e a reprodução da resposta, o agente não captura novas
falas. Interrupção da resposta por voz (barge-in) não está implementada. Ao desligar,
uma requisição em andamento é descartada após retornar; uma ação já aceita pelo
JARVIS não é desfeita. Falhas de STT/IA/TTS geram erro operacional, sem respostas
inventadas. Em falha, a chamada continua aberta para uma nova tentativa.

## Testes e atualização

Com a ponte e o daemon compilados, no repositório CHATGPT:

```sh
python3 tests/run_sip_voice_tests.py \
  --bridge /caminho/CHATGPT/native/sip/build/libchatgpt_sip_bridge.so \
  --daemon /caminho/casa/clusters/voz/sip-agent/build/casa-sip-agent
```

No Casa, valide também o encaminhamento de contexto:

```sh
php .github/tests/test_sip_conversation.php
```

Para atualizar, sincronize os dois repositórios, execute novamente `install.sh`
e reinicie `casa-sip-agent`. Os arquivos locais de configuração são preservados.
O atualizador de fontes Python do cluster **não recompila este executável Pascal**;
use esse procedimento para publicar uma nova versão do daemon.
