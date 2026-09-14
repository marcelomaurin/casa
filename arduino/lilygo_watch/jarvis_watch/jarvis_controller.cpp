#include "jarvis_controller.h"

void JarvisController::begin(
  const JarvisPowerHooks &powerHooks,
  const JarvisAlarmHooks &alarmHooks,
  const JarvisControllerHooks &hooks,
  bool screenInitiallyOn
){
  hooks_ = hooks;
  queue_.clear();
  power_.begin(powerHooks, screenInitiallyOn);
  alarm_.begin(&queue_, alarmHooks);
  network_.begin(&queue_);
  voiceState_ = JARVIS_VOICE_IDLE;
  callState_ = JARVIS_CALL_IDLE;
}

bool JarvisController::emit(const JarvisEvent &event){
  return queue_.push(event);
}

void JarvisController::dispatch(const JarvisEvent &event){
  power_.process(event);
  alarm_.process(event);
  network_.process(event);

  switch(event.type){
    case EVT_VOICE_START: voiceState_ = JARVIS_VOICE_LISTENING; break;
    case EVT_VOICE_STOP: voiceState_ = JARVIS_VOICE_IDLE; break;
    case EVT_VOICE_RESULT: voiceState_ = JARVIS_VOICE_PLAYING; break;
    case EVT_CALL_START: callState_ = JARVIS_CALL_DIALING; break;
    case EVT_CALL_INCOMING: callState_ = JARVIS_CALL_RINGING; break;
    case EVT_CALL_ACCEPT: callState_ = JARVIS_CALL_CONNECTING; break;
    case EVT_CALL_END: callState_ = JARVIS_CALL_IDLE; break;
    default: break;
  }

  if(hooks_.onEvent) hooks_.onEvent(event);
}

void JarvisController::update(unsigned long now){
  power_.update(now);
  alarm_.update(now);
  network_.update(now);

  // Limita eventos por ciclo para impedir starvation da interface/touch.
  JarvisEvent event;
  uint8_t processed = 0;
  while(processed < 12 && queue_.pop(event)){
    dispatch(event);
    processed++;
  }
}
