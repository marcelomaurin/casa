#include "jarvis_events.h"

JarvisEvent jarvisEvent(
  JarvisEventType type,
  JarvisEventPriority priority,
  int32_t value1,
  int32_t value2,
  const String &text
){
  JarvisEvent e;
  e.type = type;
  e.priority = priority;
  e.value1 = value1;
  e.value2 = value2;
  e.text = text;
  e.createdAt = millis();
  return e;
}

bool JarvisEventQueue::push(const JarvisEvent &event){
  if(count_ >= CAPACITY){
    // Eventos críticos podem substituir o evento de menor prioridade.
    if(event.priority == JARVIS_PRI_CRITICAL){
      int lowest = -1;
      for(uint8_t i=0;i<count_;i++){
        if(lowest < 0 || items_[i].priority < items_[lowest].priority) lowest = i;
      }
      if(lowest >= 0 && items_[lowest].priority < JARVIS_PRI_CRITICAL){
        items_[lowest] = event;
        dropped_++;
        return true;
      }
    }
    dropped_++;
    return false;
  }
  items_[count_++] = event;
  return true;
}

bool JarvisEventQueue::pop(JarvisEvent &event){
  if(count_ == 0) return false;

  uint8_t best = 0;
  for(uint8_t i=1;i<count_;i++){
    if(items_[i].priority > items_[best].priority){
      best = i;
    } else if(items_[i].priority == items_[best].priority && items_[i].createdAt < items_[best].createdAt){
      best = i;
    }
  }

  event = items_[best];
  for(uint8_t i=best;i+1<count_;i++) items_[i] = items_[i+1];
  count_--;
  return true;
}

void JarvisEventQueue::clear(){
  count_ = 0;
}
