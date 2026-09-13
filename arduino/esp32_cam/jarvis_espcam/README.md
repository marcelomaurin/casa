# ESP32-CAM — Módulo de Visualização & Detecção JARVIS

Este projeto implementa o firmware completo para o microcontrolador **AI-Thinker ESP32-CAM** (sensor OV2640), integrando streaming de vídeo ao vivo, captura de fotos sob demanda, controle de iluminação por flash LED e alertas de segurança diretamente no cluster do JARVIS.

---

## 1. Recursos Implementados
- **Streaming de Vídeo MJPEG**: Endpoint `/stream` com até 25 FPS para visualização na interface Web HUD.
- **Captura de Snapshot**: Endpoint `/capture` para download instantâneo de quadros JPEG.
- **Controle de Flash LED**: Endpoints `/flash/on` e `/flash/off` (GPIO 4).
- **Alerta de Movimento com Upload**: Envia quadros capturados automaticamente para `/api/agente_externo.php?acao=upload_espcam&movimento=1` com disparo de notificação no Telegram.
- **Segurança com Hardware Token**: Comunica-se exclusivamente com cabeçalho `X-Device-Token`.
- **Telemetria Contínua**: Reporta memória RAM livre (Free Heap) e força do sinal WiFi (RSSI) ao banco de dados do cluster a cada 15 segundos.

---

## 2. Como Gravar na Placa (Arduino IDE)
1. Instale o suporte a placas ESP32 no Gerenciador de Placas (`https://dl.espressif.com/dl/package_esp32_index.json`).
2. Selecione a placa: **AI Thinker ESP32-CAM**.
3. Parâmetros de compilação:
   - **CPU Frequency**: `240MHz (WiFi/BT)`
   - **Flash Frequency**: `80MHz`
   - **Flash Mode**: `QIO`
   - **Partition Scheme**: `Huge APP (3MB No OTA/1MB SPIFFS)`
4. Ajuste no fonte `jarvis_espcam.ino`:
   - `ssid` e `password` do seu WiFi.
   - `jarvis_server`: IP do seu servidor Raspberry Pi (ex: `http://192.168.2.12`).
   - `device_token`: Token cadastrado no painel JARVIS.
5. Conecte o pino `GPIO 0` ao `GND` para entrar em modo de gravação (Flash mode) e aperte Reset. Após o upload, desconecte `GPIO 0` e aperte Reset novamente.
