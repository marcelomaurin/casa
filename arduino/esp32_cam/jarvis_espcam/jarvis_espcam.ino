/**
 * JARVIS CASA - ESP32-CAM AI Thinker
 *
 * Ciclo de vida:
 *  1. Firmware recem-gravado: memoria (NVS) vazia -> MODO PROVISIONAMENTO.
 *     A camera cria o Wi-Fi ABERTO "espcam" (sem senha, IP 192.168.4.1).
 *     O gravafirmware conecta nessa rede e usa:
 *        GET  /provision  -> identidade da camera, token atual e redes gravadas
 *        POST /provision  -> grava token + redes Wi-Fi da casa e reinicia
 *  2. Com redes gravadas -> MODO ESTACAO: escaneia, entra na rede da casa de
 *     MAIOR SINAL, publica /stream e /capture e aceita comandos apenas de IPs
 *     da rede interna (10.x, 172.16-31.x, 192.168.x).
 *  3. Sem conseguir entrar em nenhuma rede por 3 minutos, reabre o "espcam"
 *     (provisionamento livre) por 5 minutos e depois reinicia para tentar de novo.
 *
 * O token nao protege o acesso local; ele identifica a camera no site CASA
 * (heartbeat com X-Camera-Token).
 */

#include "esp_camera.h"
#include "img_converters.h"
#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <WiFiUdp.h>
#include "casa_ca.h"

Preferences preferences;
WebServer server(80);
WiFiUDP discoveryUdp;
bool discoveryStarted = false;
bool cameraSupportsJpeg = true;
bool cameraInitialized = false;
static const char* NVS_NAMESPACE = "jarviscam";
static const char* FW_VERSION = "2.0.1";

// Unico site para onde o token da camera pode ser enviado. A URL gravada na NVS
// (por POST /provision ou SET_CASA_URL) precisa ser HTTPS neste host; o
// certificado e validado contra as raizes de casa_ca.h. Para outro servidor,
// compile com -DCASA_ALLOWED_HOST="\"meu.host\"".
#ifndef CASA_ALLOWED_HOST
#define CASA_ALLOWED_HOST "maurinsoft.com.br"
#endif
static const char* CASA_DEFAULT_URL = "https://" CASA_ALLOWED_HOST "/casa";

static const char* PROV_AP_SSID = "espcam";
static const unsigned long NO_WIFI_TO_AP_MS = 3UL * 60UL * 1000UL;    // sem rede -> reabre espcam
static const unsigned long FALLBACK_AP_WINDOW_MS = 5UL * 60UL * 1000UL; // espcam de fallback fica aberto
static const unsigned long WIFI_RETRY_MS = 25000UL;
// Descoberta na rede da casa: o app manda "ESPCAM_DISCOVER" em broadcast UDP e a
// camera responde com identidade, IP e portas (HTTP de comandos e de stream).
static const uint16_t DISCOVERY_PORT = 32108;
static const uint16_t HTTP_PORT = 80;
static const uint16_t STREAM_PORT = 80;

enum CamMode { MODE_PROVISION, MODE_STATION };
CamMode camMode = MODE_STATION;

String casa_url, device_token, device_id, device_name, device_location;
bool serverStarted = false;
unsigned long lastSerialAnnounce = 0;
unsigned long lastHeartbeatMillis = 0;
unsigned long lastWifiRetry = 0;
unsigned long noWifiSince = 0;
unsigned long provisionDeadline = 0;   // 0 = espcam sem prazo (camera sem configuracao)
unsigned long restartAt = 0;

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

struct HouseWifiNetwork {
  String ssid;
  String pass;
};

#define MAX_HOUSE_WIFIS 12
HouseWifiNetwork houseWifis[MAX_HOUSE_WIFIS];
int numHouseWifis = 0;
unsigned long lastWifiSurveyTime = 0;
String cachedSurveyJson = "[]";

// ---------------------------------------------------------------------------
// JSON minimo (sem ArduinoJson)
// ---------------------------------------------------------------------------

String jsonEscape(const String& v) {
  String o; o.reserve(v.length()+8);
  for (size_t i=0;i<v.length();i++) {
    char c=v.charAt(i);
    if(c=='\\') o+="\\\\"; else if(c=='\"') o+="\\\"";
    else if(c=='\n') o+="\\n"; else if(c=='\r') o+="\\r"; else if(c=='\t') o+="\\t"; else o+=c;
  }
  return o;
}

// Le o valor string de "key" dentro de json[from, limit). Trata espacos e escapes \" \\.
// Retorna false se a chave nao existir no intervalo.
bool jsonStringValue(const String& json, const char* key, int from, int limit, String& out) {
  String needle = String("\"") + key + "\"";
  int k = json.indexOf(needle, from);
  if (k < 0 || k >= limit) return false;
  int i = k + needle.length();
  while (i < limit && (json[i] == ' ' || json[i] == '\t' || json[i] == '\n' || json[i] == '\r')) i++;
  if (i >= limit || json[i] != ':') return false;
  i++;
  while (i < limit && (json[i] == ' ' || json[i] == '\t' || json[i] == '\n' || json[i] == '\r')) i++;
  if (i >= limit || json[i] != '"') return false;
  i++;
  out = "";
  while (i < limit && json[i] != '"') {
    if (json[i] == '\\' && i + 1 < limit) i++;
    out += json[i++];
  }
  return i < limit;
}

void addHouseWifi(const String& ssid, const String& pass) {
  if (ssid.isEmpty() || numHouseWifis >= MAX_HOUSE_WIFIS) return;
  for (int j = 0; j < numHouseWifis; ++j) {
    if (houseWifis[j].ssid == ssid) { houseWifis[j].pass = pass; return; }
  }
  houseWifis[numHouseWifis].ssid = ssid;
  houseWifis[numHouseWifis].pass = pass;
  numHouseWifis++;
}

// Cada objeto {...} mais interno com "ssid" e "senha"/"pass" vira uma rede da casa.
// Aceita {"wifis":[{...}]}, [{...}] ou o corpo completo do POST /provision.
void parseHouseWifis(const String& jsonStr) {
  numHouseWifis = 0;
  int pos = 0;
  while (numHouseWifis < MAX_HOUSE_WIFIS) {
    int objStart = jsonStr.indexOf('{', pos);
    if (objStart < 0) break;
    // Fim do objeto ignorando chaves dentro de strings (senhas podem conter '}')
    int objEnd = -1;
    bool inStr = false;
    for (int i = objStart + 1; i < (int)jsonStr.length(); i++) {
      char c = jsonStr[i];
      if (inStr) {
        if (c == '\\') i++;
        else if (c == '"') inStr = false;
      } else if (c == '"') inStr = true;
      else if (c == '{') objStart = i;
      else if (c == '}') { objEnd = i; break; }
    }
    if (objEnd < 0) break;
    String s, p;
    if (jsonStringValue(jsonStr, "ssid", objStart, objEnd, s)) {
      if (!jsonStringValue(jsonStr, "senha", objStart, objEnd, p)) {
        if (!jsonStringValue(jsonStr, "pass", objStart, objEnd, p)) p = "";
      }
      addHouseWifi(s, p);
    }
    pos = objEnd + 1;
  }
}

String houseWifisToJson(bool withPasswords) {
  String json = "[";
  for (int j = 0; j < numHouseWifis; ++j) {
    if (j) json += ",";
    json += "{\"ssid\":\"" + jsonEscape(houseWifis[j].ssid) + "\"";
    if (withPasswords) json += ",\"senha\":\"" + jsonEscape(houseWifis[j].pass) + "\"";
    json += "}";
  }
  return json + "]";
}

void persistHouseWifis() {
  String json = houseWifisToJson(true);
  preferences.begin(NVS_NAMESPACE, false);
  // Evita regravar a flash quando o daemon reenvia a mesma lista a cada minuto
  if (preferences.getString("house_wifis", "") != json) preferences.putString("house_wifis", json);
  preferences.end();
}

// ---------------------------------------------------------------------------
// Configuracao persistente
// ---------------------------------------------------------------------------

// Aceita somente https://CASA_ALLOWED_HOST, opcionalmente seguido de /caminho.
// Bloqueia http:// e outros dominios: sem isso, quem alcancasse o AP "espcam"
// apontava a camera para um servidor proprio e recebia o token no heartbeat.
bool isAllowedCasaUrl(const String& u) {
  String base = String("https://") + CASA_ALLOWED_HOST;
  if (!u.startsWith(base)) return false;
  if (u.length() == base.length()) return true;
  return u.charAt(base.length()) == '/';
}

String physicalMac(){
  String m=WiFi.macAddress();
  m.toUpperCase();
  return m;
}

void loadConfig() {
  preferences.begin(NVS_NAMESPACE, false);
  casa_url = preferences.getString("casa_url", CASA_DEFAULT_URL);
  if (!isAllowedCasaUrl(casa_url)) {
    Serial.printf("CAM_STAGE:CASA_URL_REJEITADA:%s:usando %s\n", casa_url.c_str(), CASA_DEFAULT_URL);
    casa_url = CASA_DEFAULT_URL;
  }
  device_token = preferences.getString("token", "");
  device_id = preferences.getString("device_id", "");
  device_name = preferences.getString("name", "ESP32-CAM");
  device_location = preferences.getString("location", "Monitoramento");
  String wifisJson = preferences.getString("house_wifis", "");

  // device_id fixo derivado do MAC de fabrica
  if (device_id.isEmpty()) {
    uint64_t chip = ESP.getEfuseMac();
    char suffix[7];
    snprintf(suffix, sizeof(suffix), "%06X", (uint32_t)(chip & 0xFFFFFF));
    device_id = String("esp32cam-") + suffix;
    preferences.putString("device_id", device_id);
  }
  preferences.end();
  parseHouseWifis(wifisJson);
}

void saveString(const char* key, const String& value) {
  preferences.begin(NVS_NAMESPACE, false);
  preferences.putString(key, value);
  preferences.end();
}

bool isValidTokenFormat(const String& t) {
  if (t.length() < 16 || t.length() > 128) return false;
  for (size_t i = 0; i < t.length(); i++) {
    if (!isalnum((unsigned char)t[i])) return false;
  }
  return true;
}

void factoryReset() {
  preferences.begin(NVS_NAMESPACE, false);
  preferences.clear();
  preferences.end();
}

// ---------------------------------------------------------------------------
// Wi-Fi
// ---------------------------------------------------------------------------

bool tryConnect(const String& ssid, const String& pass, unsigned long timeoutMs=8000UL) {
  if (ssid.isEmpty()) return false;
  Serial.printf("CAM_STAGE:WIFI_CONNECTING:%s\n", ssid.c_str());
  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);
  WiFi.disconnect(false,false);
  delay(100);
  WiFi.begin(ssid.c_str(), pass.c_str());
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < timeoutMs) {
    handleSerialCommands();
    delay(100);
    yield();
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("CAM_STAGE:WIFI_OK:SSID=%s:IP=%s:RSSI=%d\n", ssid.c_str(), WiFi.localIP().toString().c_str(), WiFi.RSSI());
    return true;
  }
  WiFi.disconnect(false, false);
  return false;
}

// Escaneia e conecta na rede da casa com MAIOR SINAL; se nenhuma aparecer no
// scan, tenta todas em sequencia.
bool connectBestHouseWifi() {
  if (numHouseWifis == 0) return false;
  Serial.printf("CAM_STAGE:WIFI_SCAN:%d redes cadastradas\n", numHouseWifis);
  WiFi.mode(WIFI_STA);
  WiFi.disconnect();
  delay(120);

  int n = WiFi.scanNetworks(false, false, false, 350);
  int bestRssi = -999, bestIdx = -1;
  for (int i = 0; i < n; ++i) {
    for (int j = 0; j < numHouseWifis; ++j) {
      if (WiFi.SSID(i) == houseWifis[j].ssid) {
        Serial.printf("CAM_STAGE:WIFI_FOUND:%s:%d dBm\n", houseWifis[j].ssid.c_str(), WiFi.RSSI(i));
        if (WiFi.RSSI(i) > bestRssi) { bestRssi = WiFi.RSSI(i); bestIdx = j; }
      }
    }
  }
  WiFi.scanDelete();

  if (bestIdx >= 0) {
    Serial.printf("CAM_STAGE:WIFI_BEST:%s:%d dBm\n", houseWifis[bestIdx].ssid.c_str(), bestRssi);
    if (tryConnect(houseWifis[bestIdx].ssid, houseWifis[bestIdx].pass, 10000UL)) return true;
  }
  for (int j = 0; j < numHouseWifis; ++j) {
    if (j == bestIdx) continue;
    if (tryConnect(houseWifis[j].ssid, houseWifis[j].pass, 7000UL)) return true;
  }
  return false;
}

void startProvisioningAp(const char* reason, unsigned long windowMs) {
  camMode = MODE_PROVISION;
  WiFi.disconnect(true, false);
  delay(100);
  WiFi.mode(WIFI_AP_STA);   // STA ligado so para o /scan
  WiFi.softAP(PROV_AP_SSID);   // rede aberta: o gravafirmware conecta sem senha
  provisionDeadline = windowMs ? millis() + windowMs : 0;
  Serial.printf("CAM_STAGE:AP_ESPCAM:SSID=%s:IP=%s:MOTIVO=%s\n", PROV_AP_SSID,
    WiFi.softAPIP().toString().c_str(), reason);
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
  for (int j = 0; j < numHouseWifis; ++j) {
    String s = houseWifis[j].ssid;
    int rssi = -999;
    bool isConn = (s == connectedSsid);
    if (isConn) {
      rssi = connectedRssi;
    } else {
      for (int i = 0; i < n; ++i) {
        if (WiFi.SSID(i) == s && WiFi.RSSI(i) > rssi) rssi = WiFi.RSSI(i);
      }
    }
    if (j) json += ",";
    json += "{\"ssid\":\"" + jsonEscape(s) + "\",\"rssi\":" + String(rssi) + ",\"connected\":" + (isConn ? "true" : "false") + "}";
  }
  json += "]";
  WiFi.scanDelete();
  cachedSurveyJson = json;
  return json;
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

// Rede interna: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 (inclui 192.168.4.x do espcam)
bool isInternalClient() {
  IPAddress ip = server.client().remoteIP();
  if (ip[0] == 10) return true;
  if (ip[0] == 172 && ip[1] >= 16 && ip[1] <= 31) return true;
  if (ip[0] == 192 && ip[1] == 168) return true;
  return false;
}

bool guardStation() {
  server.sendHeader("Access-Control-Allow-Origin", "*");
  if (camMode != MODE_STATION) {
    server.send(409, "application/json", "{\"ok\":false,\"erro\":\"Camera em modo de provisionamento (rede espcam)\"}");
    return false;
  }
  if (!isInternalClient()) {
    server.send(403, "application/json", "{\"ok\":false,\"erro\":\"Acesso permitido apenas pela rede interna\"}");
    return false;
  }
  return true;
}

bool guardProvision() {
  server.sendHeader("Access-Control-Allow-Origin", "*");
  if (camMode != MODE_PROVISION) {
    server.send(409, "application/json", "{\"ok\":false,\"erro\":\"Provisionamento so pela rede espcam\"}");
    return false;
  }
  return true;
}

String identityJson() {
  return "\"device_id\":\"" + jsonEscape(device_id) + "\",\"mac\":\"" + physicalMac() +
         "\",\"versao\":\"" + FW_VERSION + "\",\"modo\":\"" +
         (camMode == MODE_PROVISION ? "provisionamento" : "estacao") + "\"";
}

void handleProvisionGet() {
  if (!guardProvision()) return;
  String j = "{\"ok\":true," + identityJson() +
             ",\"token\":\"" + jsonEscape(device_token) + "\"" +
             ",\"casa_url\":\"" + jsonEscape(casa_url) + "\"" +
             ",\"wifis\":" + houseWifisToJson(false) + "}";
  server.send(200, "application/json", j);
}

void handleProvisionPost() {
  if (!guardProvision()) return;
  String body = server.arg("plain");
  if (body.isEmpty()) {
    server.send(400, "application/json", "{\"ok\":false,\"erro\":\"Corpo JSON obrigatorio\"}");
    return;
  }
  int limit = body.length();
  String token, url, nome, local;
  bool hasToken = jsonStringValue(body, "token", 0, limit, token);
  if (hasToken && !isValidTokenFormat(token)) {
    server.send(400, "application/json", "{\"ok\":false,\"erro\":\"token invalido (16-128 caracteres alfanumericos)\"}");
    return;
  }
  bool hasUrl = jsonStringValue(body, "casa_url", 0, limit, url);
  if (hasUrl && !url.isEmpty() && !isAllowedCasaUrl(url)) {
    server.send(400, "application/json", String("{\"ok\":false,\"erro\":\"casa_url deve ser https://") + CASA_ALLOWED_HOST + "\"}");
    return;
  }

  HouseWifiNetwork backup[MAX_HOUSE_WIFIS];
  int backupCount = numHouseWifis;
  for (int j = 0; j < numHouseWifis; ++j) backup[j] = houseWifis[j];
  parseHouseWifis(body);
  if (numHouseWifis == 0) {
    for (int j = 0; j < backupCount; ++j) houseWifis[j] = backup[j];
    numHouseWifis = backupCount;
    server.send(400, "application/json", "{\"ok\":false,\"erro\":\"Informe ao menos uma rede em wifis:[{ssid,senha}]\"}");
    return;
  }

  persistHouseWifis();
  if (hasToken) { device_token = token; saveString("token", token); }
  if (hasUrl && !url.isEmpty()) { casa_url = url; saveString("casa_url", url); }
  if (jsonStringValue(body, "nome", 0, limit, nome) && !nome.isEmpty()) { device_name = nome; saveString("name", nome); }
  if (jsonStringValue(body, "local", 0, limit, local) && !local.isEmpty()) { device_location = local; saveString("location", local); }

  Serial.printf("CAM_STAGE:PROVISIONED:REDES=%d:TOKEN=%s\n", numHouseWifis, device_token.isEmpty() ? "NAO" : "SIM");
  server.send(200, "application/json", "{\"ok\":true," + identityJson() +
    ",\"redes\":" + String(numHouseWifis) + ",\"mensagem\":\"Configuracao gravada. Reiniciando para entrar na rede de maior sinal.\"}");
  restartAt = millis() + 1500;   // da tempo da resposta chegar ao app
}

void handleScan() {
  if (!guardProvision()) return;
  int n = WiFi.scanNetworks(false, false, false, 300);
  String j = "{\"ok\":true,\"redes\":[";
  for (int i = 0; i < n; ++i) {
    if (i) j += ",";
    j += "{\"ssid\":\"" + jsonEscape(WiFi.SSID(i)) + "\",\"rssi\":" + String(WiFi.RSSI(i)) + "}";
  }
  WiFi.scanDelete();
  server.send(200, "application/json", j + "]}");
}

void handleStream(){
  if (!guardStation()) return;
  WiFiClient client=server.client();
  client.setNoDelay(true);
  server.sendContent("HTTP/1.1 200 OK\r\nContent-Type: multipart/x-mixed-replace; boundary=frame\r\n\r\n");
  while(client.connected()){
    camera_fb_t* fb=esp_camera_fb_get();
    if(!fb) break;
    if (fb->format == PIXFORMAT_JPEG) {
      client.print("--frame\r\nContent-Type: image/jpeg\r\nContent-Length: "+String(fb->len)+"\r\n\r\n");
      client.write(fb->buf,fb->len);
      client.print("\r\n");
    } else {
      uint8_t *jpg_buf = NULL;
      size_t jpg_len = 0;
      // Qualidade 50 para compressao por software: muito mais rapido e fluido no GC2145
      if (frame2jpg(fb, 50, &jpg_buf, &jpg_len) && jpg_buf) {
        client.print("--frame\r\nContent-Type: image/jpeg\r\nContent-Length: "+String(jpg_len)+"\r\n\r\n");
        client.write(jpg_buf, jpg_len);
        client.print("\r\n");
        free(jpg_buf);
      }
    }
    esp_camera_fb_return(fb);
    // O WebServer e single-thread: enquanto o stream roda, o loop() nao executa.
    // Mantem aqui o heartbeat (senao o site marca a camera offline apos 90 s) e
    // atende reinicios pedidos pela serial.
    handleSerialCommands();
    handleDiscovery();
    if (restartAt && millis() > restartAt) break;
    if (millis() - lastHeartbeatMillis >= 30000UL) {
      lastHeartbeatMillis = millis();
      sendHeartbeatToCasa();
    }
    yield();
  }
}

void handleCapture(){
  if (!guardStation()) return;
  camera_fb_t* fb=esp_camera_fb_get();
  if(!fb){
    server.send(500,"text/plain","Falha na captura");
    return;
  }
  if (fb->format == PIXFORMAT_JPEG) {
    server.setContentLength(fb->len);
    server.send(200, "image/jpeg", "");
    server.client().write(fb->buf,fb->len);
  } else {
    uint8_t *jpg_buf = NULL;
    size_t jpg_len = 0;
    if (frame2jpg(fb, 80, &jpg_buf, &jpg_len) && jpg_buf) {
      server.setContentLength(jpg_len);
      server.send(200, "image/jpeg", "");
      server.client().write(jpg_buf, jpg_len);
      free(jpg_buf);
    } else {
      server.send(500, "text/plain", "Falha na conversao JPEG por software");
    }
  }
  esp_camera_fb_return(fb);
}

void handleStatus(){
  server.sendHeader("Access-Control-Allow-Origin","*");
  bool internal = isInternalClient();
  // Sem scan aqui: o scan trava a camera por segundos e o /status e consultado
  // com frequencia (daemon a cada 8 s). O survey atualizado fica em /wifi_survey.
  String survey = (internal && camMode == MODE_STATION) ? cachedSurveyJson : String("[]");
  String j = "{\"status\":\"online\",\"type\":\"esp32cam\"," + identityJson() +
             ",\"name\":\""+jsonEscape(device_name)+"\",\"location\":\""+jsonEscape(device_location)+
             "\",\"ip\":\""+WiFi.localIP().toString()+"\",\"rssi\":"+String(WiFi.RSSI())+
             ",\"camera_ok\":"+(cameraInitialized ? "true" : "false")+
             ",\"camera_hw_jpeg\":"+(cameraSupportsJpeg ? "true" : "false")+
             ",\"wifi_ssid\":\""+jsonEscape(WiFi.SSID())+"\",\"wifi_survey\":"+survey+"}";
  server.send(200, "application/json", j);
}

void setupHttpRoutes() {
  server.on("/provision", HTTP_GET, handleProvisionGet);
  server.on("/provision", HTTP_POST, handleProvisionPost);
  server.on("/scan", HTTP_GET, handleScan);
  server.on("/stream", handleStream);
  server.on("/capture", handleCapture);
  server.on("/status", handleStatus);
  server.on("/wifi_survey", []{
    if (!guardStation()) return;
    String survey = performWifiSurvey(server.hasArg("refresh"));
    String resp = "{\"device_id\":\"" + device_id + "\",\"connected_ssid\":\"" + jsonEscape(WiFi.SSID())
                + "\",\"connected_rssi\":" + String(WiFi.RSSI())
                + ",\"wifis\":" + survey + ",\"timestamp\":" + String(millis()) + "}";
    server.send(200, "application/json", resp);
  });
  // Daemon repassa as redes cadastradas no site (valem a partir do proximo reinicio/reconexao)
  server.on("/config/wifis", HTTP_POST, []{
    if (!guardStation()) return;
    String body = server.arg("plain");
    HouseWifiNetwork backup[MAX_HOUSE_WIFIS];
    int backupCount = numHouseWifis;
    for (int j = 0; j < numHouseWifis; ++j) backup[j] = houseWifis[j];
    parseHouseWifis(body);
    if (numHouseWifis == 0) {
      for (int j = 0; j < backupCount; ++j) houseWifis[j] = backup[j];
      numHouseWifis = backupCount;
      server.send(400, "application/json", "{\"ok\":false,\"erro\":\"Nenhuma rede valida no corpo\"}");
      return;
    }
    persistHouseWifis();
    server.send(200, "application/json", "{\"ok\":true,\"count\":" + String(numHouseWifis) + "}");
  });
  server.on("/flash/on", []{
    if (!guardStation()) return;
    digitalWrite(FLASH_LED_PIN, HIGH);
    server.send(200, "application/json", "{\"flash\":1}");
  });
  server.on("/flash/off", []{
    if (!guardStation()) return;
    digitalWrite(FLASH_LED_PIN, LOW);
    server.send(200, "application/json", "{\"flash\":0}");
  });
}

void ensureServer() {
  if (!serverStarted) {
    server.begin();
    serverStarted = true;
  }
}

// ---------------------------------------------------------------------------
// Site CASA
// ---------------------------------------------------------------------------

void sendHeartbeatToCasa() {
  if (camMode != MODE_STATION || WiFi.status() != WL_CONNECTED || casa_url.isEmpty() || device_token.isEmpty()) return;
  if (!isAllowedCasaUrl(casa_url)) return;   // nunca envia o token para outro destino
  // Clientes declarados antes do HTTPClient: sao destruidos depois dele
  WiFiClientSecure tls;
  HTTPClient http;
  String url = casa_url + "/api/camera_discovery.php?acao=heartbeat&device_id=" + device_id
             + "&ip=" + WiFi.localIP().toString()
             + "&mac=" + physicalMac()
             + "&rssi=" + String(WiFi.RSSI());
  // HTTPS validado contra as raizes Let's Encrypt (casa_ca.h): o token so sai
  // para o site verdadeiro. Antes estava setInsecure(), aceitando qualquer certificado.
  tls.setCACert(CASA_ROOT_CA);
  if (!http.begin(tls, url)) {
    Serial.println("[HEARTBEAT] Erro: URL invalida");
    return;
  }
  http.setTimeout(5000);
  http.addHeader("X-Camera-Token", device_token);
  int httpCode = http.GET();
  if (httpCode > 0) {
    Serial.printf("[HEARTBEAT] HTTP %d%s\n", httpCode, httpCode == 401 ? " (token nao registrado no site: use o gravafirmware)" : "");
  } else {
    // Falha de TLS (certificado nao confere) tambem cai aqui
    Serial.printf("[HEARTBEAT] Erro: %s\n", http.errorToString(httpCode).c_str());
  }
  http.end();
}

String discoveryJson() {
  return "{\"type\":\"esp32cam\"," + identityJson() +
         ",\"ip\":\"" + WiFi.localIP().toString() + "\"" +
         ",\"porta\":" + String(HTTP_PORT) + ",\"porta_stream\":" + String(STREAM_PORT) +
         ",\"stream\":\"/stream\",\"capture\":\"/capture\"" +
         ",\"ssid\":\"" + jsonEscape(WiFi.SSID()) + "\",\"rssi\":" + String(WiFi.RSSI()) + "}";
}

void handleDiscovery() {
  if (camMode != MODE_STATION || WiFi.status() != WL_CONNECTED) return;
  if (!discoveryStarted) {
    discoveryStarted = discoveryUdp.begin(DISCOVERY_PORT);
    if (discoveryStarted) Serial.printf("CAM_STAGE:DISCOVERY_UDP:%d\n", DISCOVERY_PORT);
  }
  int len = discoveryUdp.parsePacket();
  if (len <= 0) return;
  char buf[64];
  int n = discoveryUdp.read(buf, sizeof(buf) - 1);
  buf[n > 0 ? n : 0] = 0;
  IPAddress from = discoveryUdp.remoteIP();
  bool internal = from[0] == 10 || (from[0] == 172 && from[1] >= 16 && from[1] <= 31) || (from[0] == 192 && from[1] == 168);
  if (!internal || strncmp(buf, "ESPCAM_DISCOVER", 15) != 0) return;
  String reply = discoveryJson();
  discoveryUdp.beginPacket(from, discoveryUdp.remotePort());
  discoveryUdp.print(reply);
  discoveryUdp.endPacket();
}

void serviceBackgroundTasks() {
  handleSerialCommands();
  handleDiscovery();

  if (restartAt && millis() > restartAt) {
    Serial.println("CAM_STAGE:RESTART");
    delay(100);
    ESP.restart();
  }

  if(millis() - lastSerialAnnounce >= 15000UL) {
    lastSerialAnnounce = millis();
    if (camMode == MODE_PROVISION)
      Serial.printf("[CASA-CAM-ESPCAM] DEVICE=%s AP=%s IP=%s CLIENTES=%d\n", device_id.c_str(), PROV_AP_SSID,
        WiFi.softAPIP().toString().c_str(), WiFi.softAPgetStationNum());
    else
      Serial.printf("[CASA-CAM-ONLINE] DEVICE=%s IP=%s\n", device_id.c_str(), WiFi.localIP().toString().c_str());
  }

  if(millis() - lastHeartbeatMillis >= 30000UL) {
    lastHeartbeatMillis = millis();
    sendHeartbeatToCasa();
  }
}

// ---------------------------------------------------------------------------
// Serial (diagnostico e recuperacao)
// ---------------------------------------------------------------------------

const char* resetReasonName() {
  switch (esp_reset_reason()) {
    case ESP_RST_POWERON:  return "POWERON";
    case ESP_RST_EXT:      return "BOTAO_RST";
    case ESP_RST_SW:       return "SOFTWARE";
    case ESP_RST_PANIC:    return "PANIC";
    case ESP_RST_INT_WDT:
    case ESP_RST_TASK_WDT:
    case ESP_RST_WDT:      return "WATCHDOG";
    case ESP_RST_BROWNOUT: return "BROWNOUT_ALIMENTACAO_FRACA";
    case ESP_RST_DEEPSLEEP:return "DEEPSLEEP";
    default:               return "OUTRO";
  }
}

void announceReady() {
  Serial.printf("CAM_READY:VER=%s:DEVICE_ID=%s:MODO=%s\n", FW_VERSION, device_id.c_str(),
    camMode == MODE_PROVISION ? "PROVISIONAMENTO" : "ESTACAO");
}

void handleSerialCommands() {
  if (!Serial.available()) return;
  String cmd = Serial.readStringUntil('\n');
  cmd.trim();
  if (cmd.isEmpty()) return;
  if (cmd == "PING") {
    announceReady();
  } else if (cmd == "GET_TOKEN" || cmd == "INFO") {
    Serial.printf("CAM_SECRET:DEVICE_ID=%s:MAC=%s:TOKEN=%s:IP=%s:VER=%s\n",
      device_id.c_str(), physicalMac().c_str(), device_token.c_str(), WiFi.localIP().toString().c_str(), FW_VERSION);
  } else if (cmd.startsWith("SET_TOKEN:")) {
    String newToken = cmd.substring(10);
    newToken.trim();
    if (!isValidTokenFormat(newToken)) {
      Serial.println("ERR:TOKEN_INVALIDO:16-128 caracteres alfanumericos");
    } else {
      device_token = newToken;
      saveString("token", newToken);
      Serial.printf("OK:TOKEN_UPDATED:TOKEN=%s\n", device_token.c_str());
    }
  } else if (cmd == "CLEAR_WIFIS") {
    numHouseWifis = 0;
    persistHouseWifis();
    Serial.println("OK:WIFIS_CLEARED");
  } else if (cmd.startsWith("ADD_WIFI:")) {
    int p1 = cmd.indexOf(':', 9);
    String s = p1 > 0 ? cmd.substring(9, p1) : cmd.substring(9);
    String pass = p1 > 0 ? cmd.substring(p1 + 1) : "";
    addHouseWifi(s, pass);
    persistHouseWifis();
    Serial.printf("OK:WIFI_ADDED:SSID=%s:TOTAL=%d\n", s.c_str(), numHouseWifis);
  } else if (cmd.startsWith("SET_CASA_URL:")) {
    String u = cmd.substring(13);
    u.trim();
    if (isAllowedCasaUrl(u)) {
      casa_url = u;
      saveString("casa_url", u);
      Serial.printf("OK:CASA_URL_SAVED:%s\n", casa_url.c_str());
    } else {
      Serial.printf("ERR:CASA_URL_INVALIDA:use https://%s\n", CASA_ALLOWED_HOST);
    }
  } else if (cmd.startsWith("SET_WIFI:")) {
    // SET_WIFI:<ssid>:<senha>  adiciona/atualiza uma rede e reinicia
    int p1 = cmd.indexOf(':', 9);
    String s = p1 > 0 ? cmd.substring(9, p1) : cmd.substring(9);
    String pass = p1 > 0 ? cmd.substring(p1 + 1) : "";
    addHouseWifi(s, pass);
    persistHouseWifis();
    Serial.println("OK:WIFI_SAVED:RESTARTING");
    restartAt = millis() + 300;
  } else if (cmd == "FACTORY_RESET") {
    factoryReset();
    Serial.println("OK:FACTORY_RESET:RESTARTING");
    restartAt = millis() + 300;
  } else if (cmd == "STATUS") {
    Serial.printf("CASA-CAM:DEVICE=%s:MAC=%s:MODO=%s:IP=%s:RSSI=%d:REDES=%d\n",
      device_id.c_str(), physicalMac().c_str(), camMode == MODE_PROVISION ? "PROVISIONAMENTO" : "ESTACAO",
      WiFi.localIP().toString().c_str(), WiFi.RSSI(), numHouseWifis);
  } else if (cmd == "FLASH:ON") {
    digitalWrite(FLASH_LED_PIN, HIGH);
    Serial.println("OK:FLASH=1");
  } else if (cmd == "FLASH:OFF") {
    digitalWrite(FLASH_LED_PIN, LOW);
    Serial.println("OK:FLASH=0");
  } else if (cmd == "RESTART") {
    Serial.println("OK:RESTARTING");
    restartAt = millis() + 200;
  } else {
    Serial.printf("ERR:COMANDO_DESCONHECIDO:%s\n", cmd.c_str());
  }
}

// ---------------------------------------------------------------------------

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

  // Fallback se o sensor nao suportar hardware JPEG (ex: GC2145, OV7670)
  if (e == ESP_ERR_NOT_SUPPORTED) {
    Serial.println("CAM_STAGE:CAMERA_INFO:Sensor sem JPEG por HW (GC2145). Configurando YUV422 para maior velocidade...");
    esp_camera_deinit();
    delay(100);
    // YUV422 converte diretamente para JPEG sem matriz RGB intermediaria
    c.pixel_format = PIXFORMAT_YUV422;
    c.frame_size = FRAMESIZE_QVGA;
    c.jpeg_quality = 10;
    c.fb_count = 1;
    e = esp_camera_init(&c);
    if (e == ESP_OK) {
      cameraSupportsJpeg = false;
      cameraInitialized = true;
      Serial.println("CAM_STAGE:CAMERA_OK:YUV422_SW_JPEG");
      sensor_t *s = esp_camera_sensor_get();
      if (s) Serial.printf("CAM_STAGE:SENSOR_INFO:PID=0x%x\n", s->id.PID);
      return;
    }
    // Fallback para RGB565
    Serial.println("CAM_STAGE:CAMERA_INFO:Tentando RGB565...");
    esp_camera_deinit();
    delay(100);
    c.pixel_format = PIXFORMAT_RGB565;
    e = esp_camera_init(&c);
    if (e == ESP_OK) {
      cameraSupportsJpeg = false;
      cameraInitialized = true;
      Serial.println("CAM_STAGE:CAMERA_OK:RGB565_SW_JPEG");
      sensor_t *s = esp_camera_sensor_get();
      if (s) Serial.printf("CAM_STAGE:SENSOR_INFO:PID=0x%x\n", s->id.PID);
      return;
    }
  }

  if (e != ESP_OK) {
    cameraInitialized = false;
    Serial.printf("CAM_STAGE:CAMERA_ERR:0x%x (modulo da camera/flat ou alimentacao)\n", e);
  } else {
    cameraSupportsJpeg = true;
    cameraInitialized = true;
    Serial.println("CAM_STAGE:CAMERA_OK");
    sensor_t *s = esp_camera_sensor_get();
    if (s) Serial.printf("CAM_STAGE:SENSOR_INFO:PID=0x%x\n", s->id.PID);
  }
}

void setup(){
  Serial.begin(115200);
  Serial.setTimeout(200);
  delay(500);
  Serial.println();
  Serial.printf("CAM_STAGE:BOOT:VER=%s:RESET=%s\n", FW_VERSION, resetReasonName());
  pinMode(FLASH_LED_PIN,OUTPUT);
  digitalWrite(FLASH_LED_PIN,LOW);
  loadConfig();
  Serial.printf("CAM_STAGE:CONFIG_OK:DEVICE_ID=%s:REDES=%d:TOKEN=%s\n", device_id.c_str(), numHouseWifis,
    device_token.isEmpty() ? "NAO" : "SIM");
  startCamera();
  setupHttpRoutes();

  if (numHouseWifis == 0) {
    // Firmware recem-gravado: espera o gravafirmware pela rede espcam, sem prazo
    startProvisioningAp("SEM_CONFIGURACAO", 0);
  } else if (connectBestHouseWifi()) {
    camMode = MODE_STATION;
  } else {
    camMode = MODE_STATION;
    noWifiSince = millis();
    lastWifiRetry = millis();
    Serial.println("CAM_STAGE:WIFI_FAIL:nenhuma rede da casa conectou; tentando de novo");
  }
  ensureServer();
  announceReady();
  lastHeartbeatMillis = millis() - 25000UL;   // primeiro heartbeat logo apos conectar
}

void loop(){
  if (camMode == MODE_PROVISION) {
    // espcam de fallback: fecha depois da janela (se ninguem estiver conectado) e tenta as redes de novo
    if (provisionDeadline && millis() > provisionDeadline && WiFi.softAPgetStationNum() == 0) {
      Serial.println("CAM_STAGE:AP_TIMEOUT:reiniciando para tentar as redes da casa");
      restartAt = millis();
    }
  } else if (WiFi.status() != WL_CONNECTED) {
    if (noWifiSince == 0) noWifiSince = millis();
    if (millis() - noWifiSince >= NO_WIFI_TO_AP_MS) {
      startProvisioningAp("SEM_REDE_HA_3_MIN", FALLBACK_AP_WINDOW_MS);
    } else if (millis() - lastWifiRetry >= WIFI_RETRY_MS) {
      lastWifiRetry = millis();
      if (connectBestHouseWifi()) noWifiSince = 0;
    }
  } else {
    noWifiSince = 0;
  }

  server.handleClient();
  serviceBackgroundTasks();
  delay(2);
}
