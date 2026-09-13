# ESP32 Voice Satellite — Assistente Residencial tipo Google Home JARVIS

Este projeto transforma um módulo **ESP32** com amplificador I2S em um satélite de voz inteligente, permitindo conversar com o JARVIS, receber respostas por áudio sintetizado e atuar como um nó de telemetria distribuído.

---

## 1. Conexões de Hardware Recomendadas
- **Módulo ESP32**: ESP32-WROOM-32 (NodeMCU / DevKit v1)
- **Amplificador / DAC I2S**: MAX98357A (ou PCM5102)
  - `LRC / WSEL` -> `GPIO 25`
  - `BCLK` -> `GPIO 26`
  - `DIN` -> `GPIO 22`
  - `VIN` -> `5V` (ou 3.3V)
  - `GND` -> `GND`
  - Alto-falante: 4Ω / 8Ω 3W conectado nos bornes `+` e `-`
- **Botão Push-to-Talk**:
  - Conectado entre `GPIO 0` (ou outro pino) e o `GND`.
- **LED Indicador**:
  - `GPIO 2` com resistor de 220Ω para `GND` (ou LED azul onboard).

---

## 2. Bibliotecas Necessárias (Arduino IDE)
- `WiFi` (integrada ao core ESP32)
- `HTTPClient` (integrada ao core ESP32)
- `ArduinoJson` (v6.x instalável pelo Gerenciador de Bibliotecas)
- `driver/i2s.h` (integrada ao ESP-IDF/core ESP32)

---

## 3. Funcionamento
1. A placa inicializa o driver de áudio I2S para 22.050 Hz (formato nativo do sintetizador neural Piper TTS).
2. Conecta ao WiFi e envia heartbeat com `device_token` para o banco de dados do JARVIS.
3. Ao pressionar o botão, envia o comando via HTTP REST com autenticação e recebe a resposta do modelo cognitivo.
4. Faz o streaming do áudio gerado diretamente para o alto-falante sem travar a placa.
