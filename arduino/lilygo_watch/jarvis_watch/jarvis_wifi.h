#pragma once

#include <Arduino.h>

void jarvisWifiBegin();
void jarvisWifiLoop();
bool jarvisWifiIsConnected();
String jarvisWifiSsid();
int jarvisWifiRssi();

// Perfis conhecidos da casa, provisionados pelo celular.
bool jarvisWifiSetProfile(uint8_t slot, const String &ssid, const String &password);
void jarvisWifiClearProfiles();

// Credencial exclusiva do Watch para a API CASA. Nunca usar token mestre.
void jarvisWifiSetCasa(const String &baseUrl, const String &deviceToken);

// Procura somente SSIDs previamente provisionados e conecta ao de melhor RSSI.
bool jarvisWifiConnectBestKnown(uint32_t timeoutMs = 12000);

// Fallback HTTPS quando o celular/BLE estiver indisponível.
bool jarvisWifiPostJson(const String &path, const String &jsonPayload, String *response = nullptr);
