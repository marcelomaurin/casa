/*
 * CASA Bridge ESP32
 * Gateway local entre o servidor CASA e dispositivos Wi-Fi/BLE.
 *
 * Dependencias:
 *   - ESP32 Arduino Core
 *   - ArduinoJson 7.x
 *
 * O ESP32 NAO faz espelhamento/streaming de video. Ele recebe comandos,
 * descobre dispositivos e executa drivers leves. Streaming/Cast deve ser
 * delegado a um bridge Linux quando necessario.
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <NimBLEDevice.h>
#include <Preferences.h>
#include <WebServer.h>

// ---------- CONFIGURACAO ----------
static const char *FW_VERSION = "0.1.0";
static const char *DEFAULT_DEVICE_NAME = "CASA-BRIDGE-ESP32";
static const uint32_t POLL_INTERVAL_MS = 2500;
static const uint32_t HEARTBEAT_INTERVAL_MS = 30000;
static const uint32_t BLE_SCAN_INTERVAL_MS = 60000;

Preferences prefs;
WebServer localServer(80);

String wifiSsid;
String wifiPassword;
String casaBaseUrl;
String casaToken;
String bridgeId;

uint32_t lastPoll = 0;
uint32_t lastHeartbeat = 0;
uint32_t lastBleScan = 0;

struct BleDeviceInfo {
  String address;
  String name;
  int rssi;
};

static const size_t MAX_BLE_DEVICES = 30;
BleDeviceInfo bleDevices[MAX_BLE_DEVICES];
size_t bleDeviceCount = 0;

String chipId() {
  uint64_t mac = ESP.getEfuseMac();
  char out[20];
  snprintf(out, sizeof(out), "%04X%08X",
           (uint16_t)(mac >> 32), (uint32_t)mac);
  return String(out);
}

void loadConfig() {
  prefs.begin("casa-bridge", true);
  wifiSsid = prefs.getString("ssid", "");
  wifiPassword = prefs.getString("wifi_pass", "");
  casaBaseUrl = prefs.getString("base_url", "");
  casaToken = prefs.getString("token", "");
  bridgeId = prefs.getString("bridge_id", "");
  prefs.end();

  if (bridgeId.length() == 0) bridgeId = "esp32-" + chipId();
}

void saveConfig(const String &ssid, const String &pass,
                const String &baseUrl, const String &token) {
  prefs.begin("casa-bridge", false);
  prefs.putString("ssid", ssid);
  prefs.putString("wifi_pass", pass);
  prefs.putString("base_url", baseUrl);
  prefs.putString("token", token);
  prefs.putString("bridge_id", bridgeId);
  prefs.end();
}

bool connectWiFi(uint32_t timeoutMs = 20000) {
  if (wifiSsid.length() == 0) return false;

  WiFi.mode(WIFI_STA);
  WiFi.setHostname(DEFAULT_DEVICE_NAME);
  WiFi.begin(wifiSsid.c_str(), wifiPassword.c_str());

  uint32_t start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < timeoutMs) {
    delay(250);
  }
  return WiFi.status() == WL_CONNECTED;
}

void addAuth(HTTPClient &http) {
  http.addHeader("Content-Type", "application/json");
  if (casaToken.length()) http.addHeader("Authorization", "Bearer " + casaToken);
  http.addHeader("X-CASA-Bridge", bridgeId);
}

bool postJson(const String &path, const String &body, String *response = nullptr) {
  if (WiFi.status() != WL_CONNECTED || casaBaseUrl.length() == 0) return false;

  HTTPClient http;
  http.setTimeout(5000);
  http.begin(casaBaseUrl + path);
  addAuth(http);
  int code = http.POST(body);
  if (response) *response = http.getString();
  http.end();
  return code >= 200 && code < 300;
}

bool getJson(const String &path, String &response) {
  if (WiFi.status() != WL_CONNECTED || casaBaseUrl.length() == 0) return false;

  HTTPClient http;
  http.setTimeout(5000);
  http.begin(casaBaseUrl + path);
  addAuth(http);
  int code = http.GET();
  response = http.getString();
  http.end();
  return code >= 200 && code < 300;
}

void heartbeat() {
  JsonDocument doc;
  doc["bridge_id"] = bridgeId;
  doc["firmware"] = FW_VERSION;
  doc["platform"] = "esp32";
  doc["ip"] = WiFi.localIP().toString();
  doc["rssi"] = WiFi.RSSI();
  doc["free_heap"] = ESP.getFreeHeap();
  doc["bluetooth"] = true;
  doc["wifi"] = true;
  doc["video_streaming"] = false;
  String json;
  serializeJson(doc, json);
  postJson("/api/bridge/heartbeat", json);
}

void scanBle() {
  bleDeviceCount = 0;
  NimBLEScan *scan = NimBLEDevice::getScan();
  scan->setActiveScan(true);
  scan->setInterval(100);
  scan->setWindow(80);

  NimBLEScanResults results = scan->getResults(5 * 1000, false);
  for (int i = 0; i < results.getCount() && bleDeviceCount < MAX_BLE_DEVICES; ++i) {
    const NimBLEAdvertisedDevice *d = results.getDevice(i);
    bleDevices[bleDeviceCount].address = String(d->getAddress().toString().c_str());
    bleDevices[bleDeviceCount].name = d->haveName() ? String(d->getName().c_str()) : "";
    bleDevices[bleDeviceCount].rssi = d->getRSSI();
    bleDeviceCount++;
  }
  scan->clearResults();

  JsonDocument doc;
  doc["bridge_id"] = bridgeId;
  JsonArray devices = doc["devices"].to<JsonArray>();
  for (size_t i = 0; i < bleDeviceCount; ++i) {
    JsonObject o = devices.add<JsonObject>();
    o["protocol"] = "ble";
    o["address"] = bleDevices[i].address;
    o["name"] = bleDevices[i].name;
    o["rssi"] = bleDevices[i].rssi;
  }
  String json;
  serializeJson(doc, json);
  postJson("/api/bridge/discovery", json);
}

bool executeBleCommand(JsonObject cmd, String &message) {
  // Estrutura pronta para drivers GATT especificos.
  // Nao escrevemos em caracteristicas arbitrarias por seguranca/compatibilidade.
  const char *action = cmd["action"] | "";
  if (!strcmp(action, "scan")) {
    scanBle();
    message = "BLE scan executado";
    return true;
  }
  message = "Comando BLE requer driver GATT especifico do dispositivo";
  return false;
}

bool executeWifiCommand(JsonObject cmd, String &message) {
  const char *action = cmd["action"] | "";

  if (!strcmp(action, "http_request")) {
    const char *url = cmd["url"] | "";
    const char *method = cmd["method"] | "GET";
    if (strlen(url) == 0) {
      message = "URL ausente";
      return false;
    }

    HTTPClient http;
    http.setTimeout(4000);
    http.begin(url);
    int code;
    if (!strcmp(method, "POST")) {
      http.addHeader("Content-Type", "application/json");
      String payload = cmd["payload"] | "{}";
      code = http.POST(payload);
    } else {
      code = http.GET();
    }
    http.end();
    message = "HTTP " + String(code);
    return code >= 200 && code < 300;
  }

  message = "Driver Wi-Fi nao suportado";
  return false;
}

void reportCommandResult(const String &commandId, bool ok, const String &message) {
  JsonDocument doc;
  doc["bridge_id"] = bridgeId;
  doc["command_id"] = commandId;
  doc["success"] = ok;
  doc["message"] = message;
  String json;
  serializeJson(doc, json);
  postJson("/api/bridge/command/result", json);
}

void pollCommands() {
  String response;
  String path = "/api/bridge/command/next?bridge_id=" + bridgeId;
  if (!getJson(path, response) || response.length() == 0) return;

  JsonDocument doc;
  if (deserializeJson(doc, response)) return;
  if (doc["command"].isNull()) return;

  JsonObject cmd = doc["command"].as<JsonObject>();
  String id = cmd["id"] | "";
  String protocol = cmd["protocol"] | "";
  String message;
  bool ok = false;

  if (protocol == "ble") ok = executeBleCommand(cmd, message);
  else if (protocol == "wifi") ok = executeWifiCommand(cmd, message);
  else message = "Protocolo desconhecido: " + protocol;

  reportCommandResult(id, ok, message);
}

void startLocalApi() {
  localServer.on("/", HTTP_GET, []() {
    localServer.send(200, "text/plain", "CASA Bridge ESP32 " + String(FW_VERSION));
  });

  localServer.on("/health", HTTP_GET, []() {
    JsonDocument doc;
    doc["ok"] = true;
    doc["bridge_id"] = bridgeId;
    doc["wifi_connected"] = WiFi.status() == WL_CONNECTED;
    doc["ip"] = WiFi.localIP().toString();
    doc["ble_devices"] = bleDeviceCount;
    String json;
    serializeJson(doc, json);
    localServer.send(200, "application/json", json);
  });

  localServer.on("/ble/scan", HTTP_POST, []() {
    scanBle();
    localServer.send(200, "application/json", "{\"ok\":true}");
  });

  localServer.begin();
}

void setup() {
  Serial.begin(115200);
  delay(300);
  Serial.println("\nCASA Bridge ESP32 iniciando...");

  loadConfig();
  NimBLEDevice::init(DEFAULT_DEVICE_NAME);

  if (!connectWiFi()) {
    Serial.println("Wi-Fi nao configurado/conectado. Configure NVS conforme README.");
  } else {
    Serial.print("Wi-Fi OK: ");
    Serial.println(WiFi.localIP());
  }

  startLocalApi();
  heartbeat();
  scanBle();
}

void loop() {
  localServer.handleClient();

  if (WiFi.status() != WL_CONNECTED) {
    connectWiFi(3000);
    delay(50);
    return;
  }

  uint32_t now = millis();
  if (now - lastPoll >= POLL_INTERVAL_MS) {
    lastPoll = now;
    pollCommands();
  }
  if (now - lastHeartbeat >= HEARTBEAT_INTERVAL_MS) {
    lastHeartbeat = now;
    heartbeat();
  }
  if (now - lastBleScan >= BLE_SCAN_INTERVAL_MS) {
    lastBleScan = now;
    scanBle();
  }
  delay(5);
}
