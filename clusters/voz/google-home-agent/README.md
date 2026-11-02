# Agente Google Home do Casa

O Raspberry recebe tarefas do Casa por HTTPS, sintetiza a mensagem pelo Piper e usa Google Cast para reproduzir no Google Home/Nest. A hospedagem nao acessa `127.0.0.1:8100` nem precisa de uma porta aberta na internet. O agente usa o motor de reservas do casa-tarefas, mantendo renovacao, novas tentativas e confirmacao do resultado. Consome apenas executor `google_home`, com uma fala por vez.

## Instalar ou atualizar no Raspberry principal

Requer Python 3.11+, consumidor casa-tarefas instalado e o TTS existente em `/home/mmm/servicos/tts` com Piper e modelo pt-BR. Execute no checkout do repositorio:

```bash
sudo python3 clusters/voz/google-home-agent/install.py
```

O instalador prepara um ambiente isolado, reutiliza a credencial privada `tasks.worker` de `/etc/casa-tarefas.env`, guarda backups em `/var/backups/casa-google-home/` e habilita `casa-google-home.service` e `casa-tts.service` apos reiniciar. Nao mostra nem versiona as credenciais. A configuracao especifica fica em `/etc/casa/google-home-agent.env` (root:mmm, 0640).

O arquivo de configuracao aceita:

- `CASA_BASE_URL`: endereco HTTPS do site Casa.
- `CASA_TAREFAS_CHAVE`: credencial com `tasks.worker`; para `/command`, precisa tambem de `jarvis.command`.
- `CASA_GOOGLE_HOME_API_TOKEN`: chave para `/speak` e `/command` locais. Sem chave, apenas chamadas de loopback sao aceitas.
- `CASA_GOOGLE_HOME_PUBLIC_BASE_URL`: endereco que o aparelho consegue acessar para buscar WAV. No Raspberry principal: `http://192.168.2.13:8100`.
- `CASA_GOOGLE_HOME_DEFAULT_DEVICE`: nome exato ou UUID do aparelho preferido. Sem este valor e com varios aparelhos, e preciso escolher o destino.
- `CASA_GOOGLE_HOME_KNOWN_HOSTS`: IPs separados por virgula, quando multicast/mDNS nao atravessa a rede.
- `CASA_TAREFAS_WORKER_FILE`: caminho do consumidor base (padrao `/opt/casa-tarefas/casa-tarefas.py`).

Nao encaminhar a porta 8100 no roteador. O Google Home deve conseguir acessar o Raspberry pela LAN; Wi-Fi de visitantes, isolamento de clientes e redes distintas podem impedir descoberta e audio.

## Usar no site

Atualize o site publicado para a mesma versao do repositorio usando o instalador PHP do Casa. O painel **Agentes externos > Google Home** recebe os aparelhos pelo heartbeat `/api/v1/google_home.php`, autenticado com a credencial do consumidor. Heartbeats sem contato por 90 segundos ficam offline.

**ANUNCIAR TEXTO** enfileira uma mensagem. **ENVIAR E FALAR** consulta o COMPUTER e enfileira a resposta. Ambos mostram estado pendente ate o agente confirmar inicio da reproducao. O plano fica aberto ate a tarefa terminar, e o resultado permanece no historico da fila. Uma tarefa tem formato:

```json
{"executor":"google_home","fila":"geral","payload":{"modo":"falar","texto":"Mensagem do Casa","device":"nome ou UUID","speaker":"padrao"}}
```

O executor nao executa novamente comandos de automacao: uma falha de audio pode repetir a fala, mas nao deve repetir uma acao sobre a casa. Nao ha garantia de fala exatamente uma vez em uma queda ocorrida entre inicio da reproducao e confirmacao na fila.

## Comandos falados ao Google Home

Google Cast e a saida de audio; ele nao fornece acesso ao microfone do Google Home. Para controlar a casa dizendo "Ok Google", o projeto do Google Home precisa estar vinculado a uma integracao Cloud-to-cloud ou Matter com os dispositivos do Casa. O daemon local nao substitui a vinculacao da conta, OAuth nem o fulfillment exigidos pelo projeto Google.

O endpoint local autenticado `/command` encaminha comandos estruturados de uma ponte autorizada ao `/api/v1/comando`, preserva `task_context` e reproduz a resposta. Ele nao e, sozinho, um fulfillment Cloud-to-cloud e nao recebe automaticamente tudo que for falado ao Google. O endpoint legado de hardware tambem requer uma ponte que realmente encaminhe os eventos; nao configurar uma rotina inexistente de webhook como se o Google oferecesse esse recurso nativamente.

## Diagnostico

```bash
systemctl status casa-google-home casa-tts
journalctl -u casa-google-home -n 50 --no-pager
curl http://127.0.0.1:8100/status
```

`agent_online` confirma o processo; `queue_alive` confirma o consumidor; `online` requer aparelho descoberto. A conclusao da fila so ocorre depois que o Cast confirma estado PLAYING ou BUFFERING. Falta de aparelho, sintese invalida e erros de reproducao sao devolvidos a fila como falhas.

Testes: `python clusters/voz/google-home-agent/tests/test_google_home.py` (requer requirements e httpx), e `php .github/tests/test_google_home.php` (MariaDB de testes). O workflow Integration Control Plane executa ambos.
