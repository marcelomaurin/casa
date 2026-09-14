#include "jarvis_network_sm.h"
#include <WiFi.h>

void JarvisNetworkStateMachine::begin(JarvisEventQueue *queue){
  queue_ = queue;
  state_ = jarvisWifiIsConnected() ? JARVIS_NET_CONNECTED : JARVIS_NET_IDLE;
}

const JarvisWifiNetwork *JarvisNetworkStateMachine::networkAt(int index) const{
  if(index < 0 || index >= networkCount_) return nullptr;
  return &networks_[index];
}

bool JarvisNetworkStateMachine::requestScan(){
  if(state_ == JARVIS_NET_SCANNING || state_ == JARVIS_NET_CONNECTING) return false;
  networkCount_ = 0;
  lastError_ = "";
  if(!jarvisWifiScanStart()){
    state_ = JARVIS_NET_ERROR;
    lastError_ = "Falha ao iniciar busca Wi-Fi";
    if(queue_) queue_->push(jarvisEvent(EVT_WIFI_FAILED, JARVIS_PRI_NORMAL, 0, 0, lastError_));
    return false;
  }
  state_ = JARVIS_NET_SCANNING;
  return true;
}

bool JarvisNetworkStateMachine::saveAndConnect(const String &ssid, const String &password){
  if(ssid.isEmpty()) return false;
  if(password.length() > 0 && password.length() < 8){
    lastError_ = "Senha Wi-Fi precisa de 8+ caracteres";
    state_ = JARVIS_NET_ERROR;
    if(queue_) queue_->push(jarvisEvent(EVT_WIFI_FAILED, JARVIS_PRI_NORMAL, 0, 0, lastError_));
    return false;
  }

  int slot = jarvisWifiFindFreeSlot();
  if(!jarvisWifiSetProfile(slot, ssid, password)){
    lastError_ = "Falha ao salvar perfil Wi-Fi";
    state_ = JARVIS_NET_ERROR;
    if(queue_) queue_->push(jarvisEvent(EVT_WIFI_FAILED, JARVIS_PRI_NORMAL, 0, 0, lastError_));
    return false;
  }

  if(!jarvisWifiStartProfile(slot)){
    lastError_ = "Falha ao iniciar conexão Wi-Fi";
    state_ = JARVIS_NET_ERROR;
    if(queue_) queue_->push(jarvisEvent(EVT_WIFI_FAILED, JARVIS_PRI_NORMAL, 0, 0, lastError_));
    return false;
  }

  connectingSlot_ = slot;
  connectDeadline_ = millis() + 12000UL;
  state_ = JARVIS_NET_CONNECTING;
  return true;
}

void JarvisNetworkStateMachine::disconnect(){
  WiFi.disconnect(false, false);
  state_ = JARVIS_NET_OFFLINE;
  connectingSlot_ = -1;
  connectDeadline_ = 0;
}

void JarvisNetworkStateMachine::process(const JarvisEvent &event){
  switch(event.type){
    case EVT_WIFI_SCAN_REQUEST:
      requestScan();
      break;
    default:
      break;
  }
}

void JarvisNetworkStateMachine::update(unsigned long now){
  if(state_ == JARVIS_NET_SCANNING){
    int n = jarvisWifiScanPoll(networks_, 12);
    if(n == -1) return;
    if(n < 0){
      state_ = JARVIS_NET_ERROR;
      lastError_ = "Erro durante busca Wi-Fi";
      if(queue_) queue_->push(jarvisEvent(EVT_WIFI_FAILED, JARVIS_PRI_NORMAL, 0, 0, lastError_));
      return;
    }
    networkCount_ = n;
    state_ = JARVIS_NET_SELECTING;
    if(queue_) queue_->push(jarvisEvent(EVT_WIFI_SCAN_DONE, JARVIS_PRI_NORMAL, n));
    return;
  }

  if(state_ == JARVIS_NET_CONNECTING){
    if(WiFi.status() == WL_CONNECTED){
      state_ = JARVIS_NET_CONNECTED;
      connectingSlot_ = -1;
      connectDeadline_ = 0;
      if(queue_) queue_->push(jarvisEvent(EVT_WIFI_CONNECTED, JARVIS_PRI_NORMAL, WiFi.RSSI(), 0, WiFi.SSID()));
      return;
    }
    if((long)(now - connectDeadline_) >= 0){
      WiFi.disconnect(false, false);
      state_ = JARVIS_NET_ERROR;
      connectingSlot_ = -1;
      connectDeadline_ = 0;
      lastError_ = "Tempo esgotado ao conectar Wi-Fi";
      if(queue_) queue_->push(jarvisEvent(EVT_WIFI_FAILED, JARVIS_PRI_NORMAL, 0, 0, lastError_));
    }
    return;
  }

  if(jarvisWifiIsConnected()){
    if(state_ != JARVIS_NET_CONNECTED){
      state_ = JARVIS_NET_CONNECTED;
      if(queue_) queue_->push(jarvisEvent(EVT_WIFI_CONNECTED, JARVIS_PRI_LOW, jarvisWifiRssi(), 0, jarvisWifiSsid()));
    }
  } else if(state_ == JARVIS_NET_CONNECTED){
    state_ = JARVIS_NET_OFFLINE;
    if(queue_) queue_->push(jarvisEvent(EVT_WIFI_FAILED, JARVIS_PRI_LOW, 0, 0, "Wi-Fi desconectado"));
  }
}
