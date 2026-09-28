/**
 * JARVIS CASA - ESP32-CAM AI Thinker
 *
 * Provisionamento oficial para ESP32/ESP-01:
 *  1. Sem rede valida, o ESP cria seu proprio Access Point.
 *  2. O celular conecta ao AP e abre http://192.168.4.1.
 *  3. O ESP recebe SSID/senha + URL CASA + nome/local e testa a rede.
 *  4. Se falhar, volta ao AP. Nenhum token e recebido do celular.
 *  5. Com rede, o proprio ESP solicita pareamento ao CASA.
 *  6. O Mobile autoriza; o ESP consulta o CASA e recebe device_id/token.
 *  7. Credencial permanente fica em NVS. Em falha persistente de Wi-Fi, AP volta.
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <Preferences.h>

Preferences preferences;
WebServer server(80);
static const char* NVS_NAMESPACE = "jarviscam";
static const char* FW_VERSION = "1.1.0";

String wifi_ssid, wifi_password, casa_url;
String device_token, device_id, device_name, device_location;
String pairing_request_id, pairing_code;
bool operational = false;
bool configPortal = false;
bool httpStarted = false;
unsigned long lastHeartbeat = 0;
unsigned long lastPairingPoll = 0;
const unsigned long HEARTBEAT_INTERVAL = 15000UL;
const unsigned long PAIRING_POLL_INTERVAL = 5000UL;

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

String jsonEscape(const String& v) {
  String o; o.reserve(v.length()+8);
  for (size_t i=0;i<v.length();i++) {
    char c=v.charAt(i);
    if(c=='\\') o+="\\\\"; else if(c=='\"') o+="\\\"";
    else if(c=='\n') o+="\\n"; else if(c=='\r') o+="\\r"; else if(c=='\t') o+="\\t"; else o+=c;
  }
  return o;
}

String jsonValue(const String& json, const String& key) {
  String marker="\""+key+"\""; int p=json.indexOf(marker); if(p<0) return "";
  p=json.indexOf(':',p+marker.length()); if(p<0) return ""; p++;
  while(p<(int)json.length() && (json[p]==' '||json[p]=='\t')) p++;
  if(p>=(int)json.length() || json[p]!='\"') return ""; p++;
  String out; bool esc=false;
  for(;p<(int)json.length();p++) { char c=json[p]; if(esc){out+=c;esc=false;} else if(c=='\\')esc=true; else if(c=='\"')break; else out+=c; }
  return out;
}

void loadConfig() {
  preferences.begin(NVS_NAMESPACE,true);
  wifi_ssid=preferences.getString("ssid",""); wifi_password=preferences.getString("wifi_pass","");
  casa_url=preferences.getString("casa_url","https://maurinsoft.com.br/casa");
  device_token=preferences.getString("token",""); device_id=preferences.getString("device_id","");
  device_name=preferences.getString("name","ESP32-CAM"); device_location=preferences.getString("location","Residencia");
  pairing_request_id=preferences.getString("pair_req",""); pairing_code=preferences.getString("pair_code","");
  preferences.end();
  operational=!wifi_ssid.isEmpty()&&!casa_url.isEmpty()&&!device_token.isEmpty()&&!device_id.isEmpty();
}

void saveNetwork(const String& ssid,const String& pass,const String& base,const String& name,const String& location) {
  preferences.begin(NVS_NAMESPACE,false);
  preferences.putString("ssid",ssid); preferences.putString("wifi_pass",pass); preferences.putString("casa_url",base);
  preferences.putString("name",name.isEmpty()?"ESP32-CAM":name); preferences.putString("location",location.isEmpty()?"Residencia":location);
  preferences.remove("token"); preferences.remove("device_id"); preferences.remove("pair_req"); preferences.remove("pair_code");
  preferences.end(); loadConfig();
}

void savePairing(const String& req,const String& code) {
  preferences.begin(NVS_NAMESPACE,false); preferences.putString("pair_req",req); preferences.putString("pair_code",code); preferences.end();
  pairing_request_id=req; pairing_code=code;
}

void saveCredential(const String& id,const String& token) {
  preferences.begin(NVS_NAMESPACE,false); preferences.putString("device_id",id); preferences.putString("token",token);
  preferences.remove("pair_req"); preferences.remove("pair_code"); preferences.end(); loadConfig();
}

bool connectWifi(unsigned long timeoutMs=15000UL) {
  if(wifi_ssid.isEmpty()) return false;
  WiFi.mode(WIFI_STA); WiFi.persistent(false); WiFi.disconnect(false,false); delay(100);
  WiFi.begin(wifi_ssid.c_str(),wifi_password.c_str()); unsigned long start=millis();
  while(WiFi.status()!=WL_CONNECTED && millis()-start<timeoutMs){delay(100);yield();}
  if(WiFi.status()==WL_CONNECTED){Serial.printf("[WIFI] IP=%s RSSI=%d\n",WiFi.localIP().toString().c_str(),WiFi.RSSI());return true;}
  WiFi.disconnect(false,false); return false;
}

String apName() {
  uint64_t chip=ESP.getEfuseMac(); char suffix[7]; snprintf(suffix,sizeof(suffix),"%06X",(uint32_t)(chip&0xFFFFFF));
  return String("CASA-ESP32-")+suffix;
}

String configPage(const String& message="") {
  String h="<!doctype html><html><head><meta name='viewport' content='width=device-width,initial-scale=1'><title>CASA ESP32</title></head><body>";
  h+="<h2>Configurar ESP32-CAM</h2>"; if(!message.isEmpty())h+="<p><b>"+message+"</b></p>";
  h+="<form method='POST' action='/configure'><label>Wi-Fi SSID</label><br><input name='ssid' required><br><label>Senha Wi-Fi</label><br><input name='password' type='password'><br>";
  h+="<label>Servidor CASA</label><br><input name='casa' value='"+casa_url+"' required><br><label>Nome</label><br><input name='name' value='"+device_name+"'><br>";
  h+="<label>Local</label><br><input name='location' value='"+device_location+"'><br><br><button type='submit'>SALVAR E CONECTAR</button></form>";
  h+="<p>Se a conexao falhar, este ponto de acesso sera disponibilizado novamente.</p></body></html>"; return h;
}

void startConfigPortal() {
  configPortal=true; httpStarted=true; operational=false;
  WiFi.mode(WIFI_AP); String name=apName(); WiFi.softAP(name.c_str());
  server.stop(); server.on("/",HTTP_GET,[]{server.send(200,"text/html",configPage());});
  server.on("/status",HTTP_GET,[]{server.send(200,"application/json","{\"mode\":\"config_ap\",\"ap\":\""+jsonEscape(apName())+"\",\"ip\":\""+WiFi.softAPIP().toString()+"\"}");});
  server.on("/configure",HTTP_POST,[]{
    String ssid=server.arg("ssid"),pass=server.arg("password"),base=server.arg("casa"),name=server.arg("name"),loc=server.arg("location");
    ssid.trim(); base.trim(); base.replace("/api/v1",""); while(base.endsWith("/"))base.remove(base.length()-1);
    if(ssid.isEmpty()||(!base.startsWith("https://")&&!base.startsWith("http://"))){server.send(400,"text/html",configPage("SSID ou URL CASA invalida."));return;}
    server.send(200,"text/html","<html><body><h3>Configuracao recebida.</h3><p>O ESP vai testar a rede. Se falhar, conecte novamente ao AP "+apName()+".</p></body></html>");
    delay(500); saveNetwork(ssid,pass,base,name,loc); ESP.restart();
  });
  server.begin(); Serial.printf("[CONFIG] AP %s em http://%s\n",name.c_str(),WiFi.softAPIP().toString().c_str());
}

bool beginHttp(HTTPClient& http,WiFiClientSecure& tls,const String& url) {
  tls.setInsecure(); // TODO: instalar CA da implantacao.
  tls.setTimeout(5); http.setConnectTimeout(3000); http.setTimeout(5000); return http.begin(tls,url);
}

String physicalMac(){ String m=WiFi.macAddress(); m.toUpperCase(); return m; }

bool requestPairing() {
  if(WiFi.status()!=WL_CONNECTED||casa_url.isEmpty())return false;
  WiFiClientSecure tls; HTTPClient http; if(!beginHttp(http,tls,casa_url+"/api/v1/provision.php?acao=solicitar_pareamento"))return false;
  http.addHeader("Content-Type","application/json");
  String payload="{\"mac\":\""+jsonEscape(physicalMac())+"\",\"type\":\"esp32cam\",\"model\":\"ESP32-CAM AI Thinker\",\"firmware_version\":\""+String(FW_VERSION)+"\",\"capabilities\":[\"camera\",\"snapshot\",\"mjpeg\",\"flash\",\"telemetry\",\"wifi\"]}";
  int code=http.POST(payload); String body=http.getString(); http.end();
  if(code<200||code>=300){Serial.printf("[PAIR] solicitacao HTTP %d\n",code);return false;}
  String req=jsonValue(body,"request_id"),pc=jsonValue(body,"pairing_code"); if(req.isEmpty())return false;
  savePairing(req,pc); Serial.printf("[PAIR] pedido=%s codigo=%s\n",req.c_str(),pc.c_str()); return true;
}

void pollPairing() {
  if(pairing_request_id.isEmpty()||WiFi.status()!=WL_CONNECTED)return;
  WiFiClientSecure tls; HTTPClient http; if(!beginHttp(http,tls,casa_url+"/api/v1/provision.php?acao=consultar_pareamento"))return;
  http.addHeader("Content-Type","application/json");
  String payload="{\"request_id\":\""+jsonEscape(pairing_request_id)+"\",\"mac\":\""+jsonEscape(physicalMac())+"\",\"pairing_code\":\""+jsonEscape(pairing_code)+"\"}";
  int code=http.POST(payload); String body=http.getString(); http.end(); if(code<200||code>=300)return;
  String state=jsonValue(body,"status_pareamento"); if(state.isEmpty())state=jsonValue(body,"status");
  if(state=="authorized") { String id=jsonValue(body,"device_id"),token=jsonValue(body,"device_token"); if(!id.isEmpty()&&!token.isEmpty()){saveCredential(id,token);Serial.println("[PAIR] autorizado. Credencial gravada em NVS.");ESP.restart();} }
  else if(state=="expired"||state=="rejected") { savePairing("",""); Serial.println("[PAIR] pedido expirou/rejeitado; novo pedido sera criado."); }
}

void handleStream(){WiFiClient client=server.client();server.sendContent("HTTP/1.1 200 OK\r\nContent-Type: multipart/x-mixed-replace; boundary=frame\r\n\r\n");while(client.connected()){camera_fb_t*fb=esp_camera_fb_get();if(!fb)break;client.print("--frame\r\nContent-Type: image/jpeg\r\nContent-Length: "+String(fb->len)+"\r\n\r\n");client.write(fb->buf,fb->len);client.print("\r\n");esp_camera_fb_return(fb);yield();}}
void handleCapture(){camera_fb_t*fb=esp_camera_fb_get();if(!fb){server.send(500,"text/plain","Falha na captura");return;}server.sendHeader("Content-Type","image/jpeg");server.sendHeader("Content-Length",String(fb->len));server.client().write(fb->buf,fb->len);esp_camera_fb_return(fb);}
void handleStatus(){server.send(200,"application/json","{\"device_id\":\""+jsonEscape(device_id)+"\",\"name\":\""+jsonEscape(device_name)+"\",\"location\":\""+jsonEscape(device_location)+"\",\"type\":\"esp32cam\",\"ip\":\""+WiFi.localIP().toString()+"\",\"rssi\":"+String(WiFi.RSSI())+"}");}
void startCameraHttp(){server.stop();server.on("/stream",handleStream);server.on("/capture",handleCapture);server.on("/status",handleStatus);server.on("/flash/on",[]{digitalWrite(FLASH_LED_PIN,HIGH);server.send(200,"application/json","{\"flash\":1}");});server.on("/flash/off",[]{digitalWrite(FLASH_LED_PIN,LOW);server.send(200,"application/json","{\"flash\":0}");});server.begin();httpStarted=true;}

void sendHeartbeat(){if(!operational||WiFi.status()!=WL_CONNECTED)return;WiFiClientSecure tls;HTTPClient http;if(!beginHttp(http,tls,casa_url+"/api/v1/device.php?acao=heartbeat"))return;http.addHeader("Content-Type","application/json");http.addHeader("X-Device-Token",device_token);http.addHeader("Authorization","Bearer "+device_token);String p="{\"device_id\":\""+jsonEscape(device_id)+"\",\"transport\":\"wifi\",\"health\":\"ok\",\"protocol_version\":\"CASA/1.0\",\"model\":\"ESP32-CAM\",\"rssi\":"+String(WiFi.RSSI())+",\"local_ip\":\""+WiFi.localIP().toString()+"\",\"capabilities\":[\"camera\",\"snapshot\",\"mjpeg\",\"flash\",\"telemetry\",\"wifi\"]}";int c=http.POST(p);Serial.printf("[CASA] heartbeat HTTP %d\n",c);http.end();}

void startCamera(){camera_config_t c={};c.ledc_channel=LEDC_CHANNEL_0;c.ledc_timer=LEDC_TIMER_0;c.pin_d0=Y2_GPIO_NUM;c.pin_d1=Y3_GPIO_NUM;c.pin_d2=Y4_GPIO_NUM;c.pin_d3=Y5_GPIO_NUM;c.pin_d4=Y6_GPIO_NUM;c.pin_d5=Y7_GPIO_NUM;c.pin_d6=Y8_GPIO_NUM;c.pin_d7=Y9_GPIO_NUM;c.pin_xclk=XCLK_GPIO_NUM;c.pin_pclk=PCLK_GPIO_NUM;c.pin_vsync=VSYNC_GPIO_NUM;c.pin_href=HREF_GPIO_NUM;c.pin_sscb_sda=SIOD_GPIO_NUM;c.pin_sscb_scl=SIOC_GPIO_NUM;c.pin_pwdn=PWDN_GPIO_NUM;c.pin_reset=RESET_GPIO_NUM;c.xclk_freq_hz=20000000;c.pixel_format=PIXFORMAT_JPEG;if(psramFound()){c.frame_size=FRAMESIZE_VGA;c.jpeg_quality=12;c.fb_count=2;}else{c.frame_size=FRAMESIZE_QVGA;c.jpeg_quality=15;c.fb_count=1;}esp_err_t e=esp_camera_init(&c);if(e!=ESP_OK)Serial.printf("[CAM] init 0x%x\n",e);}

void setup(){Serial.begin(115200);pinMode(FLASH_LED_PIN,OUTPUT);digitalWrite(FLASH_LED_PIN,LOW);loadConfig();startCamera();
  if(wifi_ssid.isEmpty()){startConfigPortal();return;}
  if(!connectWifi()){Serial.println("[SETUP] Rede salva falhou; retornando ao AP.");startConfigPortal();return;}
  if(!operational){Serial.println("[SETUP] Rede OK; aguardando autorizacao CASA.");if(pairing_request_id.isEmpty())requestPairing();return;}
  startCameraHttp();sendHeartbeat();
}

void loop(){
  if(configPortal){server.handleClient();delay(2);return;}
  if(WiFi.status()!=WL_CONNECTED){Serial.println("[WIFI] Conexao perdida; retornando ao AP de configuracao.");startConfigPortal();return;}
  if(!operational){if(pairing_request_id.isEmpty())requestPairing();if(millis()-lastPairingPoll>=PAIRING_POLL_INTERVAL){lastPairingPoll=millis();pollPairing();}delay(10);return;}
  if(httpStarted)server.handleClient();if(millis()-lastHeartbeat>=HEARTBEAT_INTERVAL){lastHeartbeat=millis();sendHeartbeat();}delay(2);
}
