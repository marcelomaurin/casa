#include "jarvis_wifi.h"
#include <WiFi.h>
#include <Preferences.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>

static Preferences wifiPrefs;
static String casaBase;
static String casaToken;
static unsigned long lastRetry = 0;
static bool scanRunning = false;

static String keySsid(uint8_t slot){ return "ssid" + String(slot); }
static String keyPass(uint8_t slot){ return "pass" + String(slot); }

static bool isKnownSsid(const String &ssid){
  for(uint8_t i=0;i<5;i++) if(wifiPrefs.getString(keySsid(i).c_str(), "") == ssid) return true;
  return false;
}

void jarvisWifiBegin(){
  wifiPrefs.begin("jarviswifi", false);
  casaBase = wifiPrefs.getString("base", "https://maurinsoft.com.br/casa");
  casaToken = wifiPrefs.getString("token", "");
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
}

void jarvisWifiLoop(){
  if(WiFi.status()==WL_CONNECTED) return;
  // Evita scans automáticos frequentes, que consomem energia e podem degradar a UI.
  if(millis()-lastRetry < 120000UL || scanRunning) return;
  lastRetry = millis();
}

bool jarvisWifiIsConnected(){ return WiFi.status()==WL_CONNECTED; }
String jarvisWifiSsid(){ return jarvisWifiIsConnected() ? WiFi.SSID() : String(); }
int jarvisWifiRssi(){ return jarvisWifiIsConnected() ? WiFi.RSSI() : -127; }

bool jarvisWifiSetProfile(uint8_t slot, const String &ssid, const String &password){
  if(slot>4 || ssid.length()==0 || ssid.length()>32 || password.length()>63) return false;
  if(password.length()>0 && password.length()<8) return false;
  wifiPrefs.putString(keySsid(slot).c_str(), ssid);
  wifiPrefs.putString(keyPass(slot).c_str(), password);
  return true;
}

bool jarvisWifiGetProfile(uint8_t slot, String &ssid, String &password){
  if(slot>4) return false;
  ssid = wifiPrefs.getString(keySsid(slot).c_str(), "");
  password = wifiPrefs.getString(keyPass(slot).c_str(), "");
  return !ssid.isEmpty();
}

int jarvisWifiFindFreeSlot(){
  for(uint8_t i=0;i<5;i++) if(wifiPrefs.getString(keySsid(i).c_str(), "").isEmpty()) return i;
  return 0; // se lotado, substitui o slot 0 explicitamente pela UI.
}

void jarvisWifiClearProfiles(){
  for(uint8_t i=0;i<5;i++){
    wifiPrefs.remove(keySsid(i).c_str());
    wifiPrefs.remove(keyPass(i).c_str());
  }
}

bool jarvisWifiScanStart(){
  if(scanRunning) return true;
  WiFi.scanDelete();
  int rc = WiFi.scanNetworks(true, true);
  if(rc == WIFI_SCAN_FAILED) return false;
  scanRunning = true;
  return true;
}

int jarvisWifiScanPoll(JarvisWifiNetwork *out, int maxItems){
  if(!out || maxItems<=0) return -2;
  int n = WiFi.scanComplete();
  if(n == WIFI_SCAN_RUNNING){ scanRunning = true; return -1; }
  if(n < 0){ scanRunning = false; WiFi.scanDelete(); return -2; }
  scanRunning = false;
  int copied = 0;
  for(int i=0;i<n && copied<maxItems;i++){
    String ssid = WiFi.SSID(i);
    if(ssid.isEmpty()) continue;
    bool duplicate = false;
    for(int j=0;j<copied;j++) if(out[j].ssid == ssid){ duplicate=true; if(WiFi.RSSI(i)>out[j].rssi) out[j].rssi=WiFi.RSSI(i); break; }
    if(duplicate) continue;
    out[copied].ssid = ssid;
    out[copied].rssi = WiFi.RSSI(i);
    out[copied].secure = WiFi.encryptionType(i) != WIFI_AUTH_OPEN;
    out[copied].known = isKnownSsid(ssid);
    copied++;
  }
  // Ordena por melhor RSSI.
  for(int i=0;i<copied-1;i++) for(int j=i+1;j<copied;j++) if(out[j].rssi > out[i].rssi){ JarvisWifiNetwork t=out[i]; out[i]=out[j]; out[j]=t; }
  WiFi.scanDelete();
  return copied;
}

bool jarvisWifiScanRunning(){ return scanRunning; }

void jarvisWifiSetCasa(const String &baseUrl, const String &deviceToken){
  casaBase = baseUrl;
  casaBase.trim();
  while(casaBase.endsWith("/")) casaBase.remove(casaBase.length()-1);
  casaToken = deviceToken;
  wifiPrefs.putString("base", casaBase);
  wifiPrefs.putString("token", casaToken);
}

static bool waitConnected(uint32_t timeoutMs){
  unsigned long start=millis();
  while(WiFi.status()!=WL_CONNECTED && millis()-start<timeoutMs){ delay(20); yield(); }
  return WiFi.status()==WL_CONNECTED;
}

bool jarvisWifiConnectProfile(uint8_t slot, uint32_t timeoutMs){
  String ssid, pass;
  if(!jarvisWifiGetProfile(slot, ssid, pass)) return false;
  if(WiFi.status()==WL_CONNECTED && WiFi.SSID()==ssid) return true;
  WiFi.disconnect(false, false);
  delay(20);
  WiFi.begin(ssid.c_str(), pass.c_str());
  return waitConnected(timeoutMs);
}

bool jarvisWifiConnectBestKnown(uint32_t timeoutMs){
  JarvisWifiNetwork nets[12];
  if(!jarvisWifiScanStart()) return false;
  unsigned long start=millis();
  int count=-1;
  while(count==-1 && millis()-start<5000UL){ count=jarvisWifiScanPoll(nets,12); delay(20); yield(); }
  if(count<=0) return false;
  for(int i=0;i<count;i++){
    if(!nets[i].known) continue;
    for(uint8_t slot=0;slot<5;slot++){
      String ssid, pass;
      if(jarvisWifiGetProfile(slot, ssid, pass) && ssid==nets[i].ssid) return jarvisWifiConnectProfile(slot, timeoutMs);
    }
  }
  return false;
}

bool jarvisWifiPostJson(const String &path, const String &jsonPayload, String *response){
  if(!jarvisWifiIsConnected() || casaToken.isEmpty()) return false;
  WiFiClientSecure tls;
  // Fase de transição: trocar por setCACert() antes de produção.
  tls.setInsecure();
  tls.setTimeout(5);
  HTTPClient http;
  http.setConnectTimeout(3000);
  http.setTimeout(5000);
  String url = casaBase + (path.startsWith("/") ? path : "/" + path);
  if(!http.begin(tls, url)) return false;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", "Bearer " + casaToken);
  http.addHeader("X-Device-Token", casaToken);
  int code=http.POST(jsonPayload);
  if(response && code>0) *response=http.getString();
  http.end();
  return code>=200 && code<300;
}
