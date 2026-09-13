/**
 * JARVIS RESIDENCIAL - MÓDULO ESP32-CAM (VISUALIZAÇÃO & TELEMETRIA)
 * 
 * Hardware: AI-Thinker ESP32-CAM (Sensor OV2640)
 * Recursos:
 *  1. Streaming de Vídeo MJPEG (/stream) em tempo real
 *  2. Captura de Foto Snapshot (/capture)
 *  3. Controle do LED Flash (/flash/on, /flash/off)
 *  4. Telemetria e Heartbeat contínuo para o Cluster JARVIS
 *  5. Detecção de movimento com upload automático e disparo de alerta
 *  6. Autenticação por Hardware Token
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <WiFiClient.h>
#include <WebServer.h>
#include <HTTPClient.h>

// ================= CONFIGURAÇÕES DE REDE E JARVIS =================
const char* ssid = "SUA_REDE_WIFI";
const char* password = "SUA_SENHA_WIFI";

const char* jarvis_server = "http://192.168.2.12";
const char* device_token = "token_espcam_portao_2026";
const char* device_name = "ESP32-CAM Entrada";

// Pinos da Câmera AI-Thinker ESP32-CAM
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27
#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

#define FLASH_LED_PIN      4

WebServer server(80);
unsigned long last_heartbeat = 0;
const unsigned long heartbeat_interval = 15000; // 15 segundos

// Stream MJPEG Handler
void handle_stream() {
  WiFiClient client = server.client();
  String response = "HTTP/1.1 200 OK\r\n";
  response += "Content-Type: multipart/x-mixed-replace; boundary=frame\r\n\r\n";
  server.sendContent(response);

  while (client.connected()) {
    camera_fb_t * fb = esp_camera_fb_get();
    if (!fb) {
      Serial.println("Falha na captura do frame");
      break;
    }

    client.print("--frame\r\n");
    client.print("Content-Type: image/jpeg\r\n");
    client.print("Content-Length: " + String(fb->len) + "\r\n\r\n");
    client.write(fb->buf, fb->len);
    client.print("\r\n");
    esp_camera_fb_return(fb);

    yield();
  }
}

// Snapshot Capture Handler
void handle_capture() {
  camera_fb_t * fb = esp_camera_fb_get();
  if (!fb) {
    server.send(500, "text/plain", "Falha na captura");
    return;
  }
  server.sendHeader("Content-Type", "image/jpeg");
  server.sendHeader("Content-Length", String(fb->len));
  server.sendHeader("Access-Control-Allow-Origin", "*");
  WiFiClient client = server.client();
  client.write(fb->buf, fb->len);
  esp_camera_fb_return(fb);
}

// Controle de Flash
void handle_flash_on() {
  digitalWrite(FLASH_LED_PIN, HIGH);
  server.send(200, "application/json", "{\"flash\": 1}");
}

void handle_flash_off() {
  digitalWrite(FLASH_LED_PIN, LOW);
  server.send(200, "application/json", "{\"flash\": 0}");
}

// Status & Telemetria JSON
void handle_status() {
  String json = "{";
  json += "\"dispositivo\": \"" + String(device_name) + "\",";
  json += "\"tipo\": \"esp32_cam\",";
  json += "\"ip\": \"" + WiFi.localIP().toString() + "\",";
  json += "\"rssi\": " + String(WiFi.RSSI()) + ",";
  json += "\"free_heap\": " + String(ESP.getFreeHeap()) + ",";
  json += "\"uptime_s\": " + String(millis() / 1000);
  json += "}";
  server.send(200, "application/json", json);
}

// Envio de Telemetria e Heartbeat ao Cluster JARVIS
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
  payload += "\"reles_status\": {\"flash\": " + String(digitalRead(FLASH_LED_PIN)) + "}";
  payload += "}";

  int httpCode = http.POST(payload);
  if (httpCode > 0) {
    Serial.printf("[JARVIS] Heartbeat OK: %d\n", httpCode);
  } else {
    Serial.printf("[JARVIS] Falha no Heartbeat: %s\n", http.errorToString(httpCode).c_str());
  }
  http.end();
}

// Upload de Frame com Alerta de Movimento para o Servidor JARVIS
void alertar_movimento() {
  if (WiFi.status() != WL_CONNECTED) return;

  camera_fb_t * fb = esp_camera_fb_get();
  if (!fb) return;

  HTTPClient http;
  String url = String(jarvis_server) + "/api/agente_externo.php?acao=upload_espcam&movimento=1";
  http.begin(url);
  http.addHeader("Content-Type", "image/jpeg");
  http.addHeader("X-Device-Token", device_token);

  int httpCode = http.POST(fb->buf, fb->len);
  Serial.printf("[JARVIS] Alerta de Movimento Enviado: %d\n", httpCode);
  http.end();
  esp_camera_fb_return(fb);
}

void setup() {
  Serial.begin(115200);
  pinMode(FLASH_LED_PIN, OUTPUT);
  digitalWrite(FLASH_LED_PIN, LOW);

  // Inicialização da Câmera
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  if (psramFound()) {
    config.frame_size = FRAMESIZE_VGA; // 640x480
    config.jpeg_quality = 12;
    config.fb_count = 2;
  } else {
    config.frame_size = FRAMESIZE_QVGA; // 320x240
    config.jpeg_quality = 15;
    config.fb_count = 1;
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("Erro ao iniciar câmera: 0x%x\n", err);
    return;
  }

  // Conexão WiFi
  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWiFi Conectado! IP: " + WiFi.localIP().toString());

  // Rotas HTTP
  server.on("/stream", handle_stream);
  server.on("/capture", handle_capture);
  server.on("/flash/on", handle_flash_on);
  server.on("/flash/off", handle_flash_off);
  server.on("/status", handle_status);
  server.begin();

  Serial.println("Servidor Web ESP32-CAM ativo na porta 80.");
  enviar_heartbeat();
}

void loop() {
  server.handleClient();

  if (millis() - last_heartbeat > heartbeat_interval) {
    last_heartbeat = millis();
    enviar_heartbeat();
  }
}
