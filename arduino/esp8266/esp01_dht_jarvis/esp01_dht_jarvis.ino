/* JARVIS CASA - ESP-01 / ESP8266 + DHT
 * Rede: sem configuracao ou apos falha, cria AP CASA-ESP01-xxxxxx.
 * Celular conecta ao AP, abre http://192.168.4.1 e informa Wi-Fi/CASA.
 * Pareamento e credencial continuam sendo feitos diretamente com o CASA.
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
const unsigned long INTERVALO_ENVIO_MS=60000UL;
const unsigned long INTERVALO_POLL_PAREAMENTO_MS=3000UL;

DHT dht(DHT_PIN,DHT_TYPE);
CasaDeviceProvisioning prov;
ESP8266WebServer portal(80);
String wifiSsid,wifiPass,casaBase="https://maurinsoft.com.br/casa";
bool portalAtivo=false;
unsigned long ultimoEnvio=0,ultimoPollPareamento=0;

String esc(const String&s){String o;for(size_t i=0;i<s.length();i++){char c=s[i];if(c=='&')o+="&amp;";else if(c=='<')o+="&lt;";else if(c=='>')o+="&gt;";else if(c=='\"')o+="&quot;";else o+=c;}return o;}
String apName(){char b[7];snprintf(b,sizeof(b),"%06X",ESP.getChipId());return String("CASA-ESP01-")+b;}

bool loadNetwork(){
  if(!LittleFS.begin())return false;if(!LittleFS.exists("/network.cfg"))return false;
  File f=LittleFS.open("/network.cfg","r");if(!f)return false;
  wifiSsid=f.readStringUntil('\n');wifiPass=f.readStringUntil('\n');casaBase=f.readStringUntil('\n');f.close();
  wifiSsid.trim();wifiPass.trim();casaBase.trim();return !wifiSsid.isEmpty()&&!casaBase.isEmpty();
}
bool saveNetwork(const String&ssid,const String&pass,const String&base){
  if(!LittleFS.begin())return false;File f=LittleFS.open("/network.cfg","w");if(!f)return false;
  f.println(ssid);f.println(pass);f.println(base);f.close();wifiSsid=ssid;wifiPass=pass;casaBase=base;return true;
}
String page(const String&msg=""){
  String h="<!doctype html><html><head><meta name='viewport' content='width=device-width,initial-scale=1'><title>CASA ESP-01</title></head><body><h2>Configurar ESP-01</h2>";
  if(!msg.isEmpty())h+="<p><b>"+esc(msg)+"</b></p>";
  h+="<form method='POST' action='/configure'>Wi-Fi SSID<br><input name='ssid' required><br>Senha<br><input type='password' name='password'><br>Servidor CASA<br><input name='casa' value='"+esc(casaBase)+"' required><br><br><button>Salvar e conectar</button></form>";
  h+="<p>Se a rede falhar, o ESP-01 volta automaticamente a este modo.</p></body></html>";return h;
}
void startPortal(){
  portalAtivo=true;WiFi.mode(WIFI_AP);String n=apName();WiFi.softAP(n.c_str());
  portal.on("/",HTTP_GET,[]{portal.send(200,"text/html",page());});
  portal.on("/configure",HTTP_POST,[]{String s=portal.arg("ssid"),p=portal.arg("password"),b=portal.arg("casa");s.trim();b.trim();while(b.endsWith("/"))b.remove(b.length()-1);if(s.isEmpty()||(!b.startsWith("https://")&&!b.startsWith("http://"))){portal.send(400,"text/html",page("Configuracao invalida"));return;}if(!saveNetwork(s,p,b)){portal.send(500,"text/html",page("Falha ao salvar"));return;}portal.send(200,"text/html","<h3>Configuracao salva.</h3><p>O ESP-01 vai testar a rede. Se falhar, conecte novamente ao AP.</p>");delay(500);ESP.restart();});
  portal.begin();Serial.printf("[CONFIG] AP %s em http://%s\n",n.c_str(),WiFi.softAPIP().toString().c_str());
}
bool conectarWiFi(){
  if(wifiSsid.isEmpty())return false;WiFi.mode(WIFI_STA);WiFi.begin(wifiSsid.c_str(),wifiPass.c_str());unsigned long ini=millis();
  while(WiFi.status()!=WL_CONNECTED&&millis()-ini<15000UL){delay(250);yield();}
  if(WiFi.status()==WL_CONNECTED){Serial.printf("[WIFI] IP=%s RSSI=%d\n",WiFi.localIP().toString().c_str(),WiFi.RSSI());return true;}WiFi.disconnect();return false;
}
String tipoSensor(){return DHT_TYPE==DHT11?"dht11":"dht22";}

bool enviarLeitura(float temperatura,float umidade){
  if(WiFi.status()!=WL_CONNECTED||!prov.isProvisioned())return false;
  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);client->setInsecure();HTTPClient http;
  String url=prov.getBaseUrl()+"/api/v1/device.php?acao=heartbeat";if(!http.begin(*client,url))return false;
  http.addHeader("Content-Type","application/json");http.addHeader("Authorization",String("Bearer ")+prov.getDeviceToken());http.addHeader("X-Device-Token",prov.getDeviceToken());http.addHeader("X-Device-Id",prov.getDeviceId());http.setTimeout(8000);
  String payload="{\"device_id\":\""+prov.getDeviceId()+"\",\"transport\":\"wifi\",\"health\":\"ok\",\"protocol_version\":\"CASA/1.0\",\"capabilities\":[\"temperature\",\"humidity\",\"telemetry\"],\"rssi\":"+String(WiFi.RSSI())+",\"data\":{\"sensor_type\":\""+tipoSensor()+"\",\"temperature_c\":"+String(temperatura,2)+",\"humidity_pct\":"+String(umidade,2)+"}}";
  int code=http.POST(payload);http.end();return code>=200&&code<300;
}
void lerEEnviar(){float u=dht.readHumidity(),t=dht.readTemperature();if(isnan(t)||isnan(u)){Serial.println("[DHT] Falha na leitura");return;}enviarLeitura(t,u);}
void verificarPareamento(){
  if(prov.isProvisioned())return;
  if(prov.getState()==CASA_STATE_UNPROVISIONED){if(prov.requestPairing("sensor","ESP01-DHT","[\"temperature\",\"humidity\",\"telemetry\",\"wifi\"]"))Serial.printf("[PAIR] Aguardando Mobile. Codigo=%s\n",prov.getPairingCode().c_str());}
  else if(prov.getState()==CASA_STATE_PAIRING_REQUESTED&&millis()-ultimoPollPareamento>INTERVALO_POLL_PAREAMENTO_MS){ultimoPollPareamento=millis();if(prov.pollPairingStatus())Serial.printf("[PAIR] Autorizado ID=%s\n",prov.getDeviceId().c_str());}
}
void setup(){
  Serial.begin(115200);delay(100);dht.begin();
  if(!loadNetwork()){startPortal();return;}
  if(!conectarWiFi()){Serial.println("[WIFI] Rede salva falhou; retornando ao AP.");startPortal();return;}
  prov.begin(casaBase);
}
void loop(){
  if(portalAtivo){portal.handleClient();delay(2);return;}
  if(WiFi.status()!=WL_CONNECTED){Serial.println("[WIFI] Conexao perdida; abrindo AP para reconfiguracao.");startPortal();return;}
  if(!prov.isProvisioned())verificarPareamento();
  else if(millis()-ultimoEnvio>=INTERVALO_ENVIO_MS||ultimoEnvio==0){ultimoEnvio=millis();lerEEnviar();}
  delay(100);
}
