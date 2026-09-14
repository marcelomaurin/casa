#include "config.h"
#include "jarvis_ble.h"

TTGOClass *watch = nullptr;
TFT_eSPI *tft = nullptr;

unsigned long lastClockRefresh = 0;
String lastMessage = "Modo standalone iniciado";
String connectionState = "STANDALONE";

static const uint16_t LCARS_BG       = 0xFFDF;
static const uint16_t LCARS_TEXT     = 0x18C3;
static const uint16_t LCARS_ORANGE   = 0xFBE0;
static const uint16_t LCARS_SALMON   = 0xFB2C;
static const uint16_t LCARS_LAVENDER = 0xB57F;
static const uint16_t LCARS_BLUE     = 0x5D7F;
static const uint16_t LCARS_GREEN    = 0x6E6B;
static const uint16_t LCARS_RED      = 0xF9E7;
static const uint16_t LCARS_MUTED    = 0x7BEF;

void drawUi();
void drawClockStatus();
void drawMessagePanel();

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

void drawBatteryIcon(int x, int y, int percentage, bool charging) {
  const int w = 28;
  const int h = 12;
  uint16_t color = percentage >= 20 ? LCARS_TEXT : LCARS_RED;

  tft->drawRoundRect(x, y, w, h, 3, color);
  tft->fillRect(x + w, y + 3, 3, h - 6, color);

  if (percentage >= 0) {
    int fill = (w - 4) * percentage / 100;
    if (fill > 0) {
      tft->fillRect(x + 2, y + 2, fill, h - 4, color);
    }
  }

  if (charging) {
    tft->setTextColor(LCARS_ORANGE, LCARS_BG);
    tft->drawString("+", x - 9, y - 2, 2);
  }
}

void drawClockStatus() {
  if (!watch || !watch->rtc || !tft) return;

  RTC_Date now = watch->rtc->getDateTime();
  int batt = batteryPercent();
  bool charging = watch->power && watch->power->isChargeing();

  tft->fillRect(0, 0, 240, 76, LCARS_BG);

  tft->fillRoundRect(5, 5, 230, 48, 16, LCARS_ORANGE);
  tft->fillRect(5, 25, 230, 28, LCARS_ORANGE);
  tft->fillRect(5, 49, 42, 20, LCARS_ORANGE);
  tft->fillRoundRect(5, 54, 42, 22, 10, LCARS_ORANGE);

  String hhmm = twoDigits(now.hour) + ":" + twoDigits(now.minute);
  tft->setTextColor(LCARS_TEXT, LCARS_ORANGE);
  tft->drawString(hhmm, 55, 7, 4);

  String dateText = dayName(now.day, now.month, now.year) + "  " +
                    twoDigits(now.day) + "/" + twoDigits(now.month);
  tft->setTextColor(LCARS_TEXT, LCARS_ORANGE);
  tft->drawString(dateText, 55, 34, 2);
  tft->drawRightString("JARVIS", 226, 34, 2);

  uint16_t statusColor = jarvisBleIsConnected() ? LCARS_GREEN : LCARS_LAVENDER;
  connectionState = jarvisBleIsConnected() ? "BLE OK" : "STANDALONE";

  tft->fillRoundRect(52, 57, 91, 17, 8, statusColor);
  tft->setTextColor(LCARS_TEXT, statusColor);
  tft->drawCentreString(connectionState, 97, 59, 1);

  drawBatteryIcon(151, 59, batt, charging);
  tft->setTextColor(LCARS_TEXT, LCARS_BG);
  String battText = batt >= 0 ? String(batt) + "%" : "--%";
  tft->drawRightString(battText, 232, 59, 2);
}

void drawWrapped(const String &text, int y, uint16_t color = LCARS_TEXT) {
  tft->setTextColor(color, LCARS_BG);
  tft->setTextSize(1);

  const int maxChars = 31;
  int pos = 0;
  int line = 0;

  while (pos < (int)text.length() && line < 3) {
    int end = min(pos + maxChars, (int)text.length());

    if (end < (int)text.length()) {
      int space = text.lastIndexOf(' ', end);
      if (space > pos) end = space;
    }

    String part = text.substring(pos, end);
    part.trim();
    tft->drawString(part, 12, y + line * 14, 2);

    pos = end;
    while (pos < (int)text.length() && text[pos] == ' ') pos++;
    line++;
  }
}

void lcarsButton(int x, int y, int w, int h, uint16_t color, const char *label) {
  tft->fillRoundRect(x, y, w, h, 10, color);
  tft->fillRect(x + 9, y + h - 7, w - 9, 7, color);
  tft->setTextColor(LCARS_TEXT, color);
  tft->drawCentreString(label, x + (w / 2), y + 8, 2);
}

void drawMessagePanel() {
  if (!tft) return;

  tft->fillRoundRect(5, 164, 230, 73, 14, LCARS_LAVENDER);
  tft->fillRect(17, 164, 218, 73, LCARS_LAVENDER);
  tft->fillRoundRect(14, 170, 215, 61, 9, LCARS_BG);
  tft->setTextColor(LCARS_MUTED, LCARS_BG);
  tft->drawString("SISTEMA", 18, 173, 1);
  drawWrapped(lastMessage, 184, LCARS_TEXT);
}

void drawUi() {
  if (!tft) return;

  tft->fillScreen(LCARS_BG);
  drawClockStatus();

  lcarsButton(5,   82, 111, 32, LCARS_LAVENDER, "STATUS");
  lcarsButton(124, 82, 111, 32, LCARS_SALMON,   "LUZ SALA");
  lcarsButton(5,  122, 111, 32, LCARS_BLUE,     "TEMPERAT.");
  lcarsButton(124,122, 111, 32, LCARS_ORANGE,   "JARVIS");

  drawMessagePanel();
}

void vibrateShort() {
  if (watch && watch->motor) {
    watch->motor->onec();
  }
}

void showStandaloneMessage(const String &message) {
  lastMessage = message;
  drawMessagePanel();
}

void handleTouch(int x, int y) {
  if (y >= 82 && y <= 114) {
    if (x < 120) {
      int batt = batteryPercent();
      String status = "Standalone | Bateria ";
      status += batt >= 0 ? String(batt) + "%" : "--%";
      showStandaloneMessage(status);
    } else {
      if (!jarvisBleSendCommand("Alterne a luz da sala")) {
        showStandaloneMessage("Luz sala: comunicacao externa desativada");
      }
    }

    vibrateShort();
    delay(250);
    return;
  }

  if (y >= 122 && y <= 154) {
    if (x < 120) {
      if (!jarvisBleSendCommand("Qual a temperatura atual dos sensores?")) {
        showStandaloneMessage("Temperatura: aguardando modulo de comunicacao");
      }
    } else {
      if (!jarvisBleSendCommand("Informe o status da casa")) {
        showStandaloneMessage("JARVIS offline no modo standalone");
      }
    }

    vibrateShort();
    delay(250);
  }
}

void setup() {
  Serial.begin(115200);

  watch = TTGOClass::getWatch();
  watch->begin();
  watch->openBL();
  tft = watch->tft;

  if (watch->rtc) {
    watch->rtc->check();
  }

  if (watch->power) {
    watch->power->adc1Enable(
      AXP202_VBUS_VOL_ADC1 |
      AXP202_VBUS_CUR_ADC1 |
      AXP202_BATT_CUR_ADC1 |
      AXP202_BATT_VOL_ADC1,
      true
    );
  }

  jarvisBleBegin();
  drawUi();
}

void loop() {
  jarvisBleLoop();

  if (millis() - lastClockRefresh >= 1000) {
    lastClockRefresh = millis();
    drawClockStatus();
  }

  int16_t x = 0;
  int16_t y = 0;

  if (watch->getTouch(x, y)) {
    handleTouch(x, y);
  }

  delay(30);
}
