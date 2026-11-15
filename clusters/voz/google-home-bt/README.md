# CASA / JARVIS - Daemon Ponte Bluetooth com Google Home (`casa-google-home-bt`)

Daemon para conversação bidirecional com caixas **Google Home / Google Nest / Google Assistant** utilizando conexão **Bluetooth A2DP** na porta `:8094`.

## Capacidades

1. **Conexão Bluetooth Nativa**:
   - Varredura, pareamento e conexão automática via BlueZ / `bluetoothctl`.
   - Suporte a perfis de áudio A2DP Source e Sink via PulseAudio.

2. **Falar com o Google Home**:
   - Recebe texto de ordens ou perguntas (ex: "*Ok Google, que horas são?*", "*Ok Google, tocar música*").
   - Sintetiza áudio WAV através do daemon **Pocket-TTS** (`:8093`) ou Piper (`:8097`).
   - Transmite e reproduz o áudio diretamente na caixa Google Home pelo canal Bluetooth.

3. **Ouvir e Ler o que o Google Home fala**:
   - Captura a resposta falada emitida pelo Google Home através do canal de áudio Bluetooth.
   - Encaminha o fluxo gravado para o daemon **Parakeet STT** (`:8092`) para transcrição local precisa.

4. **Conversação Bidirecional com a IA**:
   - Rota `/api/v1/google_home/dialogo`:
     1. Dispara a fala com o Google.
     2. Aguarda a janela de resposta.
     3. Captura o áudio emitido pelo assistente.
     4. Transcreve com o Parakeet STT.
     5. Alimenta a resposta transcrita na IA do **Orquestrador Residencial** (`:8090`).

## Endpoints HTTP Principais

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/health` | Status da conexão Bluetooth, sinks de áudio e estatísticas |
| `GET` | `/api/v1/bluetooth/dispositivos` | Varre e lista dispositivos Bluetooth próximos |
| `POST` | `/api/v1/bluetooth/conectar` | Conecta ao Google Home via endereço MAC |
| `POST` | `/api/v1/bluetooth/desconectar` | Desconecta o dispositivo Bluetooth |
| `POST` | `/api/v1/google_home/falar` | Sintetiza áudio e fala com o Google Home via Bluetooth |
| `POST` | `/api/v1/google_home/ouvir` | Grava a resposta falada do Google e transcreve via Parakeet |
| `POST` | `/api/v1/google_home/dialogo` | Diálogo completo: Fala -> Ouve -> Transcreve -> IA Orquestrador |
| `GET` | `/api/v1/google_home/historico` | Histórico das últimas interações com o Google Home |

