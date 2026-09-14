#pragma once

#include <Arduino.h>

// Camada de comunicacao isolada do firmware principal.
// Nesta versao standalone nao existe dependencia de BLE.
// A implementacao real podera ser recolocada apenas em jarvis_ble.cpp.

void jarvisBleBegin();
void jarvisBleLoop();
bool jarvisBleIsConnected();
bool jarvisBleSendCommand(const String &command);
