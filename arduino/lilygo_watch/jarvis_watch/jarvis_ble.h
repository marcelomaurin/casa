#pragma once

#include <Arduino.h>

// T-Watch = BLE Peripheral / GATT Server.
// Android = BLE Central / GATT Client.
//
// Service:
//   7a9f1000-3a8c-4b62-9e5f-1b0c0e91a001
// Phone -> Watch (WRITE):
//   7a9f1001-3a8c-4b62-9e5f-1b0c0e91a001
// Watch -> Phone (NOTIFY/READ):
//   7a9f1002-3a8c-4b62-9e5f-1b0c0e91a001
//
// O callback abaixo sempre e executado por jarvisBleLoop(), nunca diretamente
// pela task interna do Bluetooth. Assim a UI/TFT pode ser manipulada com seguranca.
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

// Envia JSON ja pronto ao Android.
bool jarvisBleSendJson(const String &json);

// Compatibilidade com o firmware principal: converte comandos conhecidos para
// o protocolo JSON v2 e usa "voice_text" para comandos em linguagem natural.
bool jarvisBleSendCommand(const String &command);
