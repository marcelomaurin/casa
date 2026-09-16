#pragma once

#include <Arduino.h>

// Transporte local do JARVIS Watch:
//   Wi-Fi SoftAP: JARVIS-WATCH
//   IP:           192.168.4.1
//   TCP:          4040
//   Protocolo:    JSON UTF-8 delimitado por LF
//
// Os nomes jarvisBle* foram mantidos temporariamente por compatibilidade com
// o firmware principal. A implementação não usa Bluetooth/NimBLE/Bluedroid.
typedef void (*JarvisBleEventHandler)(
  const String &type,
  const String &title,
  const String &text
);

void jarvisBleBegin();
void jarvisBleLoop();
bool jarvisBleIsConnected();
bool jarvisBlePhoneInternet();
void jarvisBleSetEventHandler(JarvisBleEventHandler handler);
bool jarvisBleSendJson(const String &json);
bool jarvisBleSendCommand(const String &command);
