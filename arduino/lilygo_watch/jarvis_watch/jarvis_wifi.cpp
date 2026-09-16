#include "jarvis_wifi.h"
#include <WiFi.h>
#include <Preferences.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>

static Preferences wifiPrefs;
static String casaBase;
static String casaToken;
static String casaDeviceId;
static int preferredSlot = -1;
static unsigned long lastRetry = 0;
static bool reconnectInProgress = false;
static const unsigned long WIFI_RETRY_INTERVAL_MS = 10000UL;
static bool scanRunning = false;
static bool casaOnline = false;
static bool casaChecked = false;
static bool casaCheckRequested = true;
static unsigned long lastCasaCheck = 0;
static const unsigned long CASA_CHECK_INTERVAL_MS = 30000UL;

static String keySsid(uint8_t slot){ return "ssid" + String(slot); }
static String keyPass(uint8_t slot){ return "pass" + String(slot); }

static bool isKnownSsid(const String &ssid){
  for(uint8_t i=0;i<5;i++) if(wifiPrefs.getString(keySsid(i).c_str(), "") == ssid) return true;
  return false;
}

void jarvisWifiBegin(){
  wifiPrefs.begin("jarviswifi", false);
  casaBase = wifiPrefs.getString("base", "https://casa.maurinsoft.com.br");
  if(casaBase == "https://maurinsoft.com.br/casa"){
    casaBase = "https://casa.maurinsoft.com.br";
    wifiPrefs.putString("base", casaBase);
  }
  casaToken = wifiPrefs.getString("token", "");
  casaDeviceId = wifiPrefs.getString("deviceid", "");
  preferredSlot = wifiPrefs.getInt("lastslot", -1);
  if(preferredSlot < 0 || preferredSlot > 4) preferredSlot = -1;
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  lastRetry = 0;
  reconnectInProgress = false;
}

static bool checkCasaReachability(){
  if(WiFi.status()!=WL_CONNECTED || casaBase.isEmpty()) return false;

  WiFiClientSecure tls;
  tls.setInsecure();
  tls.setTimeout(2);

  HTTPClient http;
  http.setConnectTimeout(1200);
  http.setTimeout(1500);

  if(!http.begin(tls, casaBase)) return false;
  http.addHeader("Accept", "text/html,application/json;q=0.9,*/*;q=0.8");
  int code=http.GET();
  http.end();

  // Qualquer resposta HTTP normal 2xx/3xx confirma que o servidor CASA
  // foi alcancado por HTTPS.
  return code>=200 && code<400;
}

void jarvisWifiLoop(){
  if(WiFi.status()!=WL_CONNECTED){
    casaOnline=false;
    casaChecked=false;
    casaCheckRequested=true;

    if(scanRunning) return;

    // WiFi.setAutoReconnect() nao e suficiente apos deep sleep/WIFI_OFF.
    // Se a associacao cair, reinicia explicitamente o ultimo perfil conhecido.
    unsigned long now = millis();
    if(lastRetry == 0 || now-lastRetry >= WIFI_RETRY_INTERVAL_MS){
      lastRetry = now;
      reconnectInProgress = jarvisWifiStartPreferred();
    }
    return;
  }

  reconnectInProgress=false;
  lastRetry=millis();

  if(scanRunning) return;

  String connectedSsid = WiFi.SSID();
  for(uint8_t slot=0; slot<5; slot++){
    if(wifiPrefs.getString(keySsid(slot).c_str(), "") == connectedSsid){
      if(preferredSlot != slot){
        preferredSlot = slot;
        wifiPrefs.putInt("lastslot", preferredSlot);
      }
      break;
    }
  }

  unsigned long now=millis();
  if(!casaCheckRequested && now-lastCasaCheck < CASA_CHECK_INTERVAL_MS) return;

  casaCheckRequested=false;
  lastCasaCheck=now;
  casaOnline=checkCasaReachability();
  casaChecked=true;
}

bool jarvisWifiIsConnected(){ return WiFi.status()==WL_CONNECTED; }
String jarvisWifiSsid(){ return jarvisWifiIsConnected() ? WiFi.SSID() : String(); }
int jarvisWifiRssi(){ return jarvisWifiIsConnected() ? WiFi.RSSI() : -127; }
bool jarvisWifiCasaOnline(){ return jarvisWifiIsConnected() && casaOnline; }
bool jarvisWifiCasaChecked(){ return jarvisWifiIsConnected() && casaChecked; }
void jarvisWifiRequestCasaCheck(){ casaChecked=false; casaCheckRequested=true; }
String jarvisWifiCasaBase(){ return casaBase; }

bool jarvisWifiSetProfile(uint8_t slot, const String &ssid, const String &password){
  if(slot>4 || ssid.length()==0 || ssid.length()>32 || password.length()>63) return false;
  if(password.length()>0 && password.length()<8) return false;
  wifiPrefs.putString(keySsid(slot).c_str(), ssid);
  wifiPrefs.putString(keyPass(slot).c_str(), password);
  // Tenta associar sem bloquear a interface. O estado é acompanhado no loop/UI.
  casaOnline=false;
  casaChecked=false;
  casaCheckRequested=true;
  WiFi.disconnect(false, false);
  WiFi.begin(ssid.c_str(), password.c_str());
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
  return 0;
}

void jarvisWifiClearProfiles(){
  for(uint8_t i=0;i<5;i++){
    wifiPrefs.remove(keySsid(i).c_str());
    wifiPrefs.remove(keyPass(i).c_str());
  }
}

bool jarvisWifiScanStart(){
  if(scanRunning) return true;

  // Depois de deep sleep o radio pode estar em WIFI_OFF. Reativa e estabiliza
  // a interface antes de iniciar o scan assincrono.
  if(WiFi.getMode() == WIFI_OFF){
    WiFi.mode(WIFI_STA);
    delay(30);
  }else if(WiFi.getMode() != WIFI_STA){
    WiFi.mode(WIFI_STA);
    delay(20);
  }

  WiFi.scanDelete();

  int rc = WiFi.scanNetworks(true, true);
  if(rc == WIFI_SCAN_FAILED){
    // Uma segunda inicializacao curta recupera estados residuais do driver
    // observados apos wake/deep sleep.
    WiFi.mode(WIFI_OFF);
    delay(20);
    WiFi.mode(WIFI_STA);
    delay(50);
    rc = WiFi.scanNetworks(true, true);
  }

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
  casaOnline=false;
  casaChecked=false;
  casaCheckRequested=true;
}

void jarvisWifiSetDeviceId(const String &deviceId){
  casaDeviceId = deviceId;
  casaDeviceId.trim();
  wifiPrefs.putString("deviceid", casaDeviceId);
}

String jarvisWifiDeviceId(){ return casaDeviceId; }
bool jarvisWifiHasCasaCredentials(){ return !casaBase.isEmpty() && !casaToken.isEmpty(); }

bool jarvisWifiStartProfile(uint8_t slot){
  String ssid, pass;
  if(!jarvisWifiGetProfile(slot, ssid, pass)) return false;
  if(WiFi.status()==WL_CONNECTED && WiFi.SSID()==ssid) return true;

  casaOnline=false;
  casaChecked=false;
  casaCheckRequested=true;

  if(WiFi.getMode() == WIFI_OFF){
    WiFi.mode(WIFI_STA);
    delay(30);
  }else if(WiFi.getMode() != WIFI_STA){
    WiFi.mode(WIFI_STA);
    delay(20);
  }

  WiFi.disconnect(false, false);
  delay(10);
  WiFi.begin(ssid.c_str(), pass.c_str());
  reconnectInProgress=true;
  return true;
}

static bool waitConnected(uint32_t timeoutMs){
  unsigned long start=millis();
  while(WiFi.status()!=WL_CONNECTED && millis()-start<timeoutMs){ delay(20); yield(); }
  return WiFi.status()==WL_CONNECTED;
}

bool jarvisWifiConnectProfile(uint8_t slot, uint32_t timeoutMs){
  if(!jarvisWifiStartProfile(slot)) return false;
  bool ok = waitConnected(timeoutMs);
  if(ok){
    preferredSlot = slot;
    wifiPrefs.putInt("lastslot", preferredSlot);
  }
  return ok;
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

bool jarvisWifiStartPreferred(){
  if(preferredSlot >= 0 && preferredSlot <= 4){
    if(jarvisWifiStartProfile((uint8_t)preferredSlot)) return true;
  }
  // Primeira inicializacao apos cadastrar perfis: usa o primeiro existente.
  for(uint8_t slot=0;slot<5;slot++){
    String ssid, pass;
    if(jarvisWifiGetProfile(slot, ssid, pass)){
      preferredSlot = slot;
      wifiPrefs.putInt("lastslot", preferredSlot);
      return jarvisWifiStartProfile(slot);
    }
  }
  return false;
}

bool jarvisWifiConnectPreferred(uint32_t timeoutMs){
  if(jarvisWifiIsConnected()) return true;
  if(!jarvisWifiStartPreferred()) return false;
  bool ok = waitConnected(timeoutMs);
  if(ok && preferredSlot >= 0) wifiPrefs.putInt("lastslot", preferredSlot);
  return ok;
}

void jarvisWifiPrepareSleep(){
  scanRunning=false;
  reconnectInProgress=false;
  WiFi.scanDelete();
  WiFi.disconnect(false, false);
  delay(10);
  WiFi.mode(WIFI_OFF);
  casaOnline=false;
  casaChecked=false;
  casaCheckRequested=true;
  lastRetry=0;
}

bool jarvisWifiGetJson(const String &path, String *response){
  if(!jarvisWifiIsConnected() || casaToken.isEmpty()) return false;
  WiFiClientSecure tls;
  tls.setInsecure();
  tls.setTimeout(4);
  HTTPClient http;
  http.setConnectTimeout(2500);
  http.setTimeout(4000);
  String url = casaBase + (path.startsWith("/") ? path : "/" + path);
  if(!http.begin(tls, url)) return false;
  http.addHeader("Accept", "application/json");
  http.addHeader("Authorization", "Bearer " + casaToken);
  http.addHeader("X-Device-Token", casaToken);
  int code=http.GET();
  if(response && code>0) *response=http.getString();
  http.end();
  return code>=200 && code<300;
}

bool jarvisWifiPostJson(const String &path, const String &jsonPayload, String *response){
  if(!jarvisWifiIsConnected() || casaToken.isEmpty()) return false;
  WiFiClientSecure tls;
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
