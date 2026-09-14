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

bool jarvisWifiSetProfile(uint8_t slot, const String &ssid, const String &password);
bool jarvisWifiGetProfile(uint8_t slot, String &ssid, String &password);
int jarvisWifiFindFreeSlot();
void jarvisWifiClearProfiles();

bool jarvisWifiScanStart();
int jarvisWifiScanPoll(JarvisWifiNetwork *out, int maxItems);
bool jarvisWifiScanRunning();

void jarvisWifiSetCasa(const String &baseUrl, const String &deviceToken);

// Inicia associação e retorna imediatamente. A UI pode acompanhar jarvisWifiIsConnected().
bool jarvisWifiStartProfile(uint8_t slot);
bool jarvisWifiConnectProfile(uint8_t slot, uint32_t timeoutMs = 8000);
bool jarvisWifiConnectBestKnown(uint32_t timeoutMs = 8000);

bool jarvisWifiPostJson(const String &path, const String &jsonPayload, String *response = nullptr);
