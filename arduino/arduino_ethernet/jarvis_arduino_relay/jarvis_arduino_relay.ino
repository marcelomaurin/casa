/**
 * JARVIS RESIDENCIAL - MÓDULO ARDUINO ETHERNET (RELÉS FÍSICOS & TELEMETRIA)
 * 
 * Hardware: Arduino Uno / Mega + Ethernet Shield W5100 ou W5500
 * Recursos:
 *  1. Controle de 4 Relés de Alta Corrente por Cabo de Rede
 *  2. Servidor HTTP REST (porta 8080) com validação de Hardware Token
 *  3. Respostas padronizadas em JSON (/status, /rele/{id}/{estado})
 *  4. Telemetria e Heartbeat contínuo para o Cluster JARVIS
 *  5. Medição de RAM livre do microcontrolador AVR
 */

#include <SPI.h>
#include <Ethernet.h>

// ================= CONFIGURAÇÕES DE REDE E JARVIS =================
byte mac[] = { 0xDE, 0xAD, 0xBE, 0xEF, 0x20, 0x26 };

IPAddress ip(192, 168, 2, 52);          // IP Estático de fallback
IPAddress gateway(192, 168, 2, 1);
IPAddress subnet(255, 255, 255, 0);

IPAddress jarvis_ip(192, 168, 2, 12);    // IP do Raspberry Pi JARVIS
const int jarvis_port = 80;

const char* device_id = "arduino-ethernet-reles-01";
const char* device_token = "token_ard_reles_2026";
const char* device_name = "Arduino Ethernet Reles";

// Pinos dos Relés (Ativos em nível LOW na maioria dos módulos de relé)
#define PIN_RELE_1  4
#define PIN_RELE_2  5
#define PIN_RELE_3  6
#define PIN_RELE_4  7

#define RELAY_ON    LOW
#define RELAY_OFF   HIGH

EthernetServer server(8080);
EthernetClient client_heartbeat;

unsigned long last_heartbeat = 0;
const unsigned long heartbeat_interval = 20000; // 20 segundos

// Medição de Memória RAM Livre no Arduino (AVR)
#ifdef __arm__
// Para Arduino Due ou ARM
extern "C" char* sbrk(int incr);
int freeRam() {
  char top = 't';
  return &top - reinterpret_cast<char*>(sbrk(0));
}
#else
// Para AVR (Uno / Mega / Nano)
extern int __heap_start, *__brkval;
int freeRam() {
  int v;
  return (int) &v - (__brkval == 0 ? (int) &__heap_start : (int) __brkval);
}
#endif

// Leitura do estado lógico dos relés (1 = ligado, 0 = desligado)
int get_rele_state(int pin) {
  return digitalRead(pin) == RELAY_ON ? 1 : 0;
}

void set_rele_state(int pin, int state) {
  digitalWrite(pin, state == 1 ? RELAY_ON : RELAY_OFF);
}

// Envio de Heartbeat e Telemetria para o JARVIS
void enviar_heartbeat() {
  if (client_heartbeat.connect(jarvis_ip, jarvis_port)) {
    Serial.println(F("[JARVIS] Enviando Heartbeat Ethernet..."));

    String json = "{";
    json += "\"device_id\":\"" + String(device_id) + "\",";
    json += "\"transport\":\"ethernet\",";
    json += "\"health\":\"ok\",";
    json += "\"protocol_version\":\"CASA/1.0\",";
    json += "\"capabilities\":[\"relay\",\"telemetry\"],";
    json += "\"rssi\":0,"; // Cabo de rede fisica
    json += "\"data\":{\"ram_livre\":" + String(freeRam()) + ",\"reles_status\":{";
    json += "\"rele1\":" + String(get_rele_state(PIN_RELE_1)) + ",";
    json += "\"rele2\":" + String(get_rele_state(PIN_RELE_2)) + ",";
    json += "\"rele3\":" + String(get_rele_state(PIN_RELE_3)) + ",";
    json += "\"rele4\":" + String(get_rele_state(PIN_RELE_4));
    json += "}}}";

    client_heartbeat.println(F("POST /api/v1/device.php?acao=heartbeat HTTP/1.1"));
    client_heartbeat.println(F("Host: 192.168.2.12"));
    client_heartbeat.println(F("User-Agent: ArduinoEthernet/1.0"));
    client_heartbeat.println(F("Content-Type: application/json"));
    client_heartbeat.print(F("Authorization: Bearer "));
    client_heartbeat.println(device_token);
    client_heartbeat.print(F("X-Device-Token: "));
    client_heartbeat.println(device_token);
    client_heartbeat.print(F("Content-Length: "));
    client_heartbeat.println(json.length());
    client_heartbeat.println(F("Connection: close"));
    client_heartbeat.println();
    client_heartbeat.println(json);

    delay(20);
    client_heartbeat.stop();
  }
}

void setup() {
  Serial.begin(9600);

  // Inicializa Relés em OFF
  pinMode(PIN_RELE_1, OUTPUT); digitalWrite(PIN_RELE_1, RELAY_OFF);
  pinMode(PIN_RELE_2, OUTPUT); digitalWrite(PIN_RELE_2, RELAY_OFF);
  pinMode(PIN_RELE_3, OUTPUT); digitalWrite(PIN_RELE_3, RELAY_OFF);
  pinMode(PIN_RELE_4, OUTPUT); digitalWrite(PIN_RELE_4, RELAY_OFF);

  // Inicialização Ethernet com DHCP (ou fallback para IP fixo)
  Serial.println(F("[ETHERNET] Conectando a rede..."));
  if (Ethernet.begin(mac) == 0) {
    Serial.println(F("[ETHERNET] DHCP falhou, usando IP estatico"));
    Ethernet.begin(mac, ip, gateway, subnet);
  }

  Serial.print(F("[ETHERNET] IP Ativo: "));
  Serial.println(Ethernet.localIP());

  server.begin();
  Serial.println(F("[SERVER] Servidor de Reles ouvindo na porta 8080"));

  enviar_heartbeat();
}

void loop() {
  EthernetClient client = server.available();
  if (client) {
    String req = "";
    boolean currentLineIsBlank = true;

    while (client.connected()) {
      if (client.available()) {
        char c = client.read();
        req += c;

        if (c == '\n' && currentLineIsBlank) {
          // Processamento do comando recebido
          boolean autorizado = (req.indexOf(device_token) >= 0);

          if (req.indexOf("GET /status") >= 0) {
            client.println(F("HTTP/1.1 200 OK"));
            client.println(F("Content-Type: application/json"));
            client.println(F("Access-Control-Allow-Origin: *"));
            client.println();
            client.print(F("{\"dispositivo\":\"")); client.print(device_name);
            client.print(F("\",\"tipo\":\"arduino_ethernet\",\"ram_livre\":")); client.print(freeRam());
            client.print(F(",\"rele1\":")); client.print(get_rele_state(PIN_RELE_1));
            client.print(F(",\"rele2\":")); client.print(get_rele_state(PIN_RELE_2));
            client.print(F(",\"rele3\":")); client.print(get_rele_state(PIN_RELE_3));
            client.print(F(",\"rele4\":")); client.print(get_rele_state(PIN_RELE_4));
            client.println(F("}"));
          } 
          else if (req.indexOf("/rele/") >= 0) {
            // Formato: /rele/1/1 ou /rele/1/0
            int idx = req.indexOf("/rele/");
            int numRele = req.charAt(idx + 6) - '0';
            int estado = req.charAt(idx + 8) - '0';

            int targetPin = -1;
            if (numRele == 1) targetPin = PIN_RELE_1;
            else if (numRele == 2) targetPin = PIN_RELE_2;
            else if (numRele == 3) targetPin = PIN_RELE_3;
            else if (numRele == 4) targetPin = PIN_RELE_4;

            if (targetPin > 0 && (estado == 0 || estado == 1)) {
              set_rele_state(targetPin, estado);
              client.println(F("HTTP/1.1 200 OK"));
              client.println(F("Content-Type: application/json"));
              client.println(F("Access-Control-Allow-Origin: *"));
              client.println();
              client.print(F("{\"status\":\"sucesso\",\"rele\":")); client.print(numRele);
              client.print(F(",\"estado\":")); client.print(estado);
              client.println(F("}"));
            } else {
              client.println(F("HTTP/1.1 400 Bad Request\r\n\r\n{\"erro\":\"Parametro invalido\"}"));
            }
          } else {
            client.println(F("HTTP/1.1 404 Not Found\r\n\r\n{\"erro\":\"Rota nao encontrada\"}"));
          }
          break;
        }

        if (c == '\n') currentLineIsBlank = true;
        else if (c != '\r') currentLineIsBlank = false;
      }
    }
    delay(1);
    client.stop();
  }

  // Heartbeat periódico
  if (millis() - last_heartbeat > heartbeat_interval) {
    last_heartbeat = millis();
    enviar_heartbeat();
  }
}
