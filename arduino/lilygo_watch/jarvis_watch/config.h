#pragma once

#define LILYGO_WATCH_2020_V3
#include <LilyGoWatch.h>

#define JARVIS_WATCH_NAME       "JARVIS Watch"
#define JARVIS_PHONE_NAME       "JARVIS-PHONE"
#define JARVIS_PROTOCOL_VERSION "1.0"

// UUIDs reservados para a ponte Android. A camada de transporte permanece
// isolada para nao acoplar a interface do relogio a uma biblioteca BLE externa.
#define JARVIS_BLE_SERVICE_UUID "7a9f1000-3a8c-4b62-9e5f-1b0c0e91a001"
#define JARVIS_BLE_RX_UUID      "7a9f1001-3a8c-4b62-9e5f-1b0c0e91a001"
#define JARVIS_BLE_TX_UUID      "7a9f1002-3a8c-4b62-9e5f-1b0c0e91a001"
#define JARVIS_RECONNECT_MS     5000
#define JARVIS_REQUEST_TIMEOUT  15000

// Hardware oficial do T-Watch 2020 V3.
#define JARVIS_PDM_DATA_PIN     2
#define JARVIS_PDM_CLK_PIN      0
#define JARVIS_MOTION_INT_PIN   39

// O relogio nao deve armazenar Wi-Fi, token mestre, credenciais de banco
// ou chave RunPod. A IA remota passa pelo celular/gateway.
