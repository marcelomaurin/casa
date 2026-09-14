#pragma once

#include <Arduino.h>
#include "jarvis_events.h"
#include "jarvis_power_sm.h"
#include "jarvis_alarm_sm.h"
#include "jarvis_network_sm.h"

enum JarvisVoiceState : uint8_t {
  JARVIS_VOICE_IDLE = 0,
  JARVIS_VOICE_PREPARING,
  JARVIS_VOICE_LISTENING,
  JARVIS_VOICE_SENDING,
  JARVIS_VOICE_WAITING,
  JARVIS_VOICE_PLAYING,
  JARVIS_VOICE_ERROR
};

enum JarvisCallState : uint8_t {
  JARVIS_CALL_IDLE = 0,
  JARVIS_CALL_DIALING,
  JARVIS_CALL_RINGING,
  JARVIS_CALL_CONNECTING,
  JARVIS_CALL_ACTIVE,
  JARVIS_CALL_ENDING,
  JARVIS_CALL_ERROR
};

struct JarvisControllerHooks {
  void (*onEvent)(const JarvisEvent &event) = nullptr;
};

class JarvisController {
public:
  void begin(
    const JarvisPowerHooks &powerHooks,
    const JarvisAlarmHooks &alarmHooks,
    const JarvisControllerHooks &hooks,
    bool screenInitiallyOn = true
  );

  bool emit(const JarvisEvent &event);
  void update(unsigned long now = millis());

  JarvisEventQueue &events(){ return queue_; }
  JarvisPowerStateMachine &power(){ return power_; }
  JarvisAlarmStateMachine &alarm(){ return alarm_; }
  JarvisNetworkStateMachine &network(){ return network_; }

  JarvisVoiceState voiceState() const { return voiceState_; }
  JarvisCallState callState() const { return callState_; }
  void setVoiceState(JarvisVoiceState state){ voiceState_ = state; }
  void setCallState(JarvisCallState state){ callState_ = state; }

  uint32_t droppedEvents() const { return queue_.dropped(); }

private:
  void dispatch(const JarvisEvent &event);

  JarvisEventQueue queue_;
  JarvisPowerStateMachine power_;
  JarvisAlarmStateMachine alarm_;
  JarvisNetworkStateMachine network_;
  JarvisControllerHooks hooks_;
  JarvisVoiceState voiceState_ = JARVIS_VOICE_IDLE;
  JarvisCallState callState_ = JARVIS_CALL_IDLE;
};
