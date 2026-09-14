#pragma once

// Alvo: LILYGO T-Watch 2020 V3
#define LILYGO_WATCH_2020_V3
#include <LilyGoWatch.h>

// O relogio nao armazena Wi-Fi, URL publica ou token da casa.
// A interface atual funciona em modo standalone e a camada de comunicacao
// externa permanece isolada em jarvis_ble.h / jarvis_ble.cpp.

#define JARVIS_WATCH_NAME       "JARVIS Watch"
#define JARVIS_PHONE_NAME       "JARVIS-PHONE"
#define JARVIS_PROTOCOL_VERSION "1.0"

// UUIDs reservados para a futura reativacao da ponte Watch <-> Android.
#define JARVIS_BLE_SERVICE_UUID "7a9f1000-3a8c-4b62-9e5f-1b0c0e91a001"
#define JARVIS_BLE_RX_UUID      "7a9f1001-3a8c-4b62-9e5f-1b0c0e91a001"
#define JARVIS_BLE_TX_UUID      "7a9f1002-3a8c-4b62-9e5f-1b0c0e91a001"

#define JARVIS_RECONNECT_MS     5000
#define JARVIS_REQUEST_TIMEOUT  15000
