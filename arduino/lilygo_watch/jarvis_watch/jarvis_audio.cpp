#include "jarvis_audio.h"
#include <driver/i2s.h>

static TTGOClass *audioWatch = nullptr;
static bool audioDriverReady = false;
static bool tonePlaying = false;

static const i2s_port_t AUDIO_PORT = I2S_NUM_1;
static const uint32_t AUDIO_RATE = 16000;

static uint32_t tonePhase = 0;
static uint16_t toneFrequency = 0;
static uint32_t toneSamplesRemaining = 0;
static int16_t toneAmplitude = 0;

static bool ensureAudioDriver(){
  if(audioDriverReady) return true;
  if(!audioWatch) return false;

  // No T-Watch 2020 V3 o modulo de audio e alimentado pelo AXP202 LDO4.
  audioWatch->enableAudio();

  i2s_config_t cfg = {};
  cfg.mode = (i2s_mode_t)(I2S_MODE_MASTER | I2S_MODE_TX);
  cfg.sample_rate = AUDIO_RATE;
  cfg.bits_per_sample = I2S_BITS_PER_SAMPLE_16BIT;
  cfg.channel_format = I2S_CHANNEL_FMT_RIGHT_LEFT;
  cfg.communication_format = I2S_COMM_FORMAT_I2S;
  cfg.intr_alloc_flags = ESP_INTR_FLAG_LEVEL1;
  cfg.dma_buf_count = 4;
  cfg.dma_buf_len = 128;
  cfg.use_apll = false;

  esp_err_t e = i2s_driver_install(AUDIO_PORT, &cfg, 0, nullptr);
  if(e != ESP_OK) return false;

  i2s_pin_config_t pins = {};
  pins.bck_io_num = JARVIS_AUDIO_BCK_PIN;
  pins.ws_io_num = JARVIS_AUDIO_WS_PIN;
  pins.data_out_num = JARVIS_AUDIO_DOUT_PIN;
  pins.data_in_num = I2S_PIN_NO_CHANGE;

  e = i2s_set_pin(AUDIO_PORT, &pins);
  if(e != ESP_OK){
    i2s_driver_uninstall(AUDIO_PORT);
    return false;
  }

  i2s_zero_dma_buffer(AUDIO_PORT);
  audioDriverReady = true;
  return true;
}

void jarvisAudioBegin(TTGOClass *watch){
  audioWatch = watch;
  // O driver e instalado sob demanda para economizar RAM durante o uso normal.
}

bool jarvisAudioIsReady(){
  return audioDriverReady;
}

bool jarvisAudioIsPlaying(){
  return tonePlaying;
}

bool jarvisAudioPlayTone(uint16_t frequencyHz, uint16_t durationMs, uint8_t volume){
  if(frequencyHz < 40 || durationMs == 0) return false;
  if(!ensureAudioDriver()) return false;

  if(volume > 100) volume = 100;
  toneFrequency = frequencyHz;
  toneSamplesRemaining = (AUDIO_RATE * (uint32_t)durationMs) / 1000UL;
  tonePhase = 0;
  // Limita a amplitude para evitar saturacao no pequeno alto-falante.
  toneAmplitude = (int16_t)((12000L * volume) / 100L);
  tonePlaying = true;
  return true;
}

void jarvisAudioStop(){
  tonePlaying = false;
  toneSamplesRemaining = 0;
  if(audioDriverReady) i2s_zero_dma_buffer(AUDIO_PORT);
}

void jarvisAudioLoop(){
  if(!tonePlaying || !audioDriverReady) return;

  // 48 frames por ciclo: curto o bastante para nao prejudicar touch/eventos.
  int16_t frames[48 * 2];
  size_t framesWanted = toneSamplesRemaining > 48 ? 48 : toneSamplesRemaining;

  for(size_t i=0;i<framesWanted;i++){
    tonePhase += toneFrequency;
    if(tonePhase >= AUDIO_RATE) tonePhase -= AUDIO_RATE;
    int16_t sample = (tonePhase < (AUDIO_RATE / 2)) ? toneAmplitude : -toneAmplitude;
    frames[i*2] = sample;
    frames[i*2+1] = sample;
  }

  size_t bytesWritten = 0;
  esp_err_t e = i2s_write(
    AUDIO_PORT,
    frames,
    framesWanted * 2 * sizeof(int16_t),
    &bytesWritten,
    0
  );

  if(e != ESP_OK || bytesWritten == 0) return;

  size_t sentFrames = bytesWritten / (2 * sizeof(int16_t));
  if(sentFrames >= toneSamplesRemaining){
    toneSamplesRemaining = 0;
    tonePlaying = false;
    i2s_zero_dma_buffer(AUDIO_PORT);
  }else{
    toneSamplesRemaining -= sentFrames;
  }
}

size_t jarvisAudioWritePcm16Mono(const int16_t *samples, size_t sampleCount){
  if(!samples || sampleCount == 0) return 0;
  if(!ensureAudioDriver()) return 0;

  // PCM externo interrompe qualquer beep em andamento.
  tonePlaying = false;
  toneSamplesRemaining = 0;

  size_t totalSent = 0;
  int16_t stereo[64 * 2];

  while(totalSent < sampleCount){
    size_t n = sampleCount - totalSent;
    if(n > 64) n = 64;

    for(size_t i=0;i<n;i++){
      int16_t s = samples[totalSent+i];
      stereo[i*2] = s;
      stereo[i*2+1] = s;
    }

    size_t bytesWritten = 0;
    esp_err_t e = i2s_write(
      AUDIO_PORT,
      stereo,
      n * 2 * sizeof(int16_t),
      &bytesWritten,
      pdMS_TO_TICKS(20)
    );
    if(e != ESP_OK || bytesWritten == 0) break;

    totalSent += bytesWritten / (2 * sizeof(int16_t));
  }

  return totalSent;
}

void jarvisAudioShutdown(){
  jarvisAudioStop();
  if(audioDriverReady){
    i2s_driver_uninstall(AUDIO_PORT);
    audioDriverReady = false;
  }
  if(audioWatch) audioWatch->disableAudio();
}
