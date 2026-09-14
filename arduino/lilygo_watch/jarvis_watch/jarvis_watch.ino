#include "config.h"
#include "jarvis_ble.h"
#include <Preferences.h>

TTGOClass *watch = nullptr;
TFT_eSPI *tft = nullptr;
Preferences prefs;

enum ScreenId {
  SCREEN_HOME,
  SCREEN_CONTROLS,
  SCREEN_SENSORS,
  SCREEN_JARVIS,
  SCREEN_STATUS,
  SCREEN_SETTINGS,
  SCREEN_CLOCK
};

ScreenId currentScreen = SCREEN_HOME;
ScreenId previousScreen = SCREEN_HOME;

unsigned long lastClockRefresh = 0;
unsigned long lastTouch = 0;
unsigned long lastInteraction = 0;
unsigned long bootMillis = 0;
bool screenAwake = true;

uint8_t brightnessLevel = 180;
bool vibrationEnabled = true;
uint16_t screenTimeoutSec = 30;

String lastMessage = "Sistema pronto";

static const uint16_t LCARS_BG       = 0xFFDF;
static const uint16_t LCARS_TEXT     = 0x18C3;
static const uint16_t LCARS_ORANGE   = 0xFBE0;
static const uint16_t LCARS_SALMON   = 0xFB2C;
static const uint16_t LCARS_LAVENDER = 0xB57F;
static const uint16_t LCARS_BLUE     = 0x5D7F;
static const uint16_t LCARS_GREEN    = 0x6E6B;
static const uint16_t LCARS_RED      = 0xF9E7;
static const uint16_t LCARS_GOLD     = 0xFE60;
static const uint16_t LCARS_MUTED    = 0x7BEF;
static const uint16_t LCARS_WHITE    = 0xFFFF;

void drawScreen();
void drawHeader(const char *title);
void drawFooter();
void drawHome();
void drawControls();
void drawSensors();
void drawJarvis();
void drawStatus();
void drawSettings();
void drawClockSetup();
void handleTouch(int x, int y);

String twoDigits(uint8_t value) {
  return value < 10 ? "0" + String(value) : String(value);
}

String dayName(uint8_t day, uint8_t month, uint16_t year) {
  static const char *days[] = {"DOM", "SEG", "TER", "QUA", "QUI", "SEX", "SAB"};
  if (!watch || !watch->rtc) return "---";
  int dow = watch->rtc->getDayOfWeek(day, month, year);
  if (dow < 0 || dow > 6) return "---";
  return String(days[dow]);
}

int batteryPercent() {
  if (!watch || !watch->power || !watch->power->isBatteryConnect()) return -1;
  int p = watch->power->getBattPercentage();
  if (p < 0) p = 0;
  if (p > 100) p = 100;
  return p;
}

String uptimeText() {
  unsigned long total = (millis() - bootMillis) / 1000UL;
  unsigned long h = total / 3600UL;
  unsigned long m = (total % 3600UL) / 60UL;
  return String(h) + "h " + String(m) + "m";
}

void loadSettings() {
  prefs.begin("jarvis", false);
  brightnessLevel = prefs.getUChar("bright", 180);
  vibrationEnabled = prefs.getBool("vibrate", true);
  screenTimeoutSec = prefs.getUShort("timeout", 30);
  if (brightnessLevel < 40) brightnessLevel = 40;
  if (screenTimeoutSec != 0 && screenTimeoutSec < 10) screenTimeoutSec = 10;
}

void saveBrightness() {
  prefs.putUChar("bright", brightnessLevel);
}

void saveVibration() {
  prefs.putBool("vibrate", vibrationEnabled);
}

void saveTimeout() {
  prefs.putUShort("timeout", screenTimeoutSec);
}

void vibrateShort() {
  if (vibrationEnabled && watch && watch->motor) watch->motor->onec();
}

void wakeDisplay() {
  if (!screenAwake) {
    watch->openBL();
    watch->setBrightness(brightnessLevel);
    screenAwake = true;
    drawScreen();
  }
  lastInteraction = millis();
}

void sleepDisplay() {
  if (!screenAwake) return;
  watch->closeBL();
  screenAwake = false;
}

void showMessage(const String &message) {
  lastMessage = message;
  drawScreen();
}

void drawBatteryIcon(int x, int y, int percentage, bool charging) {
  const int w = 25;
  const int h = 11;
  uint16_t color = percentage >= 20 ? LCARS_TEXT : LCARS_RED;
  tft->drawRoundRect(x, y, w, h, 3, color);
  tft->fillRect(x + w, y + 3, 3, h - 6, color);
  if (percentage >= 0) {
    int fill = (w - 4) * percentage / 100;
    if (fill > 0) tft->fillRect(x + 2, y + 2, fill, h - 4, color);
  }
  if (charging) {
    tft->setTextColor(LCARS_ORANGE, LCARS_BG);
    tft->drawString("+", x - 8, y - 3, 2);
  }
}

void drawHeader(const char *title) {
  RTC_Date now = watch->rtc->getDateTime();
  int batt = batteryPercent();
  bool charging = watch->power && watch->power->isChargeing();

  tft->fillRect(0, 0, 240, 57, LCARS_BG);
  tft->fillRoundRect(5, 5, 230, 43, 14, LCARS_ORANGE);
  tft->fillRect(5, 25, 230, 23, LCARS_ORANGE);
  tft->fillRect(5, 42, 37, 12, LCARS_ORANGE);
  tft->fillRoundRect(5, 43, 37, 13, 7, LCARS_ORANGE);

  String hhmm = twoDigits(now.hour) + ":" + twoDigits(now.minute);
  tft->setTextColor(LCARS_TEXT, LCARS_ORANGE);
  tft->drawString(hhmm, 49, 7, 4);

  tft->drawRightString(title, 228, 9, 2);
  String dateText = dayName(now.day, now.month, now.year) + " " +
                    twoDigits(now.day) + "/" + twoDigits(now.month);
  tft->drawString(dateText, 49, 34, 1);

  drawBatteryIcon(178, 33, batt, charging);
  tft->setTextColor(LCARS_TEXT, LCARS_ORANGE);
  String battText = batt >= 0 ? String(batt) + "%" : "--%";
  tft->drawRightString(battText, 228, 32, 1);
}

void lcarsButton(int x, int y, int w, int h, uint16_t color, const char *label, const char *sub = nullptr) {
  tft->fillRoundRect(x, y, w, h, 10, color);
  tft->fillRect(x + 10, y + h - 6, w - 10, 6, color);
  tft->setTextColor(LCARS_TEXT, color);
  tft->drawCentreString(label, x + w / 2, y + 7, 2);
  if (sub) {
    tft->setTextColor(LCARS_TEXT, color);
    tft->drawCentreString(sub, x + w / 2, y + 25, 1);
  }
}

void drawFooter() {
  tft->fillRect(0, 207, 240, 33, LCARS_BG);
  if (currentScreen != SCREEN_HOME) {
    lcarsButton(5, 211, 72, 25, LCARS_LAVENDER, "VOLTAR");
    lcarsButton(82, 211, 72, 25, LCARS_ORANGE, "HOME");
  }

  uint16_t stateColor = jarvisBleIsConnected() ? LCARS_GREEN : LCARS_GOLD;
  tft->fillRoundRect(160, 211, 75, 25, 8, stateColor);
  tft->setTextColor(LCARS_TEXT, stateColor);
  tft->drawCentreString(jarvisBleIsConnected() ? "ONLINE" : "LOCAL", 197, 218, 1);
}

void drawMessageStrip() {
  tft->fillRoundRect(5, 181, 230, 22, 8, LCARS_WHITE);
  tft->drawRoundRect(5, 181, 230, 22, 8, LCARS_LAVENDER);
  tft->setTextColor(LCARS_TEXT, LCARS_WHITE);
  String msg = lastMessage;
  if (msg.length() > 34) msg = msg.substring(0, 31) + "...";
  tft->drawCentreString(msg, 120, 187, 1);
}

void drawHome() {
  drawHeader("JARVIS");

  lcarsButton(5,   62, 111, 51, LCARS_SALMON,   "CASA", "controles");
  lcarsButton(124, 62, 111, 51, LCARS_BLUE,     "SENSORES", "telemetria");
  lcarsButton(5,  119, 111, 51, LCARS_ORANGE,   "JARVIS", "comandos");
  lcarsButton(124,119, 111, 51, LCARS_LAVENDER, "STATUS", "hardware");

  drawMessageStrip();
  drawFooter();

  // Configuração acessível pelo segmento inferior direito.
  tft->fillRoundRect(160, 211, 75, 25, 8, LCARS_GREEN);
  tft->setTextColor(LCARS_TEXT, LCARS_GREEN);
  tft->drawCentreString("CONFIG", 197, 218, 1);
}

void drawControls() {
  drawHeader("CASA");
  lcarsButton(5,   62, 111, 51, LCARS_SALMON, "LUZ SALA", "alternar");
  lcarsButton(124, 62, 111, 51, LCARS_ORANGE, "LUZ QUARTO", "alternar");
  lcarsButton(5,  119, 111, 51, LCARS_BLUE, "PORTAO", "acionar");
  lcarsButton(124,119, 111, 51, LCARS_LAVENDER, "CENA NOITE", "executar");
  drawMessageStrip();
  drawFooter();
}

void drawSensors() {
  drawHeader("SENSORES");
  int batt = batteryPercent();
  bool charging = watch->power && watch->power->isChargeing();

  tft->fillRoundRect(8, 64, 224, 108, 14, LCARS_BLUE);
  tft->fillRoundRect(18, 73, 204, 90, 10, LCARS_BG);
  tft->setTextColor(LCARS_TEXT, LCARS_BG);
  tft->drawString("BATERIA", 27, 82, 2);
  tft->drawRightString(batt >= 0 ? (String(batt) + "%") : "--%", 211, 82, 2);
  tft->drawString("CARGA", 27, 106, 2);
  tft->drawRightString(charging ? "SIM" : "NAO", 211, 106, 2);
  tft->drawString("TEMPERATURA", 27, 130, 2);
  tft->drawRightString("REMOTA", 211, 130, 2);
  tft->drawString("COMUNICACAO", 27, 149, 1);
  tft->drawRightString(jarvisBleIsConnected() ? "ATIVA" : "OFFLINE", 211, 149, 1);
  drawFooter();
}

void sendPresetCommand(const String &command, const String &offlineText) {
  if (jarvisBleSendCommand(command)) showMessage("Comando enviado");
  else showMessage(offlineText);
}

void drawJarvis() {
  drawHeader("JARVIS");
  lcarsButton(5,   62, 111, 51, LCARS_ORANGE, "STATUS CASA", "consultar");
  lcarsButton(124, 62, 111, 51, LCARS_SALMON, "LUZES", "estado");
  lcarsButton(5,  119, 111, 51, LCARS_BLUE, "TEMPERAT.", "consultar");
  lcarsButton(124,119, 111, 51, LCARS_LAVENDER, "AJUDA", "comandos");
  drawMessageStrip();
  drawFooter();
}

void drawStatus() {
  drawHeader("STATUS");
  int batt = batteryPercent();

  tft->fillRoundRect(8, 64, 224, 112, 14, LCARS_LAVENDER);
  tft->fillRoundRect(18, 73, 204, 94, 10, LCARS_BG);
  tft->setTextColor(LCARS_TEXT, LCARS_BG);
  tft->drawString("T-WATCH", 27, 80, 2);
  tft->drawRightString("OK", 211, 80, 2);
  tft->drawString("TOUCH", 27, 104, 2);
  tft->drawRightString("ATIVO", 211, 104, 2);
  tft->drawString("BATERIA", 27, 128, 2);
  tft->drawRightString(batt >= 0 ? String(batt) + "%" : "--%", 211, 128, 2);
  tft->drawString("UPTIME", 27, 149, 1);
  tft->drawRightString(uptimeText(), 211, 149, 1);
  drawFooter();
}

String timeoutLabel() {
  if (screenTimeoutSec == 0) return "NUNCA";
  return String(screenTimeoutSec) + "s";
}

void drawSettings() {
  drawHeader("CONFIG");

  tft->setTextColor(LCARS_TEXT, LCARS_BG);
  tft->drawString("BRILHO", 10, 64, 2);
  lcarsButton(112, 60, 55, 32, LCARS_LAVENDER, "-");
  lcarsButton(174, 60, 61, 32, LCARS_ORANGE, "+");

  tft->drawString("VIBRACAO", 10, 101, 2);
  lcarsButton(124, 97, 111, 32, vibrationEnabled ? LCARS_GREEN : LCARS_RED,
              vibrationEnabled ? "LIGADA" : "DESLIG.");

  tft->drawString("TELA", 10, 138, 2);
  lcarsButton(124, 134, 111, 32, LCARS_BLUE, timeoutLabel().c_str());

  lcarsButton(5, 171, 111, 30, LCARS_GOLD, "AJUSTAR HORA");
  lcarsButton(124,171,111, 30, LCARS_SALMON, "PADRAO");

  drawFooter();
}

void drawClockSetup() {
  drawHeader("RELOGIO");
  RTC_Date now = watch->rtc->getDateTime();

  tft->setTextColor(LCARS_TEXT, LCARS_BG);
  tft->drawCentreString((twoDigits(now.hour) + ":" + twoDigits(now.minute)).c_str(), 120, 64, 4);
  tft->drawCentreString((twoDigits(now.day) + "/" + twoDigits(now.month) + "/" + String(now.year)).c_str(), 120, 94, 2);

  lcarsButton(5,   121, 52, 38, LCARS_LAVENDER, "H-");
  lcarsButton(62,  121, 52, 38, LCARS_ORANGE, "H+");
  lcarsButton(124, 121, 52, 38, LCARS_LAVENDER, "M-");
  lcarsButton(181, 121, 54, 38, LCARS_ORANGE, "M+");

  lcarsButton(5,   165, 111, 36, LCARS_BLUE, "DIA +");
  lcarsButton(124, 165, 111, 36, LCARS_SALMON, "MES +");
  drawFooter();
}

void drawScreen() {
  if (!tft || !screenAwake) return;
  tft->fillScreen(LCARS_BG);

  switch (currentScreen) {
    case SCREEN_CONTROLS: drawControls(); break;
    case SCREEN_SENSORS: drawSensors(); break;
    case SCREEN_JARVIS: drawJarvis(); break;
    case SCREEN_STATUS: drawStatus(); break;
    case SCREEN_SETTINGS: drawSettings(); break;
    case SCREEN_CLOCK: drawClockSetup(); break;
    case SCREEN_HOME:
    default: drawHome(); break;
  }
}

void goTo(ScreenId screen) {
  previousScreen = currentScreen;
  currentScreen = screen;
  drawScreen();
}

void goBack() {
  if (currentScreen == SCREEN_HOME) return;
  ScreenId target = previousScreen;
  if (target == currentScreen) target = SCREEN_HOME;
  currentScreen = target;
  previousScreen = SCREEN_HOME;
  drawScreen();
}

void adjustClock(int hourDelta, int minuteDelta, int dayDelta, int monthDelta) {
  RTC_Date now = watch->rtc->getDateTime();
  int hour = now.hour + hourDelta;
  int minute = now.minute + minuteDelta;
  int day = now.day + dayDelta;
  int month = now.month + monthDelta;

  if (hour < 0) hour = 23;
  if (hour > 23) hour = 0;
  if (minute < 0) minute = 59;
  if (minute > 59) minute = 0;
  if (day < 1) day = 31;
  if (day > 31) day = 1;
  if (month < 1) month = 12;
  if (month > 12) month = 1;

  watch->rtc->setDateTime(now.year, month, day, hour, minute, 0);
  drawClockSetup();
}

void cycleTimeout() {
  if (screenTimeoutSec == 15) screenTimeoutSec = 30;
  else if (screenTimeoutSec == 30) screenTimeoutSec = 60;
  else if (screenTimeoutSec == 60) screenTimeoutSec = 0;
  else screenTimeoutSec = 15;
  saveTimeout();
}

void resetSettings() {
  brightnessLevel = 180;
  vibrationEnabled = true;
  screenTimeoutSec = 30;
  saveBrightness();
  saveVibration();
  saveTimeout();
  watch->setBrightness(brightnessLevel);
  lastMessage = "Configuracao restaurada";
}

void handleFooterTouch(int x, int y) {
  if (y < 207) return;

  if (currentScreen == SCREEN_HOME) {
    if (x >= 155) goTo(SCREEN_SETTINGS);
    return;
  }

  if (x < 78) goBack();
  else if (x < 157) goTo(SCREEN_HOME);
}

void handleTouch(int x, int y) {
  lastInteraction = millis();
  if (millis() - lastTouch < 180) return;
  lastTouch = millis();

  vibrateShort();

  if (y >= 207) {
    handleFooterTouch(x, y);
    return;
  }

  switch (currentScreen) {
    case SCREEN_HOME:
      if (y >= 62 && y <= 113) {
        if (x < 120) goTo(SCREEN_CONTROLS);
        else goTo(SCREEN_SENSORS);
      } else if (y >= 119 && y <= 170) {
        if (x < 120) goTo(SCREEN_JARVIS);
        else goTo(SCREEN_STATUS);
      }
      break;

    case SCREEN_CONTROLS:
      if (y >= 62 && y <= 113) {
        if (x < 120) sendPresetCommand("Alterne a luz da sala", "Luz sala: offline");
        else sendPresetCommand("Alterne a luz do quarto", "Luz quarto: offline");
      } else if (y >= 119 && y <= 170) {
        if (x < 120) sendPresetCommand("Acione o portao", "Portao: offline");
        else sendPresetCommand("Ative a cena noite", "Cena noite: offline");
      }
      break;

    case SCREEN_JARVIS:
      if (y >= 62 && y <= 113) {
        if (x < 120) sendPresetCommand("Informe o status da casa", "JARVIS offline");
        else sendPresetCommand("Informe o estado das luzes", "JARVIS offline");
      } else if (y >= 119 && y <= 170) {
        if (x < 120) sendPresetCommand("Qual a temperatura atual dos sensores?", "JARVIS offline");
        else showMessage("Use os botoes para comandos rapidos");
      }
      break;

    case SCREEN_SETTINGS:
      if (y >= 58 && y <= 94) {
        if (x >= 108 && x < 171) {
          brightnessLevel = brightnessLevel > 60 ? brightnessLevel - 30 : 40;
          watch->setBrightness(brightnessLevel);
          saveBrightness();
          drawSettings();
        } else if (x >= 171) {
          brightnessLevel = brightnessLevel < 225 ? brightnessLevel + 30 : 255;
          watch->setBrightness(brightnessLevel);
          saveBrightness();
          drawSettings();
        }
      } else if (y >= 96 && y <= 131 && x >= 118) {
        vibrationEnabled = !vibrationEnabled;
        saveVibration();
        drawSettings();
      } else if (y >= 133 && y <= 168 && x >= 118) {
        cycleTimeout();
        drawSettings();
      } else if (y >= 170 && y <= 203) {
        if (x < 120) goTo(SCREEN_CLOCK);
        else {
          resetSettings();
          drawSettings();
        }
      }
      break;

    case SCREEN_CLOCK:
      if (y >= 119 && y <= 162) {
        if (x < 58) adjustClock(-1, 0, 0, 0);
        else if (x < 119) adjustClock(1, 0, 0, 0);
        else if (x < 179) adjustClock(0, -1, 0, 0);
        else adjustClock(0, 1, 0, 0);
      } else if (y >= 164 && y <= 203) {
        if (x < 120) adjustClock(0, 0, 1, 0);
        else adjustClock(0, 0, 0, 1);
      }
      break;

    case SCREEN_SENSORS:
    case SCREEN_STATUS:
    default:
      break;
  }
}

void setup() {
  Serial.begin(115200);
  bootMillis = millis();

  watch = TTGOClass::getWatch();
  watch->begin();
  watch->openBL();
  tft = watch->tft;

  if (watch->rtc) watch->rtc->check();

  if (watch->power) {
    watch->power->adc1Enable(
      AXP202_VBUS_VOL_ADC1 |
      AXP202_VBUS_CUR_ADC1 |
      AXP202_BATT_CUR_ADC1 |
      AXP202_BATT_VOL_ADC1,
      true
    );
  }

  loadSettings();
  watch->setBrightness(brightnessLevel);
  jarvisBleBegin();

  lastInteraction = millis();
  drawScreen();
}

void loop() {
  jarvisBleLoop();

  if (screenAwake && millis() - lastClockRefresh >= 1000) {
    lastClockRefresh = millis();
    if (currentScreen == SCREEN_HOME || currentScreen == SCREEN_STATUS || currentScreen == SCREEN_SENSORS) {
      drawScreen();
    } else {
      drawHeader(
        currentScreen == SCREEN_CONTROLS ? "CASA" :
        currentScreen == SCREEN_JARVIS ? "JARVIS" :
        currentScreen == SCREEN_SETTINGS ? "CONFIG" :
        currentScreen == SCREEN_CLOCK ? "RELOGIO" : "JARVIS"
      );
    }
  }

  if (screenTimeoutSec > 0 && screenAwake &&
      millis() - lastInteraction >= (unsigned long)screenTimeoutSec * 1000UL) {
    sleepDisplay();
  }

  int16_t x = 0;
  int16_t y = 0;
  if (watch->getTouch(x, y)) {
    if (!screenAwake) {
      wakeDisplay();
      delay(250);
    } else {
      handleTouch(x, y);
    }
  }

  delay(30);
}
