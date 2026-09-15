#include "jarvis_ble.h"
#include "jarvis_wifi.h"

#include <NimBLEDevice.h>

static const char *SERVICE_UUID = "7a9f1000-3a8c-4b62-9e5f-1b0c0e91a001";
static const char *CONTROL_UUID = "7a9f1001-3a8c-4b62-9e5f-1b0c0e91a001";
static const char *EVENT_UUID   = "7a9f1002-3a8c-4b62-9e5f-1b0c0e91a001";
static const char *DEVICE_NAME  = "JARVIS Watch";

static NimBLEServer *bleServer = nullptr;
static NimBLECharacteristic *eventCharacteristic = nullptr;
static volatile bool bleConnected = false;
static volatile bool phoneInternet = false;
static JarvisBleEventHandler eventHandler = nullptr;

// Fila fixa: callbacks BLE nao fazem String/draw/TFT.
static const uint8_t RX_QUEUE_SIZE = 8;
static const size_t RX_MAX = 181;
static char rxQueue[RX_QUEUE_SIZE][RX_MAX];
static uint16_t rxLen[RX_QUEUE_SIZE];
static volatile uint8_t rxHead = 0;
static volatile uint8_t rxTail = 0;
static volatile uint8_t rxCount = 0;
static portMUX_TYPE rxMux = portMUX_INITIALIZER_UNLOCKED;

static String jsonEscape(const String &s){
  String out;
  out.reserve(s.length() + 8);
  for(size_t i=0;i<s.length();i++){
    char c=s[i];
    switch(c){
      case '\\': out += "\\\\"; break;
      case '"': out += "\\\""; break;
      case '\n': out += "\\n"; break;
      case '\r': out += "\\r"; break;
      case '\t': out += "\\t"; break;
      default:
        if((uint8_t)c >= 0x20) out += c;
        break;
    }
  }
  return out;
}

static int findJsonValue(const String &json, const char *key){
  String needle = "\"" + String(key) + "\"";
  int p = json.indexOf(needle);
  if(p < 0) return -1;
  p = json.indexOf(':', p + needle.length());
  if(p < 0) return -1;
  p++;
  while(p < (int)json.length() && (json[p]==' ' || json[p]=='\t' || json[p]=='\r' || json[p]=='\n')) p++;
  return p;
}

static String jsonString(const String &json, const char *key, const String &fallback=String()){
  int p=findJsonValue(json,key);
  if(p<0 || p>=(int)json.length() || json[p]!='"') return fallback;
  p++;
  String out;
  while(p<(int)json.length()){
    char c=json[p++];
    if(c=='"') break;
    if(c=='\\' && p<(int)json.length()){
      char e=json[p++];
      if(e=='n') out+='\n';
      else if(e=='r') out+='\r';
      else if(e=='t') out+='\t';
      else out+=e;
    }else out+=c;
  }
  return out;
}

static int jsonInt(const String &json, const char *key, int fallback=0){
  int p=findJsonValue(json,key);
  if(p<0) return fallback;
  bool neg=false;
  if(p<(int)json.length() && json[p]=='-'){neg=true;p++;}
  long v=0; bool any=false;
  while(p<(int)json.length() && json[p]>='0' && json[p]<='9'){
    any=true;v=v*10+(json[p]-'0');p++;
  }
  return any ? (neg ? -v : v) : fallback;
}

static bool jsonBool(const String &json, const char *key, bool fallback=false){
  int p=findJsonValue(json,key);
  if(p<0) return fallback;
  if(json.startsWith("true",p)) return true;
  if(json.startsWith("false",p)) return false;
  return fallback;
}

static bool queueIncoming(const uint8_t *data, size_t len){
  if(!data || len==0) return false;
  if(len >= RX_MAX) len = RX_MAX-1;
  portENTER_CRITICAL(&rxMux);
  if(rxCount >= RX_QUEUE_SIZE){
    portEXIT_CRITICAL(&rxMux);
    return false;
  }
  uint8_t slot=rxTail;
  memcpy(rxQueue[slot],data,len);
  rxQueue[slot][len]=0;
  rxLen[slot]=(uint16_t)len;
  rxTail=(rxTail+1)%RX_QUEUE_SIZE;
  rxCount++;
  portEXIT_CRITICAL(&rxMux);
  return true;
}

static bool popIncoming(String &out){
  char local[RX_MAX];
  uint16_t len=0;
  portENTER_CRITICAL(&rxMux);
  if(rxCount==0){
    portEXIT_CRITICAL(&rxMux);
    return false;
  }
  uint8_t slot=rxHead;
  len=rxLen[slot];
  memcpy(local,rxQueue[slot],len);
  local[len]=0;
  rxHead=(rxHead+1)%RX_QUEUE_SIZE;
  rxCount--;
  portEXIT_CRITICAL(&rxMux);
  out=String(local);
  out.trim();
  return true;
}

class JarvisServerCallbacks : public NimBLEServerCallbacks {
  void onConnect(NimBLEServer *server) override {
    (void)server;
    bleConnected=true;
  }

  void onDisconnect(NimBLEServer *server) override {
    (void)server;
    bleConnected=false;
    phoneInternet=false;
    NimBLEDevice::startAdvertising();
  }
};

class JarvisControlCallbacks : public NimBLECharacteristicCallbacks {
  void onWrite(NimBLECharacteristic *characteristic) override {
    std::string value=characteristic->getValue();
    if(value.empty()) return;
    queueIncoming((const uint8_t*)value.data(),value.size());
  }
};

void jarvisBleSetEventHandler(JarvisBleEventHandler handler){
  eventHandler=handler;
}

void jarvisBleBegin(){
  NimBLEDevice::init(DEVICE_NAME);
  NimBLEDevice::setMTU(185);

  bleServer=NimBLEDevice::createServer();
  bleServer->setCallbacks(new JarvisServerCallbacks());

  NimBLEService *service=bleServer->createService(SERVICE_UUID);

  NimBLECharacteristic *control=service->createCharacteristic(
    CONTROL_UUID,
    NIMBLE_PROPERTY::WRITE | NIMBLE_PROPERTY::WRITE_NR
  );
  control->setCallbacks(new JarvisControlCallbacks());

  eventCharacteristic=service->createCharacteristic(
    EVENT_UUID,
    NIMBLE_PROPERTY::READ | NIMBLE_PROPERTY::NOTIFY
  );
  // O NimBLE cria automaticamente o CCCD 0x2902 para characteristics NOTIFY.
  eventCharacteristic->setValue("{\"type\":\"boot\",\"protocol\":\"2.0\"}");

  service->start();

  NimBLEAdvertising *advertising=NimBLEDevice::getAdvertising();
  advertising->addServiceUUID(SERVICE_UUID);
  advertising->setScanResponse(true);
  advertising->start();
}

bool jarvisBleIsConnected(){
  return bleConnected;
}

bool jarvisBlePhoneInternet(){
  return bleConnected && phoneInternet;
}

bool jarvisBleSendJson(const String &json){
  if(!bleConnected || !eventCharacteristic || json.isEmpty()) return false;
  // Android monta mensagens delimitadas por LF. O frame completo deve caber
  // no MTU negociado (185 -> 182 bytes de ATT; usamos 180 por margem).
  if(json.length() > 179) return false;
  String framed=json+"\n";
  eventCharacteristic->setValue((uint8_t*)framed.c_str(),framed.length());
  eventCharacteristic->notify();
  return true;
}

static void sendResult(const char *type, bool ok, const String &message=String()){
  String json="{\"type\":\""+String(type)+"\",\"ok\":"+(ok?"true":"false");
  if(!message.isEmpty()) json += ",\"message\":\""+jsonEscape(message)+"\"";
  json += ",\"protocol\":\"2.0\"}";
  jarvisBleSendJson(json);
}

static void dispatchExternal(const String &json, const String &type){
  if(!eventHandler) return;

  String title=jsonString(json,"title","");
  String text=jsonString(json,"text","");
  if(text.isEmpty()) text=jsonString(json,"message","");
  if(title.isEmpty()) title=jsonString(json,"sender","");

  if(type=="jarvis_result" && text.isEmpty()) text=jsonString(json,"answer","");
  if(type=="gps_result" && text.isEmpty()){
    String lat=jsonString(json,"lat","");
    String lon=jsonString(json,"lon","");
    if(!lat.isEmpty() || !lon.isEmpty()) text=lat+","+lon;
  }

  eventHandler(type,title,text);
}

static void processIncoming(const String &json){
  String type=jsonString(json,"type","");
  if(type.isEmpty()) return;

  if(type=="hello"){
    String out="{\"type\":\"hello\",\"ok\":true,\"device\":\"JARVIS Watch\",\"protocol\":\"2.0\",\"wifi\":";
    out += jarvisWifiIsConnected() ? "true" : "false";
    out += "}";
    jarvisBleSendJson(out);
    return;
  }

  if(type=="ping"){
    jarvisBleSendJson("{\"type\":\"pong\",\"ok\":true}");
    return;
  }

  if(type=="phone_state"){
    phoneInternet=jsonBool(json,"internet",false);
    dispatchExternal(json,type);
    return;
  }

  if(type=="wifi_profile"){
    int slot=jsonInt(json,"slot",-1);
    String ssid=jsonString(json,"ssid","");
    String pass=jsonString(json,"password","");
    bool ok=slot>=0 && slot<5 && jarvisWifiSetProfile((uint8_t)slot,ssid,pass);
    sendResult("wifi_profile_result",ok,ok?("Rede "+ssid+" salva"):"Perfil Wi-Fi invalido");
    return;
  }

  if(type=="casa_config"){
    String base=jsonString(json,"base_url","");
    String token=jsonString(json,"device_token","");
    bool ok=!base.isEmpty() && !token.isEmpty();
    if(ok) jarvisWifiSetCasa(base,token);
    sendResult("casa_config_result",ok,ok?"CASA configurada":"Configuracao CASA incompleta");
    return;
  }

  if(type=="status"){
    String out="{\"type\":\"status\",\"ok\":true,\"ble\":true,\"wifi\":";
    out += jarvisWifiIsConnected() ? "true" : "false";
    if(jarvisWifiIsConnected()) out += ",\"ssid\":\""+jsonEscape(jarvisWifiSsid())+"\"";
    out += ",\"phone_internet\":";
    out += phoneInternet ? "true" : "false";
    out += "}";
    jarvisBleSendJson(out);
    return;
  }

  // find_watch, incoming_call, phone_notification, family_message,
  // jarvis_result, gps_result, camera_result etc. vao para o firmware principal.
  dispatchExternal(json,type);
}

void jarvisBleLoop(){
  String json;
  // Processa no maximo duas mensagens por volta para nunca monopolizar a UI.
  for(uint8_t i=0;i<2 && popIncoming(json);i++) processIncoming(json);
}

bool jarvisBleSendCommand(const String &command){
  if(command.isEmpty()) return false;

  if(command=="camera_capture")
    return jarvisBleSendJson("{\"type\":\"camera_capture\"}");

  if(command=="gps_request")
    return jarvisBleSendJson("{\"type\":\"gps_request\"}");

  if(command.startsWith("family_call:")){
    String mode=command.substring(String("family_call:").length());
    return jarvisBleSendJson("{\"type\":\"family_call\",\"mode\":\""+jsonEscape(mode)+"\"}");
  }

  if(command.startsWith("voice_capture:")){
    String output=command.substring(String("voice_capture:").length());
    return jarvisBleSendJson("{\"type\":\"voice_capture\",\"output\":\""+jsonEscape(output)+"\"}");
  }

  if(command=="alarm_sound:phone")
    return jarvisBleSendJson("{\"type\":\"alarm_sound\",\"target\":\"phone\"}");

  // Comandos em linguagem natural seguem para o mesmo pipeline da IA no celular.
  return jarvisBleSendJson("{\"type\":\"voice_text\",\"text\":\""+jsonEscape(command)+"\"}");
}
