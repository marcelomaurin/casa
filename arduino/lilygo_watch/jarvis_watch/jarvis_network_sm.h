#pragma once

#include <Arduino.h>
#include "jarvis_events.h"
#include "jarvis_wifi.h"

enum JarvisNetworkState : uint8_t {
  JARVIS_NET_OFFLINE = 0,
  JARVIS_NET_IDLE,
  JARVIS_NET_SCANNING,
  JARVIS_NET_SELECTING,
  JARVIS_NET_CONNECTING,
  JARVIS_NET_CONNECTED,
  JARVIS_NET_ERROR
};

class JarvisNetworkStateMachine {
public:
  void begin(JarvisEventQueue *queue);
  void process(const JarvisEvent &event);
  void update(unsigned long now = millis());

  bool requestScan();
  bool saveAndConnect(const String &ssid, const String &password);
  void disconnect();

  JarvisNetworkState state() const { return state_; }
  int networkCount() const { return networkCount_; }
  const JarvisWifiNetwork *networkAt(int index) const;
  String currentSsid() const { return jarvisWifiSsid(); }
  int currentRssi() const { return jarvisWifiRssi(); }
  String lastError() const { return lastError_; }

private:
  JarvisEventQueue *queue_ = nullptr;
  JarvisNetworkState state_ = JARVIS_NET_OFFLINE;
  JarvisWifiNetwork networks_[12];
  int networkCount_ = 0;
  int connectingSlot_ = -1;
  unsigned long connectDeadline_ = 0;
  String lastError_;
};
