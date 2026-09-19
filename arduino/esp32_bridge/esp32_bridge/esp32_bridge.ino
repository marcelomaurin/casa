/*
 * CASA Bridge ESP32
 * Gateway local entre a CASA API v1 e dispositivos Wi-Fi/BLE.
 *
 * O Bridge e um device comum do Control Plane:
 *   heartbeat -> /api/v1/device.php?acao=heartbeat
 *   comandos  -> /api/v1/device.php?acao=commands
 *   ack       -> /api/v1/device.php?acao=command_ack
 *   resultado -> /api/v1/device.php?acao=command_result
 *   descoberta-> /api/v1/device.php?acao=event
 *
 * Dependencias:
 *   - ESP32 Arduino Core
 *   - ArduinoJson 7.x
 *   - NimBLE-Arduino
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <NimBLEDevice.h>
#include <Preferences.h>
#include <WebServer.h>

static const char *FW_VERSION = "0.2.0";
static const char *PROTOCOL_VERSION = "CASA/1.0";
static const char *DEFAULT_DEVICE_NAME = "CASA-BRIDGE-ESP32";
static const char *DEFAULT_CASA_URL = "https://maurinsoft.com.br/casa";
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

String normalizeBaseUrl(String value) {
  value.trim();
  while (value.endsWith("/")) value.remove(value.length() - 1);
  if (value.length() == 0) value = DEFAULT_CASA_URL;

  // Migra automaticamente a URL historica.
  if (value == "https://maurinsoft.com.br/casa") {
    value = DEFAULT_CASA_URL;
  }
  return value;
}

void loadConfig() {
  prefs.begin("casa-bridge", true);
  wifiSsid = prefs.getString("ssid", "");
  wifiPassword = prefs.getString("wifi_pass", "");
  casaBaseUrl = normalizeBaseUrl(prefs.getString("base_url", DEFAULT_CASA_URL));
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
  prefs.putString("base_url", normalizeBaseUrl(baseUrl));
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

void addAuth(HTTPClient &http, bool json = true) {
  if (json) http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  if (casaToken.length()) {
    http.addHeader("Authorization", "Bearer " + casaToken);
    http.addHeader("X-Device-Token", casaToken);
  }
  http.addHeader("X-Device-Id", bridgeId);
}

bool postJson(const String &path, const String &body, String *response = nullptr) {
  if (WiFi.status() != WL_CONNECTED || casaBaseUrl.length() == 0) return false;

  HTTPClient http;
  http.setTimeout(7000);
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
  http.setTimeout(7000);
  http.begin(casaBaseUrl + path);
  addAuth(http, false);
  int code = http.GET();
  response = http.getString();
  http.end();
  return code >= 200 && code < 300;
}

void heartbeat() {
  JsonDocument doc;
  doc["device_id"] = bridgeId;
  doc["transport"] = "wifi";
  doc["local_ip"] = WiFi.localIP().toString();
  doc["rssi"] = WiFi.RSSI();
  doc["health"] = "ok";
  doc["firmware_version"] = FW_VERSION;
  doc["protocol_version"] = PROTOCOL_VERSION;
  doc["uptime_sec"] = millis() / 1000;

  JsonArray caps = doc["capabilities"].to<JsonArray>();
  caps.add("gateway");
  caps.add("ble");
  caps.add("wifi");
  caps.add("http");
  caps.add("discovery");

  JsonObject data = doc["data"].to<JsonObject>();
  data["platform"] = "esp32";
  data["free_heap"] = ESP.getFreeHeap();
  data["ble_devices"] = bleDeviceCount;
  data["video_streaming"] = false;

  String json;
  serializeJson(doc, json);
  postJson("/api/v1/device.php?acao=heartbeat", json);
}

void publishDiscoveryEvent() {
  JsonDocument doc;
  doc["device_id"] = bridgeId;
  doc["type"] = "device.discovery";
  doc["priority"] = "normal";

  JsonObject data = doc["data"].to<JsonObject>();
  data["protocol"] = "ble";
  data["gateway_device_id"] = bridgeId;
  JsonArray devices = data["devices"].to<JsonArray>();

  for (size_t i = 0; i < bleDeviceCount; ++i) {
    JsonObject o = devices.add<JsonObject>();
    o["protocol"] = "ble";
    o["address"] = bleDevices[i].address;
    o["name"] = bleDevices[i].name;
    o["rssi"] = bleDevices[i].rssi;
  }

  String json;
  serializeJson(doc, json);
  postJson("/api/v1/device.php?acao=event", json);
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
  publishDiscoveryEvent();
}

bool executeBleCommand(const String &action, JsonObject payload, String &message) {
  if (action == "scan" || action == "ble.scan") {
    scanBle();
    message = "BLE scan executado";
    return true;
  }

  // Drivers GATT especificos entram aqui.
  message = "Comando BLE requer driver GATT especifico";
  return false;
}

bool executeWifiCommand(const String &action, JsonObject payload, String &message) {
  if (action != "http_request" && action != "wifi.http_request") {
    message = "Driver Wi-Fi nao suportado";
    return false;
  }

  String url = payload["url"] | "";
  String method = payload["method"] | "GET";
  if (url.length() == 0) {
    message = "URL ausente";
    return false;
  }

  HTTPClient http;
  http.setTimeout(5000);
  http.begin(url);

  int code;
  if (method == "POST") {
    http.addHeader("Content-Type", "application/json");
    String body;
    if (payload["body"].is<String>()) {
      body = payload["body"].as<String>();
    } else if (!payload["body"].isNull()) {
      serializeJson(payload["body"], body);
    } else {
      body = "{}";
    }
    code = http.POST(body);
  } else {
    code = http.GET();
  }

  http.end();
  message = "HTTP " + String(code);
  return code >= 200 && code < 300;
}

bool ackCommand(long commandId) {
  JsonDocument doc;
  doc["device_id"] = bridgeId;
  doc["id"] = commandId;
  String json;
  serializeJson(doc, json);
  return postJson("/api/v1/device.php?acao=command_ack", json);
}

void reportCommandResult(long commandId, bool ok, const String &message) {
  JsonDocument doc;
  doc["device_id"] = bridgeId;
  doc["id"] = commandId;
  doc["status"] = ok ? "success" : "error";

  JsonObject result = doc["result"].to<JsonObject>();
  result["message"] = message;

  if (!ok) doc["error"] = message;

  String json;
  serializeJson(doc, json);
  postJson("/api/v1/device.php?acao=command_result", json);
}

void executeCommand(JsonObject cmd) {
  long id = cmd["id"] | 0;
  String command = cmd["comando"] | "";
  if (id <= 0 || command.length() == 0) return;

  JsonDocument payloadDoc;
  JsonObject payload;

  if (cmd["payload"].is<const char*>()) {
    String rawPayload = cmd["payload"].as<String>();
    if (rawPayload.length() && !deserializeJson(payloadDoc, rawPayload)) {
      payload = payloadDoc.as<JsonObject>();
    }
  } else if (cmd["payload"].is<JsonObject>()) {
    payload = cmd["payload"].as<JsonObject>();
  }

  ackCommand(id);

  String protocol = payload["protocol"] | "";
  String action = payload["action"] | "";

  // CASA/1.0 prefere nomes qualificados no campo comando.
  if (command.startsWith("ble.")) {
    protocol = "ble";
    if (action.length() == 0) action = command;
  } else if (command.startsWith("wifi.") || command.startsWith("http.")) {
    protocol = "wifi";
    if (action.length() == 0) action = command;
  }

  // Compatibilidade com comandos historicos.
  if (protocol.length() == 0 && command == "scan") {
    protocol = "ble";
    action = "scan";
  }
  if (protocol.length() == 0 && command == "http_request") {
    protocol = "wifi";
    action = "http_request";
  }

  String message;
  bool ok = false;

  if (protocol == "ble") {
    ok = executeBleCommand(action, payload, message);
  } else if (protocol == "wifi" || protocol == "http") {
    ok = executeWifiCommand(action, payload, message);
  } else {
    message = "Comando sem adapter: " + command;
  }

  reportCommandResult(id, ok, message);
}

void pollCommands() {
  String response;
  String path = "/api/v1/device.php?acao=commands&limit=5&device_id=" + bridgeId;
  if (!getJson(path, response) || response.length() == 0) return;

  JsonDocument doc;
  if (deserializeJson(doc, response)) return;

  JsonArray commands = doc["commands"].as<JsonArray>();
  if (commands.isNull()) return;

  for (JsonObject cmd : commands) {
    executeCommand(cmd);
  }
}

void startLocalApi() {
  localServer.on("/", HTTP_GET, []() {
    localServer.send(200, "text/plain", "CASA Bridge ESP32 " + String(FW_VERSION));
  });

  localServer.on("/health", HTTP_GET, []() {
    JsonDocument doc;
    doc["ok"] = true;
    doc["device_id"] = bridgeId;
    doc["protocol_version"] = PROTOCOL_VERSION;
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
