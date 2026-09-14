/*
 * CASA/JARVIS - CONTROLE DA CADEIRA / NEXTION
 * ESP8266 + interface serial.
 *
 * Arquitetura distribuida:
 *   comandos -> https://maurinsoft.com.br/casa/api/v1/comando
 *   autenticacao por token individual do dispositivo.
 */

#include <NTPClient.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <SoftwareSerial.h>
#include <WiFiUdp.h>

#define STASSID "SUA_REDE_WIFI"
#define STAPSK  "SUA_SENHA_WIFI"

const char* CASA_URL = "https://maurinsoft.com.br/casa";
const char* DEVICE_ID = "esp8266-cadeira-01";
const char* DEVICE_TOKEN = "TOKEN_INDIVIDUAL_DA_CADEIRA";
const char* DEVICE_CAPABILITIES = "nextion,chair,commands,clock";

const long utcOffsetInSeconds = -10800;
WiFiUDP ntpUDP;
NTPClient timeClient(ntpUDP, "south-america.pool.ntp.org", utcOffsetInSeconds);
SoftwareSerial swSer;

#ifndef D5
#define D5 (14)
#define D6 (12)
#endif

#define BAUD_RATE 9600

String Buffer;
int flag = 0;
int flgImprime = 0;

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

void set_time() {
  timeClient.begin();
}

void set_serial() {
  Serial.begin(9600);
  swSer.begin(BAUD_RATE, SWSERIAL_8N1, D5, D6, false, 128);
}

void Mainpage() {
  char info[40];
  sprintf(info, "page Main%c%c%c", 0xFF, 0xFF, 0xFF);
  swSer.println(info);
}

void EntraTXT(char* device, char* valor) {
  char info[96];
  sprintf(info, "%s.txt=%c%s%c%c%c%c", device, 0x22, valor, 0x22, 0xFF, 0xFF, 0xFF);
  swSer.print(info);
}

void Atualizahora() {
  char hora[20];
  String f = timeClient.getFormattedTime();
  f.toCharArray(hora, sizeof(hora));
  EntraTXT((char*)"hora", hora);
}

String jsonEscape(const String& s) {
  String out;
  for (unsigned int i = 0; i < s.length(); i++) {
    char c = s[i];
    if (c == '\\' || c == '"') out += '\\';
    if (c == '\n') out += "\\n";
    else if (c != '\r') out += c;
  }
  return out;
}

bool SendCMD(const String& cmd) {
  if (WiFi.status() != WL_CONNECTED) return false;

  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
  client->setInsecure();

  HTTPClient http;
  String url = String(CASA_URL) + "/api/v1/comando";
  if (!http.begin(*client, url)) return false;

  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", String("Bearer ") + DEVICE_TOKEN);
  http.addHeader("X-Device-Token", DEVICE_TOKEN);
  http.addHeader("X-Device-Id", DEVICE_ID);
  http.addHeader("X-Device-Capabilities", DEVICE_CAPABILITIES);

  String payload = "{";
  payload += "\"comando\":\"" + jsonEscape(cmd) + "\",";
  payload += "\"origem\":\"ESP8266_CADEIRA\",";
  payload += "\"device_id\":\"" + String(DEVICE_ID) + "\",";
  payload += "\"capabilities\":\"" + String(DEVICE_CAPABILITIES) + "\"}";

  int code = http.POST(payload);
  String response = http.getString();
  Serial.printf("[CASA] HTTP %d %s\n", code, response.c_str());
  http.end();
  return code >= 200 && code < 300;
}

void ProcessaCMD(const String& cmd) {
  String clean = cmd;
  clean.trim();
  if (clean.length() == 0) return;
  SendCMD(clean);
}

void setup() {
  set_serial();
  set_wifi();
  set_time();
  Buffer = "";
  Mainpage();
  Serial.println("CASA/JARVIS cadeira pronta: https://maurinsoft.com.br/casa");
}

void loop() {
  timeClient.update();
  Atualizahora();

  while (swSer.available() > 0) {
    char c = swSer.read();
    if (c == 'e') flag = 1;
    if (c == 0xFF) flag = 0;

    if (flag == 0) {
      if (c != 0xFF && c != 0x0A) Buffer += c;
      if (c == 0x0A) flgImprime = 1;
    }
  }

  if (Buffer != "" && flgImprime != 0) {
    ProcessaCMD(Buffer);
    flgImprime = 0;
    Buffer = "";
  }

  delay(20);
}
