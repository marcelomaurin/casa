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
String lastMessage = "Inicializando...";
String connectionState = "OFFLINE";

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

void drawWrapped(const String &text, int y, uint16_t color = TFT_WHITE) {
  tft->setTextColor(color, TFT_BLACK);
  tft->setTextSize(1);
  const int maxChars = 32;
  int pos = 0;
  int line = 0;
  while (pos < (int)text.length() && line < 8) {
    int end = min(pos + maxChars, (int)text.length());
    if (end < (int)text.length()) {
      int space = text.lastIndexOf(' ', end);
      if (space > pos) end = space;
    }
    String part = text.substring(pos, end);
    part.trim();
    tft->drawString(part, 8, y + line * 16, 2);
    pos = end;
    while (pos < (int)text.length() && text[pos] == ' ') pos++;
    line++;
  }
}

void drawUi() {
  tft->fillScreen(TFT_BLACK);
  tft->setTextColor(TFT_CYAN, TFT_BLACK);
  tft->setTextSize(2);
  tft->drawString("JARVIS", 10, 8, 2);

  tft->setTextSize(1);
  uint16_t statusColor = connected ? TFT_GREEN : TFT_RED;
  tft->setTextColor(statusColor, TFT_BLACK);
  tft->drawString(connectionState, 10, 38, 2);

  tft->drawRoundRect(8, 68, 108, 48, 8, TFT_BLUE);
  tft->drawRoundRect(124, 68, 108, 48, 8, TFT_BLUE);
  tft->drawRoundRect(8, 124, 108, 48, 8, TFT_BLUE);
  tft->drawRoundRect(124, 124, 108, 48, 8, TFT_BLUE);

  tft->setTextColor(TFT_WHITE, TFT_BLACK);
  tft->drawCentreString("STATUS", 62, 84, 2);
  tft->drawCentreString("LUZ SALA", 178, 84, 2);
  tft->drawCentreString("TEMPERAT.", 62, 140, 2);
  tft->drawCentreString("JARVIS", 178, 140, 2);

  tft->drawFastHLine(8, 182, 224, TFT_DARKGREY);
  drawWrapped(lastMessage, 190, TFT_LIGHTGREY);
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
    lastMessage = String("Offline: comando guardado no celular");
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
  if (y >= 68 && y <= 116) {
    if (x < 120) {
      sendStatus();
    } else {
      sendJarvis("Alterne a luz da sala");
    }
    vibrateShort();
    delay(250);
    return;
  }

  if (y >= 124 && y <= 172) {
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
