#include "config.h"
#include <NimBLEDevice.h>
#include <ArduinoJson.h>

TTGOClass *watch = nullptr;
TFT_eSPI *tft = nullptr;

static const NimBLEUUID serviceUUID(JARVIS_BLE_SERVICE_UUID);
static const NimBLEUUID rxUUID(JARVIS_BLE_RX_UUID);
static const NimBLEUUID txUUID(JARVIS_BLE_TX_UUID);

NimBLEClient *bleClient = nullptr;
NimBLERemoteCharacteristic *rxChar = nullptr;
NimBLERemoteCharacteristic *txChar = nullptr;
const NimBLEAdvertisedDevice *phoneDevice = nullptr;

bool connected = false;
bool connecting = false;
unsigned long lastReconnect = 0;
unsigned long lastClockRefresh = 0;
String rxBuffer;
String lastMessage = "Inicializando sistema...";
String connectionState = "OFFLINE";

// Protótipos explícitos: os callbacks BLE são declarados antes das funções de UI.
void drawUi();
void drawClockStatus();
void drawMessagePanel();
bool connectPhone();

// LCARS claro: alto contraste e boa leitura no display de 240x240.
static const uint16_t LCARS_BG       = 0xFFDF; // marfim claro
static const uint16_t LCARS_TEXT     = 0x18C3; // grafite
static const uint16_t LCARS_ORANGE   = 0xFBE0;
static const uint16_t LCARS_SALMON   = 0xFB2C;
static const uint16_t LCARS_LAVENDER = 0xB57F;
static const uint16_t LCARS_BLUE     = 0x5D7F;
static const uint16_t LCARS_GREEN    = 0x6E6B;
static const uint16_t LCARS_RED      = 0xF9E7;
static const uint16_t LCARS_MUTED    = 0x7BEF;

class ClientCallbacks : public NimBLEClientCallbacks {
  void onConnect(NimBLEClient *client) override {
    connected = true;
    connecting = false;
    connectionState = "BLE OK";
    lastMessage = "Celular conectado";
    drawUi();
  }

  void onDisconnect(NimBLEClient *client, int reason) override {
    connected = false;
    connecting = false;
    rxChar = nullptr;
    txChar = nullptr;
    phoneDevice = nullptr;
    connectionState = "SEM BLE";
    lastMessage = "Celular desconectado";
    drawUi();
  }
};

ClientCallbacks clientCallbacks;

class ScanCallbacks : public NimBLEScanCallbacks {
  void onResult(const NimBLEAdvertisedDevice *advertisedDevice) override {
    if (!advertisedDevice) return;
    if (!advertisedDevice->isAdvertisingService(serviceUUID)) return;

    phoneDevice = advertisedDevice;
    NimBLEDevice::getScan()->stop();
  }
};

ScanCallbacks scanCallbacks;

String twoDigits(uint8_t value) {
  return value < 10 ? "0" + String(value) : String(value);
}

String dayName(uint8_t day, uint8_t month, uint16_t year) {
  static const char *days[] = {"DOM", "SEG", "TER", "QUA", "QUI", "SEX", "SAB"};
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
    if (fill > 0) tft->fillRect(x + 2, y + 2, fill, h - 4, color);
  }

  if (charging) {
    tft->setTextColor(LCARS_ORANGE, LCARS_BG);
    tft->drawString("+", x - 9, y - 2, 2);
  }
}

void drawClockStatus() {
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

  uint16_t statusColor = connected ? LCARS_GREEN : (connecting ? LCARS_LAVENDER : LCARS_RED);
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
  tft->fillRoundRect(5, 164, 230, 73, 14, LCARS_LAVENDER);
  tft->fillRect(17, 164, 218, 73, LCARS_LAVENDER);
  tft->fillRoundRect(14, 170, 215, 61, 9, LCARS_BG);
  tft->setTextColor(LCARS_MUTED, LCARS_BG);
  tft->drawString("RESPOSTA", 18, 173, 1);
  drawWrapped(lastMessage, 184, LCARS_TEXT);
}

void drawUi() {
  tft->fillScreen(LCARS_BG);
  drawClockStatus();

  lcarsButton(5,   82, 111, 32, LCARS_LAVENDER, "STATUS");
  lcarsButton(124, 82, 111, 32, LCARS_SALMON,   "LUZ SALA");
  lcarsButton(5,  122, 111, 32, LCARS_BLUE,     "TEMPERAT.");
  lcarsButton(124,122, 111, 32, LCARS_ORANGE,   "JARVIS");

  drawMessagePanel();
}

void handlePhoneJson(const String &jsonText) {
  StaticJsonDocument<1024> doc;
  DeserializationError err = deserializeJson(doc, jsonText);
  if (err) {
    lastMessage = jsonText;
    drawUi();
    return;
  }

  const char *type = doc["type"] | "";
  bool ok = doc["ok"] | false;

  if (strcmp(type, "jarvis_result") == 0) {
    lastMessage = String(doc["text"] | "Sem resposta");
  } else if (strcmp(type, "status") == 0) {
    lastMessage = String(doc["message"] | (ok ? "Celular online" : "Celular offline"));
  } else if (strcmp(type, "queued") == 0) {
    lastMessage = "Offline: comando guardado no celular";
  } else if (strcmp(type, "pong") == 0) {
    lastMessage = "Ponte BLE OK";
  } else {
    lastMessage = jsonText;
  }
  drawUi();
}

static void notifyCallback(
  NimBLERemoteCharacteristic *characteristic,
  uint8_t *data,
  size_t length,
  bool isNotify
) {
  for (size_t i = 0; i < length; i++) {
    char c = (char)data[i];
    if (c == '\n') {
      if (rxBuffer.length() > 0) {
        handlePhoneJson(rxBuffer);
        rxBuffer = "";
      }
    } else {
      rxBuffer += c;
      if (rxBuffer.length() > 4096) rxBuffer = "";
    }
  }
}

bool connectPhone() {
  if (connecting || connected) return connected;

  connecting = true;
  connectionState = "PROCURANDO";
  drawClockStatus();

  NimBLEScan *scan = NimBLEDevice::getScan();
  scan->setScanCallbacks(&scanCallbacks, false);
  scan->setActiveScan(true);
  scan->setInterval(100);
  scan->setWindow(100);

  phoneDevice = nullptr;
  scan->start(4000, false, true);

  if (!phoneDevice) {
    connecting = false;
    connectionState = "SEM BLE";
    drawClockStatus();
    return false;
  }

  if (!bleClient) {
    bleClient = NimBLEDevice::createClient();
    bleClient->setClientCallbacks(&clientCallbacks, false);
  }

  if (!bleClient->connect(phoneDevice)) {
    connecting = false;
    connectionState = "FALHA BLE";
    drawClockStatus();
    return false;
  }

  NimBLERemoteService *service = bleClient->getService(serviceUUID);
  if (!service) {
    bleClient->disconnect();
    connecting = false;
    return false;
  }

  rxChar = service->getCharacteristic(rxUUID);
  txChar = service->getCharacteristic(txUUID);
  if (!rxChar || !txChar) {
    bleClient->disconnect();
    connecting = false;
    return false;
  }

  if (txChar->canNotify()) {
    if (!txChar->subscribe(true, notifyCallback)) {
      bleClient->disconnect();
      connecting = false;
      connectionState = "FALHA NOTIFY";
      drawClockStatus();
      return false;
    }
  }

  connected = true;
  connecting = false;
  connectionState = "BLE OK";
  lastMessage = "Ponte BLE pronta";
  drawUi();
  return true;
}

bool sendJson(const String &json) {
  if (!connected || !rxChar) {
    lastMessage = "Celular nao conectado";
    drawMessagePanel();
    return false;
  }

  bool ok = rxChar->writeValue(
    (const uint8_t *)json.c_str(),
    json.length(),
    true
  );

  if (!ok) {
    connected = false;
    connectionState = "FALHA ENVIO";
    lastMessage = "Falha ao enviar para o celular";
    drawUi();
    return false;
  }

  return true;
}

void sendJarvis(const String &text) {
  StaticJsonDocument<384> doc;
  doc["type"] = "jarvis";
  doc["text"] = text;
  doc["source"] = "watch";
  doc["protocol"] = JARVIS_PROTOCOL_VERSION;
  String json;
  serializeJson(doc, json);
  lastMessage = "Enviando ao celular...";
  drawMessagePanel();
  sendJson(json);
}

void sendStatus() {
  lastMessage = "Consultando estado...";
  drawMessagePanel();
  sendJson("{\"type\":\"status\",\"source\":\"watch\"}");
}

void vibrateShort() {
  watch->motor->onec();
}

void handleTouch(int x, int y) {
  if (y >= 82 && y <= 114) {
    if (x < 120) {
      sendStatus();
    } else {
      sendJarvis("Alterne a luz da sala");
    }
    vibrateShort();
    delay(250);
    return;
  }

  if (y >= 122 && y <= 154) {
    if (x < 120) {
      sendJarvis("Qual a temperatura atual dos sensores?");
    } else {
      sendJarvis("Informe o status da casa");
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

  NimBLEDevice::init(JARVIS_WATCH_NAME);
  drawUi();
  connectPhone();
}

void loop() {
  if (!connected && millis() - lastReconnect >= JARVIS_RECONNECT_MS) {
    lastReconnect = millis();
    connectPhone();
  }

  if (millis() - lastClockRefresh >= 1000) {
    lastClockRefresh = millis();
    drawClockStatus();
  }

  int16_t x = 0, y = 0;
  if (watch->getTouch(x, y)) {
    handleTouch(x, y);
  }

  delay(30);
}
