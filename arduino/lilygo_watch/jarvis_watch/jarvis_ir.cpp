#include "jarvis_ir.h"
#include "config.h"

#include <driver/rmt.h>

static const rmt_channel_t IR_CHANNEL = RMT_CHANNEL_0;
static const uint32_t IR_DEFAULT_CARRIER = 38000;
static const uint8_t IR_DEFAULT_DUTY = 33;
static const uint32_t RMT_SOURCE_HZ = 80000000UL;

// Buffer persistente: rmt_write_items(..., false) continua usando os dados
// depois do retorno da funcao chamadora.
static rmt_item32_t irItems[128];
static size_t irItemCount = 0;

static bool irReady = false;
static bool irBusy = false;
static bool irNecSequence = false;
static uint8_t irRepeatsRemaining = 0;
static unsigned long irRepeatDue = 0;
static bool irLastFrameWasRepeat = false;

static bool setCarrier(uint32_t frequencyHz, uint8_t dutyPercent){
  if(!irReady || frequencyHz < 20000 || frequencyHz > 60000) return false;
  if(dutyPercent < 10) dutyPercent = 10;
  if(dutyPercent > 50) dutyPercent = 50;

  uint32_t period = RMT_SOURCE_HZ / frequencyHz;
  if(period < 2 || period > 65535) return false;

  uint16_t highTicks = (uint16_t)((period * dutyPercent) / 100UL);
  if(highTicks < 1) highTicks = 1;
  uint16_t lowTicks = (uint16_t)(period - highTicks);
  if(lowTicks < 1) lowTicks = 1;

  return rmt_set_tx_carrier(
    IR_CHANNEL,
    true,
    highTicks,
    lowTicks,
    RMT_CARRIER_LEVEL_HIGH
  ) == ESP_OK;
}

static rmt_item32_t item(uint16_t markUs, uint16_t spaceUs){
  rmt_item32_t out = {};
  out.level0 = 1;
  out.duration0 = markUs;
  out.level1 = 0;
  out.duration1 = spaceUs;
  return out;
}

static bool startItems(size_t count){
  if(!irReady || irBusy || count == 0 || count > 128) return false;
  irItemCount = count;
  esp_err_t e = rmt_write_items(IR_CHANNEL, irItems, (int)irItemCount, false);
  if(e != ESP_OK) return false;
  irBusy = true;
  return true;
}

static void appendNecByte(size_t &n, uint8_t value){
  for(uint8_t bit=0; bit<8; bit++){
    bool one = (value >> bit) & 0x01; // NEC transmite LSB primeiro.
    irItems[n++] = item(560, one ? 1690 : 560);
  }
}

static bool startNecFrame(uint8_t b0, uint8_t b1, uint8_t b2, uint8_t b3, uint8_t repeats){
  if(!irReady || irBusy) return false;
  if(!setCarrier(IR_DEFAULT_CARRIER, IR_DEFAULT_DUTY)) return false;

  size_t n = 0;
  irItems[n++] = item(9000, 4500);
  appendNecByte(n, b0);
  appendNecByte(n, b1);
  appendNecByte(n, b2);
  appendNecByte(n, b3);
  irItems[n++] = item(560, 0);

  irNecSequence = true;
  irRepeatsRemaining = repeats;
  irLastFrameWasRepeat = false;
  irRepeatDue = 0;
  if(!startItems(n)){
    irNecSequence = false;
    irRepeatsRemaining = 0;
    return false;
  }
  return true;
}

static bool startNecRepeat(){
  if(!irReady || irBusy) return false;

  size_t n = 0;
  irItems[n++] = item(9000, 2250);
  irItems[n++] = item(560, 0);

  irLastFrameWasRepeat = true;
  return startItems(n);
}

void jarvisIrBegin(){
  if(irReady) return;

  rmt_config_t cfg = RMT_DEFAULT_CONFIG_TX((gpio_num_t)JARVIS_IR_PIN, IR_CHANNEL);
  cfg.clk_div = 80; // 80 MHz / 80 = 1 us por tick.
  cfg.mem_block_num = 1;
  cfg.tx_config.carrier_en = true;
  cfg.tx_config.carrier_freq_hz = IR_DEFAULT_CARRIER;
  cfg.tx_config.carrier_duty_percent = IR_DEFAULT_DUTY;
  cfg.tx_config.carrier_level = RMT_CARRIER_LEVEL_HIGH;
  cfg.tx_config.idle_level = RMT_IDLE_LEVEL_LOW;
  cfg.tx_config.idle_output_en = true;
  cfg.tx_config.loop_en = false;

  if(rmt_config(&cfg) != ESP_OK) return;
  if(rmt_driver_install(IR_CHANNEL, 0, 0) != ESP_OK) return;

  irReady = true;
  setCarrier(IR_DEFAULT_CARRIER, IR_DEFAULT_DUTY);
}

void jarvisIrLoop(){
  if(!irReady) return;

  if(irBusy){
    esp_err_t state = rmt_wait_tx_done(IR_CHANNEL, 0);
    if(state == ESP_OK){
      irBusy = false;

      if(irNecSequence && irRepeatsRemaining > 0){
        // O primeiro frame NEC leva ~68 ms. O repeat inicia por volta de
        // 108 ms apos o inicio; repeats subsequentes mantem ~108 ms.
        irRepeatDue = millis() + (irLastFrameWasRepeat ? 96UL : 40UL);
      }else{
        irNecSequence = false;
        irRepeatDue = 0;
      }
    }
  }

  if(!irBusy && irNecSequence && irRepeatsRemaining > 0 &&
     irRepeatDue != 0 && (long)(millis() - irRepeatDue) >= 0){
    irRepeatDue = 0;
    if(startNecRepeat()){
      irRepeatsRemaining--;
    }else{
      irNecSequence = false;
      irRepeatsRemaining = 0;
    }
  }
}

void jarvisIrShutdown(){
  if(!irReady) return;
  if(irBusy) rmt_wait_tx_done(IR_CHANNEL, pdMS_TO_TICKS(150));
  rmt_driver_uninstall(IR_CHANNEL);
  pinMode(JARVIS_IR_PIN, OUTPUT);
  digitalWrite(JARVIS_IR_PIN, LOW);
  irReady = false;
  irBusy = false;
  irNecSequence = false;
  irRepeatsRemaining = 0;
}

bool jarvisIrIsReady(){
  return irReady;
}

bool jarvisIrIsBusy(){
  return irBusy || (irNecSequence && irRepeatsRemaining > 0);
}

bool jarvisIrSendNec(uint8_t address, uint8_t command, uint8_t repeats){
  return startNecFrame(address, (uint8_t)~address, command, (uint8_t)~command, repeats);
}

bool jarvisIrSendNecExtended(uint16_t address, uint8_t command, uint8_t repeats){
  return startNecFrame(
    (uint8_t)(address & 0xFF),
    (uint8_t)((address >> 8) & 0xFF),
    command,
    (uint8_t)~command,
    repeats
  );
}

static bool appendRawSegment(size_t &itemIndex, bool mark, uint32_t durationUs, bool &half){
  while(durationUs > 0){
    if(itemIndex >= 128) return false;
    uint16_t part = durationUs > 32767UL ? 32767U : (uint16_t)durationUs;

    if(!half){
      irItems[itemIndex] = {};
      irItems[itemIndex].level0 = mark ? 1 : 0;
      irItems[itemIndex].duration0 = part;
      half = true;
    }else{
      irItems[itemIndex].level1 = mark ? 1 : 0;
      irItems[itemIndex].duration1 = part;
      itemIndex++;
      half = false;
    }
    durationUs -= part;
  }
  return true;
}

bool jarvisIrSendRaw(
  const uint32_t *durationsUs,
  size_t count,
  uint32_t carrierHz,
  uint8_t dutyPercent
){
  if(!irReady || irBusy || !durationsUs || count == 0) return false;
  if(!setCarrier(carrierHz, dutyPercent)) return false;

  size_t n = 0;
  bool half = false;
  for(size_t i=0;i<count;i++){
    uint32_t duration = durationsUs[i];
    if(duration == 0) continue;
    bool mark = (i % 2) == 0;
    if(!appendRawSegment(n, mark, duration, half)) return false;
  }

  if(half){
    irItems[n].level1 = 0;
    irItems[n].duration1 = 0;
    n++;
  }
  if(n == 0) return false;

  irNecSequence = false;
  irRepeatsRemaining = 0;
  irRepeatDue = 0;
  return startItems(n);
}
