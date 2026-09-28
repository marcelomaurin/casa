/* JARVIS CASA - ESP-01 + SHIELD RELE 1 CANAL
 * GPIO0, ativo LOW. O servidor CASA e a fonte de verdade do estado desejado.
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
const unsigned long PAIRING_POLL_MS=3000UL,DESIRED_POLL_MS=3000UL,HEARTBEAT_MS=15000UL;
CasaDeviceProvisioning prov; ESP8266WebServer portal(80);
String wifiSsid,wifiPass,casaBase="https://maurinsoft.com.br/casa";bool portalAtivo=false,relayOn=false;unsigned long lastPairing=0,lastDesired=0,lastHeartbeat=0;
String esc(const String&s){String o;for(size_t i=0;i<s.length();i++){char c=s[i];if(c=='&')o+="&amp;";else if(c=='<')o+="&lt;";else if(c=='>')o+="&gt;";else if(c=='\"')o+="&quot;";else o+=c;}return o;}
String apName(){char b[7];snprintf(b,sizeof(b),"%06X",ESP.getChipId());return String("CASA-RELE-")+b;}
bool loadNetwork(){if(!LittleFS.begin()||!LittleFS.exists("/network.cfg"))return false;File f=LittleFS.open("/network.cfg","r");if(!f)return false;wifiSsid=f.readStringUntil('\n');wifiPass=f.readStringUntil('\n');casaBase=f.readStringUntil('\n');f.close();wifiSsid.trim();wifiPass.trim();casaBase.trim();return !wifiSsid.isEmpty()&&!casaBase.isEmpty();}
bool saveNetwork(const String&s,const String&p,const String&b){if(!LittleFS.begin())return false;File f=LittleFS.open("/network.cfg","w");if(!f)return false;f.println(s);f.println(p);f.println(b);f.close();wifiSsid=s;wifiPass=p;casaBase=b;return true;}
String page(const String&m=""){String h="<html><meta name='viewport' content='width=device-width,initial-scale=1'><body><h2>ESP-01 Rele - CASA</h2>";if(!m.isEmpty())h+="<b>"+esc(m)+"</b>";h+="<form method='POST' action='/configure'>SSID<br><input name='ssid' required><br>Senha<br><input type='password' name='password'><br>Servidor CASA<br><input name='casa' value='"+esc(casaBase)+"' required><br><button>Salvar</button></form></body></html>";return h;}
void startPortal(){portalAtivo=true;WiFi.mode(WIFI_AP);String n=apName();WiFi.softAP(n.c_str());portal.on("/",HTTP_GET,[]{portal.send(200,"text/html",page());});portal.on("/configure",HTTP_POST,[]{String s=portal.arg("ssid"),p=portal.arg("password"),b=portal.arg("casa");s.trim();b.trim();while(b.endsWith("/"))b.remove(b.length()-1);if(s.isEmpty()||(!b.startsWith("https://")&&!b.startsWith("http://"))){portal.send(400,"text/html",page("Invalido"));return;}if(!saveNetwork(s,p,b)){portal.send(500,"text/plain","Falha ao salvar");return;}portal.send(200,"text/html","<h3>Salvo. Reiniciando.</h3>");delay(500);ESP.restart();});portal.begin();}
bool connectWifi(){if(wifiSsid.isEmpty())return false;WiFi.mode(WIFI_STA);WiFi.begin(wifiSsid.c_str(),wifiPass.c_str());unsigned long t=millis();while(WiFi.status()!=WL_CONNECTED&&millis()-t<15000UL){delay(250);yield();}return WiFi.status()==WL_CONNECTED;}
void setRelay(bool on){if(relayOn==on)return;relayOn=on;digitalWrite(RELAY_PIN,on?RELAY_ACTIVE_LEVEL:RELAY_INACTIVE_LEVEL);Serial.printf("[RELE] reconciliado: %s\n",on?"ON":"OFF");}
std::unique_ptr<BearSSL::WiFiClientSecure> secureClient(){auto c=std::unique_ptr<BearSSL::WiFiClientSecure>(new BearSSL::WiFiClientSecure);c->setInsecure();return c;}
void auth(HTTPClient&h){h.addHeader("Authorization",String("Bearer ")+prov.getDeviceToken());h.addHeader("X-Device-Token",prov.getDeviceToken());h.addHeader("X-Device-Id",prov.getDeviceId());}
bool heartbeat(){auto c=secureClient();HTTPClient h;if(!h.begin(*c,prov.getBaseUrl()+"/api/v1/device.php?acao=heartbeat"))return false;auth(h);h.addHeader("Content-Type","application/json");String p="{\"device_id\":\""+prov.getDeviceId()+"\",\"transport\":\"wifi\",\"health\":\"ok\",\"model\":\"ESP01 Relay Shield\",\"rssi\":"+String(WiFi.RSSI())+",\"capabilities\":[\"relay\",\"desired_state\",\"telemetry\"],\"data\":{\"relay_on\":"+String(relayOn?"true":"false")+"}}";int code=h.POST(p);h.end();return code>=200&&code<300;}
void reconcileDesiredState(){auto c=secureClient();HTTPClient h;String url=prov.getBaseUrl()+"/api/v1/device_desired_state.php?device_id="+prov.getDeviceId();if(!h.begin(*c,url))return;auth(h);h.setTimeout(7000);int code=h.GET();String body=h.getString();h.end();if(code<200||code>=300)return;int p=body.indexOf("\"relay_on\"");if(p<0)return;p=body.indexOf(':',p);if(p<0)return;p++;while(p<(int)body.length()&&(body[p]==' '||body[p]=='\t'))p++;if(body.substring(p,p+4)=="true")setRelay(true);else if(body.substring(p,p+5)=="false")setRelay(false);}
void pairing(){if(prov.isProvisioned())return;if(prov.getState()==CASA_STATE_UNPROVISIONED){if(prov.requestPairing("esp8266","ESP01 Relay Shield","[\"relay\",\"desired_state\",\"telemetry\",\"wifi\"]"))Serial.printf("[PAIR] Codigo=%s\n",prov.getPairingCode().c_str());}else if(prov.getState()==CASA_STATE_PAIRING_REQUESTED&&millis()-lastPairing>=PAIRING_POLL_MS){lastPairing=millis();prov.pollPairingStatus();}}
void setup(){Serial.begin(115200);pinMode(RELAY_PIN,OUTPUT);relayOn=false;digitalWrite(RELAY_PIN,RELAY_INACTIVE_LEVEL);if(!loadNetwork()){startPortal();return;}if(!connectWifi()){startPortal();return;}prov.begin(casaBase);}
void loop(){if(portalAtivo){portal.handleClient();delay(2);return;}if(WiFi.status()!=WL_CONNECTED){digitalWrite(RELAY_PIN,RELAY_INACTIVE_LEVEL);relayOn=false;startPortal();return;}if(!prov.isProvisioned())pairing();else{if(lastDesired==0||millis()-lastDesired>=DESIRED_POLL_MS){lastDesired=millis();reconcileDesiredState();}if(lastHeartbeat==0||millis()-lastHeartbeat>=HEARTBEAT_MS){lastHeartbeat=millis();heartbeat();}}delay(50);}
