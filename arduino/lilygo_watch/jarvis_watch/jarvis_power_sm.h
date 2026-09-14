#pragma once

#include <Arduino.h>
#include "jarvis_events.h"

enum JarvisPowerState : uint8_t {
  JARVIS_PWR_SCREEN_ON = 0,
  JARVIS_PWR_SCREEN_OFF,
  JARVIS_PWR_PREPARE_SLEEP,
  JARVIS_PWR_DEEP_SLEEP
};

struct JarvisPowerHooks {
  void (*screenOn)() = nullptr;
  void (*screenOff)() = nullptr;
  void (*deepSleep)() = nullptr;
};

class JarvisPowerStateMachine {
public:
  void begin(const JarvisPowerHooks &hooks, bool screenInitiallyOn = true);
  void setConfig(uint16_t timeoutSec, uint8_t powerMode);
  void markInteraction(unsigned long now = millis());
  void process(const JarvisEvent &event);
  void update(unsigned long now = millis());

  JarvisPowerState state() const { return state_; }
  bool screenAwake() const { return state_ == JARVIS_PWR_SCREEN_ON; }
  unsigned long lastInteraction() const { return lastInteraction_; }

private:
  void wake();
  void sleep();

  JarvisPowerHooks hooks_;
  JarvisPowerState state_ = JARVIS_PWR_SCREEN_ON;
  uint16_t timeoutSec_ = 20;
  uint8_t powerMode_ = 1;
  unsigned long lastInteraction_ = 0;
  unsigned long screenOffAt_ = 0;
};
