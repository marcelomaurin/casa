/**
 * JARVIS CASA - ESP32-CAM AI Thinker
 *
 * Arquitetura:
 *   1. Device novo -> BLE JARVIS-CAM-xxxxxx.
 *   2. JARVIS Mobile envia SSID/senha + URL CASA + token individual + nome/local.
 *   3. ESP32-CAM testa o Wi-Fi, grava em NVS e reinicia.
 *   4. Operacao normal -> somente Wi-Fi/HTTP(S). BLE nao e necessario no uso diario.
 *   5. Se a rede salva falhar no boot, BLE volta automaticamente para reconfiguracao.
 *
 * Nenhum token mestre, senha de banco ou chave RunPod fica no firmware-fonte.
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <BLEDevice.h>
#include <BLEServer.h>
#include <BLEUtils.h>
#include <BLE2902.h>

// ================= NVS / CONFIGURACAO =================
Preferences preferences;
static const char* NVS_NAMESPACE = "jarviscam";

String wifi_ssid;
String wifi_password;
String casa_url;
String device_token;
String device_name;
String device_location;
bool provisioned = false;

// ================= BLE PROVISIONAMENTO =================
#define BLE_SERVICE_UUID        "7a5b0001-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_SSID_UUID      "7a5b0002-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_WIFI_UUID      "7a5b0003-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_APPLY_UUID     "7a5b0006-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_STATUS_UUID    "7a5b0007-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_CASA_URL_UUID  "7a5b0008-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_TOKEN_UUID     "7a5b0009-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_NAME_UUID      "7a5b000a-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_LOCATION_UUID  "7a5b000b-78fc-4b97-9f0f-9e9f5a31b401"

BLEServer* bleServer = nullptr;
BLECharacteristic* bleStatus = nullptr;
bool bleStarted = false;
bool bleClientConnected = false;
volatile bool applyRequested = false;

String pending_ssid;
String pending_wifi_password;
String pending_casa_url;
String pending_device_token;
String pending_device_name;
String pending_location;

// ================= CAMERA AI-THINKER =================
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27
#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22
#define FLASH_LED_PIN      4

WebServer server(80);
unsigned long lastHeartbeat = 0;
const unsigned long HEARTBEAT_INTERVAL = 15000UL;

String jsonEscape(const String& value) {
  String out;
  out.reserve(value.length() + 8);
  for (size_t i = 0; i < value.length(); i++) {
    char c = value.charAt(i);
    switch (c) {
      case '\\': out += "\\\\"; break;
      case '"': out += "\\\""; break;
      case '\n': out += "\\n"; break;
      case '\r': out += "\\r"; break;
      case '\t': out += "\\t"; break;
      default: out += c; break;
    }
  }
  return out;
}

String boolJson(bool v) { return v ? "true" : "false"; }

void bleStatusUpdate(const String& code, const String& message) {
  if (!bleStatus) return;
  String json = "{\"status\":\"" + jsonEscape(code) + "\",\"mensagem\":\"" + jsonEscape(message) + "\",\"configurado\":" + boolJson(provisioned) + ",\"wifi_conectado\":" + boolJson(WiFi.status() == WL_CONNECTED) + "}";
  bleStatus->setValue(json.c_str());
  if (bleClientConnected) bleStatus->notify();
  Serial.println("[BLE] " + json);
}

void loadConfig() {
  preferences.begin(NVS_NAMESPACE, true);
  provisioned = preferences.getBool("configured", false);
  wifi_ssid = preferences.getString("ssid", "");
  wifi_password = preferences.getString("wifi_pass", "");
  casa_url = preferences.getString("casa_url", "https://maurinsoft.com.br/casa");
  device_token = preferences.getString("token", "");
  device_name = preferences.getString("name", "ESP32-CAM");
  device_location = preferences.getString("location", "Residencia");
  preferences.end();

  if (wifi_ssid.isEmpty() || casa_url.isEmpty() || device_token.isEmpty()) provisioned = false;
}

bool saveConfig(
  const String& ssid,
  const String& wifiPass,
  const String& casaUrl,
  const String& token,
  const String& name,
  const String& location
) {
  if (ssid.isEmpty() || casaUrl.isEmpty() || token.isEmpty()) return false;
  preferences.begin(NVS_NAMESPACE, false);
  bool ok = true;
  ok &= preferences.putString("ssid", ssid) > 0;
  preferences.putString("wifi_pass", wifiPass);
  ok &= preferences.putString("casa_url", casaUrl) > 0;
  ok &= preferences.putString("token", token) > 0;
  preferences.putString("name", name.isEmpty() ? "ESP32-CAM" : name);
  preferences.putString("location", location.isEmpty() ? "Residencia" : location);
  preferences.putBool("configured", ok);
  preferences.end();
  if (ok) loadConfig();
  return ok;
}

bool connectWifi(const String& ssid, const String& pass, unsigned long timeoutMs = 15000UL) {
  if (ssid.isEmpty()) return false;
  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);
  WiFi.disconnect(false, false);
  delay(100);
  WiFi.begin(ssid.c_str(), pass.c_str());
  const unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < timeoutMs) {
    delay(50);
    yield();
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[WIFI] %s -> %s RSSI=%d\n", ssid.c_str(), WiFi.localIP().toString().c_str(), WiFi.RSSI());
    return true;
  }
  WiFi.disconnect(false, false);
  return false;
}

void clearPending() {
  pending_ssid = "";
  pending_wifi_password = "";
  pending_casa_url = "";
  pending_device_token = "";
  pending_device_name = "";
  pending_location = "";
}

class ServerCallbacks : public BLEServerCallbacks {
  void onConnect(BLEServer*) override {
    bleClientConnected = true;
    bleStatusUpdate("CONECTADO", "Celular conectado. Envie a configuracao.");
  }
  void onDisconnect(BLEServer*) override {
    bleClientConnected = false;
    clearPending();
    if (bleStarted) BLEDevice::startAdvertising();
  }
};

class TextWriteCallback : public BLECharacteristicCallbacks {
public:
  explicit TextWriteCallback(String* target) : target_(target) {}
  void onWrite(BLECharacteristic* c) override {
    if (!target_) return;
    std::string v = c->getValue();
    if (v.size() > 256) return;
    *target_ = String(v.c_str());
  }
private:
  String* target_;
};

class ApplyCallback : public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic* c) override {
    String v(c->getValue().c_str());
    v.trim(); v.toUpperCase();
    if (v == "APPLY" || v == "SALVAR" || v == "1") applyRequested = true;
  }
};

void startBleProvisioning() {
  if (bleStarted) return;
  String mac = WiFi.macAddress();
  mac.replace(":", "");
  String bleName = "JARVIS-CAM-" + mac.substring(mac.length() - 6);

  BLEDevice::init(bleName.c_str());
  bleServer = BLEDevice::createServer();
  bleServer->setCallbacks(new ServerCallbacks());
  BLEService* service = bleServer->createService(BLE_SERVICE_UUID);

  auto addWrite = [&](const char* uuid, String* target) {
    BLECharacteristic* c = service->createCharacteristic(uuid, BLECharacteristic::PROPERTY_WRITE);
    c->setCallbacks(new TextWriteCallback(target));
  };
  addWrite(BLE_CHAR_SSID_UUID, &pending_ssid);
  addWrite(BLE_CHAR_WIFI_UUID, &pending_wifi_password);
  addWrite(BLE_CHAR_CASA_URL_UUID, &pending_casa_url);
  addWrite(BLE_CHAR_TOKEN_UUID, &pending_device_token);
  addWrite(BLE_CHAR_NAME_UUID, &pending_device_name);
  addWrite(BLE_CHAR_LOCATION_UUID, &pending_location);

  BLECharacteristic* apply = service->createCharacteristic(BLE_CHAR_APPLY_UUID, BLECharacteristic::PROPERTY_WRITE);
  apply->setCallbacks(new ApplyCallback());

  bleStatus = service->createCharacteristic(BLE_CHAR_STATUS_UUID, BLECharacteristic::PROPERTY_READ | BLECharacteristic::PROPERTY_NOTIFY);
  bleStatus->addDescriptor(new BLE2902());

  service->start();
  BLEAdvertising* adv = BLEDevice::getAdvertising();
  adv->addServiceUUID(BLE_SERVICE_UUID);
  adv->setScanResponse(true);
  adv->start();
  bleStarted = true;
  bleStatusUpdate("AGUARDANDO_CONFIGURACAO", "Abra JARVIS Mobile > Novos Devices.");
  Serial.println("[BLE] Provisionamento ativo: " + bleName);
}

void processProvisioning() {
  applyRequested = false;
  if (pending_ssid.isEmpty()) { bleStatusUpdate("ERRO", "SSID nao informado"); return; }
  if (pending_wifi_password.length() > 0 && pending_wifi_password.length() < 8) { bleStatusUpdate("ERRO", "Senha Wi-Fi invalida"); return; }
  if (!pending_casa_url.startsWith("https://")) { bleStatusUpdate("ERRO", "URL CASA deve usar HTTPS"); return; }
  if (pending_device_token.isEmpty()) { bleStatusUpdate("ERRO", "Token individual nao informado"); return; }

  bleStatusUpdate("TESTANDO_WIFI", "Conectando a rede informada...");
  if (!connectWifi(pending_ssid, pending_wifi_password, 15000UL)) {
    bleStatusUpdate("WIFI_INVALIDO", "Nao foi possivel conectar ao Wi-Fi");
    return;
  }

  if (!saveConfig(pending_ssid, pending_wifi_password, pending_casa_url, pending_device_token, pending_device_name, pending_location)) {
    bleStatusUpdate("ERRO_NVS", "Falha ao gravar configuracao");
    return;
  }

  bleStatusUpdate("CONFIGURADO", "Configuracao salva. Reiniciando para modo Wi-Fi.");
  delay(700);
  ESP.restart();
}

// ================= HTTP LOCAL DA CAMERA =================
void handleStream() {
  WiFiClient client = server.client();
  server.sendContent("HTTP/1.1 200 OK\r\nContent-Type: multipart/x-mixed-replace; boundary=frame\r\n\r\n");
  while (client.connected()) {
    camera_fb_t* fb = esp_camera_fb_get();
    if (!fb) break;
    client.print("--frame\r\nContent-Type: image/jpeg\r\nContent-Length: " + String(fb->len) + "\r\n\r\n");
    client.write(fb->buf, fb->len);
    client.print("\r\n");
    esp_camera_fb_return(fb);
    yield();
  }
}

void handleCapture() {
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) { server.send(500, "text/plain", "Falha na captura"); return; }
  server.sendHeader("Content-Type", "image/jpeg");
  server.sendHeader("Content-Length", String(fb->len));
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.client().write(fb->buf, fb->len);
  esp_camera_fb_return(fb);
}

void handleStatus() {
  String json = "{\"dispositivo\":\"" + jsonEscape(device_name) + "\",\"local\":\"" + jsonEscape(device_location) + "\",\"tipo\":\"esp32cam\",\"ip\":\"" + WiFi.localIP().toString() + "\",\"rssi\":" + String(WiFi.RSSI()) + ",\"free_heap\":" + String(ESP.getFreeHeap()) + "}";
  server.send(200, "application/json", json);
}

void startHttpServer() {
  server.on("/stream", handleStream);
  server.on("/capture", handleCapture);
  server.on("/flash/on", [](){ digitalWrite(FLASH_LED_PIN, HIGH); server.send(200,"application/json","{\"flash\":1}"); });
  server.on("/flash/off", [](){ digitalWrite(FLASH_LED_PIN, LOW); server.send(200,"application/json","{\"flash\":0}"); });
  server.on("/status", handleStatus);
  server.begin();
}

bool beginSecureHttp(HTTPClient& http, WiFiClientSecure& tls, const String& url) {
  tls.setInsecure(); // TODO: instalar CA da implantacao antes de producao.
  tls.setTimeout(5);
  http.setConnectTimeout(3000);
  http.setTimeout(5000);
  return http.begin(tls, url);
}

void sendHeartbeat() {
  if (WiFi.status() != WL_CONNECTED || device_token.isEmpty() || casa_url.isEmpty()) return;
  WiFiClientSecure tls;
  HTTPClient http;
  String url = casa_url + "/api/crud.php?tabela=dispositivos_cluster&acao=heartbeat_iot";
  if (!beginSecureHttp(http, tls, url)) return;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", device_token);
  http.addHeader("Authorization", "Bearer " + device_token);
  String payload = "{\"device_token\":\"" + jsonEscape(device_token) + "\",\"ram_livre\":" + String(ESP.getFreeHeap()) + ",\"sinal_rssi\":" + String(WiFi.RSSI()) + ",\"ip_address\":\"" + WiFi.localIP().toString() + "\",\"metadata\":{\"local\":\"" + jsonEscape(device_location) + "\"}}";
  int code = http.POST(payload);
  Serial.printf("[CASA] Heartbeat HTTP %d\n", code);
  http.end();
}

void startCamera() {
  camera_config_t config = {};
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM; config.pin_d1 = Y3_GPIO_NUM; config.pin_d2 = Y4_GPIO_NUM; config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM; config.pin_d5 = Y7_GPIO_NUM; config.pin_d6 = Y8_GPIO_NUM; config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM; config.pin_pclk = PCLK_GPIO_NUM; config.pin_vsync = VSYNC_GPIO_NUM; config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM; config.pin_sscb_scl = SIOC_GPIO_NUM; config.pin_pwdn = PWDN_GPIO_NUM; config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000; config.pixel_format = PIXFORMAT_JPEG;
  if (psramFound()) { config.frame_size = FRAMESIZE_VGA; config.jpeg_quality = 12; config.fb_count = 2; }
  else { config.frame_size = FRAMESIZE_QVGA; config.jpeg_quality = 15; config.fb_count = 1; }
  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) Serial.printf("[CAM] Falha init: 0x%x\n", err);
}

void setup() {
  Serial.begin(115200);
  pinMode(FLASH_LED_PIN, OUTPUT);
  digitalWrite(FLASH_LED_PIN, LOW);
  loadConfig();
  startCamera();

  if (!provisioned) {
    Serial.println("[SETUP] Sem configuracao. Entrando em modo BLE.");
    startBleProvisioning();
    return;
  }

  Serial.println("[SETUP] Configurado. Tentando operacao normal por Wi-Fi.");
  if (connectWifi(wifi_ssid, wifi_password, 15000UL)) {
    startHttpServer();
    sendHeartbeat();
  } else {
    Serial.println("[SETUP] Wi-Fi salvo falhou. Reabrindo BLE para reconfiguracao.");
    startBleProvisioning();
  }
}

void loop() {
  if (applyRequested) processProvisioning();
  if (WiFi.status() == WL_CONNECTED) {
    server.handleClient();
    if (millis() - lastHeartbeat >= HEARTBEAT_INTERVAL) {
      lastHeartbeat = millis();
      sendHeartbeat();
    }
  }
  delay(2);
}
