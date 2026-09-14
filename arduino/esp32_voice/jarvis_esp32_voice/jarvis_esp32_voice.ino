/**
 * JARVIS RESIDENCIAL - SATELITE DE VOZ ESP32
 *
 * Arquitetura distribuida:
 *  - dominio central: https://casa.maurinsoft.com.br
 *  - cada dispositivo possui DEVICE_ID e token individual
 *  - capacidades declaradas permitem roteamento logico pelo CASA
 */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include "driver/i2s.h"

const char* ssid = "SUA_REDE_WIFI";
const char* password = "SUA_SENHA_WIFI";

const char* jarvis_server = "https://casa.maurinsoft.com.br";
const char* device_id = "esp32-voice-sala";
const char* device_token = "TOKEN_INDIVIDUAL_DO_ESP32_VOICE";
const char* device_name = "ESP32 Voice Sala";
const char* device_capabilities = "voice,speaker,telemetry,rssi";

#define BTN_TALK_PIN       0
#define LED_STATUS_PIN     2
#define I2S_DOUT_PIN      22
#define I2S_BCLK_PIN      26
#define I2S_LRC_PIN       25
#define I2S_PORT          I2S_NUM_0

unsigned long last_heartbeat = 0;
const unsigned long heartbeat_interval = 15000;

void prepararTLS(WiFiClientSecure& client) {
  // Para a primeira implantacao usa certificado HTTPS da hospedagem sem pin fixo.
  // Evolucao recomendada: distribuir CA raiz por configuracao/provisionamento.
  client.setInsecure();
}

void adicionarHeadersDispositivo(HTTPClient& http) {
  http.addHeader("Authorization", String("Bearer ") + device_token);
  http.addHeader("X-Device-Token", device_token);
  http.addHeader("X-Device-Id", device_id);
  http.addHeader("X-Device-Capabilities", device_capabilities);
}

void init_i2s_speaker() {
  i2s_config_t i2s_config = {
    .mode = (i2s_mode_t)(I2S_MODE_MASTER | I2S_MODE_TX),
    .sample_rate = 22050,
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

void reproduzir_audio_jarvis(String audio_url_relativa) {
  if (WiFi.status() != WL_CONNECTED || audio_url_relativa.length() == 0) return;

  String full_url = audio_url_relativa.startsWith("http")
      ? audio_url_relativa
      : String(jarvis_server) + audio_url_relativa;

  Serial.println("[AUDIO] Baixando: " + full_url);
  digitalWrite(LED_STATUS_PIN, HIGH);

  WiFiClientSecure client;
  prepararTLS(client);
  HTTPClient http;

  if (!http.begin(client, full_url)) {
    digitalWrite(LED_STATUS_PIN, LOW);
    return;
  }

  adicionarHeadersDispositivo(http);
  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    WiFiClient* stream = http.getStreamPtr();
    uint8_t wav_hdr[44];
    stream->readBytes(wav_hdr, 44);

    uint8_t buffer[1024];
    while (http.connected()) {
      int available = stream->available();
      if (available <= 0) {
        delay(2);
        if (!http.connected()) break;
        continue;
      }
      int len = stream->readBytes(buffer, min((int)sizeof(buffer), available));
      if (len <= 0) break;
      size_t bytes_written;
      i2s_write(I2S_PORT, buffer, len, &bytes_written, portMAX_DELAY);
    }
  } else {
    Serial.printf("[AUDIO] Erro HTTP: %d\n", httpCode);
  }

  http.end();
  digitalWrite(LED_STATUS_PIN, LOW);
  i2s_zero_dma_buffer(I2S_PORT);
}

void dialogar_com_jarvis(String comando_texto) {
  if (WiFi.status() != WL_CONNECTED) return;

  Serial.println("[JARVIS] Enviando comando: " + comando_texto);
  digitalWrite(LED_STATUS_PIN, HIGH);

  WiFiClientSecure client;
  prepararTLS(client);
  HTTPClient http;
  String url = String(jarvis_server) + "/api/v1/comando";

  if (!http.begin(client, url)) {
    digitalWrite(LED_STATUS_PIN, LOW);
    return;
  }

  http.addHeader("Content-Type", "application/json");
  adicionarHeadersDispositivo(http);

  StaticJsonDocument<512> req;
  req["comando"] = comando_texto;
  req["ia_mode"] = "auto";
  req["origem"] = "ESP32_VOICE";
  req["device_id"] = device_id;
  req["device_name"] = device_name;
  req["capabilities"] = device_capabilities;
  String payload;
  serializeJson(req, payload);

  int httpCode = http.POST(payload);

  if (httpCode >= 200 && httpCode < 300) {
    String respJson = http.getString();
    StaticJsonDocument<1536> doc;
    DeserializationError err = deserializeJson(doc, respJson);
    if (!err) {
      const char* resposta = doc["resposta"] | doc["mensagem"] | "JARVIS";
      const char* audio_url = doc["audio_url"] | "";
      Serial.printf("[JARVIS] %s\n", resposta);
      if (strlen(audio_url) > 0) reproduzir_audio_jarvis(String(audio_url));
    }
  } else {
    Serial.printf("[JARVIS] Erro HTTP: %d\n", httpCode);
  }

  http.end();
  digitalWrite(LED_STATUS_PIN, LOW);
}

void enviar_heartbeat() {
  if (WiFi.status() != WL_CONNECTED) return;

  WiFiClientSecure client;
  prepararTLS(client);
  HTTPClient http;
  String url = String(jarvis_server) + "/api/crud.php?tabela=dispositivos_cluster&acao=heartbeat_iot";

  if (!http.begin(client, url)) return;

  http.addHeader("Content-Type", "application/json");
  adicionarHeadersDispositivo(http);

  StaticJsonDocument<512> doc;
  doc["device_id"] = device_id;
  doc["device_name"] = device_name;
  doc["capabilities"] = device_capabilities;
  doc["ram_livre"] = ESP.getFreeHeap();
  doc["sinal_rssi"] = WiFi.RSSI();
  doc["uptime_s"] = millis() / 1000UL;

  String payload;
  serializeJson(doc, payload);
  int httpCode = http.POST(payload);
  Serial.printf("[HEARTBEAT] HTTP %d\n", httpCode);
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

  Serial.println("\n[WiFi] Conectado. IP: " + WiFi.localIP().toString());
  Serial.println("[CASA] Dominio: " + String(jarvis_server));
  enviar_heartbeat();
}

void loop() {
  if (digitalRead(BTN_TALK_PIN) == LOW) {
    delay(50);
    if (digitalRead(BTN_TALK_PIN) == LOW) {
      dialogar_com_jarvis("JARVIS, qual o status geral da residencia?");
      while (digitalRead(BTN_TALK_PIN) == LOW) delay(10);
    }
  }

  if (millis() - last_heartbeat > heartbeat_interval) {
    last_heartbeat = millis();
    enviar_heartbeat();
  }
}
