/* JARVIS CASA - ESP-01 + SHIELD RELE 1 CANAL
 * Hardware de referencia: ESP-01/ESP8266 relay shield comum.
 * Relé: GPIO0, ativo em LOW. Ajuste RELAY_PIN/RELAY_ACTIVE_LEVEL para outra revisao.
 *
 * Fluxo:
 *  1. Sem rede: AP CASA-RELE-xxxxxx + portal http://192.168.4.1.
 *  2. ESP entra no Wi-Fi e solicita pareamento ao CASA.
 *  3. Mobile autoriza; credencial permanente vem do servidor.
 *  4. Device consome comandos do Control Plane e controla o rele.
 */
#include <ESP8266WiFi.h>
#include <ESP8266WebServer.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <LittleFS.h>
#include "../../common/CasaDeviceProvisioning.h"

#define RELAY_PIN 0
#define RELAY_ACTIVE_LEVEL LOW
#define RELAY_INACTIVE_LEVEL HIGH

const unsigned long PAIRING_POLL_MS = 3000UL;
const unsigned long COMMAND_POLL_MS = 3000UL;
const unsigned long HEARTBEAT_MS = 15000UL;

CasaDeviceProvisioning prov;
ESP8266WebServer portal(80);
String wifiSsid, wifiPass, casaBase = "https://maurinsoft.com.br/casa";
bool portalAtivo = false;
bool relayOn = false;
unsigned long lastPairing = 0, lastCommand = 0, lastHeartbeat = 0;

String htmlEsc(const String& s) {
  String o;
  for (size_t i=0;i<s.length();i++) {
    char c=s[i];
    if(c=='&')o+="&amp;"; else if(c=='<')o+="&lt;"; else if(c=='>')o+="&gt;"; else if(c=='\"')o+="&quot;"; else o+=c;
  }
  return o;
}
String apName(){ char b[7]; snprintf(b,sizeof(b),"%06X",ESP.getChipId()); return String("CASA-RELE-")+b; }

bool loadNetwork(){
  if(!LittleFS.begin() || !LittleFS.exists("/network.cfg")) return false;
  File f=LittleFS.open("/network.cfg","r"); if(!f)return false;
  wifiSsid=f.readStringUntil('\n'); wifiPass=f.readStringUntil('\n'); casaBase=f.readStringUntil('\n'); f.close();
  wifiSsid.trim(); wifiPass.trim(); casaBase.trim(); return !wifiSsid.isEmpty()&&!casaBase.isEmpty();
}
bool saveNetwork(const String&s,const String&p,const String&b){
  if(!LittleFS.begin())return false; File f=LittleFS.open("/network.cfg","w"); if(!f)return false;
  f.println(s); f.println(p); f.println(b); f.close(); wifiSsid=s; wifiPass=p; casaBase=b; return true;
}
String configPage(const String& msg=""){
  String h="<!doctype html><html><head><meta name='viewport' content='width=device-width,initial-scale=1'><title>CASA Rele</title></head><body><h2>ESP-01 Rele - CASA</h2>";
  if(!msg.isEmpty())h+="<p><b>"+htmlEsc(msg)+"</b></p>";
  h+="<form method='POST' action='/configure'>Wi-Fi SSID<br><input name='ssid' required><br>Senha<br><input name='password' type='password'><br>Servidor CASA<br><input name='casa' value='"+htmlEsc(casaBase)+"' required><br><br><button>Salvar e conectar</button></form>";
  h+="<p>Se a rede falhar, o dispositivo volta automaticamente a este AP.</p></body></html>"; return h;
}
void startPortal(){
  portalAtivo=true; WiFi.mode(WIFI_AP); String n=apName(); WiFi.softAP(n.c_str());
  portal.on("/",HTTP_GET,[]{portal.send(200,"text/html",configPage());});
  portal.on("/configure",HTTP_POST,[]{
    String s=portal.arg("ssid"),p=portal.arg("password"),b=portal.arg("casa"); s.trim(); b.trim(); while(b.endsWith("/"))b.remove(b.length()-1);
    if(s.isEmpty()||(!b.startsWith("https://")&&!b.startsWith("http://"))){portal.send(400,"text/html",configPage("Configuracao invalida"));return;}
    if(!saveNetwork(s,p,b)){portal.send(500,"text/html",configPage("Falha ao salvar"));return;}
    portal.send(200,"text/html","<h3>Configuracao salva.</h3><p>O ESP-01 vai testar a rede.</p>"); delay(500); ESP.restart();
  });
  portal.begin(); Serial.printf("[CONFIG] AP %s em http://%s\n",n.c_str(),WiFi.softAPIP().toString().c_str());
}
bool connectWifi(){
  if(wifiSsid.isEmpty())return false; WiFi.mode(WIFI_STA); WiFi.begin(wifiSsid.c_str(),wifiPass.c_str()); unsigned long t=millis();
  while(WiFi.status()!=WL_CONNECTED&&millis()-t<15000UL){delay(250);yield();}
  return WiFi.status()==WL_CONNECTED;
}
void setRelay(bool on){ relayOn=on; digitalWrite(RELAY_PIN,on?RELAY_ACTIVE_LEVEL:RELAY_INACTIVE_LEVEL); Serial.printf("[RELE] %s\n",on?"ON":"OFF"); }

std::unique_ptr<BearSSL::WiFiClientSecure> secureClient(){ auto c=std::unique_ptr<BearSSL::WiFiClientSecure>(new BearSSL::WiFiClientSecure); c->setInsecure(); return c; }
void addAuth(HTTPClient& http){ http.addHeader("Content-Type","application/json"); http.addHeader("Authorization",String("Bearer ")+prov.getDeviceToken()); http.addHeader("X-Device-Token",prov.getDeviceToken()); http.addHeader("X-Device-Id",prov.getDeviceId()); }

bool postAction(const String& action,const String& payload){
  auto client=secureClient(); HTTPClient http; String url=prov.getBaseUrl()+"/api/v1/device.php?acao="+action;
  if(!http.begin(*client,url))return false; addAuth(http); http.setTimeout(7000); int code=http.POST(payload); http.end(); return code>=200&&code<300;
}
void heartbeat(){
  String p="{\"device_id\":\""+prov.getDeviceId()+"\",\"transport\":\"wifi\",\"health\":\"ok\",\"protocol_version\":\"CASA/1.0\",\"model\":\"ESP01 Relay Shield\",\"rssi\":"+String(WiFi.RSSI())+",\"capabilities\":[\"relay\",\"switch\",\"telemetry\"],\"data\":{\"relay_on\":"+String(relayOn?"true":"false")+"}}";
  postAction("heartbeat",p);
}

String jsonString(const String& json,const String& key){
  String m="\""+key+"\""; int p=json.indexOf(m); if(p<0)return ""; p=json.indexOf(':',p+m.length()); if(p<0)return ""; p++; while(p<(int)json.length()&&json[p]==' ')p++; if(p>=(int)json.length()||json[p]!='\"')return ""; p++; int e=json.indexOf('\"',p); return e<0?"":json.substring(p,e);
}
void commandLifecycle(const String&id,const String&command){
  String idp="{\"command_id\":\""+id+"\"}";
  if(!postAction("command_ack",idp))return;
  if(!postAction("command_start",idp))return;
  bool ok=true;
  if(command=="relay.on"||command=="relay_on")setRelay(true);
  else if(command=="relay.off"||command=="relay_off")setRelay(false);
  else if(command=="relay.toggle"||command=="relay_toggle")setRelay(!relayOn);
  else if(command=="relay.status"||command=="status"){}
  else ok=false;
  String result="{\"command_id\":\""+id+"\",\"success\":"+String(ok?"true":"false")+",\"result\":{\"relay_on\":"+String(relayOn?"true":"false")+"},\"message\":\""+(ok?String("ok"):String("unsupported_command"))+"\"}";
  postAction("command_result",result);
}
void pollCommands(){
  auto client=secureClient(); HTTPClient http; String url=prov.getBaseUrl()+"/api/v1/device.php?acao=commands";
  if(!http.begin(*client,url))return; http.addHeader("Authorization",String("Bearer ")+prov.getDeviceToken()); http.addHeader("X-Device-Token",prov.getDeviceToken()); http.addHeader("X-Device-Id",prov.getDeviceId()); http.setTimeout(7000);
  int code=http.GET(); String body=http.getString(); http.end(); if(code<200||code>=300)return;
  // Control Plane devolve a fila; processa cada command_id encontrado sem executar antes de ACK/START.
  int pos=0;
  while((pos=body.indexOf("\"command_id\"",pos))>=0){
    String chunk=body.substring(pos,min((int)body.length(),pos+700)); String id=jsonString(chunk,"command_id"),cmd=jsonString(chunk,"command");
    if(!id.isEmpty()&&!cmd.isEmpty())commandLifecycle(id,cmd); pos+=12;
  }
}
void pairing(){
  if(prov.isProvisioned())return;
  if(prov.getState()==CASA_STATE_UNPROVISIONED){ if(prov.requestPairing("esp8266","ESP01 Relay Shield","[\"relay\",\"switch\",\"telemetry\",\"wifi\"]")) Serial.printf("[PAIR] Codigo=%s\n",prov.getPairingCode().c_str()); }
  else if(prov.getState()==CASA_STATE_PAIRING_REQUESTED&&millis()-lastPairing>=PAIRING_POLL_MS){lastPairing=millis();prov.pollPairingStatus();}
}
void setup(){
  Serial.begin(115200); pinMode(RELAY_PIN,OUTPUT); setRelay(false); // fail-safe: inicia desligado
  if(!loadNetwork()){startPortal();return;} if(!connectWifi()){startPortal();return;} prov.begin(casaBase);
}
void loop(){
  if(portalAtivo){portal.handleClient();delay(2);return;}
  if(WiFi.status()!=WL_CONNECTED){setRelay(false);startPortal();return;} // fail-safe em perda de rede
  if(!prov.isProvisioned())pairing();
  else {
    if(lastHeartbeat==0||millis()-lastHeartbeat>=HEARTBEAT_MS){lastHeartbeat=millis();heartbeat();}
    if(lastCommand==0||millis()-lastCommand>=COMMAND_POLL_MS){lastCommand=millis();pollCommands();}
  }
  delay(50);
}
