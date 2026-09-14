#pragma once

#include <Arduino.h>

void jarvisAssistanceBegin(uint32_t initialSteps);
void jarvisAssistanceLoop(uint32_t currentSteps);
void jarvisAssistanceMarkInteraction();

bool jarvisAssistanceSendSos();
bool jarvisAssistanceSendCheckin(bool ok = true);
bool jarvisAssistanceStartFamilyCall(bool video = true);

unsigned long jarvisAssistanceMinutesWithoutMovement();
bool jarvisAssistanceAlertActive();
void jarvisAssistanceAcknowledge();

void jarvisAssistanceSetEnabled(bool enabled);
bool jarvisAssistanceEnabled();
void jarvisAssistanceSetInactivityMinutes(uint16_t minutes);
uint16_t jarvisAssistanceInactivityMinutes();
