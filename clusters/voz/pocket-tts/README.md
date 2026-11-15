# Pocket-TTS — Síntese de Voz Leve

Serviço de síntese de voz (Text-to-Speech / TTS) rápido, de alta velocidade e baixo consumo de recursos para o ecossistema **CASA / JARVIS**.

## Funcionalidades
- **API REST na porta 8093:**
  - `GET /health` / `GET /status`: estado operacional e telemetria da síntese.
  - `POST /synthesize` (ou `/falar` / `/tts`): síntese de voz a partir de texto com parâmetros de entonação, velocidade e reprodução local opcional.
  - `GET /audio/<arquivo>`: streaming e download dos arquivos WAV gerados.
  - `GET /voices`: catálogo de vozes disponíveis.
- **Reprodução Local Instantânea:** Suporte direto a `aplay`, `paplay` e `sox` nos alto-falantes conectados ao nó.
- **Ideal para Dispositivos ARM:** Projetado para rodar em Raspberry Pi, Cubieboard ou servidores centrais sem exigir GPU pesada.

## Instalação e Execução

### Arquivos
- Daemon: `casa-pocket-tts.py`
- Unidade systemd: `casa-pocket-tts.service`
- Configuração: `/etc/casa/pocket-tts.env` (baseado em `pocket-tts.env.exemplo`)

### Iniciar Serviço
```bash
sudo systemctl enable casa-pocket-tts.service
sudo systemctl start casa-pocket-tts.service
sudo systemctl status casa-pocket-tts.service
```

### Testar Síntese
```bash
curl -X POST http://127.0.0.1:8093/synthesize \
  -H "Content-Type: application/json" \
  -d '{"texto": "Sistema JARVIS operacional com síntese de voz Pocket-TTS.", "speaker": "jarvis", "play": true}'
```
