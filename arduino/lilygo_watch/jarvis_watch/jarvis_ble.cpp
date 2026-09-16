#include "jarvis_ble.h"
#include "jarvis_wifi.h"

#include <WiFi.h>

static const char *AP_SSID = "JARVIS-WATCH";
static const char *AP_PASS = "JarvisSetup2026";
static const uint16_t SOCKET_PORT = 4040;

static WiFiServer socketServer(SOCKET_PORT);
static WiFiClient socketClient;
static JarvisBleEventHandler eventHandler = nullptr;
static bool serverStarted = false;
static bool provisioningAp = false;
static bool phoneInternet = false;
static String rxBuffer;

static String jsonEscape(const String &s){
  String out;
  out.reserve(s.length()+8);
  for(size_t i=0;i<s.length();i++){
    char c=s[i];
    switch(c){
      case '\\': out+="\\\\"; break;
      case '"': out+="\\\""; break;
      case '\n': out+="\\n"; break;
      case '\r': out+="\\r"; break;
      case '\t': out+="\\t"; break;
      default: if((uint8_t)c>=0x20) out+=c; break;
    }
  }
  return out;
}

static int findJsonValue(const String &json,const char *key){
  String needle="\""+String(key)+"\"";
  int p=json.indexOf(needle);
  if(p<0) return -1;
  p=json.indexOf(':',p+needle.length());
  if(p<0) return -1;
  p++;
  while(p<(int)json.length() && (json[p]==' '||json[p]=='\t'||json[p]=='\r'||json[p]=='\n')) p++;
  return p;
}

static String jsonString(const String &json,const char *key,const String &fallback=String()){
  int p=findJsonValue(json,key);
  if(p<0||p>=(int)json.length()||json[p]!='"') return fallback;
  p++;
  String out;
  while(p<(int)json.length()){
    char c=json[p++];
    if(c=='"') break;
    if(c=='\\'&&p<(int)json.length()){
      char e=json[p++];
      if(e=='n') out+='\n';
      else if(e=='r') out+='\r';
      else if(e=='t') out+='\t';
      else out+=e;
    }else out+=c;
  }
  return out;
}

static int jsonInt(const String &json,const char *key,int fallback=0){
  int p=findJsonValue(json,key);
  if(p<0) return fallback;
  bool neg=false;
  if(p<(int)json.length()&&json[p]=='-'){neg=true;p++;}
  long v=0; bool any=false;
  while(p<(int)json.length()&&json[p]>='0'&&json[p]<='9'){
    any=true;v=v*10+(json[p]-'0');p++;
  }
  return any?(neg?-v:v):fallback;
}

static bool jsonBool(const String &json,const char *key,bool fallback=false){
  int p=findJsonValue(json,key);
  if(p<0) return fallback;
  if(json.startsWith("true",p)) return true;
  if(json.startsWith("false",p)) return false;
  return fallback;
}

void jarvisBleSetEventHandler(JarvisBleEventHandler handler){
  eventHandler=handler;
}

static void ensureServer(){
  if(serverStarted) return;
  socketServer.begin();
  socketServer.setNoDelay(true);
  serverStarted=true;
}

static void startProvisioningAp(){
  if(provisioningAp) return;

  // AP+STA permite receber configuração do celular e manter/tentar a rede normal.
  WiFi.mode(WIFI_AP_STA);
  delay(20);

  IPAddress apIp(192,168,4,1);
  IPAddress gateway(192,168,4,1);
  IPAddress subnet(255,255,255,0);
  WiFi.softAPConfig(apIp,gateway,subnet);
  WiFi.softAP(AP_SSID,AP_PASS,1,false,1);
  provisioningAp=true;
  ensureServer();
}

void jarvisBleBegin(){
  startProvisioningAp();
}

bool jarvisBleIsConnected(){
  return socketClient && socketClient.connected();
}

bool jarvisBlePhoneInternet(){
  return jarvisBleIsConnected() && phoneInternet;
}

bool jarvisBleSendJson(const String &json){
  if(!jarvisBleIsConnected() || json.isEmpty()) return false;
  socketClient.print(json);
  socketClient.print("\n");
  return socketClient.connected();
}

static void sendResult(const char *type,bool ok,const String &message=String()){
  String json="{\"type\":\""+String(type)+"\",\"ok\":"+(ok?"true":"false");
  if(!message.isEmpty()) json+=",\"message\":\""+jsonEscape(message)+"\"";
  json+=",\"protocol\":\"TCP-1.0\"}";
  jarvisBleSendJson(json);
}

static void dispatchExternal(const String &json,const String &type){
  if(!eventHandler) return;
  String title=jsonString(json,"title","");
  String text=jsonString(json,"text","");
  if(text.isEmpty()) text=jsonString(json,"message","");
  if(title.isEmpty()) title=jsonString(json,"sender","");
  if(type=="jarvis_result"&&text.isEmpty()) text=jsonString(json,"answer","");
  if(type=="gps_result"&&text.isEmpty()){
    String lat=jsonString(json,"lat","");
    String lon=jsonString(json,"lon","");
    if(!lat.isEmpty()||!lon.isEmpty()) text=lat+","+lon;
  }
  eventHandler(type,title,text);
}

static void processIncoming(const String &json){
  String type=jsonString(json,"type","");
  if(type.isEmpty()) return;

  if(type=="hello"){
    String out="{\"type\":\"hello\",\"ok\":true,\"device\":\"JARVIS Watch\",\"protocol\":\"TCP-1.0\",\"transport\":\"tcp\",\"wifi\":";
    out+=jarvisWifiIsConnected()?"true":"false";
    String deviceId=jarvisWifiDeviceId();
    if(!deviceId.isEmpty()) out+=",\"device_id\":\""+jsonEscape(deviceId)+"\"";
    out+="}";
    jarvisBleSendJson(out);
    return;
  }

  if(type=="ping"){
    jarvisBleSendJson("{\"type\":\"pong\",\"ok\":true,\"protocol\":\"TCP-1.0\"}");
    return;
  }

  if(type=="phone_state"){
    phoneInternet=jsonBool(json,"internet",false);
    dispatchExternal(json,type);
    return;
  }

  if(type=="device_identity"){
    String deviceId=jsonString(json,"device_id","");
    deviceId.trim();
    bool ok=!deviceId.isEmpty();
    if(ok) jarvisWifiSetDeviceId(deviceId);
    sendResult("device_identity_result",ok,ok?"Device ID salvo":"Device ID invalido");
    if(ok) dispatchExternal(json,type);
    return;
  }

  if(type=="wifi_profile"){
    int slot=jsonInt(json,"slot",-1);
    String ssid=jsonString(json,"ssid","");
    String pass=jsonString(json,"password","");
    bool ok=slot>=0&&slot<5&&jarvisWifiSetProfile((uint8_t)slot,ssid,pass);
    sendResult("wifi_profile_result",ok,ok?("Rede "+ssid+" salva"):"Perfil Wi-Fi invalido");
    return;
  }

  if(type=="wifi_connect"){
    int slot=jsonInt(json,"slot",-1);
    bool ok=false;
    if(slot>=0&&slot<5) ok=jarvisWifiStartProfile((uint8_t)slot);
    else ok=jarvisWifiStartPreferred();
    sendResult("wifi_connect_result",ok,ok?"Conexao Wi-Fi iniciada":"Nenhum perfil Wi-Fi valido");
    return;
  }

  if(type=="casa_config"){
    String base=jsonString(json,"base_url","");
    String token=jsonString(json,"device_token","");
    bool ok=!base.isEmpty()&&!token.isEmpty();
    if(ok) jarvisWifiSetCasa(base,token);
    sendResult("casa_config_result",ok,ok?"CASA configurada":"Configuracao CASA incompleta");
    return;
  }

  if(type=="status"){
    String out="{\"type\":\"status\",\"ok\":true,\"transport\":\"tcp\",\"protocol\":\"TCP-1.0\",\"wifi\":";
    out+=jarvisWifiIsConnected()?"true":"false";
    if(jarvisWifiIsConnected()) out+=",\"ssid\":\""+jsonEscape(jarvisWifiSsid())+"\"";
    out+=",\"ap\":true,\"ap_ssid\":\""+String(AP_SSID)+"\"";
    out+=",\"casa_configured\":";
    out+=jarvisWifiHasCasaCredentials()?"true":"false";
    String deviceId=jarvisWifiDeviceId();
    if(!deviceId.isEmpty()) out+=",\"device_id\":\""+jsonEscape(deviceId)+"\"";
    out+="}";
    jarvisBleSendJson(out);
    return;
  }

  dispatchExternal(json,type);
}

void jarvisBleLoop(){
  ensureServer();

  if(!jarvisBleIsConnected()){
    WiFiClient incoming=socketServer.available();
    if(incoming){
      socketClient.stop();
      socketClient=incoming;
      socketClient.setNoDelay(true);
      rxBuffer="";
    }
  }

  if(!jarvisBleIsConnected()) return;

  while(socketClient.available()){
    char c=(char)socketClient.read();
    if(c=='\n'){
      String line=rxBuffer;
      rxBuffer="";
      line.trim();
      if(!line.isEmpty()) processIncoming(line);
    }else if(c!='\r'){
      if(rxBuffer.length()<1024) rxBuffer+=c;
      else rxBuffer="";
    }
  }
}

bool jarvisBleSendCommand(const String &command){
  if(command.isEmpty()) return false;
  if(command=="camera_capture") return jarvisBleSendJson("{\"type\":\"camera_capture\"}");
  if(command=="gps_request") return jarvisBleSendJson("{\"type\":\"gps_request\"}");
  if(command.startsWith("family_call:")){
    String mode=command.substring(String("family_call:").length());
    return jarvisBleSendJson("{\"type\":\"family_call\",\"mode\":\""+jsonEscape(mode)+"\"}");
  }
  if(command.startsWith("voice_capture:")){
    String output=command.substring(String("voice_capture:").length());
    return jarvisBleSendJson("{\"type\":\"voice_capture\",\"output\":\""+jsonEscape(output)+"\"}");
  }
  if(command=="alarm_sound:phone") return jarvisBleSendJson("{\"type\":\"alarm_sound\",\"target\":\"phone\"}");
  return jarvisBleSendJson("{\"type\":\"voice_text\",\"text\":\""+jsonEscape(command)+"\"}");
}
