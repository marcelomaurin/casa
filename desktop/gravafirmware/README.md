# CASA - Gravador de Firmware & Chave de Seguranca de Cameras

Este modulo contem a aplicacao de gravacao de firmware e obtencao fisica de tokens de seguranca para ESP32-CAM, ESP32 e Arduino.

## Versoes Disponiveis:

### 1. Versao Principal (Lazarus / Free Pascal com CHATGPT Lib)
- **Executavel:** `gravafirmware.exe`
- **Atalho / Batch:** `gravafirmware.bat`
- **Projeto Lazarus:** `gravafirmware.lpi`, `gravafirmware.lpr`, `frmmain.pas`
- **Componente:** Utiliza o componente `TAIFirmwareFlasher` da biblioteca `CHATGPT` (`openai_hardware.lpk` / `pacote/AI Hardware/aifirmwareflasher.pas`).
- **Suporte:** ESP32-CAM (AI Thinker), ESP32 Dev Module, Arduino Uno, Arduino Nano, Arduino Mega.

### 2. Versao Alternativa (Python / Tkinter)
- **Script:** `gravafirmware.py`
- **Atalho / Batch:** `gravafirmware_py.bat`
- Mantida como versao alternativa e portavel sem necessidade de compilador.

## Fluxo de Seguranca do Token:
1. Grava o firmware na placa.
2. Desconecta o jumper `IO0 -> GND` e pressiona `RST`.
3. Pede o token pela porta serial (`GET_TOKEN`). O token **nunca** e exposto via HTTP pela camera.
4. O aplicativo despacha o token capturado diretamente para o daemon residencial responsavel (`POST http://127.0.0.1:8088/token`) e persiste em `~/.casa/camera_tokens.json`.
