#pragma once

#include <Arduino.h>

struct JarvisWifiNetwork {
  String ssid;
  int rssi;
  bool secure;
  bool known;
};

void jarvisWifiBegin();
void jarvisWifiLoop();
bool jarvisWifiIsConnected();
String jarvisWifiSsid();
int jarvisWifiRssi();

// Perfis conhecidos da casa, provisionados pelo celular ou digitados no Watch.
bool jarvisWifiSetProfile(uint8_t slot, const String &ssid, const String &password);
bool jarvisWifiGetProfile(uint8_t slot, String &ssid, String &password);
int jarvisWifiFindFreeSlot();
void jarvisWifiClearProfiles();

// Scan assíncrono: nunca bloqueia a interface esperando redes.
bool jarvisWifiScanStart();
// Retorna: -1 ainda executando, -2 erro, >=0 quantidade copiada.
int jarvisWifiScanPoll(JarvisWifiNetwork *out, int maxItems);
bool jarvisWifiScanRunning();

// Credencial exclusiva do Watch para a API CASA. Nunca usar token mestre.
void jarvisWifiSetCasa(const String &baseUrl, const String &deviceToken);

// Conexão com timeout limitado; use somente fora do caminho de desenho/touch.
bool jarvisWifiConnectProfile(uint8_t slot, uint32_t timeoutMs = 8000);
bool jarvisWifiConnectBestKnown(uint32_t timeoutMs = 8000);

// Fallback HTTPS quando o celular/BLE estiver indisponível.
bool jarvisWifiPostJson(const String &path, const String &jsonPayload, String *response = nullptr);
