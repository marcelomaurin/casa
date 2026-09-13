/**
 * JARVIS RESIDENCIAL - SATÉLITE DE VOZ ESP32 (SMART SPEAKER / GOOGLE HOME)
 * 
 * Hardware: ESP32-WROOM-32
 * Periféricos:
 *  - Microfone I2S (ex: INMP441 / SPH0645) ou Botão Push-to-Talk
 *  - DAC de Áudio I2S (ex: MAX98357A amplificado)
 *  - Botão de Ativação (GPIO 0 ou GPIO 34)
 *  - LED Indicador de Estado (GPIO 2)
 * 
 * Fluxo:
 *  1. Pressionar botão -> Grava áudio/dispara comando
 *  2. Envia requisição segura com Token para http://192.168.2.12/api/jarvis.php
 *  3. Recebe resposta cognitiva do JARVIS e URL do áudio sintetizado
 *  4. Faz streaming do áudio para o alto-falante conectado
 *  5. Reporta telemetria periódica (RSSI, RAM livre, Uptime) ao Cluster
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include "driver/i2s.h"

// ================= CONFIGURAÇÕES DE REDE E JARVIS =================
const char* ssid = "SUA_REDE_WIFI";
const char* password = "SUA_SENHA_WIFI";

const char* jarvis_server = "http://192.168.2.12";
const char* device_token = "token_espvoice_sala_2026";
const char* device_name = "ESP32 Voice Sala";

// Pinos de I/O
#define BTN_TALK_PIN       0   // Botão Push-to-Talk (Boot button)
#define LED_STATUS_PIN     2   // LED indicador onboard

// Configuração dos Pinos I2S DAC (Saída de Áudio - MAX98357A)
#define I2S_DOUT_PIN      22   // DIN
#define I2S_BCLK_PIN      26   // BCLK
#define I2S_LRC_PIN       25   // LRC / WSEL

#define I2S_PORT          I2S_NUM_0

unsigned long last_heartbeat = 0;
const unsigned long heartbeat_interval = 15000; // 15s

void init_i2s_speaker() {
  i2s_config_t i2s_config = {
    .mode = (i2s_mode_t)(I2S_MODE_MASTER | I2S_MODE_TX),
    .sample_rate = 22050, // Taxa do Piper TTS
    .bits_per_sample = I2S_BITS_PER_SAMPLE_16BIT,
    .channel_format = I2S_CHANNEL_FMT_RIGHT_LEFT,
    .communication_format = I2S_COMM_FORMAT_STAND_I2S,
    .intr_alloc_flags = ESP_INTR_FLAG_LEVEL1,
    .dma_buf_count = 8,
    .dma_buf_len = 512,
    .use_apll = false
  };

  i2s_pin_config_t pin_config = {
    .bck_io_num = I2S_BCLK_PIN,
    .ws_io_num = I2S_LRC_PIN,
    .data_out_num = I2S_DOUT_PIN,
    .data_in_num = I2S_PIN_NO_CHANGE
  };

  i2s_driver_install(I2S_PORT, &i2s_config, 0, NULL);
  i2s_set_pin(I2S_PORT, &pin_config);
  i2s_zero_dma_buffer(I2S_PORT);
}

// Reprodução do Áudio Retornado pelo JARVIS
void reproduzir_audio_jarvis(String audio_url_relativa) {
  if (WiFi.status() != WL_CONNECTED || audio_url_relativa.length() == 0) return;

  String full_url = String(jarvis_server) + audio_url_relativa;
  Serial.println("[ÁUDIO] Baixando e reproduzindo: " + full_url);

  digitalWrite(LED_STATUS_PIN, HIGH);

  HTTPClient http;
  http.begin(full_url);
  http.addHeader("X-Device-Token", device_token);
  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    WiFiClient * stream = http.getStreamPtr();
    // Pular cabeçalho WAV (44 bytes)
    uint8_t wav_hdr[44];
    stream->readBytes(wav_hdr, 44);

    uint8_t buffer[1024];
    while (http.connected() && (stream->available() > 0 || stream->available() == -1)) {
      int len = stream->readBytes(buffer, sizeof(buffer));
      if (len > 0) {
        size_t bytes_written;
        i2s_write(I2S_PORT, buffer, len, &bytes_written, portMAX_DELAY);
      } else {
        break;
      }
    }
  }
  http.end();
  digitalWrite(LED_STATUS_PIN, LOW);
  i2s_zero_dma_buffer(I2S_PORT);
  Serial.println("[ÁUDIO] Reprodução concluída.");
}

// Enviar Comando ao JARVIS e Aguardar Resposta
void dialogar_com_jarvis(String comando_texto) {
  if (WiFi.status() != WL_CONNECTED) return;

  Serial.println("[JARVIS] Enviando comando: " + comando_texto);
  digitalWrite(LED_STATUS_PIN, HIGH);

  HTTPClient http;
  String url = String(jarvis_server) + "/api/jarvis.php";
  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", device_token);

  String payload = "{\"comando\": \"" + comando_texto + "\", \"ia_mode\": \"auto\"}";
  int httpCode = http.POST(payload);

  if (httpCode == HTTP_CODE_OK) {
    String respJson = http.getString();
    Serial.println("[JARVIS] Resposta JSON: " + respJson);

    StaticJsonDocument<1024> doc;
    DeserializationError err = deserializeJson(doc, respJson);
    if (!err) {
      const char* resposta = doc["resposta"];
      const char* audio_url = doc["audio_url"];
      Serial.printf("[JARVIS]: %s\n", resposta);

      if (audio_url && strlen(audio_url) > 0) {
        reproduzir_audio_jarvis(String(audio_url));
      }
    }
  } else {
    Serial.printf("[JARVIS] Erro HTTP: %d\n", httpCode);
  }
  http.end();
  digitalWrite(LED_STATUS_PIN, LOW);
}

// Envio de Telemetria ao Cluster JARVIS
void enviar_heartbeat() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  String url = String(jarvis_server) + "/api/crud.php?tabela=dispositivos_cluster&acao=heartbeat_iot";
  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", device_token);

  String payload = "{";
  payload += "\"device_token\": \"" + String(device_token) + "\",";
  payload += "\"ram_livre\": " + String(ESP.getFreeHeap()) + ",";
  payload += "\"sinal_rssi\": " + String(WiFi.RSSI()) + ",";
  payload += "\"reles_status\": {\"speaker_ativo\": 1}";
  payload += "}";

  int httpCode = http.POST(payload);
  http.end();
}

void setup() {
  Serial.begin(115200);
  pinMode(BTN_TALK_PIN, INPUT_PULLUP);
  pinMode(LED_STATUS_PIN, OUTPUT);
  digitalWrite(LED_STATUS_PIN, LOW);

  init_i2s_speaker();

  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\n[WiFi] Conectado! IP: " + WiFi.localIP().toString());

  enviar_heartbeat();
  Serial.println("Satélite ESP32 Voice pronto. Pressione o botão para falar com o JARVIS.");
}

void loop() {
  // Pressionar botão ativa interação com o assistente
  if (digitalRead(BTN_TALK_PIN) == LOW) {
    delay(50); // debounce
    if (digitalRead(BTN_TALK_PIN) == LOW) {
      Serial.println("[BOTÃO] Ativado! Solicitando status ao JARVIS...");
      dialogar_com_jarvis("JARVIS, qual o status geral da residência?");
      while (digitalRead(BTN_TALK_PIN) == LOW) delay(10);
    }
  }

  if (millis() - last_heartbeat > heartbeat_interval) {
    last_heartbeat = millis();
    enviar_heartbeat();
  }
}
