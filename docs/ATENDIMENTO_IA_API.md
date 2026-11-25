# Atendimento por IA no site Casa

O gateway público recebe mensagens autenticadas e consulta o mesmo JARVIS e os
mesmos modelos configurados no site. Mantém o contexto enviado pelo cliente e
devolve a resposta escrita, uma versão adequada para voz e a identificação do
modelo utilizado. Comandos residenciais continuam passando pelo JARVIS e pelo
Command Bus existentes.

## Rotas

| Método | Rota | Uso |
|---|---|---|
| POST | `/casa/api/v1/ia/atender` | Mensagens de aplicativos e integrações |
| POST | `/casa/api/v1/sip/atender` | Texto reconhecido pelo agente de telefonia |
| POST | `/casa/api/v1/ia.php` | Alternativa sem reescrita de URL |
| POST | `/casa/api/v1/comando` | Compatibilidade com clientes existentes; também preserva histórico |

As rotas passam pelo gateway, incluindo a alternativa `ia.php`. Exigem Bearer
token com escopo `jarvis.command` (ou as permissões `mobile.write`, `watch.write`
e `*` já aceitas para comandos). Chaves internas do sistema usam o mecanismo de
autenticação comum da API. Rate limit e revogação de credenciais continuam ativos.

Na tela **Acesso Web & API**, o bloco **Atendimento por IA** apresenta as URLs.
Crie uma chave com a permissão de atendimento/comandos ao JARVIS. Configure o
provedor e o modelo em **Conexões & Modelos de IA**. A API não recebe URLs nem
chaves de provedores no corpo da mensagem.

## Requisição

Cabeçalhos: `Authorization: Bearer <token>` e `Content-Type: application/json`.

```json
{
  "mensagem": "E na cozinha?",
  "session_id": "chamada-1001-abc123",
  "historico_mensagens": [
    {"role": "user", "content": "Qual a temperatura da sala?"},
    {"role": "assistant", "content": "Na sala, vinte graus."}
  ]
}
```

`comando` também é aceito como nome da mensagem. `session_id` é opcional para
requisições isoladas; quando ausente, o servidor gera um identificador. O cliente
deve manter e enviar o histórico de sua conversa, como o daemon SIP já faz.
O identificador sozinho não recupera mensagens anteriores de um banco global.

São aceitos apenas os papéis `user` e `assistant`, com limite de 24 mensagens e
32 mil bytes no histórico. O JARVIS aplica também seus próprios limites de
contexto. A mensagem atual aceita até 4000 caracteres e o corpo total até 64 KB.
Histórico e identidade de outra chamada nunca são acrescentados automaticamente.

Sem `ia_mode`, vale o roteamento configurado no site. Para sobrescrever em uma
requisição, use `auto`, `local_only` ou `cloud_only`. Integrações de tarefas podem
continuar enviando `task_context`, `correlation_id` e `skip_planner=true`.

## Resposta

Uma resposta real inclui:

| Campo | Conteúdo |
|---|---|
| `resposta` | Texto escrito; para SIP, texto pronto para síntese |
| `resposta_texto` | Resposta escrita completa |
| `resposta_fala` | Versão sem Markdown e sem marcadores de automação |
| `session_id` | Identificador desta conversa |
| `provedor_ia`, `modelo_usado` | Identificação do modelo que respondeu, sem suas credenciais |
| `task_context`, `correlation_id` | Rastreamento do pedido no JARVIS |
| `acao_executada` | Ação informada pelo núcleo, quando houver |

As origens `SIP` e `API_IA` não reproduzem a resposta nos alto-falantes da casa
nem geram uma segunda síntese no site. O cliente recebe texto; o daemon SIP
sintetiza e envia o áudio para o telefone pela própria ligação. Google Home
mantém seu comportamento de reprodução pelo agente.

Se nenhum modelo configurado responder, a API retorna HTTP 503. Erros de
upstream/JSON inválido retornam 502; timeout de transporte retorna 504. Erros
incluem uma mensagem e `correlation_id`, sem apresentar texto fictício como uma
resposta da IA. Entrada inválida retorna 400, falta de escopo 403, método indevido
405 e tamanho excedido 413.

## Agente SIP

O daemon 1.0.1 usa `/api/v1/sip/atender` por padrão. Atualize o site antes de
reiniciar o daemon com essa URL. Instalações que já usam `/api/v1/comando`
continuam compatíveis e passam a preservar o histórico pelo gateway correto.
O timeout padrão do daemon é 60 segundos; o transporte interno do site usa 55.
Configurações locais existentes são preservadas pelo instalador.

## Validação

```sh
php .github/tests/test_ai_attendance.php
php .github/tests/test_sip_conversation.php
python3 .github/tests/test_ai_attendance_http.py
```

O teste HTTP usa uma fixture local declarada para a resposta do modelo e exercita
o transporte cURL real. O CI testa também autenticação, escopos, método e validação
contra o gateway PHP real com banco de testes. O daemon e o fluxo SIP/RTP mantêm
seus testes próprios. Inferência com os modelos e credenciais reais da instalação
deve ser validada no servidor configurado.
