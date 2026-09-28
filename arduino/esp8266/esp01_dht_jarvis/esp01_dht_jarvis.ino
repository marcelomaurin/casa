/* JARVIS CASA - ESP-01 / ESP8266 + DHT11/DHT22
 * Shield/sensor de temperatura e umidade.
 * Padrao atual: DHT22 em GPIO2. Para shield DHT11, altere apenas DHT_TYPE.
 *
 * Fluxo CASA:
 *  1. Sem rede ou em falha: AP CASA-DHT-xxxxxx + http://192.168.4.1.
 *  2. Celular informa SSID/senha e URL CASA.
 *  3. ESP entra na rede e solicita pareamento.
 *  4. Mobile autoriza; device recebe a credencial permanente do servidor.
 *  5. Publica temperatura/umidade e heartbeat no Control Plane.
 */
#include <ESP8266WiFi.h>
#include <ESP8266WebServer.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <LittleFS.h>
#include <DHT.h>
#include "../../common/CasaDeviceProvisioning.h"

#define DHT_PIN 2
#define DHT_TYPE DHT22
const unsigned long TELEMETRY_MS=60000UL;
const unsigned long HEARTBEAT_MS=15000UL;
const unsigned long PAIRING_POLL_MS=3000UL;

DHT dht(DHT_PIN,DHT_TYPE);
CasaDeviceProvisioning prov;
ESP8266WebServer portal(80);
String wifiSsid,wifiPass,casaBase="https://maurinsoft.com.br/casa";
bool portalAtivo=false;
unsigned long lastTelemetry=0,lastHeartbeat=0,lastPairing=0;
float lastTemperature=NAN,lastHumidity=NAN;

String esc(const String&s){String o;for(size_t i=0;i<s.length();i++){char c=s[i];if(c=='&')o+="&amp;";else if(c=='<')o+="&lt;";else if(c=='>')o+="&gt;";else if(c=='\"')o+="&quot;";else o+=c;}return o;}
String apName(){char b[7];snprintf(b,sizeof(b),"%06X",ESP.getChipId());return String("CASA-DHT-")+b;}
String sensorType(){return DHT_TYPE==DHT11?"dht11":"dht22";}

bool loadNetwork(){if(!LittleFS.begin())return false;if(!LittleFS.exists("/network.cfg"))return false;File f=LittleFS.open("/network.cfg","r");if(!f)return false;wifiSsid=f.readStringUntil('\n');wifiPass=f.readStringUntil('\n');casaBase=f.readStringUntil('\n');f.close();wifiSsid.trim();wifiPass.trim();casaBase.trim();return !wifiSsid.isEmpty()&&!casaBase.isEmpty();}
bool saveNetwork(const String&s,const String&p,const String&b){if(!LittleFS.begin())return false;File f=LittleFS.open("/network.cfg","w");if(!f)return false;f.println(s);f.println(p);f.println(b);f.close();wifiSsid=s;wifiPass=p;casaBase=b;return true;}
String page(const String&msg=""){String h="<!doctype html><html><head><meta name='viewport' content='width=device-width,initial-scale=1'><title>CASA DHT</title></head><body><h2>ESP-01 Temperatura / Umidade</h2>";if(!msg.isEmpty())h+="<p><b>"+esc(msg)+"</b></p>";h+="<p>Sensor: "+sensorType()+"</p><form method='POST' action='/configure'>Wi-Fi SSID<br><input name='ssid' required><br>Senha<br><input type='password' name='password'><br>Servidor CASA<br><input name='casa' value='"+esc(casaBase)+"' required><br><br><button>Salvar e conectar</button></form><p>Se a rede falhar, o ESP-01 volta automaticamente a este AP.</p></body></html>";return h;}
void startPortal(){portalAtivo=true;WiFi.mode(WIFI_AP);String n=apName();WiFi.softAP(n.c_str());portal.on("/",HTTP_GET,[]{portal.send(200,"text/html",page());});portal.on("/configure",HTTP_POST,[]{String s=portal.arg("ssid"),p=portal.arg("password"),b=portal.arg("casa");s.trim();b.trim();while(b.endsWith("/"))b.remove(b.length()-1);if(s.isEmpty()||(!b.startsWith("https://")&&!b.startsWith("http://"))){portal.send(400,"text/html",page("Configuracao invalida"));return;}if(!saveNetwork(s,p,b)){portal.send(500,"text/html",page("Falha ao salvar"));return;}portal.send(200,"text/html","<h3>Configuracao salva.</h3><p>O ESP vai testar a rede; em falha o AP volta.</p>");delay(500);ESP.restart();});portal.begin();Serial.printf("[CONFIG] AP %s http://%s\n",n.c_str(),WiFi.softAPIP().toString().c_str());}
bool connectWifi(){if(wifiSsid.isEmpty())return false;WiFi.mode(WIFI_STA);WiFi.begin(wifiSsid.c_str(),wifiPass.c_str());unsigned long t=millis();while(WiFi.status()!=WL_CONNECTED&&millis()-t<15000UL){delay(250);yield();}if(WiFi.status()==WL_CONNECTED){Serial.printf("[WIFI] IP=%s RSSI=%d\n",WiFi.localIP().toString().c_str(),WiFi.RSSI());return true;}WiFi.disconnect();return false;}

bool postDevice(const String& action,const String& payload){if(WiFi.status()!=WL_CONNECTED||!prov.isProvisioned())return false;std::unique_ptr<BearSSL::WiFiClientSecure> c(new BearSSL::WiFiClientSecure);c->setInsecure();HTTPClient h;String u=prov.getBaseUrl()+"/api/v1/device.php?acao="+action;if(!h.begin(*c,u))return false;h.addHeader("Content-Type","application/json");h.addHeader("Authorization",String("Bearer ")+prov.getDeviceToken());h.addHeader("X-Device-Token",prov.getDeviceToken());h.addHeader("X-Device-Id",prov.getDeviceId());h.setTimeout(8000);int code=h.POST(payload);h.end();return code>=200&&code<300;}
void readSensor(){float h=dht.readHumidity(),t=dht.readTemperature();if(isnan(t)||isnan(h)){Serial.println("[DHT] Falha na leitura");return;}lastTemperature=t;lastHumidity=h;Serial.printf("[DHT] %.2f C %.2f %%\n",t,h);}
String statusPayload(){String t=isnan(lastTemperature)?"null":String(lastTemperature,2);String h=isnan(lastHumidity)?"null":String(lastHumidity,2);return "{\"device_id\":\""+prov.getDeviceId()+"\",\"transport\":\"wifi\",\"health\":\"ok\",\"protocol_version\":\"CASA/1.0\",\"model\":\"ESP01-DHT\",\"rssi\":"+String(WiFi.RSSI())+",\"capabilities\":[\"temperature\",\"humidity\",\"telemetry\",\"wifi\"],\"data\":{\"sensor_type\":\""+sensorType()+"\",\"temperature_c\":"+t+",\"humidity_pct\":"+h+",\"free_heap\":"+String(ESP.getFreeHeap())+"}}";}
void sendTelemetry(){readSensor();if(!isnan(lastTemperature)&&!isnan(lastHumidity))postDevice("event","{\"device_id\":\""+prov.getDeviceId()+"\",\"event_type\":\"sensor.environment\",\"payload\":{\"sensor_type\":\""+sensorType()+"\",\"temperature_c\":"+String(lastTemperature,2)+",\"humidity_pct\":"+String(lastHumidity,2)+"}}");}
void sendHeartbeat(){postDevice("heartbeat",statusPayload());}
void checkPairing(){if(prov.isProvisioned())return;if(prov.getState()==CASA_STATE_UNPROVISIONED){if(prov.requestPairing("sensor","ESP01-DHT","[\"temperature\",\"humidity\",\"telemetry\",\"wifi\"]"))Serial.printf("[PAIR] Aguardando Mobile. Codigo=%s\n",prov.getPairingCode().c_str());}else if(prov.getState()==CASA_STATE_PAIRING_REQUESTED&&millis()-lastPairing>=PAIRING_POLL_MS){lastPairing=millis();if(prov.pollPairingStatus())Serial.printf("[PAIR] Autorizado ID=%s\n",prov.getDeviceId().c_str());}}

void setup(){Serial.begin(115200);delay(100);dht.begin();if(!loadNetwork()){startPortal();return;}if(!connectWifi()){Serial.println("[WIFI] Falhou; voltando ao AP.");startPortal();return;}prov.begin(casaBase);}
void loop(){if(portalAtivo){portal.handleClient();delay(2);return;}if(WiFi.status()!=WL_CONNECTED){Serial.println("[WIFI] Perdida; abrindo AP.");startPortal();return;}if(!prov.isProvisioned())checkPairing();else{unsigned long now=millis();if(lastHeartbeat==0||now-lastHeartbeat>=HEARTBEAT_MS){lastHeartbeat=now;sendHeartbeat();}if(lastTelemetry==0||now-lastTelemetry>=TELEMETRY_MS){lastTelemetry=now;sendTelemetry();}}delay(100);}
