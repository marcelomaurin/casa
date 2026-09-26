#include "jarvis_emergency.h"
#include "jarvis_emergency_voice.h"
#include "jarvis_audio.h"
#include "jarvis_wifi.h"
#include <ArduinoJson.h>
#include <Preferences.h>
#include <esp_system.h>
#include <math.h>

namespace {
Preferences storage;
JarvisEmergencyState state = EMERGENCY_GREEN;
bool confirming = false, activationPending = false, locationPending = false, cancelPending = false;
String session, location;
uint32_t lastAttempt = 0, lastGps = 0, lastVoice = 0, lastTone = 0;
bool gpsRequested = false;
const uint8_t *speech = nullptr;
size_t speechLength = 0, speechOffset = 0;

// Only this worker sends emergency HTTP requests. UI/audio never waits for HTTP.
struct Job { char path[80]; char body[1800]; uint8_t kind; };
struct Result { bool ok; uint8_t kind; };
QueueHandle_t jobs = nullptr, results = nullptr;
bool inFlight = false;

void persist(){
  StaticJsonDocument<2400> doc;
  doc["active"] = state == EMERGENCY_RED;
  doc["session"] = session;
  doc["location"] = location;
  doc["activation"] = activationPending;
  doc["update"] = locationPending;
  doc["cancel"] = cancelPending;
  String json; serializeJson(doc,json);
  storage.putString("state",json);
}

void worker(void *){
  Job job;
  for(;;){
    if(xQueueReceive(jobs,&job,portMAX_DELAY)!=pdTRUE)continue;
    String response;
    Result result{jarvisWifiPostJson(job.path,job.body,&response),job.kind};
    StaticJsonDocument<768> reply;
    result.ok = result.ok && !deserializeJson(reply,response) &&
      String(reply["status"] | "") == "ok";
    xQueueSend(results,&result,portMAX_DELAY);
  }
}

bool enqueue(const char *path,const String &body,uint8_t kind){
  if(!jobs || inFlight || body.length()>=sizeof(Job::body))return false;
  Job job{};
  strlcpy(job.path,path,sizeof(job.path));
  strlcpy(job.body,body.c_str(),sizeof(job.body));
  job.kind=kind;
  if(xQueueSend(jobs,&job,0)!=pdTRUE)return false;
  inFlight=true;
  return true;
}

void say(bool confirmation){
  jarvisAudioStop();
  speech=confirmation?emergencyConfirmVoice:emergencyActivatedVoice;
  speechLength=confirmation?sizeof(emergencyConfirmVoice):sizeof(emergencyActivatedVoice);
  speechOffset=0;
  lastVoice=millis();
}

String eventPayload(uint8_t kind){
  StaticJsonDocument<2300> doc;
  doc["type"] = kind==1?"SOS":kind==2?"SOS_LOCATION":"SOS_CANCELLED";
  doc["severity"] = kind==3?"info":"critica";
  doc["message"] = kind==1?"Sistema de emergencia ativado no JARVIS Watch":
    kind==2?"Localizacao da emergencia recebida":"Alarme desarmado pelo usuario no JARVIS Watch";
  doc["device"] = jarvisWifiDeviceId();
  JsonObject data=doc.createNestedObject("data");
  data["source"]="watch_emergency";
  data["session_id"]=session;
  data["event_key"]=session+"-"+String(kind);
  data["active"]=kind!=3;
  data["location_status"]=location.isEmpty()?"unavailable":"available";
  if(!location.isEmpty()){
    StaticJsonDocument<768> gps;
    if(!deserializeJson(gps,location))data["location"].set(gps.as<JsonObject>());
  }
  String body; serializeJson(doc,body); return body;
}
}

void jarvisEmergencyBegin(){
  storage.begin("emergency",false);
  StaticJsonDocument<2400> doc;
  if(!deserializeJson(doc,storage.getString("state","{}"))){
    state=doc["active"]==true?EMERGENCY_RED:EMERGENCY_GREEN;
    session=String(doc["session"] | "");
    location=String(doc["location"] | "");
    activationPending=doc["activation"] | false;
    locationPending=doc["update"] | false;
    cancelPending=doc["cancel"] | false;
  }
  jobs=xQueueCreate(1,sizeof(Job)); results=xQueueCreate(1,sizeof(Result));
  if(!jobs||!results||xTaskCreate(worker,"emergency-http",8192,nullptr,1,nullptr)!=pdPASS){
    if(jobs)vQueueDelete(jobs);
    if(results)vQueueDelete(results);
    jobs=nullptr; results=nullptr;
  }
  if(state==EMERGENCY_RED)say(false);
}

JarvisEmergencyState jarvisEmergencyState(){return state;}
bool jarvisEmergencyConfirming(){return confirming;}
bool jarvisEmergencySpeaking(){return speech!=nullptr;}
bool jarvisEmergencyBusy(){return state!=EMERGENCY_GREEN||activationPending||locationPending||cancelPending||inFlight;}
String jarvisEmergencyStatus(){
  if(state==EMERGENCY_GREEN)return cancelPending?"Desarme: envio pendente":"Toque para preparar";
  if(state==EMERGENCY_YELLOW)return "Toque de novo para ativar";
  if(confirming)return "Desca novamente para desarmar";
  if(activationPending)return "Aviso: envio pendente";
  return location.isEmpty()?"Aviso enviado / sem GPS":"Aviso enviado / GPS recebido";
}

void jarvisEmergencyTap(){
  confirming=false;
  if(state==EMERGENCY_GREEN){state=EMERGENCY_YELLOW;lastTone=0;return;}
  if(state!=EMERGENCY_YELLOW)return;
  // Never overwrite an earlier SOS/cancellation waiting for delivery.
  if(activationPending||locationPending||cancelPending||inFlight)return;
  state=EMERGENCY_RED;
  session=String((uint32_t)ESP.getEfuseMac(),HEX)+"-"+String(esp_random(),HEX)+"-"+String(esp_random(),HEX);
  location="";activationPending=true;gpsRequested=false;lastAttempt=0;lastGps=0;
  persist();say(false);
}

bool jarvisEmergencySwipeDown(){
  if(state==EMERGENCY_RED&&!confirming){confirming=true;say(true);return false;}
  if(state==EMERGENCY_RED){cancelPending=true;}
  state=EMERGENCY_GREEN;confirming=false;speech=nullptr;
  jarvisAudioStop();persist();lastAttempt=0;
  return true;
}
void jarvisEmergencyCancelConfirmation(){confirming=false;}

void jarvisEmergencyReceiveGps(const String &payload) {
  if (state != EMERGENCY_RED || !location.isEmpty()) {
    return;
  }

  StaticJsonDocument<1024> doc;

  DeserializationError error = deserializeJson(doc, payload);
  if (error) {
    return;
  }

  bool gpsOk = doc["ok"].as<bool>();
  String requestId = doc["request_id"].as<String>();

  if (!gpsOk || requestId != session) {
    return;
  }

  double lat = doc["lat"].as<double>();
  double lon = doc["lon"].as<double>();
  double age = doc["age_ms"].as<double>();

  if (!isfinite(lat) || !isfinite(lon) || !isfinite(age) ||
      fabs(lat) > 90 || fabs(lon) > 180 ||
      age < 0 || age > 120000) {
    return;
  }

  StaticJsonDocument<768> fix;
  fix["lat"] = lat;
  fix["lon"] = lon;
  fix["age_ms"] = age;
  fix["source"] = "phone_gps";
  fix["time"] = doc["time"];
  fix["accuracy_m"] = doc["accuracy_m"];

  serializeJson(fix, location);
  locationPending = true;
  persist();
  lastAttempt = 0;
}

void jarvisEmergencyLoop(){
  uint32_t now=millis();
  if(speech){
    int16_t pcm[256];size_t count=min((size_t)256,speechLength-speechOffset);
    for(size_t i=0;i<count;i++)pcm[i]=((int)pgm_read_byte(speech+speechOffset+i)-128)*128;
    speechOffset+=jarvisAudioWritePcm16Mono(pcm,count);
    if(speechOffset>=speechLength){speech=nullptr;lastVoice=now;}
  }else if(state==EMERGENCY_RED&&!confirming&&now-lastVoice>=15000UL){say(false);}
  else if(state==EMERGENCY_YELLOW&&(lastTone==0||now-lastTone>=750UL)){
    lastTone=now;jarvisAudioPlayTone(1450,180,55);
  }
  Result result;
  if(results&&xQueueReceive(results,&result,0)==pdTRUE){
    inFlight=false;
    if(result.ok){
      if(result.kind==1)activationPending=false;
      if(result.kind==2)locationPending=false;
      if(result.kind==3)cancelPending=false;
      persist();
    }
  }
  if(inFlight||!jarvisWifiIsConnected()||!jarvisWifiHasCasaCredentials())return;
  if(lastAttempt!=0&&now-lastAttempt<10000UL)return;
  lastAttempt=now;
  // Initial alert does not wait for GPS. A separate event adds the fix later.
  uint8_t kind=activationPending?1:locationPending?2:cancelPending?3:0;
  if(kind){enqueue("/api/v1/watch.php?acao=assist_event",eventPayload(kind),kind);return;}
  if(state==EMERGENCY_RED&&location.isEmpty()&&(!gpsRequested||now-lastGps>=30000UL)){
    StaticJsonDocument<768> doc;
    doc["device_id"]=jarvisWifiDeviceId();doc["type"]="gps_request";doc["priority"]="high";
    JsonObject data=doc.createNestedObject("data");data["type"]="gps_request";data["request_id"]=session;data["fresh"]=true;
    String body;serializeJson(doc,body);
    if(enqueue("/api/v1/device.php?acao=event",body,4)){lastGps=now;gpsRequested=true;}
  }
}
