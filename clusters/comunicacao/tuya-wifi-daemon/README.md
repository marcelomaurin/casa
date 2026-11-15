# Daemon de Dispositivos e Sensores Tuya Wi-Fi

[Voltar à comunicação](../README.md) • [Catálogo central](../../README.md)

Controla e integra dispositivos e sensores Wi-Fi baseados na plataforma Tuya / Smart Life:
- Sensores de temperatura e umidade com backlight/display LCD.
- Interruptores, tomadas e relés inteligentes Wi-Fi.

## Funcionalidades

1. **Descoberta Passiva UDP**:
   - Escuta pacotes de broadcast nas portas UDP `6666` e `6667`.
   - Identifica novos sensores e dispositivos que acordam ou se conectam ao Wi-Fi.
2. **Decodificação de Data Points (DPs)**:
   - Temperatura: DP 1 / 101 (`va_temperature` em 0.1 °C).
   - Umidade: DP 2 / 102 (`va_humidity` em %).
   - Nível de Bateria: DP 3 / 103 (`battery_percentage` em %).
   - Backlight / Luz de fundo: DP 9 / 105 (`bright_state`).
3. **API REST Local (Porta 8101)**:
   - `GET /status`: Status do daemon e contagem de nós.
   - `GET /devices`: Lista de todos os dispositivos Tuya e leituras recentes.
   - `POST /devices`: Cadastro manual de novos dispositivos.
   - `POST /ingest`: Ingestão manual de telemetria.
   - `POST /scan`: Gatilho de varredura.
4. **Integração com o CASA**:
   - Encaminha telemetria para o `casa-orquestrador` (:8090) para automações de clima e respostas da IA.
