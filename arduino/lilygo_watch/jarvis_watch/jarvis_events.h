#pragma once

#include <Arduino.h>

enum JarvisEventPriority : uint8_t {
  JARVIS_PRI_LOW = 0,
  JARVIS_PRI_NORMAL = 1,
  JARVIS_PRI_HIGH = 2,
  JARVIS_PRI_CRITICAL = 3
};

enum JarvisEventType : uint8_t {
  EVT_NONE = 0,
  EVT_TICK_1S,
  EVT_USER_INTERACTION,
  EVT_BUTTON_SHORT,
  EVT_BUTTON_LONG,
  EVT_TOUCH_TAP,
  EVT_SWIPE_LEFT,
  EVT_SWIPE_RIGHT,
  EVT_SWIPE_UP,
  EVT_SWIPE_DOWN,
  EVT_SCREEN_TIMEOUT,
  EVT_DEEP_SLEEP_TIMEOUT,
  EVT_ALARM_TRIGGER,
  EVT_ALARM_STOP,
  EVT_WIFI_SCAN_REQUEST,
  EVT_WIFI_SCAN_DONE,
  EVT_WIFI_CONNECT_REQUEST,
  EVT_WIFI_CONNECTED,
  EVT_WIFI_FAILED,
  EVT_BLE_CONNECTED,
  EVT_BLE_DISCONNECTED,
  EVT_VOICE_START,
  EVT_VOICE_STOP,
  EVT_VOICE_RESULT,
  EVT_CALL_START,
  EVT_CALL_INCOMING,
  EVT_CALL_ACCEPT,
  EVT_CALL_END,
  EVT_SOS,
  EVT_CHECKIN,
  EVT_INTERNAL_ERROR
};

struct JarvisEvent {
  JarvisEventType type = EVT_NONE;
  JarvisEventPriority priority = JARVIS_PRI_NORMAL;
  int32_t value1 = 0;
  int32_t value2 = 0;
  String text;
  unsigned long createdAt = 0;
};

class JarvisEventQueue {
public:
  static const uint8_t CAPACITY = 24;

  bool push(const JarvisEvent &event);
  bool pop(JarvisEvent &event);
  bool available() const { return count_ > 0; }
  uint8_t size() const { return count_; }
  uint32_t dropped() const { return dropped_; }
  void clear();

private:
  JarvisEvent items_[CAPACITY];
  uint8_t count_ = 0;
  uint32_t dropped_ = 0;
};

JarvisEvent jarvisEvent(
  JarvisEventType type,
  JarvisEventPriority priority = JARVIS_PRI_NORMAL,
  int32_t value1 = 0,
  int32_t value2 = 0,
  const String &text = String()
);
