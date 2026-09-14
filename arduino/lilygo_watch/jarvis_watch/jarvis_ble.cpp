#include "jarvis_ble.h"

// Implementacao standalone.
// Nenhuma biblioteca BLE e incluida aqui.
// Retorna desconectado ate a camada de comunicacao ser reativada.

void jarvisBleBegin() {
  // Reservado para inicializacao futura da ponte Android.
}

void jarvisBleLoop() {
  // Reservado para processamento futuro da comunicacao.
}

bool jarvisBleIsConnected() {
  return false;
}

bool jarvisBleSendCommand(const String &command) {
  (void)command;
  return false;
}
