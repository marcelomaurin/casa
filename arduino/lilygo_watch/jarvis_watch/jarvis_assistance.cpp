#include "jarvis_assistance.h"
#include "jarvis_ble.h"
#include "jarvis_wifi.h"
#include <Preferences.h>

static Preferences assistPrefs;
static bool enabledFlag = true;
static bool alertActive = false;
static uint16_t inactivityMinutes = 60;
static uint32_t lastSteps = 0;
static unsigned long lastMovementMs = 0;
static unsigned long lastTelemetryMs = 0;
static unsigned long lastAlertMs = 0;

static bool sendViaAvailable(const String &bleCommand, const String &jsonPayload){
  if(jarvisBleIsConnected() && jarvisBleSendCommand(bleCommand)) return true;
  if(!jarvisWifiIsConnected()) jarvisWifiConnectBestKnown(8000);
  if(jarvisWifiIsConnected()) return jarvisWifiPostJson("/api/v1/family.php?acao=assist_event", jsonPayload, nullptr);
  return false;
}

void jarvisAssistanceBegin(uint32_t initialSteps){
  assistPrefs.begin("assist", false);
  enabledFlag = assistPrefs.getBool("enabled", true);
  inactivityMinutes = assistPrefs.getUShort("inactive", 60);
  if(inactivityMinutes < 10) inactivityMinutes = 10;
  if(inactivityMinutes > 720) inactivityMinutes = 720;
  lastSteps = initialSteps;
  lastMovementMs = millis();
}

void jarvisAssistanceMarkInteraction(){
  lastMovementMs = millis();
  if(alertActive) alertActive = false;
}

void jarvisAssistanceLoop(uint32_t currentSteps){
  if(!enabledFlag) return;
  if(currentSteps != lastSteps){ lastSteps = currentSteps; lastMovementMs = millis(); alertActive = false; }

  if(millis() - lastTelemetryMs > 300000UL){
    lastTelemetryMs = millis();
    String body = String("{\"type\":\"MOVEMENT_HEARTBEAT\",\"severity\":\"info\",\"message\":\"Telemetria de movimento do relógio\",\"device\":\"JARVIS Watch\",\"data\":{\"steps\":") + currentSteps + ",\"minutes_without_movement\":" + jarvisAssistanceMinutesWithoutMovement() + "}}";
    sendViaAvailable("assist:movement:" + String(currentSteps), body);
  }

  unsigned long limitMs = (unsigned long)inactivityMinutes * 60000UL;
  if(millis() - lastMovementMs >= limitMs && (!alertActive || millis() - lastAlertMs > limitMs)){
    alertActive = true;
    lastAlertMs = millis();
    String body = String("{\"type\":\"INACTIVITY\",\"severity\":\"alta\",\"message\":\"Período prolongado sem movimento detectado pelo relógio\",\"device\":\"JARVIS Watch\",\"data\":{\"minutes_without_movement\":") + jarvisAssistanceMinutesWithoutMovement() + ",\"steps\":" + currentSteps + "}}";
    sendViaAvailable("assist:inactivity:" + String(jarvisAssistanceMinutesWithoutMovement()), body);
  }
}

bool jarvisAssistanceSendSos(){
  alertActive = true;
  String body = "{\"type\":\"SOS\",\"severity\":\"critica\",\"message\":\"SOS acionado no JARVIS Watch\",\"device\":\"JARVIS Watch\",\"data\":{\"source\":\"watch_button\"}}";
  return sendViaAvailable("assist:sos", body);
}

bool jarvisAssistanceSendCheckin(bool ok){
  String body = String("{\"type\":\"CHECKIN\",\"severity\":\"") + (ok?"info":"alta") + "\",\"message\":\"" + (ok?"Check-in confirmado no relógio":"Check-in solicitando atenção") + "\",\"device\":\"JARVIS Watch\",\"data\":{\"ok\":" + (ok?"true":"false") + "}}";
  if(ok){ alertActive=false; lastMovementMs=millis(); }
  return sendViaAvailable(ok?"assist:checkin:ok":"assist:checkin:attention", body);
}

bool jarvisAssistanceStartFamilyCall(bool video){
  if(jarvisBleIsConnected()) return jarvisBleSendCommand(video ? "family_call:video" : "family_call:audio");
  // Sem celular não há câmera no T-Watch; pelo Wi-Fi o relógio apenas gera o convite no canal.
  if(!jarvisWifiIsConnected()) jarvisWifiConnectBestKnown(8000);
  if(!jarvisWifiIsConnected()) return false;
  String body = String("{\"mode\":\"") + (video?"video":"audio") + "\",\"origin\":\"watch\"}";
  return jarvisWifiPostJson("/api/v1/family.php?acao=call_start", body, nullptr);
}

unsigned long jarvisAssistanceMinutesWithoutMovement(){ return (millis() - lastMovementMs) / 60000UL; }
bool jarvisAssistanceAlertActive(){ return alertActive; }
void jarvisAssistanceAcknowledge(){ alertActive=false; lastMovementMs=millis(); }
void jarvisAssistanceSetEnabled(bool enabled){ enabledFlag=enabled; assistPrefs.putBool("enabled",enabledFlag); }
bool jarvisAssistanceEnabled(){ return enabledFlag; }
void jarvisAssistanceSetInactivityMinutes(uint16_t minutes){ inactivityMinutes=constrain(minutes,(uint16_t)10,(uint16_t)720); assistPrefs.putUShort("inactive",inactivityMinutes); }
uint16_t jarvisAssistanceInactivityMinutes(){ return inactivityMinutes; }
