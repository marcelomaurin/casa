#include "jarvis_power_sm.h"

void JarvisPowerStateMachine::begin(const JarvisPowerHooks &hooks, bool screenInitiallyOn){
  hooks_ = hooks;
  state_ = screenInitiallyOn ? JARVIS_PWR_SCREEN_ON : JARVIS_PWR_SCREEN_OFF;
  lastInteraction_ = millis();
  screenOffAt_ = screenInitiallyOn ? 0 : millis();
}

void JarvisPowerStateMachine::setConfig(uint16_t timeoutSec, uint8_t powerMode){
  timeoutSec_ = timeoutSec;
  powerMode_ = powerMode;
}

void JarvisPowerStateMachine::markInteraction(unsigned long now){
  lastInteraction_ = now;
}

void JarvisPowerStateMachine::wake(){
  if(state_ == JARVIS_PWR_SCREEN_ON) return;
  state_ = JARVIS_PWR_SCREEN_ON;
  lastInteraction_ = millis();
  screenOffAt_ = 0;
  if(hooks_.screenOn) hooks_.screenOn();
}

void JarvisPowerStateMachine::sleep(){
  if(state_ != JARVIS_PWR_SCREEN_ON) return;
  state_ = JARVIS_PWR_SCREEN_OFF;
  screenOffAt_ = millis();
  if(hooks_.screenOff) hooks_.screenOff();
}

void JarvisPowerStateMachine::process(const JarvisEvent &event){
  switch(event.type){
    case EVT_BUTTON_SHORT:
      if(state_ == JARVIS_PWR_SCREEN_ON) sleep(); else wake();
      break;
    case EVT_USER_INTERACTION:
    case EVT_TOUCH_TAP:
    case EVT_SWIPE_LEFT:
    case EVT_SWIPE_RIGHT:
    case EVT_SWIPE_UP:
    case EVT_SWIPE_DOWN:
      if(state_ == JARVIS_PWR_SCREEN_ON) markInteraction();
      break;
    case EVT_SCREEN_TIMEOUT:
      sleep();
      break;
    case EVT_ALARM_TRIGGER:
    case EVT_CALL_INCOMING:
    case EVT_SOS:
      wake();
      break;
    default:
      break;
  }
}

void JarvisPowerStateMachine::update(unsigned long now){
  uint16_t effectiveTimeout = timeoutSec_;
  if(powerMode_ >= 2 && effectiveTimeout == 0) effectiveTimeout = 10;

  if(state_ == JARVIS_PWR_SCREEN_ON && effectiveTimeout > 0){
    if(now - lastInteraction_ >= (unsigned long)effectiveTimeout * 1000UL) sleep();
    return;
  }

  if(state_ == JARVIS_PWR_SCREEN_OFF && powerMode_ >= 2 && screenOffAt_ > 0){
    if(now - screenOffAt_ >= 120000UL){
      state_ = JARVIS_PWR_PREPARE_SLEEP;
      if(hooks_.deepSleep){
        state_ = JARVIS_PWR_DEEP_SLEEP;
        hooks_.deepSleep();
      }
    }
  }
}
