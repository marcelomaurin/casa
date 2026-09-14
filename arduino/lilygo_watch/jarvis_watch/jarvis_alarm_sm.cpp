#include "jarvis_alarm_sm.h"

void JarvisAlarmStateMachine::begin(JarvisEventQueue *queue, const JarvisAlarmHooks &hooks){
  queue_ = queue;
  hooks_ = hooks;
  state_ = enabled_ ? JARVIS_ALARM_WAITING : JARVIS_ALARM_IDLE;
}

void JarvisAlarmStateMachine::setConfig(bool enabled, uint8_t hour, uint8_t minute, JarvisAlarmTone tone){
  enabled_ = enabled;
  hour_ = hour > 23 ? 0 : hour;
  minute_ = minute > 59 ? 0 : minute;
  tone_ = tone;
  if(!enabled_ && state_ != JARVIS_ALARM_RINGING) state_ = JARVIS_ALARM_IDLE;
  if(enabled_ && state_ == JARVIS_ALARM_IDLE) state_ = JARVIS_ALARM_WAITING;
}

void JarvisAlarmStateMachine::trigger(){
  if(state_ == JARVIS_ALARM_RINGING) return;
  state_ = JARVIS_ALARM_RINGING;
  pulse_ = 0;
  nextPulseAt_ = millis();
  if(tone_ == JARVIS_ALARM_PHONE && hooks_.phoneSound) hooks_.phoneSound();
  if(queue_) queue_->push(jarvisEvent(EVT_ALARM_TRIGGER, JARVIS_PRI_HIGH));
}

void JarvisAlarmStateMachine::stop(){
  state_ = enabled_ ? JARVIS_ALARM_WAITING : JARVIS_ALARM_IDLE;
  pulse_ = 0;
  nextPulseAt_ = 0;
}

uint8_t JarvisAlarmStateMachine::pulseCount() const{
  switch(tone_){
    case JARVIS_ALARM_SHORT: return 2;
    case JARVIS_ALARM_DOUBLE: return 6;
    case JARVIS_ALARM_URGENT: return 14;
    case JARVIS_ALARM_PHONE: return 3;
  }
  return 3;
}

unsigned long JarvisAlarmStateMachine::pulseGap(uint8_t pulse) const{
  if(tone_ == JARVIS_ALARM_URGENT) return (pulse % 3 == 2) ? 650UL : 160UL;
  if(tone_ == JARVIS_ALARM_DOUBLE) return (pulse % 2 == 1) ? 700UL : 220UL;
  if(tone_ == JARVIS_ALARM_SHORT) return 500UL;
  return 750UL;
}

void JarvisAlarmStateMachine::process(const JarvisEvent &event){
  if(event.type == EVT_ALARM_STOP){
    stop();
    return;
  }

  if(event.type != EVT_TICK_1S || !enabled_ || state_ == JARVIS_ALARM_RINGING) return;

  int day = event.value1;
  uint32_t packed = (uint32_t)event.value2;
  uint8_t hour = (packed >> 16) & 0xFF;
  uint8_t minute = (packed >> 8) & 0xFF;
  uint8_t second = packed & 0xFF;

  if(hour == hour_ && minute == minute_ && second < 2 && lastDay_ != day){
    lastDay_ = day;
    trigger();
  }
}

void JarvisAlarmStateMachine::update(unsigned long now){
  if(state_ != JARVIS_ALARM_RINGING || now < nextPulseAt_) return;

  if(pulse_ >= pulseCount()){
    // Continua em RINGING até confirmação; apenas para o padrão repetitivo.
    pulse_ = 0;
    nextPulseAt_ = now + (tone_ == JARVIS_ALARM_URGENT ? 1200UL : 2500UL);
    return;
  }

  if(hooks_.vibrateOnce) hooks_.vibrateOnce();
  nextPulseAt_ = now + pulseGap(pulse_);
  pulse_++;
}
