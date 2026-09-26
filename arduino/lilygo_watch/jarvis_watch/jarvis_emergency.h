#pragma once
#include <Arduino.h>

enum JarvisEmergencyState { EMERGENCY_GREEN, EMERGENCY_YELLOW, EMERGENCY_RED };
void jarvisEmergencyBegin();
void jarvisEmergencyLoop();
void jarvisEmergencyTap();
// True means the UI may return to the clock.
bool jarvisEmergencySwipeDown();
void jarvisEmergencyCancelConfirmation();
void jarvisEmergencyReceiveGps(const String &payload);
JarvisEmergencyState jarvisEmergencyState();
bool jarvisEmergencyConfirming();
bool jarvisEmergencyBusy();
bool jarvisEmergencySpeaking();
String jarvisEmergencyStatus();
