# CASA — automacao residencial e JARVIS
Palavras-chave: CASA, JARVIS, COMPUTER, IoT, ESP8266, ESP32, LCARS, IA, voz, RAG.
CASA e uma plataforma de automacao residencial e integracao IoT. O nucleo COMPUTER/JARVIS recebe comandos por texto e voz, mantem historico, seleciona modelos de IA e integra provedores locais/remotos.
O backend usa PHP e MySQL. Ha dispositivos como ESP-01 para rele com estado desejado/fail-safe e ESP-01 DHT para temperatura e umidade, provisioning, heartbeat e telemetria.
O JARVIS possui pipeline de tarefas, diagnostico de modelos, TTS pt-BR segmentado e interrompivel e RAG Markdown local.
Repositorio: marcelomaurin/casa.