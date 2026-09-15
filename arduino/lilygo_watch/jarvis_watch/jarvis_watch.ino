#include "config.h"
#include "jarvis_ble.h"
#include "jarvis_wifi.h"
#include "jarvis_controller.h"
#include <Preferences.h>
#include <driver/i2s.h>
#include <esp_sleep.h>
#include <math.h>

TTGOClass *watch = nullptr;
TFT_eSPI *tft = nullptr;
Preferences prefs;
JarvisController controller;

enum ScreenId {
  SCREEN_HOME, SCREEN_APPS, SCREEN_VOICE, SCREEN_ALARM, SCREEN_CAMERA,
  SCREEN_GPS, SCREEN_CONTROLS, SCREEN_HEALTH, SCREEN_STATUS,
  SCREEN_SETTINGS, SCREEN_CLOCK, SCREEN_WIFI, SCREEN_KEYBOARD
};
enum PowerMode { POWER_NORMAL, POWER_ECO, POWER_ULTRA };
enum WatchSkin { SKIN_CLASSIC, SKIN_ANADIGI, SKIN_AVIATION, SKIN_COUNT };
enum VoiceOutput { VOICE_PHONE, VOICE_TEXT, VOICE_BOTH };
enum AlarmTone { ALARM_SHORT, ALARM_DOUBLE, ALARM_URGENT, ALARM_PHONE };

ScreenId currentScreen = SCREEN_HOME;
ScreenId previousScreen = SCREEN_HOME;
PowerMode powerMode = POWER_ECO;
WatchSkin watchSkin = SKIN_CLASSIC;
VoiceOutput voiceOutput = VOICE_BOTH;
AlarmTone alarmTone = ALARM_DOUBLE;

unsigned long bootMillis=0, lastClockRefresh=0, lastStepRefresh=0;
bool screenAwake=true, vibrationEnabled=true, wristWakeEnabled=true, voiceReady=false;
uint8_t brightnessLevel=150;
uint16_t screenTimeoutSec=20;
uint32_t steps=0;
String lastMessage="JARVIS pronto", voiceText="TOQUE PARA FALAR", gpsText="GPS PELO CELULAR", cameraText="CAMERA DO CELULAR";

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
int keyboardPage=0;
const char *keyboardPages[] = {
  "abcdefghijklmnopqrstuvwxyz._-@+*",
  "ABCDEFGHIJKLMNOPQRSTUVWXYZ._-@+*",
  "1234567890!#$%&()[]{}:;?/\\=+-_"
};

static const uint16_t C_BG=0xFFDF,C_TEXT=0x18C3,C_ORANGE=0xFBE0,C_SALMON=0xFB2C;
static const uint16_t C_LAV=0xB57F,C_BLUE=0x5D7F,C_GREEN=0x6E6B,C_RED=0xF9E7,C_GOLD=0xFE60,C_WHITE=0xFFFF;
static const uint16_t C_BLACK=0x0000,C_DARK=0x2104,C_STEEL=0x8410,C_LCD=0xB5A0;

void drawScreen();
void enterDeepSleep();
void controllerEvent(const JarvisEvent &event);

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
  prefs.putUShort("timeout",screenTimeoutSec);prefs.putUChar("power",(uint8_t)powerMode);prefs.putUChar("skin",(uint8_t)watchSkin);
  prefs.putBool("alarmOn",alarmEnabled);prefs.putUChar("alarmH",alarmHour);prefs.putUChar("alarmM",alarmMinute);
  prefs.putUChar("voiceOut",(uint8_t)voiceOutput);prefs.putUChar("alarmTone",(uint8_t)alarmTone);
  syncControllerConfig();
}
void loadSettings(){
  prefs.begin("jarvis",false);brightnessLevel=prefs.getUChar("bright",150);vibrationEnabled=prefs.getBool("vibrate",true);
  wristWakeEnabled=prefs.getBool("wrist",true);screenTimeoutSec=prefs.getUShort("timeout",20);powerMode=(PowerMode)prefs.getUChar("power",POWER_ECO);
  watchSkin=(WatchSkin)prefs.getUChar("skin",SKIN_CLASSIC);alarmEnabled=prefs.getBool("alarmOn",false);alarmHour=prefs.getUChar("alarmH",7);alarmMinute=prefs.getUChar("alarmM",0);
  voiceOutput=(VoiceOutput)prefs.getUChar("voiceOut",VOICE_BOTH);alarmTone=(AlarmTone)prefs.getUChar("alarmTone",ALARM_DOUBLE);
  brightnessLevel=constrain(brightnessLevel,40,255);if(powerMode>POWER_ULTRA)powerMode=POWER_ECO;if(watchSkin>=SKIN_COUNT)watchSkin=SKIN_CLASSIC;
  if(voiceOutput>VOICE_BOTH)voiceOutput=VOICE_BOTH;if(alarmTone>ALARM_PHONE)alarmTone=ALARM_DOUBLE;if(alarmHour>23)alarmHour=7;if(alarmMinute>59)alarmMinute=0;
}

void vibrateShort(){if(vibrationEnabled&&watch&&watch->motor)watch->motor->onec();}
void alarmVibrateHook(){vibrateShort();}
void alarmPhoneHook(){jarvisBleSendCommand("alarm_sound:phone");}

void applyPowerMode(){if(!watch)return;uint8_t b=brightnessLevel;if(powerMode==POWER_ECO)b=min((int)b,140);if(powerMode==POWER_ULTRA)b=min((int)b,85);watch->setBrightness(b);}
void powerScreenOnHook(){
  if(!watch)return;
  // No desligamento automatico apenas o backlight e apagado. O controlador
  // do LCD, o touch, BLE e as demais maquinas de estado continuam ativos.
  screenAwake=true;touchActive=false;watch->openBL();applyPowerMode();drawScreen();
}
void powerScreenOffHook(){
  if(!watch)return;
  // Nao usar displaySleep() aqui. No T-Watch isso torna a retomada por touch
  // e botao muito menos confiavel. Apagamos somente o backlight.
  watch->closeBL();screenAwake=false;touchActive=false;touchWakeConsumed=false;
}
void powerDeepSleepHook(){enterDeepSleep();}

void enterDeepSleep(){
  if(!watch)return;
  if(watch->bma&&wristWakeEnabled){watch->bma->enableFeature(BMA423_WAKEUP,true);watch->bma->enableFeature(BMA423_TILT,true);watch->bma->enableWakeupInterrupt();watch->bma->enableTiltInterrupt();esp_sleep_enable_ext1_wakeup(GPIO_SEL_39,ESP_EXT1_WAKEUP_ANY_HIGH);}
  esp_sleep_enable_ext0_wakeup((gpio_num_t)AXP202_INT,0);
  watch->displaySleep();watch->closeBL();esp_deep_sleep_start();
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
  if(level<60){voiceText="NAO OUVI";lastMessage="Fale mais perto";controller.emit(jarvisEvent(EVT_VOICE_STOP));drawScreen();return;}
  voiceText="VOZ DETECTADA";String cmd="voice_capture:"+voiceOutputLabel();
  if(jarvisBleIsConnected()&&jarvisBleSendCommand(cmd)){lastMessage="Enviado ao celular";controller.emit(jarvisEvent(EVT_VOICE_RESULT));}
  else{lastMessage="Voz captada; celular indisponivel";controller.setVoiceState(JARVIS_VOICE_ERROR);}
  drawScreen();
}

void lcarsButton(int x,int y,int w,int h,uint16_t c,const char*label,const char*sub=nullptr){if(!tft)return;tft->fillRoundRect(x,y,w,h,10,c);tft->fillRect(x+10,y+h-6,w-10,6,c);tft->setTextColor(C_TEXT,c);tft->drawCentreString(label,x+w/2,y+6,2);if(sub)tft->drawCentreString(sub,x+w/2,y+24,1);}
void drawBatteryIcon(int x,int y,int p){uint16_t c=p>=20?C_TEXT:C_RED;tft->drawRoundRect(x,y,24,10,3,c);tft->fillRect(x+24,y+3,3,4,c);if(p>=0)tft->fillRect(x+2,y+2,(20*p)/100,6,c);}
void handFromAngle(int cx,int cy,int r,float deg,uint16_t color,int width=1){float a=(deg-90.0f)*0.0174532925f;int x=cx+(int)(cos(a)*r),y=cy+(int)(sin(a)*r);for(int i=0;i<width;i++)tft->drawLine(cx+i,cy,x+i,y,color);}

void drawSkinClassic(){RTC_Date n=watch->rtc->getDateTime();tft->fillScreen(C_BLACK);tft->drawCircle(120,113,105,C_STEEL);tft->drawCircle(120,113,100,C_WHITE);for(int i=0;i<60;i++){float a=i*6.0f*0.0174532925f;int r1=(i%5==0)?82:87;tft->drawLine(120+cos(a)*r1,113+sin(a)*r1,120+cos(a)*92,113+sin(a)*92,(i%5==0)?C_WHITE:C_STEEL);}tft->setTextColor(C_WHITE,C_BLACK);tft->drawCentreString("JARVIS",120,33,2);handFromAngle(120,113,48,(n.hour%12)*30+n.minute*.5f,C_WHITE,3);handFromAngle(120,113,72,n.minute*6,C_WHITE,2);handFromAngle(120,113,78,n.second*6,C_RED);tft->fillCircle(120,113,5,C_WHITE);tft->drawCentreString("< skins | apps ^",120,222,1);}
void drawSkinAnaDigi(){RTC_Date n=watch->rtc->getDateTime();tft->fillScreen(C_DARK);tft->drawRoundRect(8,8,224,224,18,C_WHITE);tft->drawCircle(70,68,42,C_STEEL);tft->drawCircle(170,68,42,C_STEEL);handFromAngle(70,68,24,(n.hour%12)*30+n.minute*.5f,C_WHITE,2);handFromAngle(70,68,32,n.minute*6,C_WHITE);handFromAngle(170,68,25,n.second*6,C_ORANGE);tft->fillRoundRect(125,122,92,72,6,C_LCD);tft->setTextColor(C_BLACK,C_LCD);tft->drawCentreString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),171,137,4);tft->drawCentreString((twoDigits(n.day)+"/"+twoDigits(n.month)).c_str(),171,174,2);tft->setTextColor(C_WHITE,C_DARK);tft->drawCentreString("< skins | apps ^",120,216,1);}
void drawSkinAviation(){RTC_Date n=watch->rtc->getDateTime();tft->fillScreen(C_BLACK);tft->drawCircle(120,113,108,C_STEEL);tft->drawCircle(120,113,101,C_WHITE);tft->setTextColor(C_WHITE,C_BLACK);tft->drawCentreString("JARVIS AVIATION",120,27,1);tft->fillRoundRect(69,61,102,30,3,C_DARK);tft->setTextColor(C_GOLD,C_DARK);tft->drawCentreString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),120,66,4);handFromAngle(120,113,48,(n.hour%12)*30+n.minute*.5f,C_WHITE,3);handFromAngle(120,113,72,n.minute*6,C_WHITE,2);handFromAngle(120,113,78,n.second*6,C_RED);tft->fillCircle(120,113,4,C_WHITE);tft->setTextColor(C_WHITE,C_BLACK);tft->drawCentreString("< skins | apps ^",120,222,1);}
void drawWatchFace(){if(watchSkin==SKIN_CLASSIC)drawSkinClassic();else if(watchSkin==SKIN_ANADIGI)drawSkinAnaDigi();else drawSkinAviation();}

void drawHeader(const char*title){RTC_Date n=watch->rtc->getDateTime();int batt=batteryPercent();tft->fillRect(0,0,240,57,C_BG);tft->fillRoundRect(5,5,230,43,14,C_ORANGE);tft->setTextColor(C_TEXT,C_ORANGE);tft->drawString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),49,7,4);tft->drawRightString(title,228,9,2);drawBatteryIcon(177,34,batt);tft->drawRightString(batt>=0?(String(batt)+"%").c_str():"--",228,34,1);}
void drawFooter(){tft->fillRect(0,207,240,33,C_BG);if(currentScreen!=SCREEN_HOME){lcarsButton(5,211,70,25,C_LAV,"VOLTAR");lcarsButton(80,211,70,25,C_ORANGE,"HOME");}uint16_t c=jarvisBleIsConnected()?C_GREEN:(jarvisWifiIsConnected()?C_BLUE:C_GOLD);tft->fillRoundRect(155,211,80,25,8,c);tft->setTextColor(C_TEXT,c);tft->drawCentreString(jarvisBleIsConnected()?"PHONE":jarvisWifiIsConnected()?"WIFI":"OFFLINE",195,218,1);}
void drawMessage(){tft->fillRoundRect(5,181,230,22,8,C_WHITE);tft->drawRoundRect(5,181,230,22,8,C_LAV);tft->setTextColor(C_TEXT,C_WHITE);String m=lastMessage;if(m.length()>34)m=m.substring(0,31)+"...";tft->drawCentreString(m,120,187,1);}

void drawApps(){drawHeader("APPS");lcarsButton(5,62,52,48,C_ORANGE,"VOZ");lcarsButton(62,62,52,48,C_SALMON,"ALM");lcarsButton(119,62,52,48,C_BLUE,"CAM");lcarsButton(176,62,59,48,C_GREEN,"GPS");lcarsButton(5,117,52,48,C_LAV,"CASA");lcarsButton(62,117,52,48,C_GOLD,"PASSOS");lcarsButton(119,117,52,48,C_BLUE,"STATUS");lcarsButton(176,117,59,48,C_SALMON,"CONFIG");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString("baixo: relogio",120,184,1);drawFooter();}
void drawVoice(){drawHeader("VOZ IA");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString(voiceText.c_str(),120,76,2);lcarsButton(35,108,170,34,C_ORANGE,voiceCapturing?"OUVINDO...":"MICROFONE");lcarsButton(35,149,170,28,C_BLUE,("SAIDA: "+voiceOutputLabel()).c_str());drawMessage();drawFooter();}
void drawAlarm(){drawHeader("ALARME");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString((twoDigits(alarmHour)+":"+twoDigits(alarmMinute)).c_str(),120,64,4);lcarsButton(12,101,48,32,C_LAV,"H-");lcarsButton(66,101,48,32,C_ORANGE,"H+");lcarsButton(126,101,48,32,C_BLUE,"M-");lcarsButton(180,101,48,32,C_SALMON,"M+");lcarsButton(25,139,190,28,C_GOLD,("TOQUE: "+alarmToneLabel()).c_str());lcarsButton(35,173,170,28,controller.alarm().ringing()?C_RED:(alarmEnabled?C_GREEN:C_RED),controller.alarm().ringing()?"PARAR ALARME":(alarmEnabled?"LIGADO":"DESLIGADO"));drawFooter();}
void drawCamera(){drawHeader("CAMERA / VIDEO");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString("Usa camera do celular",120,69,2);lcarsButton(30,103,180,36,C_ORANGE,"FOTOGRAFAR");lcarsButton(30,147,180,36,C_BLUE,"VIDEO FAMILIA");drawFooter();}
void drawGps(){drawHeader("GPS");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString(gpsText.c_str(),120,82,2);lcarsButton(55,128,130,30,C_BLUE,"ATUALIZAR");drawFooter();}
void drawControls(){drawHeader("CASA");lcarsButton(5,62,111,51,C_SALMON,"LUZ SALA");lcarsButton(124,62,111,51,C_ORANGE,"LUZ QUARTO");lcarsButton(5,119,111,51,C_BLUE,"PORTAO");lcarsButton(124,119,111,51,C_LAV,"CENA NOITE");drawMessage();drawFooter();}
void drawHealth(){drawHeader("ATIVIDADE");tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString("PASSOS",120,75,2);tft->drawCentreString(String(steps).c_str(),120,103,4);int pct=min(100,(int)(steps*100UL/6000UL));tft->drawRoundRect(27,151,184,14,6,C_TEXT);tft->fillRoundRect(29,153,(180*pct)/100,10,5,C_GREEN);drawFooter();}
void drawStatus(){drawHeader("STATUS");int b=batteryPercent();tft->setTextColor(C_TEXT,C_BG);tft->drawString("BATERIA",20,70,2);tft->drawRightString(b>=0?(String(b)+"%").c_str():"--",220,70,2);tft->drawString("BLE",20,96,2);tft->drawRightString(jarvisBleIsConnected()?"OK":"OFF",220,96,2);tft->drawString("WIFI",20,122,2);tft->drawRightString(jarvisWifiIsConnected()?jarvisWifiSsid().c_str():"OFF",220,122,2);tft->drawString("ENERGIA",20,148,2);tft->drawRightString(powerLabel().c_str(),220,148,2);tft->drawString("EVENTOS",20,174,1);tft->drawRightString(String(controller.droppedEvents()).c_str(),220,174,1);drawFooter();}
String timeoutLabel(){return screenTimeoutSec==0?"NUNCA":String(screenTimeoutSec)+"s";}
void drawSettings(){drawHeader("CONFIG");tft->setTextColor(C_TEXT,C_BG);tft->drawString("BRILHO",8,64,2);lcarsButton(110,60,58,30,C_LAV,"-");lcarsButton(174,60,61,30,C_ORANGE,"+");tft->drawString("ENERGIA",8,98,2);lcarsButton(124,94,111,30,C_GREEN,powerLabel().c_str());tft->drawString("TELA",8,132,2);lcarsButton(124,128,111,30,C_BLUE,timeoutLabel().c_str());lcarsButton(5,164,72,36,C_LAV,"WIFI");lcarsButton(82,164,72,36,C_GOLD,vibrationEnabled?"VIB ON":"VIB OFF");lcarsButton(159,164,76,36,C_SALMON,"RELOGIO");drawFooter();}
void drawClock(){drawHeader("RELOGIO");RTC_Date n=watch->rtc->getDateTime();tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),120,65,4);tft->drawCentreString((twoDigits(n.day)+"/"+twoDigits(n.month)+"/"+String(n.year)).c_str(),120,95,2);lcarsButton(5,121,52,38,C_LAV,"H-");lcarsButton(62,121,52,38,C_ORANGE,"H+");lcarsButton(124,121,52,38,C_BLUE,"M-");lcarsButton(181,121,54,38,C_SALMON,"M+");drawFooter();}

void drawWifi(){
  drawHeader("WIFI");tft->setTextColor(C_TEXT,C_BG);
  JarvisNetworkState ns=controller.network().state();
  if(ns==JARVIS_NET_SCANNING){tft->drawCentreString("BUSCANDO REDES...",120,77,2);tft->drawCentreString("interface continua ativa",120,101,1);}
  else if(ns==JARVIS_NET_CONNECTING){tft->drawCentreString("CONECTANDO...",120,77,2);tft->drawCentreString(selectedWifi.substring(0,22).c_str(),120,103,1);}
  else if(wifiNetworkCount<=0){tft->drawCentreString(jarvisWifiIsConnected()?"Wi-Fi conectado":"Nenhuma busca",120,75,2);if(jarvisWifiIsConnected())tft->drawCentreString(jarvisWifiSsid().substring(0,22).c_str(),120,99,1);lcarsButton(45,112,150,36,C_BLUE,"BUSCAR");}
  else{
    int start=wifiPage*4;for(int row=0;row<4;row++){int i=start+row;if(i>=wifiNetworkCount)break;int y=60+row*34;uint16_t c=wifiNetworks[i].known?C_GREEN:C_LAV;tft->fillRoundRect(7,y,226,29,7,c);tft->setTextColor(C_TEXT,c);String label=wifiNetworks[i].ssid;if(label.length()>18)label=label.substring(0,18);tft->drawString(label.c_str(),14,y+6,1);tft->drawRightString((String(wifiNetworks[i].rssi)+" dBm").c_str(),225,y+6,1);}
  }
  drawFooter();
}

void drawKeyboard(){
  drawHeader("SENHA WIFI");tft->setTextColor(C_TEXT,C_BG);String mask="";for(unsigned int i=0;i<wifiPassword.length();i++)mask+="*";if(mask.length()>25)mask=mask.substring(mask.length()-25);tft->drawString(selectedWifi.substring(0,22).c_str(),7,59,1);tft->drawString(mask.c_str(),7,73,1);
  String chars=keyboardPages[keyboardPage];int cols=8;for(int i=0;i<(int)chars.length()&&i<32;i++){int row=i/cols,col=i%cols,x=4+col*29,y=88+row*25;tft->fillRoundRect(x,y,26,22,4,C_WHITE);tft->setTextColor(C_TEXT,C_WHITE);char s[2]={chars[i],0};tft->drawCentreString(s,x+13,y+5,1);}
  lcarsButton(4,190,69,30,C_LAV,"PAG");lcarsButton(78,190,69,30,C_SALMON,"APAGA");lcarsButton(152,190,84,30,C_GREEN,"SALVAR");
}

void drawScreen(){if(!screenAwake||!tft)return;if(currentScreen!=SCREEN_HOME)tft->fillScreen(C_BG);switch(currentScreen){case SCREEN_HOME:drawWatchFace();break;case SCREEN_APPS:drawApps();break;case SCREEN_VOICE:drawVoice();break;case SCREEN_ALARM:drawAlarm();break;case SCREEN_CAMERA:drawCamera();break;case SCREEN_GPS:drawGps();break;case SCREEN_CONTROLS:drawControls();break;case SCREEN_HEALTH:drawHealth();break;case SCREEN_STATUS:drawStatus();break;case SCREEN_SETTINGS:drawSettings();break;case SCREEN_CLOCK:drawClock();break;case SCREEN_WIFI:drawWifi();break;case SCREEN_KEYBOARD:drawKeyboard();break;}}
void navigate(ScreenId s){previousScreen=currentScreen;currentScreen=s;lastMessage="";drawScreen();}
void goHome(){previousScreen=currentScreen;currentScreen=SCREEN_HOME;drawScreen();}
void sendCommand(const String&cmd){if(jarvisBleIsConnected()&&jarvisBleSendCommand(cmd))lastMessage="Enviado ao celular";else lastMessage="Celular indisponivel";drawScreen();}

void triggerCamera(){if(jarvisBleIsConnected()&&jarvisBleSendCommand("camera_capture"))cameraText="FOTO SOLICITADA";else cameraText="SEM CELULAR";drawScreen();}
void startVideoCall(){
  controller.emit(jarvisEvent(EVT_CALL_START,JARVIS_PRI_HIGH));
  bool ok=false;if(jarvisBleIsConnected())ok=jarvisBleSendCommand("family_call:video");
  if(!ok&&jarvisWifiIsConnected())ok=jarvisWifiPostJson("/api/v1/family.php?acao=call_start","{\"mode\":\"video\",\"origin\":\"watch\"}",nullptr);
  lastMessage=ok?"Videochamada iniciada":"Sem conexao para chamada";
  controller.setCallState(ok?JARVIS_CALL_ACTIVE:JARVIS_CALL_ERROR);drawScreen();
}
void requestGps(){if(jarvisBleIsConnected()&&jarvisBleSendCommand("gps_request"))gpsText="SOLICITANDO GPS...";else gpsText="CELULAR NECESSARIO";drawScreen();}
void adjustAlarm(int dh,int dm){int h=(int)alarmHour+dh,m=(int)alarmMinute+dm;while(h<0)h+=24;while(h>23)h-=24;while(m<0)m+=60;while(m>59)m-=60;alarmHour=h;alarmMinute=m;saveSettings();drawScreen();}
void adjustClock(int dh,int dm){RTC_Date n=watch->rtc->getDateTime();int h=(n.hour+dh+24)%24,m=(n.minute+dm+60)%60;watch->rtc->setDateTime(n.year,n.month,n.day,h,m,0);drawScreen();}
void cycleSkin(int dir){int s=(int)watchSkin+dir;if(s<0)s=SKIN_COUNT-1;if(s>=SKIN_COUNT)s=0;watchSkin=(WatchSkin)s;prefs.putUChar("skin",(uint8_t)watchSkin);vibrateShort();drawScreen();}

void syncWifiResults(){
  wifiNetworkCount=controller.network().networkCount();
  for(int i=0;i<wifiNetworkCount&&i<12;i++){const JarvisWifiNetwork *n=controller.network().networkAt(i);if(n)wifiNetworks[i]=*n;}
}
void startWifiScan(){wifiNetworkCount=0;wifiPage=0;wifiScanning=true;lastMessage="Buscando redes";controller.emit(jarvisEvent(EVT_WIFI_SCAN_REQUEST));drawScreen();}
void selectWifi(int index){if(index<0||index>=wifiNetworkCount)return;selectedWifi=wifiNetworks[index].ssid;wifiPassword="";keyboardPage=0;navigate(SCREEN_KEYBOARD);}
void saveWifiFromKeyboard(){
  if(selectedWifi.isEmpty())return;
  if(!controller.network().saveAndConnect(selectedWifi,wifiPassword)){lastMessage=controller.network().lastError();drawScreen();return;}
  lastMessage="Conectando ao Wi-Fi";previousScreen=SCREEN_SETTINGS;currentScreen=SCREEN_WIFI;drawScreen();
}

void handleTap(int x,int y){
  controller.emit(jarvisEvent(EVT_TOUCH_TAP,JARVIS_PRI_NORMAL,x,y));vibrateShort();
  if(currentScreen==SCREEN_HOME){navigate(SCREEN_APPS);return;}
  if(currentScreen!=SCREEN_HOME&&currentScreen!=SCREEN_KEYBOARD&&y>=207){if(x<78){ScreenId s=previousScreen;previousScreen=SCREEN_HOME;currentScreen=s;drawScreen();return;}if(x<154){goHome();return;}}
  if(currentScreen==SCREEN_APPS){if(y>=62&&y<=111){if(x<59)navigate(SCREEN_VOICE);else if(x<117)navigate(SCREEN_ALARM);else if(x<174)navigate(SCREEN_CAMERA);else navigate(SCREEN_GPS);return;}if(y>=117&&y<=167){if(x<59)navigate(SCREEN_CONTROLS);else if(x<117)navigate(SCREEN_HEALTH);else if(x<174)navigate(SCREEN_STATUS);else navigate(SCREEN_SETTINGS);return;}}
  else if(currentScreen==SCREEN_VOICE){if(y>=100&&y<145)beginVoiceCapture();else if(y>=145&&y<=184){voiceOutput=(VoiceOutput)(((int)voiceOutput+1)%3);saveSettings();drawScreen();}}
  else if(currentScreen==SCREEN_ALARM){
    if(y>=101&&y<=135){if(x<61)adjustAlarm(-1,0);else if(x<120)adjustAlarm(1,0);else if(x<177)adjustAlarm(0,-5);else adjustAlarm(0,5);}
    else if(y>=139&&y<=170){alarmTone=(AlarmTone)(((int)alarmTone+1)%4);saveSettings();drawScreen();}
    else if(y>=171&&y<=205){if(controller.alarm().ringing()){controller.emit(jarvisEvent(EVT_ALARM_STOP,JARVIS_PRI_HIGH));lastMessage="Alarme confirmado";drawScreen();}else{alarmEnabled=!alarmEnabled;saveSettings();drawScreen();}}
  }
  else if(currentScreen==SCREEN_CAMERA){if(y>=98&&y<=143)triggerCamera();else if(y>=144&&y<=190)startVideoCall();}
  else if(currentScreen==SCREEN_GPS){if(y>=120&&y<=170)requestGps();}
  else if(currentScreen==SCREEN_CONTROLS){if(y>=62&&y<=113)sendCommand(x<120?"Alterne a luz da sala":"Alterne a luz do quarto");else if(y>=119&&y<=170)sendCommand(x<120?"Acione o portao":"Ative a cena noite");}
  else if(currentScreen==SCREEN_SETTINGS){if(y>=60&&y<=91){if(x>=110&&x<171)brightnessLevel=max(40,(int)brightnessLevel-20);else if(x>=171)brightnessLevel=min(255,(int)brightnessLevel+20);applyPowerMode();saveSettings();drawScreen();}else if(y>=94&&y<=125&&x>=120){powerMode=(PowerMode)(((int)powerMode+1)%3);screenTimeoutSec=powerMode==POWER_NORMAL?30:powerMode==POWER_ECO?20:10;applyPowerMode();saveSettings();drawScreen();}else if(y>=128&&y<=159&&x>=120){screenTimeoutSec=screenTimeoutSec==10?20:screenTimeoutSec==20?30:screenTimeoutSec==30?60:screenTimeoutSec==60?0:10;saveSettings();drawScreen();}else if(y>=164&&y<=201){if(x<80){navigate(SCREEN_WIFI);startWifiScan();}else if(x<157){vibrationEnabled=!vibrationEnabled;saveSettings();drawScreen();}else navigate(SCREEN_CLOCK);}}
  else if(currentScreen==SCREEN_CLOCK){if(y>=121&&y<=160){if(x<58)adjustClock(-1,0);else if(x<120)adjustClock(1,0);else if(x<180)adjustClock(0,-1);else adjustClock(0,1);}}
  else if(currentScreen==SCREEN_WIFI){JarvisNetworkState ns=controller.network().state();if(ns!=JARVIS_NET_SCANNING&&wifiNetworkCount<=0){if(y>=105&&y<=155)startWifiScan();}else if(ns!=JARVIS_NET_SCANNING&&ns!=JARVIS_NET_CONNECTING&&y>=60&&y<196){int row=(y-60)/34;selectWifi(wifiPage*4+row);}}
  else if(currentScreen==SCREEN_KEYBOARD){
    if(y>=88&&y<188){int col=(x-4)/29,row=(y-88)/25,index=row*8+col;String chars=keyboardPages[keyboardPage];if(col>=0&&col<8&&index>=0&&index<(int)chars.length()&&wifiPassword.length()<63)wifiPassword+=chars[index];drawScreen();}
    else if(y>=188){if(x<75){keyboardPage=(keyboardPage+1)%3;drawScreen();}else if(x<150){if(wifiPassword.length())wifiPassword.remove(wifiPassword.length()-1);drawScreen();}else saveWifiFromKeyboard();}
  }
}

void handleGestureRelease(){
  int dx=touchLastX-touchStartX,dy=touchLastY-touchStartY;unsigned long dt=millis()-touchStartMs;int adx=abs(dx),ady=abs(dy);
  if(adx>=SWIPE_MIN||ady>=SWIPE_MIN){
    JarvisEventType evt=adx>ady?(dx>0?EVT_SWIPE_RIGHT:EVT_SWIPE_LEFT):(dy>0?EVT_SWIPE_DOWN:EVT_SWIPE_UP);controller.emit(jarvisEvent(evt));
    if(adx>ady){if(currentScreen==SCREEN_HOME)cycleSkin(dx>0?-1:1);}
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
      wifiScanning=false;lastMessage="Wi-Fi conectado: "+event.text;if(currentScreen==SCREEN_WIFI||currentScreen==SCREEN_STATUS)drawScreen();
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
  Serial.begin(115200);bootMillis=millis();watch=TTGOClass::getWatch();if(!watch)return;watch->begin();watch->openBL();tft=watch->tft;if(watch->rtc)watch->rtc->check();
  if(watch->power){watch->power->adc1Enable(AXP202_VBUS_VOL_ADC1|AXP202_VBUS_CUR_ADC1|AXP202_BATT_CUR_ADC1|AXP202_BATT_VOL_ADC1,true);pinMode(AXP202_INT,INPUT_PULLUP);attachInterrupt(AXP202_INT,onPowerButtonIrq,FALLING);watch->power->enableIRQ(AXP202_PEK_SHORTPRESS_IRQ,true);watch->power->clearIRQ();}
  loadSettings();applyPowerMode();initMotion();jarvisBleBegin();jarvisWifiBegin();

  JarvisPowerHooks powerHooks;powerHooks.screenOn=powerScreenOnHook;powerHooks.screenOff=powerScreenOffHook;powerHooks.deepSleep=powerDeepSleepHook;
  JarvisAlarmHooks alarmHooks;alarmHooks.vibrateOnce=alarmVibrateHook;alarmHooks.phoneSound=alarmPhoneHook;
  JarvisControllerHooks controllerHooks;controllerHooks.onEvent=controllerEvent;
  controller.begin(powerHooks,alarmHooks,controllerHooks,true);syncControllerConfig();drawScreen();
}

void loop(){
  if(!watch){delay(50);return;}

  processPowerButton();
  jarvisBleLoop();
  jarvisWifiLoop();
  processVoiceCapture();

  if(watch->bma&&millis()-lastStepRefresh>2000){lastStepRefresh=millis();steps=watch->bma->getCounter();if(screenAwake&&currentScreen==SCREEN_HEALTH)drawScreen();}

  if(millis()-lastClockRefresh>=1000){
    lastClockRefresh=millis();emitRtcTick();
    if(screenAwake&&(currentScreen==SCREEN_HOME||currentScreen==SCREEN_STATUS||currentScreen==SCREEN_ALARM||currentScreen==SCREEN_VOICE))drawScreen();
  }

  // O touch precisa ser consultado mesmo com a tela apagada. Como o desligamento
  // automatico agora apaga somente o backlight, o controlador touch continua vivo.
  // O primeiro toque apenas acorda a tela e e consumido ate o dedo ser retirado.
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
