#pragma once

// Alvo inicial: LILYGO T-Watch 2020 V3
#define LILYGO_WATCH_2020_V3
#include <LilyGoWatch.h>

// Wi-Fi do relogio. Futuramente pode usar o mesmo provisionamento BLE da ESP32-CAM.
#define JARVIS_WIFI_SSID     "SUA_REDE_WIFI"
#define JARVIS_WIFI_PASSWORD "SUA_SENHA_WIFI"

// Servidor CASA/JARVIS na rede local.
#define JARVIS_BASE_URL      "http://192.168.2.12"

// Cadastre o relogio em dispositivos_cluster e coloque o token individual aqui.
#define JARVIS_DEVICE_TOKEN  "TOKEN_INDIVIDUAL_DO_WATCH"

#define JARVIS_WATCH_NAME    "JARVIS Watch"
#define JARVIS_TZ            "BRT3BRST,M10.3.0/0,M2.3.0/0"
