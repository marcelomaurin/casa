#include "jarvis_ble.h"

// IMPORTANTE:
// O T-Watch permanece sem dependencia de NimBLE-Arduino, ESP32_BLE_Arduino
// ou ArduinoBLE. Esta camada fica isolada para manter o firmware pequeno e
// permitir reativar o transporte depois sem alterar a UI e as maquinas de estado.

static JarvisBleEventHandler eventHandler = nullptr;

void jarvisBleBegin() {
  // Transporte Bluetooth desativado nesta build compacta.
}

void jarvisBleLoop() {
  // Reservado para o transporte futuro.
}

bool jarvisBleIsConnected() {
  return false;
}

bool jarvisBlePhoneInternet() {
  return false;
}

void jarvisBleSetEventHandler(JarvisBleEventHandler handler) {
  eventHandler = handler;
  (void)eventHandler;
}

bool jarvisBleSendJson(const String &json) {
  (void)json;
  return false;
}

bool jarvisBleSendCommand(const String &command) {
  (void)command;
  return false;
}
