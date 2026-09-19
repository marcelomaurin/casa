#include "config.h"
#include "jarvis_ble.h"
#include "jarvis_wifi.h"
#include "jarvis_controller.h"
#include "jarvis_audio.h"
#include "jarvis_ir.h"
#include <Preferences.h>
#include <driver/i2s.h>
#include <esp_sleep.h>
#include <esp_system.h>
#include <math.h>

TTGOClass *watch = nullptr;
TFT_eSPI *tft = nullptr;
Preferences prefs;
JarvisController controller;

enum ScreenId {
  SCREEN_HOME, SCREEN_APPS, SCREEN_VOICE, SCREEN_ALARM, SCREEN_CAMERA,
  SCREEN_GPS, SCREEN_CONTROLS, SCREEN_HEALTH, SCREEN_STATUS,
  SCREEN_SETTINGS, SCREEN_CLOCK, SCREEN_WIFI, SCREEN_KEYBOARD, SCREEN_NOTIFICATION
};
enum PowerMode { POWER_NORMAL, POWER_ECO, POWER_ULTRA };
enum VoiceOutput { VOICE_PHONE, VOICE_TEXT, VOICE_BOTH };
enum AlarmTone { ALARM_SHORT, ALARM_DOUBLE, ALARM_URGENT, ALARM_PHONE };

ScreenId currentScreen = SCREEN_HOME;
ScreenId previousScreen = SCREEN_HOME;
PowerMode powerMode = POWER_ECO;
VoiceOutput voiceOutput = VOICE_BOTH;
AlarmTone alarmTone = ALARM_DOUBLE;

unsigned long bootMillis=0, lastClockRefresh=0, lastStepRefresh=0;
bool screenAwake=true, vibrationEnabled=true, wristWakeEnabled=true, voiceReady=false;
uint8_t brightnessLevel=150;
uint16_t screenTimeoutSec=20;
uint32_t steps=0;
String lastMessage="JARVIS pronto", voiceText="TOQUE PARA FALAR", gpsText="GPS PELO CELULAR", cameraText="CAMERA DO CELULAR";
String notificationTitle="", notificationText="";
long pendingCallId=0;
String pendingCallMode="video", pendingCallSender="";

bool alarmEnabled=false;
uint8_t alarmHour=7, alarmMinute=0;

// Captura PDM não bloqueante.
bool voiceCapturing=false;
unsigned long voiceCaptureUntil=0;
uint64_t voiceSum=0;
uint32_t voiceSamples=0;

// Botão físico AXP202: ISR só sinaliza. Nenhum hardware é manipulado na interrupção.
volatile bool powerButtonIrq=false;
unsigned long lastPowerButtonMs=0;
void IRAM_ATTR onPowerButtonIrq(){ powerButtonIrq=true; }

// Touch / swipe.
// touchWakeConsumed permanece true desde o primeiro toque com a tela apagada
// ate o dedo ser retirado. Assim o toque que acorda a tela nao aciona a UI.
bool touchActive=false;
bool touchWakeConsumed=false;
int16_t touchStartX=0,touchStartY=0,touchLastX=0,touchLastY=0;
unsigned long touchStartMs=0;
const int SWIPE_MIN=42;
const unsigned long TAP_MAX_MS=650;

// Wi-Fi setup. Os resultados são espelhados da NetworkStateMachine para a UI.
JarvisWifiNetwork wifiNetworks[12];
int wifiNetworkCount=0, wifiPage=0;
bool wifiScanning=false;
String selectedWifi="", wifiPassword="";
bool lastWifiUiState=false,lastCasaUiState=false,lastCasaCheckedUiState=false;

// Deep sleep / wake periodico.
// TIMER acorda em segundo plano para consultar o CASA sem acender a tela.
// EXT0 = AXP202/botao. EXT1 = touch FT6336.
esp_sleep_wakeup_cause_t wakeCause=ESP_SLEEP_WAKEUP_UNDEFINED;
bool backgroundTimerWake=false;
unsigned long lastCasaCommandPoll=0;
unsigned long lastCasaHeartbeat=0;
unsigned long lastCasaTelemetry=0;
static const unsigned long CASA_HEARTBEAT_INTERVAL_MS=30000UL;
static const unsigned long CASA_TELEMETRY_INTERVAL_MS=60000UL;

static const uint16_t C_BG=0xFFDF,C_TEXT=0x18C3,C_ORANGE=0xFBE0,C_SALMON=0xFB2C;
static const uint16_t C_LAV=0xB57F,C_BLUE=0x5D7F,C_GREEN=0x6E6B,C_RED=0xF9E7,C_GOLD=0xFE60,C_WHITE=0xFFFF;
static const uint16_t C_BLACK=0x0000,C_DARK=0x2104,C_STEEL=0x8410;

void drawScreen();
void enterDeepSleep();
void controllerEvent(const JarvisEvent &event);
void bleEventHandler(const String &type,const String &title,const String &text);

String twoDigits(uint8_t v){return v<10?"0"+String(v):String(v);}
int batteryPercent(){if(!watch||!watch->power||!watch->power->isBatteryConnect())return -1;return constrain(watch->power->getBattPercentage(),0,100);}
String powerLabel(){return powerMode==POWER_NORMAL?"NORMAL":powerMode==POWER_ECO?"ECO":"ULTRA";}
String uptimeText(){unsigned long s=(millis()-bootMillis)/1000UL;return String(s/3600UL)+"h "+String((s%3600UL)/60UL)+"m";}
String voiceOutputLabel(){return voiceOutput==VOICE_PHONE?"CELULAR":voiceOutput==VOICE_TEXT?"TEXTO":"AMBOS";}
String alarmToneLabel(){return alarmTone==ALARM_SHORT?"CURTO":alarmTone==ALARM_DOUBLE?"DUPLO":alarmTone==ALARM_URGENT?"URGENTE":"CELULAR";}

void syncControllerConfig(){
  controller.power().setConfig(screenTimeoutSec,(uint8_t)powerMode);
  controller.alarm().setConfig(alarmEnabled,alarmHour,alarmMinute,(JarvisAlarmTone)alarmTone);
}

void saveSettings(){
  prefs.putUChar("bright",brightnessLevel);prefs.putBool("vibrate",vibrationEnabled);prefs.putBool("wrist",wristWakeEnabled);
  prefs.putUShort("timeout",screenTimeoutSec);prefs.putUChar("power",(uint8_t)powerMode);
  prefs.putBool("alarmOn",alarmEnabled);prefs.putUChar("alarmH",alarmHour);prefs.putUChar("alarmM",alarmMinute);
  prefs.putUChar("voiceOut",(uint8_t)voiceOutput);prefs.putUChar("alarmTone",(uint8_t)alarmTone);
  syncControllerConfig();
}
void loadSettings(){
  prefs.begin("jarvis",false);brightnessLevel=prefs.getUChar("bright",150);vibrationEnabled=prefs.getBool("vibrate",true);
  wristWakeEnabled=prefs.getBool("wrist",true);screenTimeoutSec=prefs.getUShort("timeout",20);powerMode=(PowerMode)prefs.getUChar("power",POWER_ECO);
  // A skin antiga ANA-DIGI foi removida. O mostrador único é JARVIS AVIATION.
  alarmEnabled=prefs.getBool("alarmOn",false);alarmHour=prefs.getUChar("alarmH",7);alarmMinute=prefs.getUChar("alarmM",0);
  voiceOutput=(VoiceOutput)prefs.getUChar("voiceOut",VOICE_BOTH);alarmTone=(AlarmTone)prefs.getUChar("alarmTone",ALARM_DOUBLE);
  brightnessLevel=constrain(brightnessLevel,40,255);if(powerMode>POWER_ULTRA)powerMode=POWER_ECO;
  if(voiceOutput>VOICE_BOTH)voiceOutput=VOICE_BOTH;if(alarmTone>ALARM_PHONE)alarmTone=ALARM_DOUBLE;if(alarmHour>23)alarmHour=7;if(alarmMinute>59)alarmMinute=0;
}

void vibrateShort(){if(vibrationEnabled&&watch&&watch->motor)watch->motor->onec();}
void alarmVibrateHook(){vibrateShort();}

void alarmLocalSoundHook(JarvisAlarmTone tone,uint8_t pulse){
  uint16_t freq=1000;
  uint16_t duration=110;
  uint8_t volume=42;

  switch(tone){
    case JARVIS_ALARM_SHORT:
      freq=880;duration=120;volume=38;
      break;
    case JARVIS_ALARM_DOUBLE:
      freq=(pulse%2)?1280:980;duration=115;volume=42;
      break;
    case JARVIS_ALARM_URGENT:
      freq=(pulse%3==2)?1750:1450;duration=125;volume=50;
      break;
    case JARVIS_ALARM_PHONE:
      return;
  }
  jarvisAudioPlayTone(freq,duration,volume);
}

void previewAlarmTone(){
  if(alarmTone==ALARM_PHONE)return;
  alarmLocalSoundHook((JarvisAlarmTone)alarmTone,0);
}

void alarmPhoneHook(){jarvisBleSendCommand("alarm_sound:phone");}

void applyPowerMode(){if(!watch)return;uint8_t b=brightnessLevel;if(powerMode==POWER_ECO)b=min((int)b,140);if(powerMode==POWER_ULTRA)b=min((int)b,85);watch->setBrightness(b);}
void powerScreenOnHook(){
  if(!watch)return;
  screenAwake=true;
  touchActive=false;
  watch->displayWakeup();
  watch->openBL();
  applyPowerMode();
  drawScreen();
}
void powerScreenOffHook(){
  if(!watch)return;
  // Primeiro apaga apenas o backlight. Em ECO/ULTRA a maquina de energia
  // chama enterDeepSleep() logo depois, permitindo cancelar o sono caso
  // ocorra uma interacao nessa pequena janela.
  watch->closeBL();
  screenAwake=false;
  touchActive=false;
  touchWakeConsumed=false;
}
void powerDeepSleepHook(){enterDeepSleep();}

uint32_t secondsToNextBackgroundWake(){
  // CASA nao precisa ser consultado a cada minuto durante standby.
  // ECO: ate 5 min; ULTRA: ate 15 min. O proximo alarme sempre prevalece.
  uint32_t casaSec = powerMode==POWER_ULTRA ? 900U :
                     powerMode==POWER_ECO ? 300U : 60U;

  if(watch&&watch->rtc){
    RTC_Date n=watch->rtc->getDateTime();

    if(alarmEnabled){
      int nowSec=(int)n.hour*3600+(int)n.minute*60+(int)n.second;
      int alarmSec=(int)alarmHour*3600+(int)alarmMinute*60;
      int delta=alarmSec-nowSec;
      if(delta<=0)delta+=24*3600;
      if((uint32_t)delta<casaSec)casaSec=(uint32_t)delta;
    }

    // Evita wake imediato por arredondamento/horario exatamente coincidente.
    if(casaSec<5U)casaSec=5U;
  }
  return casaSec;
}

int jsonIntField(const String &json,const char *key,int fallback=-1){
  String needle="\""+String(key)+"\"";
  int p=json.indexOf(needle);
  if(p<0)return fallback;
  p=json.indexOf(':',p+needle.length());
  if(p<0)return fallback;
  p++;
  while(p<(int)json.length()&&(json[p]==' '||json[p]=='\t'))p++;
  bool neg=false;if(p<(int)json.length()&&json[p]=='-'){neg=true;p++;}
  long v=0;bool any=false;
  while(p<(int)json.length()&&json[p]>='0'&&json[p]<='9'){any=true;v=v*10+(json[p]-'0');p++;}
  if(!any)return fallback;
  return neg?-(int)v:(int)v;
}

String jsonStringField(const String &json,const char *key){
  String needle="\""+String(key)+"\"";
  int p=json.indexOf(needle);
  if(p<0)return String();
  p=json.indexOf(':',p+needle.length());
  if(p<0)return String();
  p++;
  while(p<(int)json.length()&&(json[p]==' '||json[p]=='\t'))p++;
  if(p>=(int)json.length()||json[p]!='\"')return String();
  p++;
  String out;
  bool esc=false;
  for(;p<(int)json.length();p++){
    char ch=json[p];
    if(esc){
      if(ch=='n'||ch=='r')out+=' ';
      else if(ch=='t')out+=' ';
      else out+=ch;
      esc=false;
      continue;
    }
    if(ch=='\\'){esc=true;continue;}
    if(ch=='\"')break;
    out+=ch;
  }
  return out;
}

String jsonEscapeValue(const String &value){
  String out;
  out.reserve(value.length()+8);
  for(size_t i=0;i<value.length();i++){
    char ch=value[i];
    if(ch=='\\')out+="\\\\";
    else if(ch=='"')out+="\\\"";
    else if(ch=='\n')out+="\\n";
    else if(ch=='\r')out+="\\r";
    else if(ch=='\t')out+="\\t";
    else if((uint8_t)ch>=0x20)out+=ch;
  }
  return out;
}

bool sendCasaHeartbeat(){
  if(!jarvisWifiIsConnected()||!jarvisWifiHasCasaCredentials())return false;
  String deviceId=jarvisWifiDeviceId();
  if(deviceId.isEmpty())return false;

  int batt=batteryPercent();
  String payload="{\"device_id\":\""+jsonEscapeValue(deviceId)+
    "\",\"transport\":\"wifi\",\"health\":\"ok\""+
    ",\"protocol_version\":\"watch-1.0\""+
    ",\"manufacturer\":\"LILYGO\""+
    ",\"model\":\"JARVIS Watch\""+
    ",\"rssi\":"+String(jarvisWifiRssi())+
    ",\"uptime_sec\":"+String(millis()/1000UL);
  if(batt>=0)payload+=",\"battery\":"+String(batt);
  payload+=",\"data\":{\"wifi_ssid\":\""+jsonEscapeValue(jarvisWifiSsid())+
    "\",\"power_mode\":\""+jsonEscapeValue(powerLabel())+"\"}}";

  return jarvisWifiPostJson("/api/v1/device.php?acao=heartbeat",payload,nullptr);
}

bool sendCasaTelemetry(){
  if(!jarvisWifiIsConnected()||!jarvisWifiHasCasaCredentials())return false;

  int batt=batteryPercent();
  String payload="{";
  if(batt>=0)payload+="\"battery\":"+String(batt)+",";
  payload+="\"steps\":"+String(steps)+
    ",\"rssi_wifi\":"+String(jarvisWifiRssi())+
    ",\"wifi_ssid\":\""+jsonEscapeValue(jarvisWifiSsid())+
    "\",\"transport\":\"wifi\""+
    ",\"power_mode\":\""+jsonEscapeValue(powerLabel())+
    "\",\"alert_active\":"+(controller.alarm().ringing()?String("true"):String("false"))+
    ",\"data\":{\"device_id\":\""+jsonEscapeValue(jarvisWifiDeviceId())+
    "\",\"source\":\"watch-direct\"}}";

  return jarvisWifiPostJson("/api/v1/watch.php?acao=telemetry",payload,nullptr);
}

void serviceCasaUplink(){
  if(!jarvisWifiIsConnected()||!jarvisWifiHasCasaCredentials())return;
  unsigned long now=millis();

  if(lastCasaHeartbeat==0||now-lastCasaHeartbeat>=CASA_HEARTBEAT_INTERVAL_MS){
    if(sendCasaHeartbeat())lastCasaHeartbeat=now;
  }

  if(lastCasaTelemetry==0||now-lastCasaTelemetry>=CASA_TELEMETRY_INTERVAL_MS){
    if(sendCasaTelemetry())lastCasaTelemetry=now;
  }
}

bool fetchBackgroundCasaUpdate(){
  if(!jarvisWifiHasCasaCredentials())return false;
  if(!jarvisWifiConnectPreferred(3500))return false;

  // Primeiro verifica o Command Bus usado pelo JARVIS Mobile.
  String deviceId=jarvisWifiDeviceId();
  if(!deviceId.isEmpty()){
    String status;
    String path="/api/v1/device.php?acao=status&device_id="+deviceId;
    if(jarvisWifiGetJson(path,&status)){
      int pending=jsonIntField(status,"commands_pending",0);
      if(pending>0)return true;
    }
  }

  // Compatibilidade com notificacoes diretas da API Watch.
  String response;
  if(!jarvisWifiGetJson("/api/v1/watch.php?acao=notificacoes",&response))return false;

  int arrayPos=response.indexOf("\"notificacoes\"");
  if(arrayPos<0)return false;
  int open=response.indexOf('[',arrayPos);
  if(open<0)return false;
  int first=response.indexOf('{',open);
  int close=response.indexOf(']',open);
  if(first<0||close<0||first>close)return false;

  int id=jsonIntField(response,"id",-1);
  notificationTitle=jsonStringField(response,"titulo");
  notificationText=jsonStringField(response,"mensagem");
  if(notificationTitle.isEmpty())notificationTitle="CASA";
  if(notificationText.isEmpty())notificationText="Nova atualizacao recebida.";

  if(id>0){
    String ack="{\"id\":"+String(id)+"}";
    jarvisWifiPostJson("/api/v1/watch.php?acao=ack",ack,nullptr);
  }
  return true;
}

bool alarmDueNow(){
  if(!alarmEnabled||!watch||!watch->rtc)return false;
  RTC_Date n=watch->rtc->getDateTime();
  return n.hour==alarmHour&&n.minute==alarmMinute;
}

void enterDeepSleep(){
  if(!watch)return;

  // Não dorme enquanto o touch ainda estiver assertado. O FT6336 usa IRQ
  // ativo em nivel baixo e o GPIO38 será fonte de wake.
  pinMode(JARVIS_TOUCH_INT_PIN,INPUT);
  if(digitalRead(JARVIS_TOUCH_INT_PIN)==LOW)return;

  if(watch->power){
    watch->power->clearIRQ();
    delay(8);
    if(digitalRead(JARVIS_POWER_INT_PIN)==LOW)return;
  }

  jarvisIrShutdown();
  jarvisAudioShutdown();

  // O microfone PDM usa I2S0 apenas sob demanda. Garante que DMA/clock do I2S
  // nao permaneçam ligados quando o Watch entra em standby.
  if(voiceReady){
    i2s_driver_uninstall(I2S_NUM_0);
    voiceReady=false;
    voiceCapturing=false;
  }

  // O BMA423 nao e fonte de wake no deep sleep atual; desliga o acelerometro.
  if(watch->bma)watch->bma->disableAccel();

  // ADCs do AXP202 so sao necessarios enquanto o Watch esta acordado.
  if(watch->power){
    watch->power->adc1Enable(
      AXP202_VBUS_VOL_ADC1|AXP202_VBUS_CUR_ADC1|
      AXP202_BATT_CUR_ADC1|AXP202_BATT_VOL_ADC1,false
    );
  }

  jarvisWifiPrepareSleep();

  watch->displaySleep();   // FT6336 fica em monitor mode, nao em deep sleep.
  watch->closeBL();

  // Wake 1: botao/AXP202, ativo em LOW.
  esp_sleep_enable_ext0_wakeup((gpio_num_t)JARVIS_POWER_INT_PIN,0);

  // Wake 2: touch FT6336 GPIO38. Em ESP32 classico EXT1 permite ALL_LOW;
  // como a mascara contem somente o touch, LOW no GPIO38 acorda o chip.
  esp_sleep_enable_ext1_wakeup(
    (1ULL<<JARVIS_TOUCH_INT_PIN),
    ESP_EXT1_WAKEUP_ALL_LOW
  );

  // Wake 3: sincronizacao CASA aproximadamente a cada minuto.
  uint64_t wakeUs=(uint64_t)secondsToNextBackgroundWake()*1000000ULL;
  esp_sleep_enable_timer_wakeup(wakeUs);

  // O wake por movimento BMA423 usa nivel HIGH e conflita com o modo LOW
  // necessario ao touch no EXT1. Durante deep sleep priorizamos exatamente
  // as fontes solicitadas: touch, botao e timer.
  esp_deep_sleep_start();
}

void initMotion(){
  if(!watch||!watch->bma)return;Acfg cfg;cfg.odr=BMA4_OUTPUT_DATA_RATE_25HZ;cfg.range=BMA4_ACCEL_RANGE_2G;cfg.bandwidth=BMA4_ACCEL_NORMAL_AVG4;cfg.perf_mode=BMA4_CONTINUOUS_MODE;
  watch->bma->accelConfig(cfg);watch->bma->enableAccel();watch->bma->enableFeature(BMA423_STEP_CNTR,true);
  if(wristWakeEnabled){watch->bma->enableFeature(BMA423_TILT,true);watch->bma->enableFeature(BMA423_WAKEUP,true);}
}

bool initMicrophone(){
  if(voiceReady)return true;i2s_config_t cfg={};cfg.mode=(i2s_mode_t)(I2S_MODE_MASTER|I2S_MODE_RX|I2S_MODE_PDM);cfg.sample_rate=16000;
  cfg.bits_per_sample=I2S_BITS_PER_SAMPLE_16BIT;cfg.channel_format=I2S_CHANNEL_FMT_ONLY_LEFT;cfg.communication_format=I2S_COMM_FORMAT_I2S;cfg.intr_alloc_flags=ESP_INTR_FLAG_LEVEL1;cfg.dma_buf_count=4;cfg.dma_buf_len=128;cfg.use_apll=false;
  esp_err_t e=i2s_driver_install(I2S_NUM_0,&cfg,0,nullptr);if(e!=ESP_OK)return false;
  i2s_pin_config_t pins={};pins.bck_io_num=I2S_PIN_NO_CHANGE;pins.ws_io_num=JARVIS_PDM_CLK_PIN;pins.data_out_num=I2S_PIN_NO_CHANGE;pins.data_in_num=JARVIS_PDM_DATA_PIN;
  e=i2s_set_pin(I2S_NUM_0,&pins);if(e!=ESP_OK){i2s_driver_uninstall(I2S_NUM_0);return false;}voiceReady=true;return true;
}
void beginVoiceCapture(){
  if(voiceCapturing)return;
  if(!initMicrophone()){voiceText="MICROFONE INDISP.";lastMessage="Falha PDM";controller.setVoiceState(JARVIS_VOICE_ERROR);drawScreen();return;}
  controller.emit(jarvisEvent(EVT_VOICE_START,JARVIS_PRI_NORMAL));
  voiceText="OUVINDO...";lastMessage="Fale agora";voiceSum=0;voiceSamples=0;voiceCaptureUntil=millis()+1800;voiceCapturing=true;vibrateShort();drawScreen();
}
void processVoiceCapture(){
  if(!voiceCapturing)return;int16_t buf[128];size_t got=0;
  esp_err_t e=i2s_read(I2S_NUM_0,buf,sizeof(buf),&got,0);if(e==ESP_OK&&got){int n=got/2;for(int i=0;i<n;i++){voiceSum+=abs((int)buf[i]);voiceSamples++;}}
  if((long)(voiceCaptureUntil-millis())>0)return;voiceCapturing=false;int level=voiceSamples?(int)(voiceSum/voiceSamples):0;
  if(level<60){
    voiceText="NAO OUVI";lastMessage="Fale mais perto";
    controller.emit(jarvisEvent(EVT_VOICE_STOP));
    i2s_driver_uninstall(I2S_NUM_0);voiceReady=false;
    drawScreen();return;
  }
  voiceText="VOZ DETECTADA";String cmd="voice_capture:"+voiceOutputLabel();
  if(jarvisBleIsConnected()&&jarvisBleSendCommand(cmd)){lastMessage="Enviado ao celular";controller.emit(jarvisEvent(EVT_VOICE_RESULT));}
  else{lastMessage="Voz captada; celular indisponivel";controller.setVoiceState(JARVIS_VOICE_ERROR);}
  i2s_driver_uninstall(I2S_NUM_0);voiceReady=false;
  drawScreen();
}

void lcarsButton(int x,int y,int w,int h,uint16_t c,const char*label,const char*sub=nullptr){if(!tft)return;tft->fillRoundRect(x,y,w,h,10,c);tft->fillRect(x+10,y+h-6,w-10,6,c);tft->setTextColor(C_TEXT,c);tft->drawCentreString(label,x+w/2,y+6,2);if(sub)tft->drawCentreString(sub,x+w/2,y+24,1);}
void drawBatteryIcon(int x,int y,int p){uint16_t c=p>=20?C_TEXT:C_RED;tft->drawRoundRect(x,y,24,10,3,c);tft->fillRect(x+24,y+3,3,4,c);if(p>=0)tft->fillRect(x+2,y+2,(20*p)/100,6,c);}

void drawHouseMini(int x,int y,uint16_t color){
  tft->drawLine(x,y+6,x+7,y,color);
  tft->drawLine(x+7,y,x+14,y+6,color);
  tft->drawRect(x+2,y+6,11,8,color);
  tft->drawRect(x+7,y+9,3,5,color);
}

void drawWifiMini(int x,int y,uint16_t color){
  // Icone vetorial pequeno, sem bitmap/PROGMEM.
  tft->drawLine(x,y+4,x+7,y,color);
  tft->drawLine(x+7,y,x+14,y+4,color);
  tft->drawLine(x+3,y+8,x+7,y+5,color);
  tft->drawLine(x+7,y+5,x+11,y+8,color);
  tft->fillCircle(x+7,y+12,2,color);
}

void drawConnectionIcons(int y,uint16_t houseColor,uint16_t wifiColor){
  if(jarvisWifiCasaOnline())drawHouseMini(8,y,houseColor);
  if(jarvisWifiIsConnected())drawWifiMini(218,y,wifiColor);
}

void handFromAngle(int cx,int cy,int r,float deg,uint16_t color,int width=1){float a=(deg-90.0f)*0.0174532925f;int x=cx+(int)(cos(a)*r),y=cy+(int)(sin(a)*r);for(int i=0;i<width;i++)tft->drawLine(cx+i,cy,x+i,y,color);}

void drawSkinJarvisAviation(){
  RTC_Date n=watch->rtc->getDateTime();
  tft->fillScreen(C_BLACK);

  // Mostrador único: reúne a leitura clássica do relógio JARVIS com os
  // elementos técnicos do antigo JARVIS AVIATION.
  tft->drawCircle(120,113,106,C_STEEL);
  tft->drawCircle(120,113,100,C_WHITE);
  for(int i=0;i<60;i++){
    float a=i*6.0f*0.0174532925f;
    int r1=(i%5==0)?82:88;
    tft->drawLine(120+cos(a)*r1,113+sin(a)*r1,
                  120+cos(a)*94,113+sin(a)*94,
                  (i%5==0)?C_WHITE:C_STEEL);
  }

  tft->setTextColor(C_WHITE,C_BLACK);
  tft->drawCentreString("JARVIS AVIATION",120,24,1);

  // Hora digital discreta, requisito herdado do Aviation.
  tft->fillRoundRect(77,49,86,23,3,C_DARK);
  tft->setTextColor(C_GOLD,C_DARK);
  tft->drawCentreString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),120,53,2);

  // Ponteiros analógicos, requisito principal do relógio JARVIS.
  handFromAngle(120,113,48,(n.hour%12)*30+n.minute*.5f,C_WHITE,3);
  handFromAngle(120,113,72,n.minute*6,C_WHITE,2);
  handFromAngle(120,113,78,n.second*6,C_RED);
  tft->fillCircle(120,113,4,C_WHITE);

  // Data curta sem criar um terceiro módulo gráfico.
  tft->setTextColor(C_WHITE,C_BLACK);
  tft->drawCentreString((twoDigits(n.day)+"/"+twoDigits(n.month)).c_str(),120,190,1);
  tft->drawCentreString("apps ^",120,222,1);
  drawConnectionIcons(7,C_GREEN,C_BLUE);
}

void drawWatchFace(){drawSkinJarvisAviation();}

void drawHeader(const char*title){RTC_Date n=watch->rtc->getDateTime();int batt=batteryPercent();tft->fillRect(0,0,240,57,C_BG);tft->fillRoundRect(5,5,230,43,14,C_ORANGE);tft->setTextColor(C_TEXT,C_ORANGE);tft->drawString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),49,7,4);tft->drawRightString(title,210,9,2);drawBatteryIcon(177,34,batt);tft->drawRightString(batt>=0?(String(batt)+"%").c_str():"--",228,34,1);drawConnectionIcons(8,C_TEXT,C_TEXT);}
void drawFooter(){tft->fillRect(0,207,240,33,C_BG);if(currentScreen!=SCREEN_HOME){lcarsButton(5,211,70,25,C_LAV,"VOLTAR");lcarsButton(80,211,70,25,C_ORANGE,"HOME");}uint16_t c=jarvisBleIsConnected()?C_GREEN:(jarvisWifiIsConnected()?C_BLUE:C_GOLD);tft->fillRoundRect(155,211,80,25,8,c);tft->setTextColor(C_TEXT,c);tft->drawCentreString(jarvisBleIsConnected()?"PHONE":jarvisWifiIsConnected()?"WIFI":"OFFLINE",195,218,1);}
void drawMessage(){tft->fillRoundRect(5,181,230,22,8,C_WHITE);tft->drawRoundRect(5,181,230,22,8,C_LAV);tft->setTextColor(C_TEXT,C_WHITE);String m=lastMessage;if(m.length()>34)m=m.substring(0,31)+"...";tft->drawCentreString(m,120,187,1);}

void drawApps(){drawHeader("APPS");lcarsButton(5,62,52,48,C_ORANGE,"VOZ");lcarsButton(62,62,52,48,C_SALMON,"ALM");lcarsButton(119,62,52,48,C_BLUE,"CAM");lcarsButton(176,62,59,48,C_GREEN,"GPS");lcarsButton(5,117,52,48,C_LAV,"CASA");lcarsButton(62,117,52,48,C_GOLD,"PASSOS");lcarsButton(119,117,52,48,C_BLUE,"STATUS");lcarsButton(176,117,59,48,C_SALMON,"CONFIG");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString("baixo: relogio",120,184,1);drawFooter();}
void drawVoice(){drawHeader("VOZ IA");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString(voiceText.c_str(),120,76,2);lcarsButton(35,108,170,34,C_ORANGE,voiceCapturing?"OUVINDO...":"MICROFONE");lcarsButton(35,149,170,28,C_BLUE,("SAIDA: "+voiceOutputLabel()).c_str());drawMessage();drawFooter();}
void drawAlarm(){drawHeader("ALARME");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString((twoDigits(alarmHour)+":"+twoDigits(alarmMinute)).c_str(),120,64,4);lcarsButton(12,101,48,32,C_LAV,"H-");lcarsButton(66,101,48,32,C_ORANGE,"H+");lcarsButton(126,101,48,32,C_BLUE,"M-");lcarsButton(180,101,48,32,C_SALMON,"M+");lcarsButton(25,139,190,28,C_GOLD,("TOQUE: "+alarmToneLabel()).c_str());lcarsButton(35,173,170,28,controller.alarm().ringing()?C_RED:(alarmEnabled?C_GREEN:C_RED),controller.alarm().ringing()?"PARAR ALARME":(alarmEnabled?"LIGADO":"DESLIGADO"));drawFooter();}
void drawCamera(){drawHeader("CAMERA / VIDEO");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString("Midia pelo celular/site",120,63,1);lcarsButton(30,88,180,30,C_ORANGE,"FOTOGRAFAR");lcarsButton(30,124,180,30,C_BLUE,"VIDEO CELULAR");lcarsButton(30,160,180,30,C_LAV,"VIDEO SITE");drawFooter();}
void drawGps(){drawHeader("GPS");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString(gpsText.c_str(),120,82,2);lcarsButton(55,128,130,30,C_BLUE,"ATUALIZAR");drawFooter();}
void drawControls(){drawHeader("CASA");lcarsButton(5,62,111,51,C_SALMON,"LUZ SALA");lcarsButton(124,62,111,51,C_ORANGE,"LUZ QUARTO");lcarsButton(5,119,111,51,C_BLUE,"PORTAO");lcarsButton(124,119,111,51,C_LAV,"CENA NOITE");drawMessage();drawFooter();}
void drawHealth(){drawHeader("ATIVIDADE");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString("PASSOS",120,75,2);tft->drawCentreString(String(steps).c_str(),120,103,4);int pct=min(100,(int)(steps*100UL/6000UL));tft->drawRoundRect(27,151,184,14,6,C_TEXT);tft->fillRoundRect(29,153,(180*pct)/100,10,5,C_GREEN);drawFooter();}
void drawStatus(){drawHeader("STATUS");int b=batteryPercent();tft->setTextColor(C_TEXT,C_BG);tft->drawString("BATERIA",20,70,2);tft->drawRightString(b>=0?(String(b)+"%").c_str():"--",220,70,2);tft->drawString("BLE",20,96,2);tft->drawRightString(jarvisBleIsConnected()?"OK":"OFF",220,96,2);tft->drawString("WIFI",20,122,2);tft->drawRightString(jarvisWifiIsConnected()?jarvisWifiSsid().c_str():"OFF",220,122,2);tft->drawString("ENERGIA",20,148,2);tft->drawRightString(powerLabel().c_str(),220,148,2);tft->drawString("IR",20,174,1);tft->drawRightString(jarvisIrIsReady()?(jarvisIrIsBusy()?"TX":"OK"):"OFF",92,174,1);tft->drawString("EVT",118,174,1);tft->drawRightString(String(controller.droppedEvents()).c_str(),220,174,1);drawFooter();}
String timeoutLabel(){return screenTimeoutSec==0?"NUNCA":String(screenTimeoutSec)+"s";}
void drawSettings(){drawHeader("CONFIG");tft->setTextColor(C_TEXT,C_BG);tft->drawString("BRILHO",8,64,2);lcarsButton(110,60,58,30,C_LAV,"-");lcarsButton(174,60,61,30,C_ORANGE,"+");tft->drawString("ENERGIA",8,98,2);lcarsButton(124,94,111,30,C_GREEN,powerLabel().c_str());tft->drawString("TELA",8,132,2);lcarsButton(124,128,111,30,C_BLUE,timeoutLabel().c_str());lcarsButton(5,164,72,36,C_LAV,"WIFI");lcarsButton(82,164,72,36,C_GOLD,vibrationEnabled?"VIB ON":"VIB OFF");lcarsButton(159,164,76,36,C_SALMON,"RELOGIO");drawFooter();}
void drawClock(){drawHeader("RELOGIO");RTC_Date n=watch->rtc->getDateTime();tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),120,65,4);tft->drawCentreString((twoDigits(n.day)+"/"+twoDigits(n.month)+"/"+String(n.year)).c_str(),120,95,2);lcarsButton(5,121,52,38,C_LAV,"H-");lcarsButton(62,121,52,38,C_ORANGE,"H+");lcarsButton(124,121,52,38,C_BLUE,"M-");lcarsButton(181,121,54,38,C_SALMON,"M+");drawFooter();}

void drawWifi(){
  drawHeader("WIFI");
  tft->setTextColor(C_TEXT,C_BG);
  JarvisNetworkState ns=controller.network().state();

  if(ns==JARVIS_NET_SCANNING){
    tft->drawCentreString("BUSCANDO REDES...",120,77,2);
    tft->drawCentreString("aguarde",120,101,1);
  }
  else if(ns==JARVIS_NET_CONNECTING){
    tft->drawCentreString("CONECTANDO...",120,77,2);
    tft->drawCentreString(selectedWifi.substring(0,22).c_str(),120,103,1);
    tft->drawCentreString("testando senha e rede",120,126,1);
  }
  else if(ns==JARVIS_NET_SELECTING && wifiNetworkCount>0){
    int start=wifiPage*4;
    for(int row=0;row<4;row++){
      int i=start+row;if(i>=wifiNetworkCount)break;
      int y=60+row*34;
      uint16_t c=wifiNetworks[i].known?C_GREEN:C_LAV;
      tft->fillRoundRect(7,y,226,29,7,c);
      tft->setTextColor(C_TEXT,c);
      String label=wifiNetworks[i].ssid;
      if(label.length()>18)label=label.substring(0,18);
      tft->drawString(label.c_str(),14,y+6,1);
      tft->drawRightString((String(wifiNetworks[i].rssi)+" dBm").c_str(),225,y+6,1);
    }
  }
  else if(jarvisWifiIsConnected()){
    tft->fillRoundRect(14,66,212,54,12,C_GREEN);
    tft->setTextColor(C_TEXT,C_GREEN);
    tft->drawCentreString("WIFI CONECTADO",120,75,2);
    tft->drawCentreString(jarvisWifiSsid().substring(0,22).c_str(),120,99,1);

    tft->setTextColor(C_TEXT,C_BG);
    if(!jarvisWifiCasaChecked())
      tft->drawCentreString("CASA: VERIFICANDO...",120,132,1);
    else if(jarvisWifiCasaOnline())
      tft->drawCentreString("CASA: CONECTADO",120,132,2);
    else
      tft->drawCentreString("CASA: SEM RESPOSTA",120,132,1);

    lcarsButton(45,158,150,36,C_BLUE,"BUSCAR");
  }
  else{
    tft->drawCentreString("WIFI DESCONECTADO",120,75,2);
    if(lastMessage.length())tft->drawCentreString(lastMessage.substring(0,30).c_str(),120,101,1);
    lcarsButton(45,122,150,36,C_BLUE,"BUSCAR");
  }

  drawFooter();
}

void drawKeyboard(){
  drawHeader("SENHA WIFI");
  tft->setTextColor(C_TEXT,C_BG);

  String shownPassword=wifiPassword;
  if(shownPassword.length()>18)shownPassword=shownPassword.substring(shownPassword.length()-18);

  String ssid=selectedWifi;
  if(ssid.length()>18)ssid=ssid.substring(0,18);
  tft->drawString(ssid.c_str(),7,59,1);
  tft->drawRightString(shownPassword.c_str(),233,59,1);

  // Teclado Wi-Fi simplificado: botoes grandes e somente numeros.
  // Linha inferior: apagar, 0 e confirmar, usando apenas simbolos.
  const int xs[3]={6,85,164};
  const int ys[4]={78,113,148,183};
  const char *digits[3][3]={{"1","2","3"},{"4","5","6"},{"7","8","9"}};

  for(int row=0;row<3;row++){
    for(int col=0;col<3;col++){
      tft->fillRoundRect(xs[col],ys[row],70,31,7,C_WHITE);
      tft->setTextColor(C_TEXT,C_WHITE);
      tft->drawCentreString(digits[row][col],xs[col]+35,ys[row]+5,4);
    }
  }

  // Backspace
  tft->fillRoundRect(xs[0],ys[3],70,31,7,C_SALMON);
  tft->setTextColor(C_TEXT,C_SALMON);
  tft->drawCentreString("<",xs[0]+35,ys[3]+5,4);

  // Zero
  tft->fillRoundRect(xs[1],ys[3],70,31,7,C_WHITE);
  tft->setTextColor(C_TEXT,C_WHITE);
  tft->drawCentreString("0",xs[1]+35,ys[3]+5,4);

  // Confirmar
  tft->fillRoundRect(xs[2],ys[3],70,31,7,C_GREEN);
  tft->setTextColor(C_TEXT,C_GREEN);
  tft->drawCentreString(">",xs[2]+35,ys[3]+5,4);
}

void drawWrappedText(const String &value,int x,int y,int maxChars,int maxLines){
  String text=value;
  text.replace("\n"," ");
  int line=0;
  while(!text.isEmpty() && line<maxLines){
    int cut=min(maxChars,(int)text.length());
    if(cut<(int)text.length()){
      int space=text.lastIndexOf(' ',cut);
      if(space>3)cut=space;
    }
    String part=text.substring(0,cut);
    part.trim();
    tft->drawString(part.c_str(),x,y+line*21,2);
    text=text.substring(cut);
    text.trim();
    line++;
  }
  if(!text.isEmpty() && line>0)tft->drawString("...",x,y+(line-1)*21,2);
}

void drawNotificationScreen(){
  bool incoming=pendingCallId>0 && controller.callState()==JARVIS_CALL_RINGING;
  drawHeader(incoming?"CHAMADA":"MENSAGEM");
  tft->setTextColor(C_TEXT,C_BG);
  String title=notificationTitle;
  if(title.length()>28)title=title.substring(0,28);
  tft->drawString(title.c_str(),8,62,2);
  drawWrappedText(notificationText,8,88,27,incoming?2:4);
  if(incoming){
    lcarsButton(8,145,108,38,C_GREEN,"ATENDER");
    lcarsButton(124,145,108,38,C_RED,"RECUSAR");
  }else{
    tft->drawCentreString("toque para fechar",120,190,1);
  }
  drawFooter();
}

void drawScreen(){if(!screenAwake||!tft)return;if(currentScreen!=SCREEN_HOME)tft->fillScreen(C_BG);switch(currentScreen){case SCREEN_HOME:drawWatchFace();break;case SCREEN_APPS:drawApps();break;case SCREEN_VOICE:drawVoice();break;case SCREEN_ALARM:drawAlarm();break;case SCREEN_CAMERA:drawCamera();break;case SCREEN_GPS:drawGps();break;case SCREEN_CONTROLS:drawControls();break;case SCREEN_HEALTH:drawHealth();break;case SCREEN_STATUS:drawStatus();break;case SCREEN_SETTINGS:drawSettings();break;case SCREEN_CLOCK:drawClock();break;case SCREEN_WIFI:drawWifi();break;case SCREEN_KEYBOARD:drawKeyboard();break;case SCREEN_NOTIFICATION:drawNotificationScreen();break;}}
void navigate(ScreenId s){previousScreen=currentScreen;currentScreen=s;lastMessage="";drawScreen();}
void goHome(){previousScreen=currentScreen;currentScreen=SCREEN_HOME;drawScreen();}
void sendCommand(const String&cmd){
  if(jarvisBleIsConnected()&&jarvisBleSendCommand(cmd)){
    lastMessage="Enviado ao celular";
  }else if(jarvisWifiIsConnected()&&jarvisWifiHasCasaCredentials()){
    String payload="{\"comando\":\""+jsonEscapeValue(cmd)+
      "\",\"origem\":\"LILYGO_WATCH\",\"ia_mode\":\"auto\"}";
    lastMessage=jarvisWifiPostJson("/api/v1/comando",payload,nullptr)
      ?"Enviado ao site"
      :"Falha ao chamar site";
  }else{
    lastMessage="Sem celular/site";
  }
  drawScreen();
}

void triggerCamera(){if(jarvisBleIsConnected()&&jarvisBleSendCommand("camera_capture"))cameraText="FOTO SOLICITADA";else cameraText="SEM CELULAR";drawScreen();}
void startVideoCall(const String &targetPlatform){
  controller.emit(jarvisEvent(EVT_CALL_START,JARVIS_PRI_HIGH));
  String target=targetPlatform=="web"?"web":"mobile";
  bool ok=false;

  if(jarvisBleIsConnected()){
    String payload="{\"type\":\"family_call\",\"mode\":\"video\",\"target_platform\":\""+target+"\"}";
    ok=jarvisBleSendJson(payload);
  }

  if(!ok&&jarvisWifiIsConnected()&&jarvisWifiHasCasaCredentials()){
    String payload="{\"mode\":\"video\",\"origin\":\"watch\",\"target_platform\":\""+target+"\"}";
    ok=jarvisWifiPostJson("/api/v1/watch.php?acao=call_start",payload,nullptr);
  }

  lastMessage=ok
    ? (target=="web"?"Chamando usuario do site":"Chamando celular")
    : "Sem conexao para chamada";
  controller.setCallState(ok?JARVIS_CALL_DIALING:JARVIS_CALL_ERROR);
  drawScreen();
}
void requestGps(){if(jarvisBleIsConnected()&&jarvisBleSendCommand("gps_request"))gpsText="SOLICITANDO GPS...";else gpsText="CELULAR NECESSARIO";drawScreen();}
void adjustAlarm(int dh,int dm){int h=(int)alarmHour+dh,m=(int)alarmMinute+dm;while(h<0)h+=24;while(h>23)h-=24;while(m<0)m+=60;while(m>59)m-=60;alarmHour=h;alarmMinute=m;saveSettings();drawScreen();}
void adjustClock(int dh,int dm){RTC_Date n=watch->rtc->getDateTime();int h=(n.hour+dh+24)%24,m=(n.minute+dm+60)%60;watch->rtc->setDateTime(n.year,n.month,n.day,h,m,0);drawScreen();}

void syncWifiResults(){
  wifiNetworkCount=controller.network().networkCount();
  for(int i=0;i<wifiNetworkCount&&i<12;i++){const JarvisWifiNetwork *n=controller.network().networkAt(i);if(n)wifiNetworks[i]=*n;}
}
void startWifiScan(){wifiNetworkCount=0;wifiPage=0;wifiScanning=true;lastMessage="Buscando redes";controller.emit(jarvisEvent(EVT_WIFI_SCAN_REQUEST));drawScreen();}
void selectWifi(int index){if(index<0||index>=wifiNetworkCount)return;selectedWifi=wifiNetworks[index].ssid;wifiPassword="";navigate(SCREEN_KEYBOARD);}
void saveWifiFromKeyboard(){
  if(selectedWifi.isEmpty())return;
  if(!controller.network().saveAndConnect(selectedWifi,wifiPassword)){lastMessage=controller.network().lastError();drawScreen();return;}
  lastMessage="Conectando ao Wi-Fi";previousScreen=SCREEN_SETTINGS;currentScreen=SCREEN_WIFI;drawScreen();
}

void sendIncomingCallDecision(bool accept){
  if(pendingCallId<=0)return;
  String action=accept?"accept":"reject";
  String payload="{\"type\":\"family_call_control\",\"action\":\""+action+
    "\",\"call_id\":"+String(pendingCallId)+
    ",\"mode\":\""+jsonEscapeValue(pendingCallMode)+"\"}";
  bool sent=false;
  if(jarvisBleIsConnected())sent=jarvisBleSendJson(payload);
  if(!sent&&jarvisWifiIsConnected()&&jarvisWifiHasCasaCredentials()){
    String eventPayload="{\"device_id\":\""+jsonEscapeValue(jarvisWifiDeviceId())+
      "\",\"type\":\"family_call_control\",\"priority\":\"high\",\"data\":"+payload+"}";
    sent=jarvisWifiPostJson("/api/v1/device.php?acao=event",eventPayload,nullptr);
  }
  if(accept){
    controller.emit(jarvisEvent(EVT_CALL_ACCEPT,JARVIS_PRI_HIGH));
    lastMessage=sent?"Atendendo no celular":"Celular indisponivel";
  }else{
    controller.emit(jarvisEvent(EVT_CALL_END,JARVIS_PRI_HIGH));
    lastMessage=sent?"Chamada recusada":"Recusa nao enviada";
  }
  pendingCallId=0;
  pendingCallSender="";
  notificationTitle="";
  notificationText="";
  goHome();
}

void handleTap(int x,int y){
  controller.emit(jarvisEvent(EVT_TOUCH_TAP,JARVIS_PRI_NORMAL,x,y));vibrateShort();
  if(currentScreen==SCREEN_NOTIFICATION){
    if(pendingCallId>0&&controller.callState()==JARVIS_CALL_RINGING){
      if(y>=140&&y<=190){sendIncomingCallDecision(x<120);return;}
      return;
    }
    goHome();return;
  }
  if(currentScreen==SCREEN_HOME){navigate(SCREEN_APPS);return;}
  if(currentScreen!=SCREEN_HOME&&currentScreen!=SCREEN_KEYBOARD&&y>=207){if(x<78){ScreenId s=previousScreen;previousScreen=SCREEN_HOME;currentScreen=s;drawScreen();return;}if(x<154){goHome();return;}}
  if(currentScreen==SCREEN_APPS){if(y>=62&&y<=111){if(x<59)navigate(SCREEN_VOICE);else if(x<117)navigate(SCREEN_ALARM);else if(x<174)navigate(SCREEN_CAMERA);else navigate(SCREEN_GPS);return;}if(y>=117&&y<=167){if(x<59)navigate(SCREEN_CONTROLS);else if(x<117)navigate(SCREEN_HEALTH);else if(x<174)navigate(SCREEN_STATUS);else navigate(SCREEN_SETTINGS);return;}}
  else if(currentScreen==SCREEN_VOICE){if(y>=100&&y<145)beginVoiceCapture();else if(y>=145&&y<=184){voiceOutput=(VoiceOutput)(((int)voiceOutput+1)%3);saveSettings();drawScreen();}}
  else if(currentScreen==SCREEN_ALARM){
    if(y>=101&&y<=135){if(x<61)adjustAlarm(-1,0);else if(x<120)adjustAlarm(1,0);else if(x<177)adjustAlarm(0,-5);else adjustAlarm(0,5);}
    else if(y>=139&&y<=170){alarmTone=(AlarmTone)(((int)alarmTone+1)%4);saveSettings();previewAlarmTone();drawScreen();}
    else if(y>=171&&y<=205){if(controller.alarm().ringing()){controller.emit(jarvisEvent(EVT_ALARM_STOP,JARVIS_PRI_HIGH));lastMessage="Alarme confirmado";drawScreen();}else{alarmEnabled=!alarmEnabled;saveSettings();drawScreen();}}
  }
  else if(currentScreen==SCREEN_CAMERA){if(y>=84&&y<=121)triggerCamera();else if(y>=122&&y<=157)startVideoCall("mobile");else if(y>=158&&y<=195)startVideoCall("web");}
  else if(currentScreen==SCREEN_GPS){if(y>=120&&y<=170)requestGps();}
  else if(currentScreen==SCREEN_CONTROLS){if(y>=62&&y<=113)sendCommand(x<120?"Alterne a luz da sala":"Alterne a luz do quarto");else if(y>=119&&y<=170)sendCommand(x<120?"Acione o portao":"Ative a cena noite");}
  else if(currentScreen==SCREEN_SETTINGS){if(y>=60&&y<=91){if(x>=110&&x<171)brightnessLevel=max(40,(int)brightnessLevel-20);else if(x>=171)brightnessLevel=min(255,(int)brightnessLevel+20);applyPowerMode();saveSettings();drawScreen();}else if(y>=94&&y<=125&&x>=120){powerMode=(PowerMode)(((int)powerMode+1)%3);screenTimeoutSec=powerMode==POWER_NORMAL?30:powerMode==POWER_ECO?20:10;applyPowerMode();saveSettings();drawScreen();}else if(y>=128&&y<=159&&x>=120){screenTimeoutSec=screenTimeoutSec==10?20:screenTimeoutSec==20?30:screenTimeoutSec==30?60:screenTimeoutSec==60?0:10;saveSettings();drawScreen();}else if(y>=164&&y<=201){if(x<80){navigate(SCREEN_WIFI);startWifiScan();}else if(x<157){vibrationEnabled=!vibrationEnabled;saveSettings();drawScreen();}else navigate(SCREEN_CLOCK);}}
  else if(currentScreen==SCREEN_CLOCK){if(y>=121&&y<=160){if(x<58)adjustClock(-1,0);else if(x<120)adjustClock(1,0);else if(x<180)adjustClock(0,-1);else adjustClock(0,1);}}
  else if(currentScreen==SCREEN_WIFI){
    JarvisNetworkState ns=controller.network().state();
    if(ns==JARVIS_NET_SCANNING||ns==JARVIS_NET_CONNECTING){
      return;
    }else if(ns==JARVIS_NET_SELECTING&&wifiNetworkCount>0&&y>=60&&y<196){
      int row=(y-60)/34;
      selectWifi(wifiPage*4+row);
    }else if(jarvisWifiIsConnected()){
      if(y>=154&&y<=202)startWifiScan();
    }else if(y>=112&&y<=165){
      startWifiScan();
    }
  }
  else if(currentScreen==SCREEN_KEYBOARD){
    const int xs[3]={6,85,164};
    const int ys[4]={78,113,148,183};

    int col=-1,row=-1;
    for(int i=0;i<3;i++)if(x>=xs[i]&&x<xs[i]+70){col=i;break;}
    for(int i=0;i<4;i++)if(y>=ys[i]&&y<ys[i]+31){row=i;break;}

    if(col>=0&&row>=0){
      if(row<3){
        static const char keypad[3][3]={{'1','2','3'},{'4','5','6'},{'7','8','9'}};
        if(wifiPassword.length()<63)wifiPassword+=keypad[row][col];
        drawScreen();
      }else if(col==0){
        if(wifiPassword.length())wifiPassword.remove(wifiPassword.length()-1);
        drawScreen();
      }else if(col==1){
        if(wifiPassword.length()<63)wifiPassword+='0';
        drawScreen();
      }else{
        saveWifiFromKeyboard();
      }
    }
  }
}

void handleGestureRelease(){
  int dx=touchLastX-touchStartX,dy=touchLastY-touchStartY;unsigned long dt=millis()-touchStartMs;int adx=abs(dx),ady=abs(dy);
  if(adx>=SWIPE_MIN||ady>=SWIPE_MIN){
    JarvisEventType evt=adx>ady?(dx>0?EVT_SWIPE_RIGHT:EVT_SWIPE_LEFT):(dy>0?EVT_SWIPE_DOWN:EVT_SWIPE_UP);controller.emit(jarvisEvent(evt));
    if(adx>ady){
      // Nao ha mais troca de skin: o mostrador unico e JARVIS AVIATION.
      if(currentScreen==SCREEN_HOME)drawScreen();
    }
    else if(currentScreen==SCREEN_WIFI&&controller.network().state()!=JARVIS_NET_SCANNING&&wifiNetworkCount>4){int pages=(wifiNetworkCount+3)/4;if(dy<0&&wifiPage<pages-1)wifiPage++;if(dy>0&&wifiPage>0)wifiPage--;drawScreen();}
    else if(dy<0){if(currentScreen==SCREEN_HOME)navigate(SCREEN_APPS);else if(currentScreen==SCREEN_APPS)navigate(SCREEN_SETTINGS);}
    else{if(currentScreen==SCREEN_APPS||currentScreen==SCREEN_SETTINGS)goHome();else if(currentScreen!=SCREEN_HOME){ScreenId s=previousScreen;previousScreen=SCREEN_HOME;currentScreen=s;drawScreen();}}
    return;
  }
  if(dt<=TAP_MAX_MS)handleTap(touchLastX,touchLastY);
}

void processPowerButton(){
  if(!powerButtonIrq)return;
  powerButtonIrq=false;
  if(!watch||!watch->power)return;
  watch->power->clearIRQ();
  if(millis()-lastPowerButtonMs<300)return;
  lastPowerButtonMs=millis();
  // Wake/sleep do botao nao pode ficar atras de telemetria ou eventos de UI.
  // CRITICAL permite que o evento de energia substitua um evento menos importante
  // caso a fila esteja momentaneamente cheia.
  controller.emit(jarvisEvent(EVT_BUTTON_SHORT,JARVIS_PRI_CRITICAL));
}

void bleEventHandler(const String &type,const String &title,const String &text){
  if(type=="device_identity"){
    String id=text.length()?text:title;
    id.trim();
    if(!id.isEmpty())jarvisWifiSetDeviceId(id);
    lastMessage=id.isEmpty()?"Device ID invalido":"Device ID salvo";
    if(screenAwake)drawScreen();
    return;
  }

  if(type=="find_watch"){
    notificationTitle="LOCALIZAR";
    notificationText="Seu celular esta procurando este relogio.";
    previousScreen=currentScreen;currentScreen=SCREEN_NOTIFICATION;
    vibrateShort();vibrateShort();
    controller.emit(jarvisEvent(EVT_USER_INTERACTION,JARVIS_PRI_HIGH));
    if(screenAwake)drawScreen();
    return;
  }

  if(type=="incoming_call"){
    String meta=text;
    int p1=meta.indexOf('|');
    int p2=p1>=0?meta.indexOf('|',p1+1):-1;
    if(p1>0){
      pendingCallId=meta.substring(0,p1).toInt();
      pendingCallMode=p2>p1?meta.substring(p1+1,p2):"video";
      pendingCallSender=p2>p1?meta.substring(p2+1):title;
    }else{
      pendingCallId=0;
      pendingCallMode="video";
      pendingCallSender=title;
    }
    notificationTitle="CHAMADA: "+(pendingCallSender.isEmpty()?title:pendingCallSender);
    notificationText=pendingCallMode=="audio"?"Chamada de audio recebida":"Videochamada recebida";
    previousScreen=currentScreen;currentScreen=SCREEN_NOTIFICATION;
    controller.emit(jarvisEvent(EVT_CALL_INCOMING,JARVIS_PRI_HIGH));
    controller.emit(jarvisEvent(EVT_USER_INTERACTION,JARVIS_PRI_CRITICAL));
    vibrateShort();vibrateShort();
    if(screenAwake)drawScreen();
    return;
  }

  if(type=="phone_notification" || type=="family_message"){
    notificationTitle=title.isEmpty()?"MENSAGEM":title;
    notificationText=text;
    previousScreen=currentScreen;currentScreen=SCREEN_NOTIFICATION;
    controller.emit(jarvisEvent(EVT_USER_INTERACTION,JARVIS_PRI_HIGH));
    vibrateShort();
    if(screenAwake)drawScreen();
    return;
  }

  if(type=="voice_ready"){
    voiceText="CELULAR PRONTO";
    lastMessage="Toque na notificacao do celular";
    controller.setVoiceState(JARVIS_VOICE_WAITING);
    if(screenAwake&&currentScreen==SCREEN_VOICE)drawScreen();
    return;
  }

  if(type=="voice_result"){
    voiceText="VOZ NAO PROCESSADA";
    lastMessage=text.isEmpty()?"Reconhecimento cancelado":text;
    controller.setVoiceState(JARVIS_VOICE_ERROR);
    if(screenAwake&&currentScreen==SCREEN_VOICE)drawScreen();
    return;
  }

  if(type=="jarvis_result"){
    voiceText="RESPOSTA RECEBIDA";
    notificationTitle="JARVIS";
    notificationText=text;
    previousScreen=currentScreen;currentScreen=SCREEN_NOTIFICATION;
    controller.emit(jarvisEvent(EVT_VOICE_RESULT,JARVIS_PRI_HIGH));
    controller.emit(jarvisEvent(EVT_USER_INTERACTION,JARVIS_PRI_HIGH));
    if(screenAwake)drawScreen();
    return;
  }

  if(type=="alarm_sound_result"){
    lastMessage="Alarme enviado ao celular";
    if(screenAwake&&currentScreen==SCREEN_ALARM)drawScreen();
    return;
  }

  if(type=="gps_result"){
    gpsText=text.isEmpty()?"GPS RECEBIDO":text;
    if(screenAwake&&currentScreen==SCREEN_GPS)drawScreen();
    return;
  }

  if(type=="camera_result"){
    lastMessage=text.isEmpty()?"Camera concluida":text;
    if(screenAwake)drawScreen();
    return;
  }

  if(type=="family_call_control_result"){
    bool ok=text.endsWith("|true");
    String action=text.substring(0,text.indexOf('|'));
    if(ok){
      lastMessage=action=="accept"?"Chamada aceita":"Chamada recusada";
      controller.setCallState(action=="accept"?JARVIS_CALL_ACTIVE:JARVIS_CALL_IDLE);
    }else{
      lastMessage="Falha na chamada";
      controller.setCallState(JARVIS_CALL_ERROR);
    }
    if(screenAwake)drawScreen();
    return;
  }

  if(type=="phone_state"){
    lastMessage=jarvisBlePhoneInternet()?"Celular online":"Celular sem Internet";
    if(screenAwake&&currentScreen==SCREEN_STATUS)drawScreen();
  }
}

bool processCasaDeviceCommandOnce(){
  if(!jarvisWifiIsConnected()||!jarvisWifiHasCasaCredentials())return false;
  String deviceId=jarvisWifiDeviceId();
  if(deviceId.isEmpty())return false;

  String response;
  String path="/api/v1/device.php?acao=commands&device_id="+deviceId+"&limit=1";
  if(!jarvisWifiGetJson(path,&response))return false;

  int arrayPos=response.indexOf("\"commands\"");
  if(arrayPos<0)return false;
  int open=response.indexOf('[',arrayPos);
  int close=response.indexOf(']',open);
  int first=response.indexOf('{',open);
  if(open<0||close<0||first<0||first>close)return false;

  int id=jsonIntField(response,"id",-1);
  String command=jsonStringField(response,"comando");
  String payload=jsonStringField(response,"payload");
  if(id<=0||command.isEmpty())return false;

  bool ok=true;
  String eventType=jsonStringField(payload,"type");

  if(command=="find_watch"){
    bleEventHandler("find_watch","","");
  }else if(command=="notification"||eventType=="phone_notification"){
    bleEventHandler(
      "phone_notification",
      jsonStringField(payload,"title"),
      jsonStringField(payload,"text")
    );
  }else if(command=="incoming_call"||eventType=="incoming_call"){
    String sender=jsonStringField(payload,"sender");
    String mode=jsonStringField(payload,"mode");
    int callId=jsonIntField(payload,"call_id",0);
    String meta=String(callId)+"|"+(mode.isEmpty()?String("video"):mode)+"|"+sender;
    bleEventHandler("incoming_call",sender,meta);
  }else if(command=="family_message"||eventType=="family_message"){
    bleEventHandler(
      "family_message",
      jsonStringField(payload,"sender"),
      jsonStringField(payload,"message")
    );
  }else if(command=="family_call_result"||eventType=="family_call_result"){
    bool callOk=jsonStringField(payload,"ok")!="false";
    lastMessage=callOk?"Chamada criada":"Falha ao criar chamada";
    controller.setCallState(callOk?JARVIS_CALL_DIALING:JARVIS_CALL_ERROR);
  }else if(command=="jarvis_result"||eventType=="jarvis_result"){
    bleEventHandler("jarvis_result","JARVIS",jsonStringField(payload,"text"));
  }else if(command=="gps_result"||eventType=="gps_result"){
    bleEventHandler("gps_result","","GPS RECEBIDO");
  }else if(command=="camera_result"||eventType=="camera_result"){
    bleEventHandler("camera_result","",jsonStringField(payload,"path"));
  }else if(command=="phone_state"||eventType=="phone_state"){
    notificationTitle="CELULAR";
    notificationText="Estado do celular atualizado.";
    lastMessage="Celular atualizado";
  }else if(command=="ir_send"){
    String protocol=jsonStringField(payload,"protocol");
    long address=jsonIntField(payload,"address",0);
    int irCommand=jsonIntField(payload,"command",-1);
    int repeats=jsonIntField(payload,"repeats",0);
    if(irCommand<0){
      ok=false;
    }else if(protocol=="nec_extended"){
      ok=jarvisIrSendNecExtended((uint16_t)address,(uint8_t)irCommand,(uint8_t)constrain(repeats,0,10));
    }else{
      ok=jarvisIrSendNec((uint8_t)address,(uint8_t)irCommand,(uint8_t)constrain(repeats,0,10));
    }
    lastMessage=ok?"IR enviado":"Falha IR";
  }else{
    // Comando ainda nao possui tela dedicada; registra como recebido para nao
    // manter o Watch acordando indefinidamente pelo mesmo item.
    notificationTitle="CASA";
    notificationText="Comando recebido: "+command;
  }

  String result="{\"device_id\":\""+deviceId+
    "\",\"id\":"+String(id)+
    ",\"status\":\""+String(ok?"success":"error")+
    "\",\"result\":{\"watch\":\"processed\"}}";

  jarvisWifiPostJson(
    "/api/v1/device.php?acao=command_result&device_id="+deviceId,
    result,
    nullptr
  );

  return true;
}

void controllerEvent(const JarvisEvent &event){
  switch(event.type){
    case EVT_ALARM_TRIGGER:
      previousScreen=currentScreen;currentScreen=SCREEN_ALARM;lastMessage="ALARME";drawScreen();
      break;
    case EVT_ALARM_STOP:
      lastMessage="Alarme parado";drawScreen();
      break;
    case EVT_WIFI_SCAN_DONE:
      wifiScanning=false;syncWifiResults();lastMessage=wifiNetworkCount?"Redes encontradas":"Nenhuma rede encontrada";if(currentScreen==SCREEN_WIFI)drawScreen();
      break;
    case EVT_WIFI_CONNECTED:
      wifiScanning=false;
      selectedWifi=event.text;
      wifiPassword="";
      lastMessage="Wi-Fi conectado: "+event.text;
      jarvisWifiRequestCasaCheck();
      if(currentScreen==SCREEN_WIFI||currentScreen==SCREEN_STATUS||currentScreen==SCREEN_HOME)drawScreen();
      break;
    case EVT_WIFI_FAILED:
      wifiScanning=false;lastMessage=event.text.length()?event.text:"Falha de Wi-Fi";if(currentScreen==SCREEN_WIFI||currentScreen==SCREEN_STATUS)drawScreen();
      break;
    case EVT_INTERNAL_ERROR:
      lastMessage="Erro protegido: "+event.text;if(screenAwake)drawScreen();
      break;
    default:
      break;
  }
}

void emitRtcTick(){
  if(!watch||!watch->rtc)return;
  RTC_Date n=watch->rtc->getDateTime();
  uint32_t packed=((uint32_t)n.hour<<16)|((uint32_t)n.minute<<8)|(uint32_t)n.second;
  controller.emit(jarvisEvent(EVT_TICK_1S,JARVIS_PRI_LOW,n.day,(int32_t)packed));
}

void setup(){
  Serial.begin(115200);
  delay(80);
  bootMillis=millis();
  wakeCause=esp_sleep_get_wakeup_cause();

  Serial.println();
  Serial.println("========================================");
  Serial.println("JARVIS Watch boot");
  Serial.printf("Reset reason : %d\n", (int)esp_reset_reason());
  Serial.printf("Wake cause   : %d\n", (int)wakeCause);
  Serial.printf("Free heap    : %u bytes\n", (unsigned)ESP.getFreeHeap());
  Serial.println("========================================");
  backgroundTimerWake=(wakeCause==ESP_SLEEP_WAKEUP_TIMER);

  watch=TTGOClass::getWatch();
  if(!watch)return;
  watch->begin();
  watch->motor_begin();
  tft=watch->tft;
  if(watch->rtc)watch->rtc->check();

  // Em wake do timer, nunca acende a tela antes de saber se existe algo para
  // mostrar. Isso evita um flash de backlight a cada minuto.
  if(backgroundTimerWake){
    watch->closeBL();
    screenAwake=false;
  }else{
    watch->displayWakeup();
    watch->openBL();
    screenAwake=true;
  }

  if(watch->power){
    watch->power->adc1Enable(
      AXP202_VBUS_VOL_ADC1|AXP202_VBUS_CUR_ADC1|
      AXP202_BATT_CUR_ADC1|AXP202_BATT_VOL_ADC1,true
    );
    pinMode(JARVIS_POWER_INT_PIN,INPUT);
    attachInterrupt(JARVIS_POWER_INT_PIN,onPowerButtonIrq,FALLING);
    watch->power->enableIRQ(AXP202_PEK_SHORTPRESS_IRQ,true);
    watch->power->clearIRQ();
  }

  loadSettings();
  jarvisWifiBegin();

  bool wakeForAlarm=backgroundTimerWake&&alarmDueNow();
  bool wakeForCasa=false;

  if(backgroundTimerWake&&!wakeForAlarm){
    wakeForCasa=fetchBackgroundCasaUpdate();
    if(!wakeForCasa){
      // Nenhuma atualizacao: radio desliga e volta imediatamente ao deep sleep.
      enterDeepSleep();
      return;
    }
  }

  // A partir daqui e um wake interativo: boot normal, botao, touch, alarme
  // ou uma atualizacao encontrada no CASA.
  screenAwake=true;
  watch->displayWakeup();
  watch->openBL();
  applyPowerMode();

  initMotion();
  jarvisAudioBegin(watch);
  jarvisIrBegin();
  jarvisBleSetEventHandler(bleEventHandler);
  jarvisBleBegin();

  // Em wake normal tenta o ultimo Wi-Fi conhecido sem scan.
  if(!jarvisWifiIsConnected())jarvisWifiStartPreferred();

  JarvisPowerHooks powerHooks;
  powerHooks.screenOn=powerScreenOnHook;
  powerHooks.screenOff=powerScreenOffHook;
  powerHooks.deepSleep=powerDeepSleepHook;

  JarvisAlarmHooks alarmHooks;
  alarmHooks.vibrateOnce=alarmVibrateHook;
  alarmHooks.localSound=alarmLocalSoundHook;
  alarmHooks.phoneSound=alarmPhoneHook;

  JarvisControllerHooks controllerHooks;
  controllerHooks.onEvent=controllerEvent;

  controller.begin(powerHooks,alarmHooks,controllerHooks,true);
  syncControllerConfig();

  if(wakeCause==ESP_SLEEP_WAKEUP_EXT1){
    // O mesmo dedo que acordou o Watch nao deve abrir um app.
    touchWakeConsumed=true;
  }

  if(wakeForCasa){
    bool processed=processCasaDeviceCommandOnce();
    if(!processed){
      previousScreen=SCREEN_HOME;
      currentScreen=SCREEN_NOTIFICATION;
      lastMessage="Atualizacao CASA";
    }
    vibrateShort();
  }

  if(wakeForAlarm){
    emitRtcTick();
    controller.update(millis());
  }

  drawScreen();
}

void loop(){
  if(!watch){delay(50);return;}

  processPowerButton();
  jarvisAudioLoop();
  jarvisIrLoop();
  jarvisBleLoop();
  jarvisWifiLoop();
  serviceCasaUplink();

  if(jarvisWifiIsConnected()&&millis()-lastCasaCommandPoll>=5000UL){
    lastCasaCommandPoll=millis();
    processCasaDeviceCommandOnce();
  }

  {
    bool wifiNow=jarvisWifiIsConnected();
    bool casaNow=jarvisWifiCasaOnline();
    bool casaCheckedNow=jarvisWifiCasaChecked();
    if(wifiNow!=lastWifiUiState||casaNow!=lastCasaUiState||casaCheckedNow!=lastCasaCheckedUiState){
      lastWifiUiState=wifiNow;
      lastCasaUiState=casaNow;
      lastCasaCheckedUiState=casaCheckedNow;
      if(screenAwake)drawScreen();
    }
  }

  processVoiceCapture();

  if(watch->bma&&millis()-lastStepRefresh>2000){lastStepRefresh=millis();steps=watch->bma->getCounter();if(screenAwake&&currentScreen==SCREEN_HEALTH)drawScreen();}

  if(millis()-lastClockRefresh>=1000){
    lastClockRefresh=millis();emitRtcTick();
    if(screenAwake&&(currentScreen==SCREEN_HOME||currentScreen==SCREEN_STATUS||currentScreen==SCREEN_ALARM||currentScreen==SCREEN_VOICE))drawScreen();
  }

  // Enquanto o ESP32 esta acordado, o touch continua sendo consultado normalmente.
  // Em ECO/ULTRA a tela apagada evolui para deep sleep; nesse estado o GPIO38
  // (FT6336 INT) acorda o ESP32 por hardware.
  {
    int16_t x=0,y=0;
    bool touching=watch->getTouch(x,y);

    if(!screenAwake){
      touchActive=false;
      if(touching){
        if(!touchWakeConsumed){
          touchWakeConsumed=true;
          controller.emit(jarvisEvent(EVT_USER_INTERACTION,JARVIS_PRI_CRITICAL));
        }
      }else{
        touchWakeConsumed=false;
      }
    }else if(touchWakeConsumed){
      // A tela acabou de acordar por toque. Ignora esse mesmo contato para nao
      // abrir app/botao que estava sob o dedo. Libera a UI somente no release.
      touchActive=false;
      if(!touching)touchWakeConsumed=false;
    }else if(touching){
      controller.emit(jarvisEvent(EVT_USER_INTERACTION,JARVIS_PRI_LOW));
      if(!touchActive){
        touchActive=true;touchStartX=x;touchStartY=y;touchLastX=x;touchLastY=y;touchStartMs=millis();
      }else{
        touchLastX=x;touchLastY=y;
      }
    }else if(touchActive){
      touchActive=false;
      handleGestureRelease();
    }
  }

  syncControllerConfig();
  controller.update(millis());

  // Loop curto e previsível. Nenhuma máquina de estado pode usar delay longo/while de espera.
  delay(5);
}
