/**
 * JARVIS CASA - ESP32-CAM AI Thinker
 * Provisionamento e operacao com seguranca por token via serial.
 *
 * Seguranca:
 *  1. O token secreto da camera e gerado e gravado em NVS.
 *  2. O TOKEN SO E ENTREGUE PELA SERIAL (comando GET_TOKEN).
 *  3. Todas as rotas de video e captura (/capture, /stream, /flash)
 *     exigem o cabecalho HTTP "X-Camera-Token: <token>" ou "?token=<token>".
 *  4. Sem o token autenticado, qualquer requisicao recebe HTTP 401 Unauthorized.
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <Preferences.h>

Preferences preferences;
WebServer server(80);
static const char* NVS_NAMESPACE = "jarviscam";
static const char* FW_VERSION = "1.3.0";

String wifi_ssid, wifi_password, casa_url;
String device_token, device_id, device_name, device_location;
String pairing_request_id, pairing_code;
bool operational = false;
bool configPortal = false;
bool httpStarted = false;
unsigned long lastHeartbeat = 0;
unsigned long lastPairingPoll = 0;
unsigned long lastSerialAnnounce = 0;
const unsigned long HEARTBEAT_INTERVAL = 15000UL;
const unsigned long PAIRING_POLL_INTERVAL = 5000UL;

#define PWDN_GPIO_NUM 32
#define RESET_GPIO_NUM -1
#define XCLK_GPIO_NUM 0
#define SIOD_GPIO_NUM 26
#define SIOC_GPIO_NUM 27
#define Y9_GPIO_NUM 35
#define Y8_GPIO_NUM 34
#define Y7_GPIO_NUM 39
#define Y6_GPIO_NUM 36
#define Y5_GPIO_NUM 21
#define Y4_GPIO_NUM 19
#define Y3_GPIO_NUM 18
#define Y2_GPIO_NUM 5
#define VSYNC_GPIO_NUM 25
#define HREF_GPIO_NUM 23
#define PCLK_GPIO_NUM 22
#define FLASH_LED_PIN 4

// Redes default para auto-configuracao na residencia
const char* DEFAULT_SSIDS[] = {"maurinsrv_1", "maurin_pwl", "maurin-cinema"};
const char* DEFAULT_PASSWORDS[] = {"1425361425", "1425361425", ""};
const int NUM_DEFAULT_NETWORKS = 3;

struct HouseWifiNetwork {
  String ssid;
  String pass;
  int priority;
};

#define MAX_HOUSE_WIFIS 12
HouseWifiNetwork houseWifis[MAX_HOUSE_WIFIS];
int numHouseWifis = 0;
unsigned long lastWifiSurveyTime = 0;
String cachedSurveyJson = "[]";

void loadHouseWifis() {
  preferences.begin(NVS_NAMESPACE, true);
  String jsonStr = preferences.getString("house_wifis", "");
  preferences.end();

  numHouseWifis = 0;
  if (!jsonStr.isEmpty()) {
    int pos = 0;
    while (numHouseWifis < MAX_HOUSE_WIFIS) {
      int sIdx = jsonStr.indexOf("\"ssid\":\"", pos);
      if (sIdx < 0) sIdx = jsonStr.indexOf("\"ssid\": \"", pos);
      sIdx = jsonStr.indexOf("\"", sIdx + 7) + 1;
      if (sIdx < 0) break;
      sIdx = jsonStr.indexOf("\"", sIdx + 7) + 1;
      int sEnd = jsonStr.indexOf("\"", sIdx);
      if (sEnd < 0) break;
      String s = jsonStr.substring(sIdx, sEnd);

      String p = "";
      int pIdx = jsonStr.indexOf("\"senha\":\"", sEnd);
      if (pIdx < 0) pIdx = jsonStr.indexOf("\"pass\":\"", sEnd);
      if (pIdx < 0) pIdx = jsonStr.indexOf("\"senha\": \"", sEnd);
      if (pIdx < 0) pIdx = jsonStr.indexOf("\"pass\": \"", sEnd);
        pIdx = jsonStr.indexOf("\"", pIdx + 7) + 1;
        pIdx = jsonStr.indexOf("\"", pIdx + 7) + 1;
        pIdx = jsonStr.indexOf("\"", pIdx + 7) + 1;
      if (pIdx >= 0) {
        pIdx = jsonStr.indexOf("\"", pIdx + 7) + 1;
        int pEnd = jsonStr.indexOf("\"", pIdx);
        if (pEnd >= 0) p = jsonStr.substring(pIdx, pEnd);
      }

      if (!s.isEmpty()) {
        houseWifis[numHouseWifis].ssid = s;
        houseWifis[numHouseWifis].pass = p;
        houseWifis[numHouseWifis].priority = numHouseWifis + 1;
        numHouseWifis++;
      }
      pos = sEnd + 1;
    }
  }

  // Carrega redes padrão se nenhuma foi gravada
  if (numHouseWifis == 0) {
    for (int i = 0; i < NUM_DEFAULT_NETWORKS && numHouseWifis < MAX_HOUSE_WIFIS; i++) {
      houseWifis[numHouseWifis].ssid = DEFAULT_SSIDS[i];
      houseWifis[numHouseWifis].pass = DEFAULT_PASSWORDS[i];
      houseWifis[numHouseWifis].priority = i + 1;
      numHouseWifis++;
    }
  }
}

void saveHouseWifisJson(const String& jsonStr) {
  preferences.begin(NVS_NAMESPACE, false);
  preferences.putString("house_wifis", jsonStr);
  preferences.end();
  loadHouseWifis();
}

String performWifiSurvey(bool forceScan = false) {
  if (!forceScan && millis() - lastWifiSurveyTime < 45000UL && cachedSurveyJson != "[]") {
    return cachedSurveyJson;
  }
  lastWifiSurveyTime = millis();

  String connectedSsid = WiFi.SSID();
  int connectedRssi = WiFi.RSSI();

  int n = WiFi.scanNetworks(false, true, false, 300);
  String json = "[";
  bool first = true;

  for (int j = 0; j < numHouseWifis; ++j) {
    String s = houseWifis[j].ssid;
    int rssi = -999;
    bool isConn = (s == connectedSsid);
    if (isConn) {
      rssi = connectedRssi;
    } else {
      for (int i = 0; i < n; ++i) {
        if (WiFi.SSID(i) == s) {
          if (WiFi.RSSI(i) > rssi) rssi = WiFi.RSSI(i);
        }
      }
    }

    if (!first) json += ",";
    first = false;
    json += "{\"ssid\":\"" + s + "\",\"rssi\":" + String(rssi) + ",\"connected\":" + (isConn ? "true" : "false") + "}";
  }
  json += "]";
  WiFi.scanDelete();
  cachedSurveyJson = json;
  return json;
}


String jsonEscape(const String& v) {
  String o; o.reserve(v.length()+8);
  for (size_t i=0;i<v.length();i++) {
    char c=v.charAt(i);
    if(c=='\\') o+="\\\\"; else if(c=='\"') o+="\\\"";
    else if(c=='\n') o+="\\n"; else if(c=='\r') o+="\\r"; else if(c=='\t') o+="\\t"; else o+=c;
  }
  return o;
}

String physicalMac(){ 
  String m=WiFi.macAddress(); 
  m.toUpperCase(); 
  return m; 
}

String generateRandomToken() {
  // Garante entropia forte e unica combinando MAC fisico do chip, ciclo de clock,
  // microssegundos e leituras do gerador de hardware esp_random.
  uint64_t chipMac = ESP.getEfuseMac();
  uint32_t cyc = ESP.getCycleCount();
  uint32_t us = (uint32_t)micros();

  // Seed do random do C++ com hardware unico
  randomSeed((uint32_t)(chipMac & 0xFFFFFFFF) ^ cyc ^ us);

  String t = "";
  for (int i = 0; i < 4; i++) {
    uint32_t r1 = esp_random();
    uint32_t r2 = (uint32_t)random(1, 0x7FFFFFFF);
    uint32_t r3 = (uint32_t)((chipMac >> (i * 8)) & 0xFF);
    uint32_t r = r1 ^ r2 ^ (r3 << 24) ^ (ESP.getCycleCount() + (uint32_t)micros());
    char buf[9];
    snprintf(buf, sizeof(buf), "%08x", r);
    t += buf;
  }
  return t;
}

void loadConfig() {
  preferences.begin(NVS_NAMESPACE, false);
  wifi_ssid = preferences.getString("ssid", ""); 
  wifi_password = preferences.getString("wifi_pass", "");
  casa_url = preferences.getString("casa_url", "https://maurinsoft.com.br/casa");
  device_token = preferences.getString("token", ""); 
  device_id = preferences.getString("device_id", "");
  device_name = preferences.getString("name", "ESP32-CAM"); 
  device_location = preferences.getString("location", "Monitoramento");
  pairing_request_id = preferences.getString("pair_req", ""); 
  pairing_code = preferences.getString("pair_code", "");

  // Assegura device_id único baseado em MAC
  if (device_id.isEmpty()) {
    uint64_t chip = ESP.getEfuseMac(); 
    char suffix[7]; 
    snprintf(suffix, sizeof(suffix), "%06X", (uint32_t)(chip & 0xFFFFFF));
    device_id = String("esp32cam-") + suffix;
    preferences.putString("device_id", device_id);
  }

  // Gera e persiste token exclusivo se nao existir
  if (device_token.isEmpty()) {
    device_token = generateRandomToken();
    preferences.putString("token", device_token);
  }
  preferences.end();
  operational = !wifi_ssid.isEmpty() && !casa_url.isEmpty() && !device_token.isEmpty();
  loadHouseWifis();
}

void saveNetwork(const String& ssid, const String& pass, const String& base, const String& name, const String& location) {
  preferences.begin(NVS_NAMESPACE, false);
  preferences.putString("ssid", ssid); 
  preferences.putString("wifi_pass", pass); 
  preferences.putString("casa_url", base);
  preferences.putString("name", name.isEmpty() ? "ESP32-CAM" : name); 
  preferences.putString("location", location.isEmpty() ? "Monitoramento" : location);
  preferences.end(); 
  loadConfig();
}

void saveToken(const String& newToken) {
  preferences.begin(NVS_NAMESPACE, false);
  preferences.putString("token", newToken);
  preferences.end();
  device_token = newToken;
}

bool isAuthorized() {
  // Se o token estiver configurado, exige autenticação
  if (device_token.isEmpty()) return true;
  String headerToken = server.header("X-Camera-Token");
  if (headerToken == device_token) return true;
  String argToken = server.arg("token");
  if (argToken == device_token) return true;
  return false;
}

void rejectUnauthorized() {
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(401, "application/json", "{\"ok\":false,\"erro\":\"Nao autorizado: token da camera obrigatorio via header X-Camera-Token ou param token\",\"codigo\":401}");
}

bool tryConnect(const String& ssid, const String& pass, unsigned long timeoutMs=8000UL) {
  if (ssid.isEmpty()) return false;
  Serial.printf("[WIFI] Conectando a '%s'...\n", ssid.c_str());
  WiFi.mode(WIFI_STA); 
  WiFi.persistent(false); 
  WiFi.disconnect(false,false); 
  delay(100);
  WiFi.begin(ssid.c_str(), pass.c_str());
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < timeoutMs) {
    delay(100);
    yield();
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[WIFI] Conectado! IP=%s RSSI=%d\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    return true;
  }
  WiFi.disconnect(false, false);
  return false;
}

bool scanAndConnectBestWifi() {
  Serial.printf("[WIFI] Escaneando ambiente para verificar a melhor das %d redes da casa...\n", numHouseWifis);
  WiFi.mode(WIFI_STA);
  WiFi.disconnect();
  delay(120);

  int n = WiFi.scanNetworks(false, false, false, 350);
  Serial.printf("[WIFI] Scan concluido: %d redes detectadas.\n", n);

  int bestRssi = -999;
  String bestSsid = "";
  String bestPass = "";

  for (int i = 0; i < n; ++i) {
    String foundSsid = WiFi.SSID(i);
    int foundRssi = WiFi.RSSI(i);

    for (int j = 0; j < numHouseWifis; ++j) {
      if (foundSsid == houseWifis[j].ssid) {
        Serial.printf("[WIFI-MATCH] Rede da casa '%s' encontrada (RSSI: %d dBm)\n", foundSsid.c_str(), foundRssi);
        if (foundRssi > bestRssi) {
          bestRssi = foundRssi;
          bestSsid = foundSsid;
          bestPass = houseWifis[j].pass;
        }
      }
    }
  }

  if (!bestSsid.isEmpty()) {
    Serial.printf("[WIFI] MELHOR SINAL: '%s' (%d dBm). Conectando...\n", bestSsid.c_str(), bestRssi);
    if (tryConnect(bestSsid, bestPass, 9000UL)) {
      wifi_ssid = bestSsid;
      wifi_password = bestPass;
      saveNetwork(bestSsid, bestPass, casa_url, device_name, device_location);
      return true;
    }
  }

  // Fallback sequencial se o scan nao casou nenhuma rede
  Serial.println("[WIFI] Tentando conexao sequencial das redes cadastradas...");
  for (int j = 0; j < numHouseWifis; ++j) {
    if (tryConnect(houseWifis[j].ssid, houseWifis[j].pass, 7000UL)) {
      wifi_ssid = houseWifis[j].ssid;
      wifi_password = houseWifis[j].pass;
      saveNetwork(houseWifis[j].ssid, houseWifis[j].pass, casa_url, device_name, device_location);
      return true;
    }
  }
  return false;
}

bool autoConnectWifi() {
  return scanAndConnectBestWifi();
}

void handleStream(){
  if (!isAuthorized()) {
    rejectUnauthorized();
    return;
  }
  WiFiClient client=server.client();
  server.sendContent("HTTP/1.1 200 OK\r\nContent-Type: multipart/x-mixed-replace; boundary=frame\r\n\r\n");
  while(client.connected()){
    camera_fb_t* fb=esp_camera_fb_get();
    if(!fb) break;
    client.print("--frame\r\nContent-Type: image/jpeg\r\nContent-Length: "+String(fb->len)+"\r\n\r\n");
    client.write(fb->buf,fb->len);
    client.print("\r\n");
    esp_camera_fb_return(fb);
    yield();
  }
}

void handleCapture(){
  if (!isAuthorized()) {
    rejectUnauthorized();
    return;
  }
  camera_fb_t* fb=esp_camera_fb_get();
  if(!fb){
    server.send(500,"text/plain","Falha na captura");
    return;
  }
  server.sendHeader("Content-Type","image/jpeg");
  server.sendHeader("Content-Length",String(fb->len));
  server.sendHeader("Access-Control-Allow-Origin","*");
  server.client().write(fb->buf,fb->len);
  esp_camera_fb_return(fb);
}

void handleStatus(){
  server.sendHeader("Access-Control-Allow-Origin","*");
  bool auth = isAuthorized();
  String survey = performWifiSurvey(false);
  String j = "{\"status\":\"online\",\"type\":\"esp32cam\",\"device_id\":\""+jsonEscape(device_id)+"\",\"name\":\""+jsonEscape(device_name)+"\",\"location\":\""+jsonEscape(device_location)+"\",\"ip\":\""+WiFi.localIP().toString()+"\",\"rssi\":"+String(WiFi.RSSI())+",\"wifi_ssid\":\""+WiFi.SSID()+"\",\"wifi_survey\":"+survey+",\"authenticated\":"+(auth?"true":"false")+"}";
  server.send(200, "application/json", j);
}

unsigned long lastHeartbeatMillis = 0;

void sendHeartbeatToCasa() {
  if (WiFi.status() != WL_CONNECTED || casa_url.isEmpty()) return;
  HTTPClient http;
  String url = casa_url + "/api/camera_discovery.php?acao=heartbeat&device_id=" + device_id 
             + "&ip=" + WiFi.localIP().toString() 
             + "&mac=" + physicalMac() 
             + "&rssi=" + String(WiFi.RSSI());
  http.begin(url);
  http.setTimeout(3500);
  int httpCode = http.GET();
  if (httpCode > 0) {
    Serial.printf("[HEARTBEAT] OK HTTP %d\n", httpCode);
  } else {
    Serial.printf("[HEARTBEAT] Erro: %s\n", http.errorToString(httpCode).c_str());
  }
  http.end();
}

void startCameraHttp(){
  server.stop();
  const char * headerkeys[] = {"X-Camera-Token", "User-Agent"} ;
  size_t headerkeyssize = sizeof(headerkeys)/sizeof(char*);
  server.collectHeaders(headerkeys, headerkeyssize);

  server.on("/stream", handleStream);
  server.on("/capture", handleCapture);
  server.on("/status", handleStatus);
  server.on("/wifi_survey", []{
    if (!isAuthorized()) { rejectUnauthorized(); return; }
    String survey = performWifiSurvey(server.hasArg("refresh"));
    String resp = "{\"device_id\":\"" + device_id + "\",\"connected_ssid\":\"" + WiFi.SSID() 
                + "\",\"connected_rssi\":" + String(WiFi.RSSI()) 
                + ",\"wifis\":" + survey + ",\"timestamp\":" + String(millis()) + "}";
    server.sendHeader("Access-Control-Allow-Origin", "*");
    server.send(200, "application/json", resp);
  });
  server.on("/config/wifis", HTTP_POST, []{
    if (!isAuthorized()) { rejectUnauthorized(); return; }
    if (!server.hasArg("plain")) {
      server.send(400, "application/json", "{\"ok\":false,\"erro\":\"Corpo JSON obrigatorio\"}");
      return;
    }
    String body = server.arg("plain");
    saveHouseWifisJson(body);
    server.sendHeader("Access-Control-Allow-Origin", "*");
    server.send(200, "application/json", "{\"ok\":true,\"count\":" + String(numHouseWifis) + ",\"mensagem\":\"Redes Wi-Fi da casa salvas com sucesso\"}");
  });
  server.on("/flash/on", []{
    if (!isAuthorized()) { rejectUnauthorized(); return; }
    digitalWrite(FLASH_LED_PIN, HIGH);
    server.sendHeader("Access-Control-Allow-Origin","*");
    server.send(200, "application/json", "{\"flash\":1}");
  });
  server.on("/flash/off", []{
    if (!isAuthorized()) { rejectUnauthorized(); return; }
    digitalWrite(FLASH_LED_PIN, LOW);
    server.sendHeader("Access-Control-Allow-Origin","*");
    server.send(200, "application/json", "{\"flash\":0}");
  });
  server.begin();
  httpStarted = true;
  Serial.printf("[CASA-CAM-ONLINE] IP=%s DEVICE=%s\n", WiFi.localIP().toString().c_str(), device_id.c_str());
  sendHeartbeatToCasa();
}

void handleSerialCommands() {
  if (Serial.available()) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();
    if (cmd == "NEW_TOKEN" || cmd == "REGEN_TOKEN" || cmd == "RESET_TOKEN") {
      device_token = generateRandomToken();
      saveToken(device_token);
      Serial.printf("CAM_SECRET:DEVICE_ID=%s:MAC=%s:TOKEN=%s:IP=%s:VER=%s\n",
        device_id.c_str(), physicalMac().c_str(), device_token.c_str(), WiFi.localIP().toString().c_str(), FW_VERSION);
    } else if (cmd == "GET_TOKEN" || cmd == "TOKEN" || cmd == "IDENTIFY" || cmd == "CAM_SECRET" || cmd == "INFO") {
      // O token so e fornecido via conexao fisica Serial
      Serial.printf("CAM_SECRET:DEVICE_ID=%s:MAC=%s:TOKEN=%s:IP=%s:VER=%s\n",
        device_id.c_str(), physicalMac().c_str(), device_token.c_str(), WiFi.localIP().toString().c_str(), FW_VERSION);
      Serial.printf("TOKEN=%s\n", device_token.c_str());
    } else if (cmd.startsWith("SET_TOKEN:")) {
      String newToken = cmd.substring(10);
      newToken.trim();
      if (!newToken.isEmpty()) {
        saveToken(newToken);
        Serial.printf("OK:TOKEN_UPDATED:TOKEN=%s\n", device_token.c_str());
        Serial.printf("CAM_SECRET:DEVICE_ID=%s:MAC=%s:TOKEN=%s:IP=%s:VER=%s\n",
          device_id.c_str(), physicalMac().c_str(), device_token.c_str(), WiFi.localIP().toString().c_str(), FW_VERSION);
      }
    } else if (cmd == "STATUS" || cmd == "DISCOVER") {
      Serial.printf("CASA-CAM:TYPE=esp32cam:DEVICE=%s:MAC=%s:IP=%s:RSSI=%d\n",
        device_id.c_str(), physicalMac().c_str(), WiFi.localIP().toString().c_str(), WiFi.RSSI());
    } else if (cmd.startsWith("SET_WIFI:")) {
      int p1 = cmd.indexOf(':', 9);
      if (p1 > 0) {
        String s = cmd.substring(9, p1);
        int p2 = cmd.indexOf(':', p1+1);
        String pass = (p2 > 0) ? cmd.substring(p1+1, p2) : cmd.substring(p1+1);
        String url = (p2 > 0) ? cmd.substring(p2+1) : casa_url;
        saveNetwork(s, pass, url, device_name, device_location);
        Serial.println("OK:WIFI_SAVED:RESTARTING");
        delay(500);
        ESP.restart();
      }
    } else if (cmd == "FLASH:ON") {
      digitalWrite(FLASH_LED_PIN, HIGH);
      Serial.println("OK:FLASH=1");
    } else if (cmd == "FLASH:OFF") {
      digitalWrite(FLASH_LED_PIN, LOW);
      Serial.println("OK:FLASH=0");
    } else if (cmd == "RESTART") {
      Serial.println("OK:RESTARTING");
      delay(200);
      ESP.restart();
    }
  }
}

void startCamera(){
  camera_config_t c={};
  c.ledc_channel=LEDC_CHANNEL_0;
  c.ledc_timer=LEDC_TIMER_0;
  c.pin_d0=Y2_GPIO_NUM;
  c.pin_d1=Y3_GPIO_NUM;
  c.pin_d2=Y4_GPIO_NUM;
  c.pin_d3=Y5_GPIO_NUM;
  c.pin_d4=Y6_GPIO_NUM;
  c.pin_d5=Y7_GPIO_NUM;
  c.pin_d6=Y8_GPIO_NUM;
  c.pin_d7=Y9_GPIO_NUM;
  c.pin_xclk=XCLK_GPIO_NUM;
  c.pin_pclk=PCLK_GPIO_NUM;
  c.pin_vsync=VSYNC_GPIO_NUM;
  c.pin_href=HREF_GPIO_NUM;
  c.pin_sscb_sda=SIOD_GPIO_NUM;
  c.pin_sscb_scl=SIOC_GPIO_NUM;
  c.pin_pwdn=PWDN_GPIO_NUM;
  c.pin_reset=RESET_GPIO_NUM;
  c.xclk_freq_hz=20000000;
  c.pixel_format=PIXFORMAT_JPEG;
  if(psramFound()){
    c.frame_size=FRAMESIZE_VGA;
    c.jpeg_quality=12;
    c.fb_count=2;
  } else {
    c.frame_size=FRAMESIZE_QVGA;
    c.jpeg_quality=15;
    c.fb_count=1;
  }
  esp_err_t e=esp_camera_init(&c);
  if(e!=ESP_OK) Serial.printf("[CAM] init error 0x%x\n",e);
  else Serial.println("[CAM] Camera OK");
}

void setup(){
  Serial.begin(115200);
  delay(500);
  Serial.println("\n[JARVIS-CASA] ESP32-CAM Token-Protected Security Node");
  pinMode(FLASH_LED_PIN,OUTPUT);
  digitalWrite(FLASH_LED_PIN,LOW);
  loadConfig();
  startCamera();

  // Anuncia token imediatamente pela Serial ao ligar (repetido para sincronizacao garantida)
  for (int i = 0; i < 3; i++) {
    Serial.printf("CAM_SECRET:DEVICE_ID=%s:MAC=%s:TOKEN=%s:IP=%s:VER=%s\n",
      device_id.c_str(), physicalMac().c_str(), device_token.c_str(), WiFi.localIP().toString().c_str(), FW_VERSION);
    Serial.printf("TOKEN=%s\n", device_token.c_str());
    delay(40);
  }

  if (autoConnectWifi()) {
    startCameraHttp();
  } else {
    Serial.println("[SETUP] Wi-Fi desconectado; aguardando comando de configuracao via Serial.");
  }
}

void loop(){
  handleSerialCommands();

  static unsigned long lastWifiRetry = 0;
  if(WiFi.status()!=WL_CONNECTED){
    if (millis() - lastWifiRetry >= 25000UL) {
      lastWifiRetry = millis();
      if (autoConnectWifi()) {
        startCameraHttp();
      }
    }
  }

  if(httpStarted) {
    server.handleClient();
  }

  if(millis() - lastSerialAnnounce >= 15000UL) {
    lastSerialAnnounce = millis();
    Serial.printf("[CASA-CAM-ONLINE] DEVICE=%s IP=%s\n", device_id.c_str(), WiFi.localIP().toString().c_str());
  }

  if(millis() - lastHeartbeatMillis >= 30000UL) {
    lastHeartbeatMillis = millis();
    sendHeartbeatToCasa();
  }

  delay(2);
}
