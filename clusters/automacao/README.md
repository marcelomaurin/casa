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

## [casa-orquestrador](casa-orquestrador/)

Daemon orquestrador residencial com inteligência artificial operando na porta 8090. Unifica e orquestra todos os artefatos da casa: controla atuadores e dispositivos via API REST v1, responde perguntas por voz (Parakeet STT + Pocket-TTS) e texto, prevê necessidades através do modelo Timestral-5M e agenda rotinas e eventos. Destino sugerido: `/opt/casa/servicos/orquestrador`.

## [timestral-series](timestral-series/)

Daemon de análise de séries temporais, previsão (forecasting) e detecção de anomalias residenciais baseado no modelo Timestral-5M. Expõe API REST na porta 8091; destino sugerido: `/opt/casa/servicos/timestral`.
