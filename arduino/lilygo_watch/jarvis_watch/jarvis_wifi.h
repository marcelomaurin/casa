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
void jarvisWifiSetDeviceId(const String &deviceId);
String jarvisWifiDeviceId();
bool jarvisWifiHasCasaCredentials();

// Estado da conexao HTTP/HTTPS com o servidor CASA configurado.
// Por padrao: https://casa.maurinsoft.com.br
bool jarvisWifiCasaOnline();
bool jarvisWifiCasaChecked();
void jarvisWifiRequestCasaCheck();
String jarvisWifiCasaBase();

// Inicia associação e retorna imediatamente. A UI pode acompanhar jarvisWifiIsConnected().
bool jarvisWifiStartProfile(uint8_t slot);
bool jarvisWifiConnectProfile(uint8_t slot, uint32_t timeoutMs = 8000);
bool jarvisWifiConnectBestKnown(uint32_t timeoutMs = 8000);

// Caminho rapido para wake periodico: tenta primeiro o ultimo perfil que
// conectou com sucesso, sem fazer scan completo.
bool jarvisWifiStartPreferred();
bool jarvisWifiConnectPreferred(uint32_t timeoutMs = 3500);

// Desliga o radio antes de deep sleep.
void jarvisWifiPrepareSleep();

bool jarvisWifiGetJson(const String &path, String *response = nullptr);
bool jarvisWifiPostJson(const String &path, const String &jsonPayload, String *response = nullptr);
