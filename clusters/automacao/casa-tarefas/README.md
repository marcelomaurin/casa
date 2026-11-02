# casa-tarefas — daemon executor da fila do planejador

[Voltar à automação](../README.md)

Quando o JARVIS recebe um pedido com várias etapas, o planejador divide o pedido em tarefas
pequenas. Com um ou mais `casa-tarefas` ativos, essas tarefas vão para uma **fila**, e cada
daemon pega as que sabe executar, roda e devolve o resultado. Tarefas independentes rodam em
paralelo; uma tarefa que depende de outra só sai quando a anterior conclui, e recebe o
resultado dela como entrada.

```text
 Pedido ──► jarvis.php ──► planejador ──► jarvis_tarefas (fila "geral" ou nome do nó)
                                               │
              ┌────────────────────────────────┴───────────────┐
        casa-tarefas (pi-sala)                     agente externo (qualquer linguagem)
        jarvis · web · fala · comando              executores próprios
              └──── /api/v1/fila.php (reservar · renovar · concluir · falhar · dividir) ────┘
```

O daemon usa **somente a API v1** e reaproveita os serviços que já rodam no nó: o agente
de pesquisa ([web-agent](../../pesquisa/README.md), porta 8099) e a voz ([tts](../../voz/README.md),
porta 8097). Ele não acessa o banco de dados.

## Mordomo

O mesmo daemon dispara, a cada `CASA_MORDOMO_INTERVALO` segundos (padrão 60; 0 desliga), um
ciclo do **mordomo** em `/api/v1/mordomo.php`. No site, o ciclo:

1. **Observa** a casa com detectores simples (sem IA): pedido de assistência não confirmado,
   dispositivo que parou de responder, bateria baixa, temperatura fora da faixa, irrigação ligada
   há muito tempo, luz acesa de madrugada, movimento em câmera no horário de silêncio e evento
   grave de segurança.
2. **Lembra** do que já viu: só o que é novo (ou voltou a acontecer) segue adiante; o que sumiu é
   dado como resolvido.
3. **Decide** com a IA, que recebe o estado atual da casa: avisar, agir ou ignorar cada caso.
4. **Executa** conforme a autonomia e registra a decisão (o JARVIS passa a saber o que fez).

| `mordomo_autonomia` | Comportamento |
|---|---|
| `observar` | Só registra o que viu. |
| `avisar` (padrão) | Registra e avisa a família; ações viram sugestão no aviso. |
| `agir` | Também executa ordens — **somente** as que o JARVIS reconhece como ordem direta (luz, irrigação, relé, cena). A IA não consegue inventar outra ação. |

Avisos: notificação no celular sempre; Telegram e fala na casa (tarefa `fala` na fila) quando o
caso é grave, ou médio fora do horário de silêncio. Caso grave que continua aberto recebe lembrete.
Se a IA não responder, casos médios e graves são avisados mesmo assim.

Ajustes em `configuracoes_sistema` (todos opcionais): `mordomo_offline_min` (10),
`mordomo_bateria_min` (20), `mordomo_temp_max` (35), `mordomo_temp_min` (8),
`mordomo_irrigacao_max_min` (30), `mordomo_silencio` (`22-7`), `mordomo_lembrete_min` (15),
`mordomo_ignorar_tipos` (`mobile,celular,smartphone,watch,relogio,tv`).

No painel, **IA & VOZ → Mordomo da casa** mostra os casos acompanhados (com "já vi" e "confirmar
atendimento"), as decisões recentes e o estado do ciclo, e permite mudar a autonomia e os limites
(somente perfil administrador; cada mudança fica em `comandos_log`). Pela API, o mesmo resumo está em
`GET /api/v1/mordomo.php?acao=estado`.

## Instalação

1. No painel, em **IA & VOZ → Acesso Web & API segura**, crie uma chave com os escopos
   **Daemon / agente de tarefas** (`tasks.worker`) e **Comandos ao JARVIS** (`jarvis.command`).
2. No nó:

   ```bash
   cd clusters/automacao/casa-tarefas
   sudo ./install.sh
   sudo nano /etc/casa-tarefas.env        # preencha CASA_TAREFAS_CHAVE
   sudo systemctl start casa-tarefas
   journalctl -u casa-tarefas -f
   ```

3. Conferir a configuração sem iniciar: `python3 casa-tarefas.py --config /etc/casa-tarefas.env --verificar`

Com o daemon ativo (contato nos últimos 5 minutos), o planejador passa a usar a fila
automaticamente. Para forçar um comportamento, grave `planejador_execucao` em
`configuracoes_sistema`: `auto` (padrão), `fila` ou `direto` (o site executa na hora, como antes).

## Executores

| Executor | O que faz | Payload |
|---|---|---|
| `jarvis` | Envia a parte para a IA por `/api/v1/comando`, anexando o resultado da etapa anterior | `{"comando": "..."}` |
| `web` | Pesquisa no agente web local | `{"query": "...", "max_results": 5, "fetch_pages": false}` |
| `fala` | Fala no alto-falante local; sem texto, fala o resultado da etapa anterior | `{"texto": "...", "speaker": "padrao"}` |
| `comando` | Roda um script **autorizado** de `/etc/casa-tarefas/comandos.d` (sem shell, com tempo limite) | `{"nome": "espaco-disco", "args": []}` |
| extensões | Arquivos `.py` em `/opt/casa-tarefas/executores` | livre |

Tarefas de **dispositivo** continuam sendo acionadas pelo próprio site (que valida capacidade e
risco); quando dependem de uma tarefa da fila, o site as dispara assim que a anterior conclui.

### Scripts autorizados (`comando`)

Só roda o que estiver em `CASA_TAREFAS_COMANDOS_DIR` **com permissão de execução**. A tarefa
chega em JSON pela entrada padrão; se a última linha da saída for JSON, ela vira o resultado.
Saída diferente de zero conta como falha (com nova tentativa). Veja `comandos.d/espaco-disco`.

### Extensões em Python

```python
EXECUTOR = "meu_executor"

def executar(tarefa, ctx):
    dados = tarefa["payload"]                 # dados da tarefa
    anterior = tarefa.get("entrada")          # resultado da tarefa da qual esta depende
    ctx.renovar()                             # em tarefas longas, estende a reserva
    if ruim: raise FalhaDefinitiva("motivo")  # não adianta repetir
    if grande: raise Dividir([{...}, {...}])  # volta para a fila em partes
    return {"resultado": ...}
```

Inclua o nome em `CASA_TAREFAS_EXECUTORES` e reinicie. O planejador passa a oferecer esse
executor à IA enquanto o daemon estiver ativo. Exemplo completo: `executores/exemplo_eco.py`.

## Divisão em partes

Um executor pode dividir a tarefa em até 20 partes. Cada parte vira uma tarefa filha que pode ir
para outra fila ou outro executor e depender de uma parte anterior (`depende_da_parte`, 1 = a
primeira). A tarefa original fica em `AGUARDANDO_PARTES` e conclui com a lista de resultados das
partes; se alguma parte falhar, ela vira `ERRO`.

## Falhas, novas tentativas e reservas

- Cada tarefa tem até `max_tentativas` (padrão 3). Uma falha comum volta para a fila com espera
  crescente (30 s, 60 s, 120 s… até 15 min). `FalhaDefinitiva` vai direto para `ERRO`.
- Quando uma tarefa vira `ERRO`, as que dependiam dela também viram `ERRO`.
- A reserva vale `CASA_TAREFAS_PRAZO` segundos e é renovada pelo daemon. Se o nó cair, a
  reserva vence e a tarefa volta para a fila sozinha.
- Ao parar (`systemctl stop`), o daemon deixa de pegar tarefas e termina as que estão rodando.
- O plano fecha como `CONCLUIDO` (ou `ERRO`) quando nenhuma tarefa está mais em andamento.

**Limite da API v1:** uma chave que passa de 60 pedidos por minuto fica bloqueada por 5 minutos
(o IP da casa é isento). Por isso o intervalo mínimo entre consultas é de 3 s; o padrão é 5 s.

## Protocolo para agentes externos

Qualquer programa pode ser um agente, em qualquer linguagem. Todas as chamadas são
`POST /api/v1/fila.php?acao=<ação>` com `Authorization: Bearer <chave tasks.worker>` e JSON
contendo `trabalhador` (nome do agente).

| Ação | Corpo | Resposta |
|---|---|---|
| `reservar` | `executores`, `filas`, `limite`, `prazo_segundos` | `tarefas: [...]` |
| `renovar` | `id`, `prazo_segundos` | `reservado_ate` |
| `concluir` | `id`, `resultado` | `ok` |
| `falhar` | `id`, `erro`, `repetir` (true/false) | `tarefa_status` |
| `liberar` | `id` (devolve sem gastar tentativa) | `ok` |
| `dividir` | `id`, `partes: [{titulo, executor, fila, payload, depende_da_parte}]` | `partes: [ids]` |
| `status` (GET) | `id_plano` | plano e tarefas |

Cada tarefa reservada traz `id`, `executor`, `payload`, `entrada` (resultado da dependência),
`tentativa`, `max_tentativas`, `reservado_ate` e `task_context` (para vincular chamadas ao plano).
Só quem reservou pode concluir, falhar, liberar ou dividir (senão: HTTP 409).

Exemplo mínimo com `curl`:

```bash
CH="Authorization: Bearer $CHAVE"; U=https://maurinsoft.com.br/casa/api/v1/fila.php
curl -s -H "$CH" -d '{"trabalhador":"meu-agente","executores":["relatorio"],"limite":1}' "$U?acao=reservar"
curl -s -H "$CH" -d '{"trabalhador":"meu-agente","id":123,"resultado":{"ok":true}}' "$U?acao=concluir"
```

A fila `geral` é atendida por todos; uma tarefa com `fila` igual ao nome de um agente só vai para
ele. O nome `servidor` é reservado ao site.
