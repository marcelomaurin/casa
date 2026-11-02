# Automação e tarefas

[Voltar ao catálogo](../README.md)

## [casa-scheduler](casa-scheduler/)

Agendamento de tarefas. Possui dependência de PostgreSQL e serviço systemd; destino: `/opt/casa-scheduler`. Não envia heartbeat ARM próprio.

## [casa-tarefas](casa-tarefas/)

Executor da fila de tarefas do planejador. Pega tarefas em `/api/v1/fila.php`, executa
(IA, pesquisa, voz, scripts autorizados e extensões) e devolve o resultado; vários nós podem
rodar ao mesmo tempo. Só usa a API v1, sem banco; destino: `/opt/casa-tarefas`. O README
descreve também o protocolo para agentes externos. O mesmo daemon dispara o ciclo do
**mordomo** (observar a casa, decidir e avisar ou agir), descrito no README.
