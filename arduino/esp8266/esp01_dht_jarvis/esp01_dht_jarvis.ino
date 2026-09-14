/*
 * JARVIS RESIDENCIAL - ESP-01 SENSOR DE TEMPERATURA E UMIDADE
 * Hardware: ESP-01 / ESP8266 + DHT22 (padrao) ou DHT11
 *
 * Runtime distribuido:
 *   Telemetria -> https://casa.maurinsoft.com.br
 *   Cada dispositivo deve possuir token individual.
 *
 * Ligacao sugerida:
 *   DHT VCC  -> 3.3V
 *   DHT GND  -> GND
 *   DHT DATA -> GPIO2 do ESP-01
 *   resistor 4.7k a 10k entre DATA e 3.3V
 */

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <DHT.h>

// ========================= CONFIGURACAO =========================
const char* WIFI_SSID = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";

const char* JARVIS_URL = "https://casa.maurinsoft.com.br/api/iot_sensor.php";
const char* DEVICE_ID = "esp01-dht-01";
const char* DEVICE_TOKEN = "TOKEN_INDIVIDUAL_DO_ESP01";
const char* DEVICE_CAPABILITIES = "temperature,humidity,rssi,telemetry";

#define DHT_PIN 2
#define DHT_TYPE DHT22

const unsigned long INTERVALO_ENVIO_MS = 60000UL;
const unsigned long INTERVALO_RECONEXAO_MS = 10000UL;

DHT dht(DHT_PIN, DHT_TYPE);
unsigned long ultimoEnvio = 0;
unsigned long ultimaTentativaWiFi = 0;

String tipoSensor() {
#if DHT_TYPE == DHT11
  return "dht11";
#else
  return "dht22";
#endif
}

void conectarWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  Serial.printf("[WIFI] Conectando em %s", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  unsigned long inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < 15000UL) {
    delay(500);
    Serial.print('.');
    yield();
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println();
    Serial.printf("[WIFI] Conectado. IP=%s RSSI=%d dBm\n",
                  WiFi.localIP().toString().c_str(), WiFi.RSSI());
  } else {
    Serial.println("\n[WIFI] Falha ao conectar.");
  }
}

bool enviarLeitura(float temperatura, float umidade) {
  if (WiFi.status() != WL_CONNECTED) return false;

  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
  // Em producao, preferir CA/fingerprint gerenciado. Mantido permissivo para
  // compatibilidade inicial com renovacao automatica de certificado da hospedagem.
  client->setInsecure();

  HTTPClient http;
  if (!http.begin(*client, JARVIS_URL)) {
    Serial.println("[HTTPS] Falha ao iniciar cliente.");
    return false;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", String("Bearer ") + DEVICE_TOKEN);
  http.addHeader("X-Device-Token", DEVICE_TOKEN);
  http.addHeader("X-Device-Id", DEVICE_ID);
  http.addHeader("X-Device-Capabilities", DEVICE_CAPABILITIES);
  http.setTimeout(8000);

  String payload = "{";
  payload += "\"device_id\":\"" + String(DEVICE_ID) + "\",";
  payload += "\"capabilities\":\"" + String(DEVICE_CAPABILITIES) + "\",";
  payload += "\"tipo_sensor\":\"" + tipoSensor() + "\",";
  payload += "\"temperatura_c\":" + String(temperatura, 2) + ",";
  payload += "\"umidade_pct\":" + String(umidade, 2) + ",";
  payload += "\"rssi\":" + String(WiFi.RSSI()) + ",";
  payload += "\"uptime_s\":" + String(millis() / 1000UL) + ",";
  payload += "\"free_heap\":" + String(ESP.getFreeHeap()) + "}";

  Serial.println("[HTTPS] POST " + payload);
  int code = http.POST(payload);

  if (code > 0) {
    String resposta = http.getString();
    Serial.printf("[HTTPS] Codigo=%d Resposta=%s\n", code, resposta.c_str());
    http.end();
    return code >= 200 && code < 300;
  }

  Serial.printf("[HTTPS] Erro: %s\n", http.errorToString(code).c_str());
  http.end();
  return false;
}

void lerEEnviar() {
  float umidade = dht.readHumidity();
  float temperatura = dht.readTemperature();

  if (isnan(umidade) || isnan(temperatura)) {
    Serial.println("[DHT] Falha ao ler o sensor.");
    return;
  }

  Serial.printf("[DHT] Temperatura: %.2f C | Umidade: %.2f %%\n",
                temperatura, umidade);

  if (enviarLeitura(temperatura, umidade)) {
    Serial.println("[JARVIS] Telemetria enviada com sucesso.");
  } else {
    Serial.println("[JARVIS] Falha no envio da telemetria.");
  }
}

void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println();
  Serial.println("JARVIS ESP-01 TEMP/UMIDADE - CASA DISTRIBUIDA");

  dht.begin();
  WiFi.persistent(false);
  WiFi.setAutoReconnect(true);
  conectarWiFi();

  delay(2000);
  lerEEnviar();
  ultimoEnvio = millis();
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    if (millis() - ultimaTentativaWiFi >= INTERVALO_RECONEXAO_MS) {
      ultimaTentativaWiFi = millis();
      conectarWiFi();
    }
  }

  if (millis() - ultimoEnvio >= INTERVALO_ENVIO_MS) {
    ultimoEnvio = millis();
    lerEEnviar();
  }

  delay(50);
  yield();
}
