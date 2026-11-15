# Parakeet STT — Reconhecimento de Voz Local

Serviço de transcrição e reconhecimento de voz (Speech-to-Text / ASR) local para o ecossistema **CASA / JARVIS**, baseado no modelo Parakeet.

## Funcionalidades
- **API REST na porta 8092:**
  - `GET /health` / `GET /status`: estado operacional e telemetria do motor ASR.
  - `POST /transcribe` (ou `/stt` / `/ouvir`): processa áudio WAV ou codificado em base64 e retorna a transcrição de texto.
  - `POST /ouvir_microfone`: captura áudio diretamente pelo microfone físico do nó (via ALSA `arecord` ou `sox rec`) e realiza o reconhecimento imediato.
- **Operação 100% Local:** Processamento no próprio nó de borda (Edge / ARM ou x86), preservando a privacidade das falas dos moradores sem depender de nuvens externas para áudio bruto.

## Instalação e Execução

### Arquivos
- Daemon: `casa-parakeet-stt.py`
- Unidade systemd: `casa-parakeet-stt.service`
- Configuração: `/etc/casa/parakeet-stt.env` (baseado em `parakeet-stt.env.exemplo`)

### Iniciar Serviço
```bash
sudo systemctl enable casa-parakeet-stt.service
sudo systemctl start casa-parakeet-stt.service
sudo systemctl status casa-parakeet-stt.service
```

### Testar Transcrição
```bash
curl -X POST http://127.0.0.1:8092/transcribe \
  -H "Content-Type: audio/wav" \
  --data-binary @comando.wav
```
