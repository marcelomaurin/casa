/*
 * CASA/JARVIS - CONTROLADOR DA PISCINA
 * ESP8266 + 2 reles.
 *
 * Arquitetura distribuida:
 *   - o dispositivo continua executando os reles localmente;
 *   - registra estado/telemetria no dominio central;
 *   - credenciais Wi-Fi e token nao sao versionados com valores reais.
 */

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>

#define STASSID "SUA_REDE_WIFI"
#define STAPSK  "SUA_SENHA_WIFI"

const char* CASA_URL = "https://casa.maurinsoft.com.br";
const char* DEVICE_ID = "esp8266-piscina-01";
const char* DEVICE_TOKEN = "TOKEN_INDIVIDUAL_DA_PISCINA";
const char* DEVICE_CAPABILITIES = "pool,relay,telemetry,rssi";

#ifndef D5
#define D5 (14)
#define D6 (12)
#endif

#define RELE01 D5
#define RELE02 D6

bool rele01 = false;
bool rele02 = false;
unsigned long ultimoHeartbeat = 0;
const unsigned long HEARTBEAT_MS = 30000UL;

void set_rele01(bool status) {
  rele01 = status;
  digitalWrite(RELE01, status ? LOW : HIGH);
}

void set_rele02(bool status) {
  rele02 = status;
  digitalWrite(RELE02, status ? LOW : HIGH);
}

void set_wifi() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(STASSID, STAPSK);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print('.');
    yield();
  }
  Serial.println();
  Serial.println("WiFi conectado: " + WiFi.localIP().toString());
}

void set_pins() {
  pinMode(RELE01, OUTPUT);
  pinMode(RELE02, OUTPUT);
  set_rele01(false);
  set_rele02(false);
}

void enviarHeartbeat() {
  if (WiFi.status() != WL_CONNECTED) return;

  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
  client->setInsecure();

  HTTPClient http;
  String url = String(CASA_URL) + "/api/crud.php?tabela=dispositivos_cluster&acao=heartbeat_iot";
  if (!http.begin(*client, url)) return;

  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", String("Bearer ") + DEVICE_TOKEN);
  http.addHeader("X-Device-Token", DEVICE_TOKEN);
  http.addHeader("X-Device-Id", DEVICE_ID);
  http.addHeader("X-Device-Capabilities", DEVICE_CAPABILITIES);

  String payload = "{";
  payload += "\"device_id\":\"" + String(DEVICE_ID) + "\",";
  payload += "\"capabilities\":\"" + String(DEVICE_CAPABILITIES) + "\",";
  payload += "\"sinal_rssi\":" + String(WiFi.RSSI()) + ",";
  payload += "\"uptime_s\":" + String(millis() / 1000UL) + ",";
  payload += "\"reles_status\":{\"rele01\":" + String(rele01 ? 1 : 0) + ",\"rele02\":" + String(rele02 ? 1 : 0) + "}";
  payload += "}";

  int code = http.POST(payload);
  Serial.printf("[CASA] Heartbeat HTTP %d\n", code);
  http.end();
}

void processacmd(String info) {
  info.trim();
  info.toUpperCase();

  if (info == "RELE01_ON") set_rele01(true);
  else if (info == "RELE01_OFF") set_rele01(false);
  else if (info == "RELE02_ON") set_rele02(true);
  else if (info == "RELE02_OFF") set_rele02(false);

  enviarHeartbeat();
}

void serialEvent() {
  static String inputString = "";
  while (Serial.available()) {
    char inChar = (char)Serial.read();
    if (inChar == '\n') {
      processacmd(inputString);
      inputString = "";
    } else if (inChar != '\r') {
      inputString += inChar;
    }
  }
}

void setup() {
  Serial.begin(9600);
  set_pins();
  set_wifi();
  enviarHeartbeat();
  ultimoHeartbeat = millis();
  Serial.println("CASA/JARVIS piscina pronta: https://casa.maurinsoft.com.br");
}

void loop() {
  serialEvent();

  if (WiFi.status() != WL_CONNECTED) {
    set_wifi();
  }

  if (millis() - ultimoHeartbeat >= HEARTBEAT_MS) {
    ultimoHeartbeat = millis();
    enviarHeartbeat();
  }

  delay(20);
}
