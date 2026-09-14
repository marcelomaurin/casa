#include "config.h"
#include <BLEDevice.h>
#include <BLEUtils.h>
#include <BLEScan.h>
#include <BLEAdvertisedDevice.h>
#include <ArduinoJson.h>

TTGOClass *watch = nullptr;
TFT_eSPI *tft = nullptr;

static BLEUUID serviceUUID(JARVIS_BLE_SERVICE_UUID);
static BLEUUID rxUUID(JARVIS_BLE_RX_UUID);
static BLEUUID txUUID(JARVIS_BLE_TX_UUID);

BLEClient *bleClient = nullptr;
BLERemoteCharacteristic *rxChar = nullptr;
BLERemoteCharacteristic *txChar = nullptr;
BLEAdvertisedDevice *phoneDevice = nullptr;

bool connected = false;
bool connecting = false;
unsigned long lastReconnect = 0;
String rxBuffer;
String lastMessage = "Inicializando sistema...";
String connectionState = "OFFLINE";

// LCARS claro: pensado para boa leitura no relógio em ambientes claros e escuros.
static const uint16_t LCARS_BG       = 0xFFDF; // marfim claro
static const uint16_t LCARS_TEXT     = 0x18C3; // grafite
static const uint16_t LCARS_ORANGE   = 0xFBE0;
static const uint16_t LCARS_SALMON   = 0xFB2C;
static const uint16_t LCARS_LAVENDER = 0xB57F;
static const uint16_t LCARS_BLUE     = 0x5D7F;
static const uint16_t LCARS_GREEN    = 0x6E6B;
static const uint16_t LCARS_RED      = 0xF9E7;
static const uint16_t LCARS_MUTED    = 0x7BEF;

class ClientCallbacks : public BLEClientCallbacks {
  void onConnect(BLEClient *client) override {
    connected = true;
    connecting = false;
    connectionState = "CELULAR CONECTADO";
  }

  void onDisconnect(BLEClient *client) override {
    connected = false;
    connecting = false;
    rxChar = nullptr;
    txChar = nullptr;
    connectionState = "SEM CELULAR";
  }
};

class ScanCallbacks : public BLEAdvertisedDeviceCallbacks {
  void onResult(BLEAdvertisedDevice advertisedDevice) override {
    if (!advertisedDevice.haveServiceUUID()) return;
    if (!advertisedDevice.isAdvertisingService(serviceUUID)) return;

    if (phoneDevice) {
      delete phoneDevice;
      phoneDevice = nullptr;
    }
    phoneDevice = new BLEAdvertisedDevice(advertisedDevice);
    BLEDevice::getScan()->stop();
  }
};

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
    tft->drawString(part, 12, y + line * 15, 2);
    pos = end;
    while (pos < (int)text.length() && text[pos] == ' ') pos++;
    line++;
  }
}

void lcarsButton(int x, int y, int w, int h, uint16_t color, const char *label) {
  tft->fillRoundRect(x, y, w, h, 11, color);
  // O corte inferior dá aparência de segmento LCARS, sem imitar uma janela comum.
  tft->fillRect(x + 10, y + h - 8, w - 10, 8, color);
  tft->setTextColor(LCARS_TEXT, color);
  tft->drawCentreString(label, x + (w / 2), y + 11, 2);
}

void drawUi() {
  tft->fillScreen(LCARS_BG);

  // Cabeçalho LCARS: a própria moldura funciona como indicador de estado.
  tft->fillRoundRect(5, 5, 230, 43, 16, LCARS_ORANGE);
  tft->fillRect(5, 25, 230, 23, LCARS_ORANGE);
  tft->fillRect(5, 44, 48, 20, LCARS_ORANGE);
  tft->fillRoundRect(5, 49, 48, 27, 12, LCARS_ORANGE);

  tft->setTextColor(LCARS_TEXT, LCARS_ORANGE);
  tft->drawString("JARVIS", 66, 10, 4);
  tft->setTextSize(1);
  tft->drawRightString("CASA", 226, 31, 2);

  uint16_t statusColor = connected ? LCARS_GREEN : LCARS_RED;
  tft->fillRoundRect(60, 52, 175, 20, 9, statusColor);
  tft->setTextColor(LCARS_TEXT, statusColor);
  tft->drawCentreString(connectionState, 147, 55, 2);

  // Quatro funções principais. Grandes o suficiente para toque com o dedo.
  lcarsButton(5,   82, 111, 42, LCARS_LAVENDER, "STATUS");
  lcarsButton(124, 82, 111, 42, LCARS_SALMON,   "LUZ SALA");
  lcarsButton(5,  132, 111, 42, LCARS_BLUE,     "TEMPERAT.");
  lcarsButton(124,132, 111, 42, LCARS_ORANGE,   "JARVIS");

  // Moldura inferior usada como área funcional de resposta do sistema.
  tft->fillRoundRect(5, 182, 230, 55, 14, LCARS_LAVENDER);
  tft->fillRect(17, 182, 218, 55, LCARS_LAVENDER);
  tft->fillRoundRect(14, 188, 215, 43, 9, LCARS_BG);
  tft->setTextColor(LCARS_MUTED, LCARS_BG);
  tft->drawString("RESPOSTA", 18, 190, 1);
  drawWrapped(lastMessage, 201, LCARS_TEXT);
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
  BLERemoteCharacteristic *characteristic,
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
  connectionState = "PROCURANDO CELULAR";
  drawUi();

  BLEScan *scan = BLEDevice::getScan();
  scan->setAdvertisedDeviceCallbacks(new ScanCallbacks(), true);
  scan->setActiveScan(true);
  phoneDevice = nullptr;
  scan->start(4, false);

  if (!phoneDevice) {
    connecting = false;
    connectionState = "SEM CELULAR";
    drawUi();
    return false;
  }

  if (!bleClient) {
    bleClient = BLEDevice::createClient();
    bleClient->setClientCallbacks(new ClientCallbacks());
  }

  if (!bleClient->connect(phoneDevice)) {
    connecting = false;
    connectionState = "FALHA BLE";
    drawUi();
    return false;
  }

  BLERemoteService *service = bleClient->getService(serviceUUID);
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

  if (txChar->canNotify()) txChar->registerForNotify(notifyCallback);

  connected = true;
  connecting = false;
  connectionState = "CELULAR CONECTADO";
  lastMessage = "Ponte BLE pronta";
  drawUi();
  return true;
}

bool sendJson(const String &json) {
  if (!connected || !rxChar) {
    lastMessage = "Celular nao conectado";
    drawUi();
    return false;
  }
  rxChar->writeValue((uint8_t *)json.c_str(), json.length(), true);
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
  drawUi();
  sendJson(json);
}

void sendStatus() {
  sendJson("{\"type\":\"status\",\"source\":\"watch\"}");
}

void vibrateShort() {
  watch->motor->onec();
}

void handleTouch(int x, int y) {
  if (y >= 82 && y <= 124) {
    if (x < 120) {
      sendStatus();
    } else {
      sendJarvis("Alterne a luz da sala");
    }
    vibrateShort();
    delay(250);
    return;
  }

  if (y >= 132 && y <= 174) {
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

  BLEDevice::init(JARVIS_WATCH_NAME);
  drawUi();
  connectPhone();
}

void loop() {
  if (!connected && millis() - lastReconnect >= JARVIS_RECONNECT_MS) {
    lastReconnect = millis();
    connectPhone();
  }

  int16_t x = 0, y = 0;
  if (watch->getTouch(x, y)) {
    handleTouch(x, y);
  }

  delay(30);
}
