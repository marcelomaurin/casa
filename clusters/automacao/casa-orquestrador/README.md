# CASA / JARVIS - Daemon Orquestrador Residencial com IA (`casa-orquestrador`)

Daemon orquestrador residencial com inteligência artificial operando na porta `:8090`. Integra e unifica todos os pilares do ecossistema CASA:

1. **Controlar a Casa**:
   - Acionamento direto de atuadores e relés (`/api/controlar`) via API REST v1 (`/api/v1/dispositivos/acionar`).
   - Mapeamento e resolução heurística de nomes de dispositivos para ordens físicas seguras.

2. **Responder Perguntas**:
   - Rota `/api/interagir` / `/api/perguntar`.
   - Transcrição de voz via modelo local Parakeet STT (`:8092`).
   - Respostas contextuais combinando telemetria da residência (`/api/v1/sensores`), pesquisa web externa (`:8099`) e IA central (`/api/v1/comando`).
   - Síntese de voz rápida de retorno via Pocket-TTS (`:8093`) e Piper TTS (`:8097`).

3. **Prever Necessidades**:
   - Ciclo em segundo plano e rota `/api/prever`.
   - Consome histórico dos sensores da casa e executa previsão de séries temporais e detecção de anomalias com o modelo **Timestral-5M** (`:8091`).
   - Alertas térmicos proativos e prevenção de anomalias operacionais.

4. **Agendar Eventos**:
   - Rota `/api/agendar`.
   - Conversão de agendamentos e regras para execução agendada pela API v1 e fila de tarefas (`casa-scheduler` e `casa-tarefas`).

5. **Orquestração Geral com IA**:
   - Coordena a malha distribuída de nós (RPi 4B, RPi 3B+, Cubieboards), câmeras ESP32-CAM e caixas Google Home.

## Endpoints HTTP Principais

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/health` | Status de saúde e métricas do orquestrador |
| `GET` | `/api/dispositivos` | Lista atuadores e dispositivos integrados |
| `POST` | `/api/controlar` | Executa controle de relés/dispositivos |
| `POST` | `/api/interagir` | Processa perguntas em texto ou áudio (STT -> IA -> TTS) |
| `POST` | `/api/prever` | Executa análise preditiva com Timestral-5M |
| `POST` | `/api/agendar` | Agenda eventos e regras temporais |

