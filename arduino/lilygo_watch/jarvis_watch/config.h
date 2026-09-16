#pragma once

#define LILYGO_WATCH_2020_V3
#include <LilyGoWatch.h>

#define JARVIS_WATCH_NAME       "JARVIS Watch"
#define JARVIS_PHONE_NAME       "JARVIS-PHONE"
#define JARVIS_PROTOCOL_VERSION "TCP-1.0"

// Transporte local de provisionamento: Wi-Fi SoftAP + TCP simples.
#define JARVIS_WATCH_AP_SSID    "JARVIS-WATCH"
#define JARVIS_WATCH_TCP_PORT   4040
#define JARVIS_RECONNECT_MS     5000
#define JARVIS_REQUEST_TIMEOUT  15000

// Hardware oficial do T-Watch 2020 V3.
#define JARVIS_PDM_DATA_PIN     2
#define JARVIS_PDM_CLK_PIN      0
#define JARVIS_MOTION_INT_PIN   39
#define JARVIS_TOUCH_INT_PIN    38
#define JARVIS_RTC_INT_PIN      37
#define JARVIS_POWER_INT_PIN    35
#define JARVIS_MOTOR_PIN        4
#define JARVIS_IR_PIN           13

#define JARVIS_BACKGROUND_WAKE_SEC 60

// MAX98357A / I2S de saida do T-Watch 2020 V3.
#define JARVIS_AUDIO_BCK_PIN    26
#define JARVIS_AUDIO_WS_PIN     25
#define JARVIS_AUDIO_DOUT_PIN   33

// O Watch pode armazenar localmente apenas perfis Wi-Fi domésticos autorizados
// e um token individual de dispositivo com escopos mínimos. Nunca armazenar
// token mestre, credenciais de banco, chave RunPod ou outros segredos centrais.
