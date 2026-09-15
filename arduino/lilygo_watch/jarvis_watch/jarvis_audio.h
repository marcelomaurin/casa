#pragma once

#include "config.h"
#include <Arduino.h>

// Saida de audio local do T-Watch 2020 V3.
// MAX98357A:
//   BCK  = GPIO26
//   WS   = GPIO25
//   DOUT = GPIO33
// Usa I2S_NUM_1 para nao conflitar com o microfone PDM, que usa I2S_NUM_0.

void jarvisAudioBegin(TTGOClass *watch);
void jarvisAudioLoop();
void jarvisAudioShutdown();

bool jarvisAudioIsReady();
bool jarvisAudioIsPlaying();

// Inicia um tom nao bloqueante. volume: 0..100.
bool jarvisAudioPlayTone(uint16_t frequencyHz, uint16_t durationMs, uint8_t volume = 45);
void jarvisAudioStop();

// Envia PCM mono 16-bit/16 kHz ao MAX98357A, duplicando nos dois canais I2S.
// Retorna a quantidade de amostras mono efetivamente enviadas.
size_t jarvisAudioWritePcm16Mono(const int16_t *samples, size_t sampleCount);
