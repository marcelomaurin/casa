#pragma once

#include <Arduino.h>

// Infravermelho do T-Watch 2020 V3.
// LED IR de transmissao: GPIO13.
//
// Implementacao direta com o periferico RMT do ESP32, sem biblioteca externa.
// O driver usa portadora de 38 kHz por padrao e suporta NEC e sequencias RAW.

void jarvisIrBegin();
void jarvisIrLoop();
void jarvisIrShutdown();

bool jarvisIrIsReady();
bool jarvisIrIsBusy();

// NEC padrao: endereco de 8 bits + complemento, comando de 8 bits + complemento.
bool jarvisIrSendNec(uint8_t address, uint8_t command, uint8_t repeats = 0);

// NEC estendido: endereco de 16 bits + comando de 8 bits + complemento.
bool jarvisIrSendNecExtended(uint16_t address, uint8_t command, uint8_t repeats = 0);

// Envio RAW. durationsUs alterna MARK/SPACE, iniciando em MARK.
// Exemplo: {9000,4500,560,560,...}. carrierHz normalmente 38000.
bool jarvisIrSendRaw(
  const uint32_t *durationsUs,
  size_t count,
  uint32_t carrierHz = 38000,
  uint8_t dutyPercent = 33
);
