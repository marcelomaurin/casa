#pragma once

#define LILYGO_WATCH_2020_V3
#include <LilyGoWatch.h>

#define JARVIS_WATCH_NAME       "JARVIS Watch"
#define JARVIS_PHONE_NAME       "JARVIS-PHONE"
#define JARVIS_PROTOCOL_VERSION "2.0"

// UUIDs reservados para a ponte Android.
// Watch = periférico/GATT server; Android = central/GATT client.
// A implementação BLE permanece isolada para não acoplar a UI a uma biblioteca externa.
#define JARVIS_BLE_SERVICE_UUID "7a9f1000-3a8c-4b62-9e5f-1b0c0e91a001"
#define JARVIS_BLE_RX_UUID      "7a9f1001-3a8c-4b62-9e5f-1b0c0e91a001"
#define JARVIS_BLE_TX_UUID      "7a9f1002-3a8c-4b62-9e5f-1b0c0e91a001"
#define JARVIS_RECONNECT_MS     5000
#define JARVIS_REQUEST_TIMEOUT  15000

// Hardware oficial do T-Watch 2020 V3.
#define JARVIS_PDM_DATA_PIN     2
#define JARVIS_PDM_CLK_PIN      0
#define JARVIS_MOTION_INT_PIN   39
#define JARVIS_MOTOR_PIN        4

// MAX98357A / I2S de saida do T-Watch 2020 V3.
#define JARVIS_AUDIO_BCK_PIN    26
#define JARVIS_AUDIO_WS_PIN     25
#define JARVIS_AUDIO_DOUT_PIN   33

// O Watch pode armazenar localmente apenas perfis Wi-Fi domésticos autorizados
// e um token individual de dispositivo com escopos mínimos. Nunca armazenar
// token mestre, credenciais de banco, chave RunPod ou outros segredos centrais.
