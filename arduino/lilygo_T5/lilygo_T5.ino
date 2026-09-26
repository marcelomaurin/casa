// LILYGO T5 V2.4, painel GDEM0213B74. Arduino ESP32 Dev Module.
#include <WiFi.h>
#include <SPI.h>
#include <GxEPD.h>
#include <GxGDEM0213B74/GxGDEM0213B74.h>
#include <GxIO/GxIO_SPI/GxIO_SPI.h>

constexpr uint8_t EPD_POWER = 12;
GxIO_SPI displayIO(SPI, /*CS=*/5, /*DC=*/17, /*RST=*/16);
GxGDEM0213B74 display(displayIO, /*RST=*/16, /*BUSY=*/4);

const char *AP_SSID = "FATEC-RP-TESTE";
const char *AP_PASSWORD = "fatec1234";
constexpr uint16_t TCP_PORT = 8090;
constexpr size_t MAX_TEXT = 38;
constexpr size_t MAX_COMMAND = 160;
constexpr uint32_t IDLE_TIMEOUT = 60000;
WiFiServer server(TCP_PORT);
WiFiClient clients[4];
String input[4];
bool overflow[4] = {};
uint32_t lastInput[4] = {};
String text1 = "FATEC-RP";
String text2 = "Equipamento de teste";
bool displayDirty = false;
uint32_t changedAt = 0, refreshedAt = 0;

// A fonte padrao nao interpreta UTF-8: converte acentos portugueses para ASCII.
String displayText(String value) {
  const char *accents[] = {"á","à","â","ã","ä","é","ê","è","ë","í","ì","î","ï","ó","ô","õ","ò","ö","ú","ù","û","ü","ç","Á","À","Â","Ã","Ä","É","Ê","È","Ë","Í","Ì","Î","Ï","Ó","Ô","Õ","Ò","Ö","Ú","Ù","Û","Ü","Ç"};
  const char *plain[] = {"a","a","a","a","a","e","e","e","e","i","i","i","i","o","o","o","o","o","u","u","u","u","c","A","A","A","A","A","E","E","E","E","I","I","I","I","O","O","O","O","O","U","U","U","U","C"};
  for (size_t i=0; i<sizeof(accents)/sizeof(accents[0]); ++i) value.replace(accents[i], plain[i]);
  return value;
}

void drawLine(const String &value, int16_t centerY) {
  const uint8_t size = value.length() <= 19 ? 2 : 1;
  const int16_t width = value.length() * 6 * size;
  display.setTextSize(size);
  display.setCursor((display.width()-width)/2, centerY-4*size);
  display.print(value);
}

void drawScreen() {
  display.fillScreen(GxEPD_WHITE);
  display.setFont(nullptr);
  display.setTextColor(GxEPD_BLACK);
  display.setTextWrap(false);
  drawLine(text1, display.height()/3);
  drawLine(text2, display.height()*2/3);
  display.update(); // Atualizacao completa, evita imagens residuais.
  displayDirty = false;
  refreshedAt = millis();
}

void processCommand(uint8_t slot) {
  String command = input[slot];
  if (!command.startsWith("TEXT1=") && !command.startsWith("TEXT2=")) {
    clients[slot].println("ERR use TEXT1=mensagem ou TEXT2=mensagem");
    return;
  }
  String value = command.substring(6);
  // Aceita tambem TEXT1=[mensagem], sem imprimir os colchetes externos.
  if (value.startsWith("[") && value.endsWith("]")) value = value.substring(1,value.length()-1);
  value = displayText(value);
  if (value.length() > MAX_TEXT) {
    clients[slot].println("ERR maximo 38 caracteres por linha");
    return;
  }
  for (size_t i=0; i<value.length(); ++i) {
    if ((uint8_t)value[i]<32 || (uint8_t)value[i]>126) {
      clients[slot].println("ERR caractere nao suportado");
      return;
    }
  }
  String &target = command.startsWith("TEXT1=") ? text1 : text2;
  if (target != value) {
    target = value;
    changedAt = millis();
    displayDirty = true;
  }
  clients[slot].println("OK"); // Aceito; a tela e atualizada logo depois.
}

void serviceClients() {
  for (uint8_t i=0; i<4; ++i) {
    if (!clients[i]) continue;
    size_t budget = 256;
    while (clients[i].available() && budget--) {
      const char c = (char)clients[i].read();
      lastInput[i] = millis();
      if (c=='\n' || c=='\r') {
        if (overflow[i]) clients[i].println("ERR comando muito longo");
        else if (input[i].length()) processCommand(i);
        input[i] = "";
        overflow[i] = false;
      } else if (!overflow[i]) {
        if (input[i].length()<MAX_COMMAND) input[i] += c;
        else {overflow[i]=true; input[i]="";}
      }
    }
    if ((!clients[i].connected() && !clients[i].available()) || millis()-lastInput[i]>=IDLE_TIMEOUT) {
      clients[i].stop(); input[i]=""; overflow[i]=false;
    }
  }
  WiFiClient incoming = server.available();
  if (!incoming) return;
  for (uint8_t i=0; i<4; ++i) {
    if (!clients[i]) {
      clients[i]=incoming; input[i]=""; overflow[i]=false;
      lastInput[i]=millis(); clients[i].setNoDelay(true);
      return;
    }
  }
  incoming.println("ERR servidor ocupado"); incoming.stop();
}

void setup() {
  Serial.begin(115200);
  pinMode(EPD_POWER, OUTPUT);
  digitalWrite(EPD_POWER, HIGH);
  delay(100);
  SPI.begin(/*SCK=*/18, /*MISO=*/-1, /*MOSI=*/23, /*SS=*/5);
  display.init(115200);
  display.setRotation(1); // Horizontal: 250 x 128 no driver B74.
  drawScreen();

  WiFi.mode(WIFI_AP);
  IPAddress ip(192,168,4,1);
  if (!WiFi.softAPConfig(ip,ip,IPAddress(255,255,255,0)) ||
      !WiFi.softAP(AP_SSID,AP_PASSWORD)) {
    Serial.println("Falha ao iniciar Wi-Fi. Reinicie a placa.");
    return;
  }
  server.begin();
  Serial.printf("Wi-Fi: %s\nIP: %s\nTCP: %u\n",AP_SSID,WiFi.softAPIP().toString().c_str(),TCP_PORT);
}

void loop() {
  serviceClients();
  // Agrupa TEXT1/TEXT2 recebidos juntos; no maximo uma atualizacao a cada 2 s.
  if (displayDirty && millis()-changedAt>=200 && millis()-refreshedAt>=2000) drawScreen();
  delay(2);
}
