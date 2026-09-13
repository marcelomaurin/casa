/**
 * JARVIS RESIDENCIAL - MÓDULO ESP32-CAM (VISUALIZAÇÃO & TELEMETRIA)
 *
 * Hardware: AI-Thinker ESP32-CAM (Sensor OV2640)
 *
 * Recursos:
 *  1. Streaming de vídeo MJPEG (/stream)
 *  2. Captura de snapshot (/capture)
 *  3. Controle do LED flash
 *  4. Telemetria e heartbeat para o JARVIS
 *  5. Provisionamento Wi-Fi por Bluetooth Low Energy (BLE)
 *  6. Primeira configuração sem usuário/senha do JARVIS
 *  7. Reconfiguração protegida por autenticação de usuário do JARVIS
 *  8. Persistência da configuração em NVS (Preferences)
 *
 * Fluxo BLE:
 *   - Câmera sem configuração: recebe SSID/senha e grava após testar o Wi-Fi.
 *   - Câmera já configurada: recebe SSID/senha + usuário/senha JARVIS.
 *     A nova configuração só é persistida se o servidor validar o usuário.
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <WiFiClient.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <BLEDevice.h>
#include <BLEServer.h>
#include <BLEUtils.h>
#include <BLE2902.h>

// ================= CONFIGURAÇÕES DO JARVIS =================
// O endereço do servidor pode continuar fixo no firmware.
// As credenciais Wi-Fi NÃO ficam mais gravadas no código-fonte.
const char* jarvis_server = "http://192.168.2.12";
const char* device_token = "token_espcam_portao_2026";
const char* device_name = "ESP32-CAM Entrada";

// ================= NVS =================
Preferences preferences;
const char* NVS_NAMESPACE = "jarviscam";

String wifi_ssid;
String wifi_password;
bool provisioned = false;

// ================= BLE =================
// Serviço proprietário do JARVIS para provisionamento.
#define BLE_SERVICE_UUID        "7a5b0001-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_SSID_UUID      "7a5b0002-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_WIFI_UUID      "7a5b0003-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_USER_UUID      "7a5b0004-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_PASS_UUID      "7a5b0005-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_APPLY_UUID     "7a5b0006-78fc-4b97-9f0f-9e9f5a31b401"
#define BLE_CHAR_STATUS_UUID    "7a5b0007-78fc-4b97-9f0f-9e9f5a31b401"

BLEServer* bleServer = nullptr;
BLECharacteristic* bleStatus = nullptr;

String pending_ssid;
String pending_wifi_password;
String pending_user;
String pending_user_password;

bool bleClientConnected = false;
volatile bool applyRequested = false;

// ================= CÂMERA AI-THINKER =================
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
unsigned long last_heartbeat = 0;
const unsigned long heartbeat_interval = 15000;

// ============================================================
// AUXILIARES
// ============================================================

String boolJson(bool value) {
  return value ? "true" : "false";
}

String jsonEscape(const String& value) {
  String out;
  out.reserve(value.length() + 8);

  for (size_t i = 0; i < value.length(); i++) {
    char c = value.charAt(i);
    switch (c) {
      case '\\': out += "\\\\"; break;
      case '"':  out += "\\\""; break;
      case '\n': out += "\\n"; break;
      case '\r': out += "\\r"; break;
      case '\t': out += "\\t"; break;
      default:   out += c; break;
    }
  }

  return out;
}

void atualizarStatusBLE(const String& codigo, const String& mensagem) {
  if (!bleStatus) return;

  String json = "{";
  json += "\"status\":\"" + jsonEscape(codigo) + "\",";
  json += "\"mensagem\":\"" + jsonEscape(mensagem) + "\",";
  json += "\"configurado\":" + boolJson(provisioned) + ",";
  json += "\"wifi_conectado\":" + boolJson(WiFi.status() == WL_CONNECTED);
  json += "}";

  bleStatus->setValue(json.c_str());
  if (bleClientConnected) {
    bleStatus->notify();
  }

  Serial.println("[BLE] " + json);
}

void carregarConfiguracao() {
  preferences.begin(NVS_NAMESPACE, true);
  provisioned = preferences.getBool("configured", false);
  wifi_ssid = preferences.getString("ssid", "");
  wifi_password = preferences.getString("wifi_pass", "");
  preferences.end();

  // Evita estado inconsistente de NVS.
  if (wifi_ssid.length() == 0) {
    provisioned = false;
  }
}

void salvarConfiguracaoWiFi(const String& ssid, const String& senha) {
  preferences.begin(NVS_NAMESPACE, false);
  preferences.putString("ssid", ssid);
  preferences.putString("wifi_pass", senha);
  preferences.putBool("configured", true);
  preferences.end();

  wifi_ssid = ssid;
  wifi_password = senha;
  provisioned = true;
}

bool conectarWiFi(const String& ssid, const String& senha, unsigned long timeoutMs = 20000) {
  if (ssid.length() == 0) return false;

  WiFi.mode(WIFI_STA);
  WiFi.disconnect(true, false);
  delay(300);

  Serial.printf("[WIFI] Tentando conectar em '%s'...\n", ssid.c_str());
  WiFi.begin(ssid.c_str(), senha.c_str());

  unsigned long inicio = millis();
  while (WiFi.status() != WL_CONNECTED && (millis() - inicio) < timeoutMs) {
    delay(250);
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("[WIFI] Conectado. IP: " + WiFi.localIP().toString());
    return true;
  }

  Serial.println("[WIFI] Falha ao conectar.");
  return false;
}

bool validarUsuarioJarvis(const String& usuario, const String& senha) {
  if (WiFi.status() != WL_CONNECTED) return false;
  if (usuario.length() == 0 || senha.length() == 0) return false;

  HTTPClient http;
  String url = String(jarvis_server) + "/api/espcam_provision.php";

  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  http.setTimeout(10000);

  String payload = "{";
  payload += "\"usuario\":\"" + jsonEscape(usuario) + "\",";
  payload += "\"senha\":\"" + jsonEscape(senha) + "\",";
  payload += "\"dispositivo\":\"" + jsonEscape(String(device_name)) + "\"";
  payload += "}";

  int httpCode = http.POST(payload);
  String resposta = http.getString();
  http.end();

  Serial.printf("[PROVISION] Autenticação JARVIS HTTP %d\n", httpCode);

  if (httpCode != 200) {
    return false;
  }

  // O endpoint devolve {"ok":true,...}
  return resposta.indexOf("\"ok\":true") >= 0;
}

void limparPendenciasBLE() {
  pending_ssid = "";
  pending_wifi_password = "";
  pending_user = "";
  pending_user_password = "";
}

void processarProvisionamento() {
  applyRequested = false;

  String novoSsid = pending_ssid;
  String novaSenha = pending_wifi_password;
  String usuario = pending_user;
  String senhaUsuario = pending_user_password;

  if (novoSsid.length() == 0) {
    atualizarStatusBLE("ERRO", "SSID não informado.");
    return;
  }

  // Guarda configuração anterior apenas em RAM para permitir rollback.
  String ssidAnterior = wifi_ssid;
  String senhaAnterior = wifi_password;
  bool estavaConfigurado = provisioned;

  if (estavaConfigurado && (usuario.length() == 0 || senhaUsuario.length() == 0)) {
    atualizarStatusBLE("AUTENTICACAO_NECESSARIA", "Reconfiguração exige usuário e senha do JARVIS.");
    return;
  }

  atualizarStatusBLE("TESTANDO_WIFI", "Testando a nova rede Wi-Fi.");

  if (!conectarWiFi(novoSsid, novaSenha)) {
    atualizarStatusBLE("WIFI_INVALIDO", "Não foi possível conectar à rede informada.");

    if (estavaConfigurado && ssidAnterior.length() > 0) {
      conectarWiFi(ssidAnterior, senhaAnterior, 12000);
    }
    return;
  }

  // PRIMEIRA CONFIGURAÇÃO:
  // basta provar que a rede funciona; não exige credenciais de usuário.
  if (!estavaConfigurado) {
    salvarConfiguracaoWiFi(novoSsid, novaSenha);
    atualizarStatusBLE("CONFIGURADO", "Primeira configuração concluída. Reiniciando.");
    delay(1200);
    ESP.restart();
    return;
  }

  // RECONFIGURAÇÃO:
  // a ESP32 conecta primeiro à rede candidata e só persiste a alteração
  // se o usuário/senha forem validados pelo servidor JARVIS.
  atualizarStatusBLE("AUTENTICANDO", "Validando usuário no JARVIS.");

  if (!validarUsuarioJarvis(usuario, senhaUsuario)) {
    atualizarStatusBLE("NAO_AUTORIZADO", "Usuário ou senha inválidos. Configuração anterior preservada.");

    if (ssidAnterior.length() > 0) {
      conectarWiFi(ssidAnterior, senhaAnterior, 12000);
    }
    return;
  }

  salvarConfiguracaoWiFi(novoSsid, novaSenha);
  atualizarStatusBLE("RECONFIGURADO", "Nova configuração autorizada e gravada. Reiniciando.");
  delay(1200);
  ESP.restart();
}

// ============================================================
// BLE CALLBACKS
// ============================================================

class JarvisBLEServerCallbacks : public BLEServerCallbacks {
  void onConnect(BLEServer* pServer) override {
    bleClientConnected = true;
    atualizarStatusBLE(provisioned ? "CONFIGURADO" : "AGUARDANDO_CONFIGURACAO",
                       provisioned
                         ? "Dispositivo já configurado. Para alterar, informe usuário e senha do JARVIS."
                         : "Informe SSID e senha do Wi-Fi para a primeira configuração.");
  }

  void onDisconnect(BLEServer* pServer) override {
    bleClientConnected = false;
    limparPendenciasBLE();
    BLEDevice::startAdvertising();
  }
};

class SsidCallbacks : public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic* c) override {
    pending_ssid = String(c->getValue().c_str());
  }
};

class WifiPasswordCallbacks : public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic* c) override {
    pending_wifi_password = String(c->getValue().c_str());
  }
};

class UserCallbacks : public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic* c) override {
    pending_user = String(c->getValue().c_str());
  }
};

class UserPasswordCallbacks : public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic* c) override {
    pending_user_password = String(c->getValue().c_str());
  }
};

class ApplyCallbacks : public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic* c) override {
    String valor = String(c->getValue().c_str());
    valor.trim();
    valor.toUpperCase();

    if (valor == "APPLY" || valor == "1" || valor == "SALVAR") {
      applyRequested = true;
    }
  }
};

void iniciarBLEProvisionamento() {
  String mac = WiFi.macAddress();
  mac.replace(":", "");
  String nomeBLE = "JARVIS-CAM-" + mac.substring(mac.length() - 6);

  BLEDevice::init(nomeBLE.c_str());
  bleServer = BLEDevice::createServer();
  bleServer->setCallbacks(new JarvisBLEServerCallbacks());

  BLEService* service = bleServer->createService(BLE_SERVICE_UUID);

  BLECharacteristic* cSsid = service->createCharacteristic(
    BLE_CHAR_SSID_UUID,
    BLECharacteristic::PROPERTY_WRITE
  );
  cSsid->setCallbacks(new SsidCallbacks());

  BLECharacteristic* cWifi = service->createCharacteristic(
    BLE_CHAR_WIFI_UUID,
    BLECharacteristic::PROPERTY_WRITE
  );
  cWifi->setCallbacks(new WifiPasswordCallbacks());

  BLECharacteristic* cUser = service->createCharacteristic(
    BLE_CHAR_USER_UUID,
    BLECharacteristic::PROPERTY_WRITE
  );
  cUser->setCallbacks(new UserCallbacks());

  BLECharacteristic* cPass = service->createCharacteristic(
    BLE_CHAR_PASS_UUID,
    BLECharacteristic::PROPERTY_WRITE
  );
  cPass->setCallbacks(new UserPasswordCallbacks());

  BLECharacteristic* cApply = service->createCharacteristic(
    BLE_CHAR_APPLY_UUID,
    BLECharacteristic::PROPERTY_WRITE
  );
  cApply->setCallbacks(new ApplyCallbacks());

  bleStatus = service->createCharacteristic(
    BLE_CHAR_STATUS_UUID,
    BLECharacteristic::PROPERTY_READ | BLECharacteristic::PROPERTY_NOTIFY
  );
  bleStatus->addDescriptor(new BLE2902());

  service->start();

  BLEAdvertising* advertising = BLEDevice::getAdvertising();
  advertising->addServiceUUID(BLE_SERVICE_UUID);
  advertising->setScanResponse(true);
  advertising->start();

  atualizarStatusBLE(provisioned ? "CONFIGURADO" : "AGUARDANDO_CONFIGURACAO",
                     provisioned
                       ? "Reconfiguração protegida por usuário/senha JARVIS."
                       : "Aguardando SSID e senha Wi-Fi.");

  Serial.println("[BLE] Provisionamento ativo como: " + nomeBLE);
}

// ============================================================
// HTTP DA CÂMERA
// ============================================================

void handle_stream() {
  WiFiClient client = server.client();
  String response = "HTTP/1.1 200 OK\r\n";
  response += "Content-Type: multipart/x-mixed-replace; boundary=frame\r\n\r\n";
  server.sendContent(response);

  while (client.connected()) {
    camera_fb_t* fb = esp_camera_fb_get();
    if (!fb) {
      Serial.println("Falha na captura do frame");
      break;
    }

    client.print("--frame\r\n");
    client.print("Content-Type: image/jpeg\r\n");
    client.print("Content-Length: " + String(fb->len) + "\r\n\r\n");
    client.write(fb->buf, fb->len);
    client.print("\r\n");
    esp_camera_fb_return(fb);
    yield();
  }
}

void handle_capture() {
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) {
    server.send(500, "text/plain", "Falha na captura");
    return;
  }

  server.sendHeader("Content-Type", "image/jpeg");
  server.sendHeader("Content-Length", String(fb->len));
  server.sendHeader("Access-Control-Allow-Origin", "*");
  WiFiClient client = server.client();
  client.write(fb->buf, fb->len);
  esp_camera_fb_return(fb);
}

void handle_flash_on() {
  digitalWrite(FLASH_LED_PIN, HIGH);
  server.send(200, "application/json", "{\"flash\":1}");
}

void handle_flash_off() {
  digitalWrite(FLASH_LED_PIN, LOW);
  server.send(200, "application/json", "{\"flash\":0}");
}

void handle_status() {
  String json = "{";
  json += "\"dispositivo\":\"" + jsonEscape(String(device_name)) + "\",";
  json += "\"tipo\":\"esp32_cam\",";
  json += "\"configurado\":" + boolJson(provisioned) + ",";
  json += "\"ip\":\"" + WiFi.localIP().toString() + "\",";
  json += "\"rssi\":" + String(WiFi.RSSI()) + ",";
  json += "\"free_heap\":" + String(ESP.getFreeHeap()) + ",";
  json += "\"uptime_s\":" + String(millis() / 1000);
  json += "}";
  server.send(200, "application/json", json);
}

// ============================================================
// JARVIS
// ============================================================

void enviar_heartbeat() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  String url = String(jarvis_server) + "/api/crud.php?tabela=dispositivos_cluster&acao=heartbeat_iot";
  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", device_token);

  String payload = "{";
  payload += "\"device_token\":\"" + String(device_token) + "\",";
  payload += "\"ram_livre\":" + String(ESP.getFreeHeap()) + ",";
  payload += "\"sinal_rssi\":" + String(WiFi.RSSI()) + ",";
  payload += "\"reles_status\":{\"flash\":" + String(digitalRead(FLASH_LED_PIN)) + "}";
  payload += "}";

  int httpCode = http.POST(payload);
  if (httpCode > 0) {
    Serial.printf("[JARVIS] Heartbeat: HTTP %d\n", httpCode);
  } else {
    Serial.printf("[JARVIS] Falha no heartbeat: %s\n", http.errorToString(httpCode).c_str());
  }
  http.end();
}

void alertar_movimento() {
  if (WiFi.status() != WL_CONNECTED) return;

  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) return;

  HTTPClient http;
  String url = String(jarvis_server) + "/api/agente_externo.php?acao=upload_espcam&movimento=1";
  http.begin(url);
  http.addHeader("Content-Type", "image/jpeg");
  http.addHeader("X-Device-Token", device_token);

  int httpCode = http.POST(fb->buf, fb->len);
  Serial.printf("[JARVIS] Alerta de movimento: HTTP %d\n", httpCode);
  http.end();
  esp_camera_fb_return(fb);
}

// ============================================================
// SETUP / LOOP
// ============================================================

void iniciarCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  if (psramFound()) {
    config.frame_size = FRAMESIZE_VGA;
    config.jpeg_quality = 12;
    config.fb_count = 2;
  } else {
    config.frame_size = FRAMESIZE_QVGA;
    config.jpeg_quality = 15;
    config.fb_count = 1;
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("[CAM] Erro ao iniciar câmera: 0x%x\n", err);
  }
}

void setup() {
  Serial.begin(115200);
  pinMode(FLASH_LED_PIN, OUTPUT);
  digitalWrite(FLASH_LED_PIN, LOW);

  carregarConfiguracao();

  // O BLE é iniciado sempre. Em dispositivo novo permite primeira configuração.
  // Em dispositivo já configurado, qualquer alteração exige autenticação JARVIS.
  iniciarBLEProvisionamento();

  iniciarCamera();

  if (provisioned) {
    if (conectarWiFi(wifi_ssid, wifi_password, 20000)) {
      server.on("/stream", handle_stream);
      server.on("/capture", handle_capture);
      server.on("/flash/on", handle_flash_on);
      server.on("/flash/off", handle_flash_off);
      server.on("/status", handle_status);
      server.begin();

      Serial.println("[HTTP] Servidor ESP32-CAM ativo na porta 80.");
      enviar_heartbeat();
    } else {
      Serial.println("[WIFI] Configuração existente não conecta. BLE disponível para reconfiguração autenticada.");
      atualizarStatusBLE("WIFI_OFFLINE", "Wi-Fi salvo não conecta. Informe nova rede e credenciais JARVIS.");
    }
  } else {
    Serial.println("[PROVISION] Dispositivo ainda não configurado. Aguardando BLE.");
    atualizarStatusBLE("AGUARDANDO_CONFIGURACAO", "Informe SSID e senha Wi-Fi.");
  }
}

void loop() {
  if (applyRequested) {
    processarProvisionamento();
  }

  if (WiFi.status() == WL_CONNECTED) {
    server.handleClient();

    if (millis() - last_heartbeat > heartbeat_interval) {
      last_heartbeat = millis();
      enviar_heartbeat();
    }
  }

  delay(2);
}
