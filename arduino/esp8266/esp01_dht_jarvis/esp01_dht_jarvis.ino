/*
 * JARVIS RESIDENCIAL - ESP-01 SENSOR DE TEMPERATURA E UMIDADE
 * Hardware: ESP-01 / ESP8266 + DHT22 (padrao) ou DHT11
 *
 * Runtime distribuido e Seguranca:
 *   Telemetria -> https://maurinsoft.com.br/casa
 *   Nenhum token gravado no fonte. O dispositivo gera um codigo de 6 digitos
 *   e solicita entrada na rede. O celular (JARVIS Mobile) autoriza e passa
 *   a chave criptografica individual, salva na EEPROM do ESP.
 */

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <DHT.h>
#include "../../common/CasaDeviceProvisioning.h"

// ========================= CONFIGURACAO WIFI =========================
const char* WIFI_SSID = "SUA_REDE_WIFI";
const char* WIFI_PASSWORD = "SUA_SENHA_WIFI";
const char* CASA_BASE_URL = "https://maurinsoft.com.br/casa";

#define DHT_PIN 2
#define DHT_TYPE DHT22

const unsigned long INTERVALO_ENVIO_MS = 60000UL;
const unsigned long INTERVALO_POLL_PAREAMENTO_MS = 3000UL;

DHT dht(DHT_PIN, DHT_TYPE);
CasaDeviceProvisioning prov;

unsigned long ultimoEnvio = 0;
unsigned long ultimoPollPareamento = 0;

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
  if (WiFi.status() != WL_CONNECTED || !prov.isProvisioned()) return false;

  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
  client->setInsecure();

  HTTPClient http;
  String url = prov.getBaseUrl() + "/api/v1/device.php?acao=event";
  if (!http.begin(*client, url)) {
    Serial.println("[HTTPS] Falha ao iniciar cliente.");
    return false;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", String("Bearer ") + prov.getDeviceToken());
  http.addHeader("X-Device-Token", prov.getDeviceToken());
  http.addHeader("X-Device-Id", prov.getDeviceId());
  http.addHeader("X-Device-Capabilities", "temperature,humidity,rssi,telemetry");
  http.setTimeout(8000);

  String payload = "{";
  payload += "\"device_id\":\"" + prov.getDeviceId() + "\",";
  payload += "\"type\":\"sensor.environment\",";
  payload += "\"priority\":\"normal\",";
  payload += "\"data\":{";
  payload += "\"sensor_type\":\"" + tipoSensor() + "\",";
  payload += "\"temperature_c\":" + String(temperatura, 2) + ",";
  payload += "\"humidity_pct\":" + String(umidade, 2) + ",";
  payload += "\"rssi\":" + String(WiFi.RSSI()) + ",";
  payload += "\"uptime_sec\":" + String(millis() / 1000UL) + ",";
  payload += "\"free_heap\":" + String(ESP.getFreeHeap()) + "}}";

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

  if (isnan(temperatura) || isnan(umidade)) {
    Serial.println("[DHT] Falha na leitura do sensor.");
    return;
  }

  Serial.printf("[DHT] Temp=%.2f C Umid=%.2f %%\n", temperatura, umidade);
  enviarLeitura(temperatura, umidade);
}

void verificarPareamento() {
  if (prov.isProvisioned()) return;

  if (prov.getState() == CASA_STATE_UNPROVISIONED) {
    Serial.println("[PAREAMENTO] Solicitando registro na API central CASA...");
    if (prov.requestPairing("sensor", "ESP01-DHT", "["temperature","humidity","telemetry"]")) {
      Serial.println("==========================================================");
      Serial.printf(" [PAREAMENTO PENDENTE] CODIGO: %s\n", prov.getPairingCode().c_str());
      Serial.println(" Abra o aplicativo JARVIS Mobile no celular e autorize.");
      Serial.println("==========================================================");
    } else {
      Serial.println("[PAREAMENTO] Falha ao enviar solicitacao. Tentando em 10s...");
    }
  } else if (prov.getState() == CASA_STATE_PAIRING_REQUESTED) {
    if (millis() - ultimoPollPareamento > INTERVALO_POLL_PAREAMENTO_MS) {
      ultimoPollPareamento = millis();
      Serial.print(".");
      if (prov.pollPairingStatus()) {
        Serial.println("\n==========================================================");
        Serial.println(" [PAREAMENTO APROVADO!]");
        Serial.printf(" Device ID: %s\n", prov.getDeviceId().c_str());
        Serial.println(" Chave individual gravada na EEPROM com sucesso.");
        Serial.println("==========================================================");
      }
    }
  }
}

void setup() {
  Serial.begin(115200);
  delay(100);
  Serial.println("\n=== ESP-01 DHT JARVIS RESIDENCIAL INICIADO ===");

  dht.begin();
  prov.begin(CASA_BASE_URL);

  if (prov.isProvisioned()) {
    Serial.printf("[AUTH] Dispositivo provisionado. ID=%s\n", prov.getDeviceId().c_str());
  } else {
    Serial.println("[AUTH] Dispositivo NAO provisionado. Aguardando pareamento pelo celular.");
  }
}

void loop() {
  conectarWiFi();

  if (WiFi.status() == WL_CONNECTED) {
    if (!prov.isProvisioned()) {
      verificarPareamento();
    } else {
      if (millis() - ultimoEnvio >= INTERVALO_ENVIO_MS || ultimoEnvio == 0) {
        ultimoEnvio = millis();
        lerEEnviar();
      }
    }
  }

  delay(100);
}
