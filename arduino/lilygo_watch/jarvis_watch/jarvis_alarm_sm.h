#pragma once

#include <Arduino.h>
#include "jarvis_events.h"

enum JarvisAlarmState : uint8_t {
  JARVIS_ALARM_IDLE = 0,
  JARVIS_ALARM_WAITING,
  JARVIS_ALARM_RINGING,
  JARVIS_ALARM_SNOOZE
};

enum JarvisAlarmTone : uint8_t {
  JARVIS_ALARM_SHORT = 0,
  JARVIS_ALARM_DOUBLE,
  JARVIS_ALARM_URGENT,
  JARVIS_ALARM_PHONE
};

struct JarvisAlarmHooks {
  void (*vibrateOnce)() = nullptr;
  void (*phoneSound)() = nullptr;
};

class JarvisAlarmStateMachine {
public:
  void begin(JarvisEventQueue *queue, const JarvisAlarmHooks &hooks);
  void setConfig(bool enabled, uint8_t hour, uint8_t minute, JarvisAlarmTone tone);
  void process(const JarvisEvent &event);
  void update(unsigned long now = millis());
  void stop();

  JarvisAlarmState state() const { return state_; }
  bool ringing() const { return state_ == JARVIS_ALARM_RINGING; }

private:
  void trigger();
  unsigned long pulseGap(uint8_t pulse) const;
  uint8_t pulseCount() const;

  JarvisEventQueue *queue_ = nullptr;
  JarvisAlarmHooks hooks_;
  JarvisAlarmState state_ = JARVIS_ALARM_IDLE;
  JarvisAlarmTone tone_ = JARVIS_ALARM_DOUBLE;
  bool enabled_ = false;
  uint8_t hour_ = 7;
  uint8_t minute_ = 0;
  int lastDay_ = -1;
  uint8_t pulse_ = 0;
  unsigned long nextPulseAt_ = 0;
};
