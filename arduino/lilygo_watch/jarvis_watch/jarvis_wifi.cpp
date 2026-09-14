#include "jarvis_wifi.h"
#include <WiFi.h>
#include <Preferences.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>

static Preferences wifiPrefs;
static String casaBase;
static String casaToken;
static unsigned long lastRetry = 0;

static String keySsid(uint8_t slot){ return "ssid" + String(slot); }
static String keyPass(uint8_t slot){ return "pass" + String(slot); }

void jarvisWifiBegin(){
  wifiPrefs.begin("jarviswifi", false);
  casaBase = wifiPrefs.getString("base", "https://maurinsoft.com.br/casa");
  casaToken = wifiPrefs.getString("token", "");
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
}

void jarvisWifiLoop(){
  if(WiFi.status()==WL_CONNECTED) return;
  if(millis()-lastRetry < 60000UL) return;
  lastRetry = millis();
  jarvisWifiConnectBestKnown(8000);
}

bool jarvisWifiIsConnected(){ return WiFi.status()==WL_CONNECTED; }
String jarvisWifiSsid(){ return jarvisWifiIsConnected() ? WiFi.SSID() : String(); }
int jarvisWifiRssi(){ return jarvisWifiIsConnected() ? WiFi.RSSI() : -127; }

bool jarvisWifiSetProfile(uint8_t slot, const String &ssid, const String &password){
  if(slot>4 || ssid.length()==0 || ssid.length()>32 || password.length()>63) return false;
  wifiPrefs.putString(keySsid(slot).c_str(), ssid);
  wifiPrefs.putString(keyPass(slot).c_str(), password);
  return true;
}

void jarvisWifiClearProfiles(){
  for(uint8_t i=0;i<5;i++){
    wifiPrefs.remove(keySsid(i).c_str());
    wifiPrefs.remove(keyPass(i).c_str());
  }
}

void jarvisWifiSetCasa(const String &baseUrl, const String &deviceToken){
  casaBase = baseUrl;
  casaBase.trim();
  while(casaBase.endsWith("/")) casaBase.remove(casaBase.length()-1);
  casaToken = deviceToken;
  wifiPrefs.putString("base", casaBase);
  wifiPrefs.putString("token", casaToken);
}

bool jarvisWifiConnectBestKnown(uint32_t timeoutMs){
  struct Candidate { String ssid; String pass; int rssi; } best;
  best.rssi = -1000;
  int count = WiFi.scanNetworks(false, true);
  if(count <= 0) return false;
  for(uint8_t slot=0;slot<5;slot++){
    String ssid = wifiPrefs.getString(keySsid(slot).c_str(), "");
    if(ssid.isEmpty()) continue;
    String pass = wifiPrefs.getString(keyPass(slot).c_str(), "");
    for(int i=0;i<count;i++){
      if(WiFi.SSID(i)==ssid && WiFi.RSSI(i)>best.rssi){ best.ssid=ssid; best.pass=pass; best.rssi=WiFi.RSSI(i); }
    }
  }
  WiFi.scanDelete();
  if(best.ssid.isEmpty()) return false;
  if(WiFi.status()==WL_CONNECTED && WiFi.SSID()==best.ssid) return true;
  WiFi.disconnect(true, false); delay(100);
  WiFi.begin(best.ssid.c_str(), best.pass.c_str());
  unsigned long start=millis();
  while(WiFi.status()!=WL_CONNECTED && millis()-start<timeoutMs) delay(150);
  return WiFi.status()==WL_CONNECTED;
}

bool jarvisWifiPostJson(const String &path, const String &jsonPayload, String *response){
  if(!jarvisWifiIsConnected() || casaToken.isEmpty()) return false;
  WiFiClientSecure tls;
  // Fase de transição: o Watch ainda não possui o CA da implantação. Substituir
  // por setCACert() antes de produção para validar o certificado do servidor.
  tls.setInsecure();
  HTTPClient http;
  String url = casaBase + (path.startsWith("/") ? path : "/" + path);
  if(!http.begin(tls, url)) return false;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", "Bearer " + casaToken);
  http.addHeader("X-Device-Token", casaToken);
  int code=http.POST(jsonPayload);
  if(response) *response=http.getString();
  http.end();
  return code>=200 && code<300;
}
