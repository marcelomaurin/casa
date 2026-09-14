#include "config.h"
#include "jarvis_ble.h"
#include <Preferences.h>
#include <driver/i2s.h>
#include <esp_sleep.h>
#include <math.h>

TTGOClass *watch = nullptr;
TFT_eSPI *tft = nullptr;
Preferences prefs;

enum ScreenId { SCREEN_HOME, SCREEN_VOICE, SCREEN_CONTROLS, SCREEN_HEALTH, SCREEN_STATUS, SCREEN_SETTINGS, SCREEN_CLOCK };
enum PowerMode { POWER_NORMAL, POWER_ECO, POWER_ULTRA };
enum WatchSkin { SKIN_CLASSIC, SKIN_ANADIGI, SKIN_AVIATION, SKIN_COUNT };

ScreenId currentScreen = SCREEN_HOME;
ScreenId previousScreen = SCREEN_HOME;
PowerMode powerMode = POWER_ECO;
WatchSkin watchSkin = SKIN_CLASSIC;

unsigned long bootMillis = 0;
unsigned long lastClockRefresh = 0;
unsigned long lastInteraction = 0;
unsigned long lastStepRefresh = 0;
bool screenAwake = true;
bool vibrationEnabled = true;
bool wristWakeEnabled = true;
bool voiceReady = false;
uint8_t brightnessLevel = 150;
uint16_t screenTimeoutSec = 20;
uint32_t steps = 0;
String lastMessage = "JARVIS pronto";
String voiceText = "TOQUE PARA FALAR";

// Gesture state: buttons are only fired after touch release if no swipe occurred.
bool touchActive = false;
int16_t touchStartX = 0, touchStartY = 0, touchLastX = 0, touchLastY = 0;
unsigned long touchStartMs = 0;
const int SWIPE_MIN = 42;
const unsigned long TAP_MAX_MS = 650;

static const uint16_t C_BG=0xFFDF, C_TEXT=0x18C3, C_ORANGE=0xFBE0, C_SALMON=0xFB2C;
static const uint16_t C_LAV=0xB57F, C_BLUE=0x5D7F, C_GREEN=0x6E6B, C_RED=0xF9E7, C_GOLD=0xFE60, C_WHITE=0xFFFF;
static const uint16_t C_BLACK=0x0000, C_DARK=0x2104, C_STEEL=0x8410, C_LCD=0xB5A0;

String twoDigits(uint8_t v){ return v<10 ? "0"+String(v) : String(v); }
int batteryPercent(){ if(!watch||!watch->power||!watch->power->isBatteryConnect()) return -1; return constrain(watch->power->getBattPercentage(),0,100); }
String powerLabel(){ return powerMode==POWER_NORMAL?"NORMAL":powerMode==POWER_ECO?"ECO":"ULTRA"; }
String uptimeText(){ unsigned long s=(millis()-bootMillis)/1000UL; return String(s/3600UL)+"h "+String((s%3600UL)/60UL)+"m"; }

void saveSettings(){
  prefs.putUChar("bright",brightnessLevel); prefs.putBool("vibrate",vibrationEnabled); prefs.putBool("wrist",wristWakeEnabled);
  prefs.putUShort("timeout",screenTimeoutSec); prefs.putUChar("power",(uint8_t)powerMode); prefs.putUChar("skin",(uint8_t)watchSkin);
}
void loadSettings(){
  prefs.begin("jarvis",false); brightnessLevel=prefs.getUChar("bright",150); vibrationEnabled=prefs.getBool("vibrate",true);
  wristWakeEnabled=prefs.getBool("wrist",true); screenTimeoutSec=prefs.getUShort("timeout",20);
  powerMode=(PowerMode)prefs.getUChar("power",POWER_ECO); watchSkin=(WatchSkin)prefs.getUChar("skin",SKIN_CLASSIC);
  brightnessLevel=constrain(brightnessLevel,40,255); if(powerMode>POWER_ULTRA)powerMode=POWER_ECO; if(watchSkin>=SKIN_COUNT)watchSkin=SKIN_CLASSIC;
}
void vibrateShort(){ if(vibrationEnabled&&watch&&watch->motor)watch->motor->onec(); }
void applyPowerMode(){ uint8_t b=brightnessLevel; if(powerMode==POWER_ECO)b=min((int)b,140); if(powerMode==POWER_ULTRA)b=min((int)b,85); if(watch)watch->setBrightness(b); }
void wakeDisplay(){ if(!screenAwake){ watch->displayWakeup(); watch->openBL(); screenAwake=true; applyPowerMode(); } lastInteraction=millis(); }
void sleepDisplay(){ if(!screenAwake)return; watch->displaySleep(); watch->closeBL(); screenAwake=false; }

void enterDeepSleep(){
  if(watch&&watch->bma&&wristWakeEnabled){ watch->bma->enableFeature(BMA423_WAKEUP,true); watch->bma->enableFeature(BMA423_TILT,true); watch->bma->enableWakeupInterrupt(); watch->bma->enableTiltInterrupt(); }
  watch->displaySleep(); watch->closeBL(); esp_sleep_enable_ext1_wakeup(GPIO_SEL_39,ESP_EXT1_WAKEUP_ANY_HIGH); esp_deep_sleep_start();
}
void initMotion(){
  if(!watch||!watch->bma)return; Acfg cfg; cfg.odr=BMA4_OUTPUT_DATA_RATE_25HZ; cfg.range=BMA4_ACCEL_RANGE_2G; cfg.bandwidth=BMA4_ACCEL_NORMAL_AVG4; cfg.perf_mode=BMA4_CONTINUOUS_MODE;
  watch->bma->accelConfig(cfg); watch->bma->enableAccel(); watch->bma->enableFeature(BMA423_STEP_CNTR,true);
  if(wristWakeEnabled){ watch->bma->enableFeature(BMA423_TILT,true); watch->bma->enableFeature(BMA423_WAKEUP,true); }
}

bool initMicrophone(){
  if(voiceReady)return true; i2s_config_t cfg={}; cfg.mode=(i2s_mode_t)(I2S_MODE_MASTER|I2S_MODE_RX|I2S_MODE_PDM);
  cfg.sample_rate=16000; cfg.bits_per_sample=I2S_BITS_PER_SAMPLE_16BIT; cfg.channel_format=I2S_CHANNEL_FMT_ONLY_LEFT; cfg.communication_format=I2S_COMM_FORMAT_I2S;
  cfg.intr_alloc_flags=ESP_INTR_FLAG_LEVEL1; cfg.dma_buf_count=4; cfg.dma_buf_len=256; cfg.use_apll=false;
  if(i2s_driver_install(I2S_NUM_0,&cfg,0,nullptr)!=ESP_OK)return false; i2s_pin_config_t pins={}; pins.bck_io_num=I2S_PIN_NO_CHANGE; pins.ws_io_num=JARVIS_PDM_CLK_PIN; pins.data_out_num=I2S_PIN_NO_CHANGE; pins.data_in_num=JARVIS_PDM_DATA_PIN;
  if(i2s_set_pin(I2S_NUM_0,&pins)!=ESP_OK){i2s_driver_uninstall(I2S_NUM_0);return false;} voiceReady=true; return true;
}
int captureVoiceLevel(uint32_t ms){
  if(!initMicrophone())return -1; int16_t buf[256]; size_t got=0; uint64_t sum=0; uint32_t count=0; unsigned long until=millis()+ms;
  while((long)(until-millis())>0){ if(i2s_read(I2S_NUM_0,buf,sizeof(buf),&got,pdMS_TO_TICKS(100))==ESP_OK){ int n=got/2; for(int i=0;i<n;i++){sum+=abs((int)buf[i]);count++;}} }
  return count?(int)(sum/count):0;
}

void lcarsButton(int x,int y,int w,int h,uint16_t c,const char*label,const char*sub=nullptr){
  tft->fillRoundRect(x,y,w,h,10,c); tft->fillRect(x+10,y+h-6,w-10,6,c); tft->setTextColor(C_TEXT,c); tft->drawCentreString(label,x+w/2,y+6,2); if(sub)tft->drawCentreString(sub,x+w/2,y+24,1);
}
void drawBatteryIcon(int x,int y,int p,uint16_t bg){ uint16_t c=p>=20?C_TEXT:C_RED; tft->drawRoundRect(x,y,24,10,3,c); tft->fillRect(x+24,y+3,3,4,c); if(p>=0)tft->fillRect(x+2,y+2,(20*p)/100,6,c); }
void handFromAngle(int cx,int cy,int r,float deg,uint16_t color,int width=1){ float a=(deg-90.0f)*0.0174532925f; int x=cx+(int)(cos(a)*r), y=cy+(int)(sin(a)*r); for(int i=0;i<width;i++)tft->drawLine(cx+i,cy,x+i,y,color); }

void drawSkinClassic(){
  RTC_Date n=watch->rtc->getDateTime(); tft->fillScreen(C_BLACK); tft->drawCircle(120,113,105,C_STEEL); tft->drawCircle(120,113,100,C_WHITE); tft->drawCircle(120,113,92,C_STEEL);
  for(int i=0;i<60;i++){ float a=i*6.0f*0.0174532925f; int r1=(i%5==0)?82:87; int r2=92; int x1=120+cos(a)*r1,y1=113+sin(a)*r1,x2=120+cos(a)*r2,y2=113+sin(a)*r2; tft->drawLine(x1,y1,x2,y2,(i%5==0)?C_WHITE:C_STEEL); }
  tft->setTextColor(C_WHITE,C_BLACK); tft->drawCentreString("JARVIS",120,33,2); tft->drawCentreString("CLASSIC",120,52,1);
  float hour=(n.hour%12)*30+n.minute*0.5f, minute=n.minute*6.0f, second=n.second*6.0f; handFromAngle(120,113,48,hour,C_WHITE,3); handFromAngle(120,113,72,minute,C_WHITE,2); handFromAngle(120,113,78,second,C_RED,1); tft->fillCircle(120,113,5,C_WHITE);
  tft->fillRoundRect(145,103,42,20,3,C_WHITE); tft->setTextColor(C_BLACK,C_WHITE); tft->drawCentreString(twoDigits(n.day).c_str(),166,108,1);
  tft->setTextColor(C_WHITE,C_BLACK); tft->drawCentreString("<  deslize  >",120,222,1);
}

void drawSkinAnaDigi(){
  RTC_Date n=watch->rtc->getDateTime(); tft->fillScreen(C_DARK); tft->drawRoundRect(8,8,224,224,18,C_STEEL); tft->drawRoundRect(14,14,212,212,14,C_WHITE);
  // two analog upper subdials
  tft->drawCircle(70,68,42,C_STEEL); tft->drawCircle(170,68,42,C_STEEL); for(int i=0;i<12;i++){ float a=i*30*0.0174532925f; tft->fillCircle(70+cos(a)*35,68+sin(a)*35,1,C_WHITE); tft->fillCircle(170+cos(a)*35,68+sin(a)*35,1,C_WHITE); }
  handFromAngle(70,68,24,(n.hour%12)*30+n.minute*.5f,C_WHITE,2); handFromAngle(70,68,32,n.minute*6,C_WHITE,1); handFromAngle(170,68,25,n.second*6,C_ORANGE,1);
  tft->fillRoundRect(23,122,92,72,6,C_BLACK); tft->fillRoundRect(125,122,92,72,6,C_LCD); tft->setTextColor(C_WHITE,C_BLACK); tft->drawCentreString("ANA-DIGI",69,132,1); tft->drawCentreString("TEMP / TIMER",69,151,1);
  tft->setTextColor(C_BLACK,C_LCD); tft->drawCentreString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),171,137,4); tft->drawCentreString((twoDigits(n.day)+"/"+twoDigits(n.month)).c_str(),171,174,2);
  tft->setTextColor(C_WHITE,C_DARK); tft->drawCentreString("<  deslize  >",120,216,1);
}

void drawSkinAviation(){
  RTC_Date n=watch->rtc->getDateTime(); tft->fillScreen(C_BLACK); tft->drawCircle(120,113,108,C_STEEL); tft->drawCircle(120,113,101,C_WHITE); tft->drawCircle(120,113,94,C_STEEL);
  for(int i=0;i<60;i++){ float a=i*6*0.0174532925f; int r1=(i%5==0)?78:86; int x1=120+cos(a)*r1,y1=113+sin(a)*r1,x2=120+cos(a)*92,y2=113+sin(a)*92; tft->drawLine(x1,y1,x2,y2,(i%5==0)?C_WHITE:C_STEEL); }
  tft->setTextColor(C_WHITE,C_BLACK); tft->drawCentreString("JARVIS AVIATION",120,27,1); tft->fillRoundRect(69,61,102,30,3,C_DARK); tft->setTextColor(C_GOLD,C_DARK); tft->drawCentreString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),120,66,4);
  tft->drawCircle(78,144,24,C_STEEL); tft->drawCircle(162,144,24,C_STEEL); handFromAngle(78,144,18,n.second*6,C_GOLD); handFromAngle(162,144,18,n.minute*6,C_GOLD);
  handFromAngle(120,113,48,(n.hour%12)*30+n.minute*.5f,C_WHITE,3); handFromAngle(120,113,72,n.minute*6,C_WHITE,2); handFromAngle(120,113,78,n.second*6,C_RED,1); tft->fillCircle(120,113,4,C_WHITE);
  tft->setTextColor(C_WHITE,C_BLACK); tft->drawCentreString("<  deslize  >",120,222,1);
}

void drawWatchFace(){ if(watchSkin==SKIN_CLASSIC)drawSkinClassic(); else if(watchSkin==SKIN_ANADIGI)drawSkinAnaDigi(); else drawSkinAviation(); }
void drawHeader(const char*title){
  RTC_Date n=watch->rtc->getDateTime(); int batt=batteryPercent(); tft->fillRect(0,0,240,57,C_BG); tft->fillRoundRect(5,5,230,43,14,C_ORANGE); tft->fillRect(5,25,230,23,C_ORANGE); tft->fillRect(5,42,37,12,C_ORANGE); tft->fillRoundRect(5,43,37,13,7,C_ORANGE);
  tft->setTextColor(C_TEXT,C_ORANGE); tft->drawString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),49,7,4); tft->drawRightString(title,228,9,2); tft->drawString((twoDigits(n.day)+"/"+twoDigits(n.month)).c_str(),49,35,1); drawBatteryIcon(177,34,batt,C_ORANGE); tft->drawRightString(batt>=0?(String(batt)+"%").c_str():"--",228,34,1);
}
void drawFooter(){ tft->fillRect(0,207,240,33,C_BG); if(currentScreen!=SCREEN_HOME){lcarsButton(5,211,70,25,C_LAV,"VOLTAR");lcarsButton(80,211,70,25,C_ORANGE,"HOME");} uint16_t c=jarvisBleIsConnected()?C_GREEN:C_GOLD; tft->fillRoundRect(155,211,80,25,8,c); tft->setTextColor(C_TEXT,c); tft->drawCentreString(jarvisBleIsConnected()?"CELULAR OK":"SEM CELULAR",195,218,1); }
void drawMessage(){ tft->fillRoundRect(5,181,230,22,8,C_WHITE); tft->drawRoundRect(5,181,230,22,8,C_LAV); tft->setTextColor(C_TEXT,C_WHITE); String m=lastMessage;if(m.length()>34)m=m.substring(0,31)+"...";tft->drawCentreString(m,120,187,1); }

void drawHome(){ drawWatchFace(); }
void drawVoice(){ drawHeader("VOZ IA"); uint16_t c=jarvisBleIsConnected()?C_GREEN:C_GOLD; tft->fillRoundRect(8,64,224,105,16,c); tft->fillRoundRect(18,74,204,85,12,C_BG); tft->setTextColor(C_TEXT,C_BG); tft->drawCentreString(voiceText.c_str(),120,83,2); tft->drawCentreString(jarvisBleIsConnected()?"CELULAR DETECTADO":"CELULAR NAO DETECTADO",120,108,1); lcarsButton(50,128,140,25,C_ORANGE,"MICROFONE"); drawMessage(); drawFooter(); }
void drawControls(){ drawHeader("CASA"); lcarsButton(5,62,111,51,C_SALMON,"LUZ SALA","alternar");lcarsButton(124,62,111,51,C_ORANGE,"LUZ QUARTO","alternar");lcarsButton(5,119,111,51,C_BLUE,"PORTAO","acionar");lcarsButton(124,119,111,51,C_LAV,"CENA NOITE","executar");drawMessage();drawFooter(); }
void drawHealth(){ drawHeader("ATIVIDADE");tft->fillRoundRect(8,64,224,108,14,C_BLUE);tft->fillRoundRect(18,73,204,90,10,C_BG);tft->setTextColor(C_TEXT,C_BG);tft->drawString("PASSOS",27,82,2);tft->drawRightString(String(steps),211,82,2);tft->drawString("META",27,108,2);tft->drawRightString("6000",211,108,2);int pct=min(100,(int)(steps*100UL/6000UL));tft->drawRoundRect(27,137,184,14,6,C_TEXT);tft->fillRoundRect(29,139,(180*pct)/100,10,5,C_GREEN);tft->drawCentreString((String(pct)+"%").c_str(),120,155,1);drawFooter(); }
void drawStatus(){ drawHeader("STATUS");int b=batteryPercent();tft->fillRoundRect(8,64,224,112,14,C_LAV);tft->fillRoundRect(18,73,204,94,10,C_BG);tft->setTextColor(C_TEXT,C_BG);tft->drawString("BATERIA",27,80,2);tft->drawRightString(b>=0?(String(b)+"%").c_str():"--",211,80,2);tft->drawString("CELULAR",27,104,2);tft->drawRightString(jarvisBleIsConnected()?"OK":"OFF",211,104,2);tft->drawString("ENERGIA",27,128,2);tft->drawRightString(powerLabel(),211,128,2);tft->drawString("UPTIME",27,151,1);tft->drawRightString(uptimeText(),211,151,1);drawFooter(); }
String timeoutLabel(){ return screenTimeoutSec==0?"NUNCA":String(screenTimeoutSec)+"s"; }
void drawSettings(){ drawHeader("CONFIG");tft->setTextColor(C_TEXT,C_BG);tft->drawString("BRILHO",8,64,2);lcarsButton(110,60,58,30,C_LAV,"-");lcarsButton(174,60,61,30,C_ORANGE,"+");tft->drawString("ENERGIA",8,98,2);lcarsButton(124,94,111,30,C_GREEN,powerLabel().c_str());tft->drawString("TELA",8,132,2);lcarsButton(124,128,111,30,C_BLUE,timeoutLabel().c_str());lcarsButton(5,164,72,36,C_LAV,vibrationEnabled?"VIB ON":"VIB OFF");lcarsButton(82,164,72,36,C_GOLD,wristWakeEnabled?"PULSO ON":"PULSO OFF");lcarsButton(159,164,76,36,C_SALMON,"RELOGIO");drawFooter(); }
void drawClock(){ drawHeader("RELOGIO");RTC_Date n=watch->rtc->getDateTime();tft->setTextColor(C_TEXT,C_BG);tft->drawCentreString((twoDigits(n.hour)+":"+twoDigits(n.minute)).c_str(),120,65,4);tft->drawCentreString((twoDigits(n.day)+"/"+twoDigits(n.month)+"/"+String(n.year)).c_str(),120,95,2);lcarsButton(5,121,52,38,C_LAV,"H-");lcarsButton(62,121,52,38,C_ORANGE,"H+");lcarsButton(124,121,52,38,C_BLUE,"M-");lcarsButton(181,121,54,38,C_SALMON,"M+");lcarsButton(5,165,111,32,C_GOLD,"DIA+");lcarsButton(124,165,111,32,C_GREEN,"MES+");drawFooter(); }
void drawScreen(){ if(!screenAwake||!tft)return; if(currentScreen!=SCREEN_HOME)tft->fillScreen(C_BG); switch(currentScreen){case SCREEN_HOME:drawHome();break;case SCREEN_VOICE:drawVoice();break;case SCREEN_CONTROLS:drawControls();break;case SCREEN_HEALTH:drawHealth();break;case SCREEN_STATUS:drawStatus();break;case SCREEN_SETTINGS:drawSettings();break;case SCREEN_CLOCK:drawClock();break;} }
void navigate(ScreenId s){ previousScreen=currentScreen;currentScreen=s;lastMessage="";drawScreen(); }
void sendCommand(const String&cmd){ if(jarvisBleSendCommand(cmd))lastMessage="Enviado ao celular";else lastMessage="Celular necessario";drawScreen(); }

void startVoice(){ voiceText="OUVINDO...";lastMessage="Fale agora";drawScreen();vibrateShort();int level=captureVoiceLevel(1800);if(level<0){voiceText="MICROFONE INDISP.";lastMessage="Falha ao iniciar PDM";drawScreen();return;}if(level<60){voiceText="NAO OUVI";lastMessage="Toque e fale mais perto";drawScreen();return;}voiceText="VOZ DETECTADA";if(jarvisBleIsConnected()){jarvisBleSendCommand("voice_capture");lastMessage="Voz detectada; celular/IA acionado";}else lastMessage="Voz OK; aguardando celular para IA";drawScreen(); }
void adjustClock(int dh,int dm,int dd,int dmo){RTC_Date n=watch->rtc->getDateTime();int h=(n.hour+dh+24)%24,m=(n.minute+dm+60)%60,d=n.day+dd,mo=n.month+dmo;if(d>31)d=1;if(d<1)d=31;if(mo>12)mo=1;if(mo<1)mo=12;watch->rtc->setDateTime(n.year,mo,d,h,m,0);drawScreen();}

void cycleSkin(int dir){ int s=(int)watchSkin+dir; if(s<0)s=SKIN_COUNT-1; if(s>=SKIN_COUNT)s=0; watchSkin=(WatchSkin)s; prefs.putUChar("skin",(uint8_t)watchSkin); vibrateShort(); drawScreen(); }
void handleTap(int x,int y){
  vibrateShort();
  if(currentScreen==SCREEN_HOME){ if(y<150)navigate(SCREEN_VOICE); else if(x<80)navigate(SCREEN_HEALTH); else if(x<160)navigate(SCREEN_CONTROLS); else navigate(SCREEN_STATUS); return; }
  if(currentScreen!=SCREEN_HOME&&y>=207){ if(x<78){ScreenId s=previousScreen;previousScreen=SCREEN_HOME;currentScreen=s;drawScreen();return;}if(x<154){navigate(SCREEN_HOME);return;} }
  if(currentScreen==SCREEN_VOICE){ if(y>=74&&y<=170)startVoice(); }
  else if(currentScreen==SCREEN_CONTROLS){ if(y>=62&&y<=113)sendCommand(x<120?"Alterne a luz da sala":"Alterne a luz do quarto"); else if(y>=119&&y<=170)sendCommand(x<120?"Acione o portao":"Ative a cena noite"); }
  else if(currentScreen==SCREEN_SETTINGS){
    if(y>=60&&y<=91){if(x>=110&&x<171)brightnessLevel=max(40,(int)brightnessLevel-20);else if(x>=171)brightnessLevel=min(255,(int)brightnessLevel+20);applyPowerMode();saveSettings();drawScreen();}
    else if(y>=94&&y<=125&&x>=120){powerMode=(PowerMode)(((int)powerMode+1)%3);screenTimeoutSec=powerMode==POWER_NORMAL?30:powerMode==POWER_ECO?20:10;applyPowerMode();saveSettings();drawScreen();}
    else if(y>=128&&y<=159&&x>=120){screenTimeoutSec=screenTimeoutSec==10?20:screenTimeoutSec==20?30:screenTimeoutSec==30?60:screenTimeoutSec==60?0:10;saveSettings();drawScreen();}
    else if(y>=164&&y<=201){if(x<80){vibrationEnabled=!vibrationEnabled;saveSettings();drawScreen();}else if(x<157){wristWakeEnabled=!wristWakeEnabled;saveSettings();initMotion();drawScreen();}else navigate(SCREEN_CLOCK);}
  } else if(currentScreen==SCREEN_CLOCK){if(y>=121&&y<=160){if(x<58)adjustClock(-1,0,0,0);else if(x<120)adjustClock(1,0,0,0);else if(x<180)adjustClock(0,-1,0,0);else adjustClock(0,1,0,0);}else if(y>=165&&y<=201){if(x<120)adjustClock(0,0,1,0);else adjustClock(0,0,0,1);}}
}
void handleGestureRelease(){
  int dx=touchLastX-touchStartX, dy=touchLastY-touchStartY; unsigned long dt=millis()-touchStartMs; int adx=abs(dx),ady=abs(dy);
  if(adx>=SWIPE_MIN||ady>=SWIPE_MIN){
    if(adx>ady){ if(currentScreen==SCREEN_HOME)cycleSkin(dx>0?-1:1); }
    else { if(dy<0){ if(currentScreen!=SCREEN_SETTINGS)navigate(SCREEN_SETTINGS); } else { if(currentScreen==SCREEN_SETTINGS)navigate(SCREEN_HOME); else if(currentScreen!=SCREEN_HOME)navigate(previousScreen); } }
    lastInteraction=millis(); return;
  }
  if(dt<=TAP_MAX_MS)handleTap(touchLastX,touchLastY);
  lastInteraction=millis();
}

void setup(){
  Serial.begin(115200);bootMillis=millis();watch=TTGOClass::getWatch();watch->begin();watch->openBL();tft=watch->tft;if(watch->rtc)watch->rtc->check();
  if(watch->power)watch->power->adc1Enable(AXP202_VBUS_VOL_ADC1|AXP202_VBUS_CUR_ADC1|AXP202_BATT_CUR_ADC1|AXP202_BATT_VOL_ADC1,true);
  loadSettings();applyPowerMode();initMotion();jarvisBleBegin();lastInteraction=millis();drawScreen();
}

void loop(){
  jarvisBleLoop();
  if(watch&&watch->bma&&millis()-lastStepRefresh>2000){lastStepRefresh=millis();steps=watch->bma->getCounter();if(screenAwake&&currentScreen==SCREEN_HEALTH)drawScreen();}
  if(screenAwake&&millis()-lastClockRefresh>1000){lastClockRefresh=millis();if(currentScreen==SCREEN_HOME||currentScreen==SCREEN_STATUS)drawScreen();}

  int16_t x=0,y=0; bool touching=watch->getTouch(x,y);
  if(touching){
    if(!screenAwake){wakeDisplay();drawScreen();touchActive=false;delay(220);} else {
      if(!touchActive){touchActive=true;touchStartX=x;touchStartY=y;touchLastX=x;touchLastY=y;touchStartMs=millis();}
      else {touchLastX=x;touchLastY=y;}
      lastInteraction=millis();
    }
  } else if(touchActive){ touchActive=false; handleGestureRelease(); }

  uint16_t timeout=screenTimeoutSec;if(powerMode==POWER_ULTRA&&timeout==0)timeout=10;
  if(screenAwake&&timeout>0&&millis()-lastInteraction>(unsigned long)timeout*1000UL)sleepDisplay();
  if(!screenAwake&&powerMode==POWER_ULTRA&&millis()-lastInteraction>60000UL)enterDeepSleep();
  delay(powerMode==POWER_NORMAL?20:powerMode==POWER_ECO?50:100);
}
