

#include <ESP8266WiFi.h>
#include <WiFiUdp.h>

#ifndef STASSID
#define STASSID "maurinsrv_1"
#define STAPSK  "1425361425"
#endif

WiFiServer wifiServer(8090);

const uint16_t portClient = 8090;
//const char * host = "192.168.0.105";


#define RELE01 D5
#define RELE02 D6


#ifndef D5
#if defined(ESP8266)
#define D5 (14)
#define D6 (12)
#define D7 (13)
#define D8 (15)
#define TX (1)
#elif defined(ESP32)
#define D5 (18)
#define D6 (19)
#define D7 (23)
#define D8 (5)
#define TX (1)
#endif
#endif

const char* ssid     = STASSID;
const char* password = STAPSK;

//const char* host = "maurinsoft.com.br";
const char* host = "192.168.0.105";
const uint16_t port = 17;

String Buffer;
int flag;
int flgImprime;

String inputString = "";         // a String to hold incoming data
bool stringComplete = false;  // whether the string is complete

void set_rele01(bool status);
void set_rele02(bool status);
void processacmd(String info);

void set_serial(){
   Serial.begin(9600);
}

void processacmd(String info){
  Serial.print("Processou");
  Serial.println(info);
}

void set_wifi() {
   /* Explicitly set the ESP8266 to be a WiFi-client, otherwise, it by default,
     would try to act as both a client and an access-point and could cause
     network-issues with your other WiFi-devices on your WiFi-network. */
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();
  Serial.println();
  Serial.print("Connecting to ");
  Serial.println(ssid);  
}

void set_pins(){
    pinMode(RELE01, OUTPUT);
    pinMode(RELE02, OUTPUT);
    set_rele01(false);
    set_rele02(false);
}

void myip(){
  Serial.println("");
  Serial.println("WiFi connected");
  Serial.println("IP address: ");
  Serial.println(WiFi.localIP());
}


void start_srv(){
    wifiServer.begin();
}



void setup() {
  set_serial();
  set_pins();
  // We start by connecting to a WiFi network
  set_wifi();
  
  
  myip();
  Buffer = "";
  start_srv();
  flag = 0;
  flgImprime = 0;

}

void set_rele01(bool status){
  digitalWrite(RELE01,((status==false)?HIGH:LOW));
}

void set_rele02(bool status){
  digitalWrite(RELE02,((status==false)?HIGH:LOW));
}



void le_srv(){
  WiFiClient client = wifiServer.available();
  String buffer;
 
  if (client) {
 
    while (client.connected()) { 
      while (client.available()>0) {
        char c = client.read();
        client.write(c);
        if (c==0x10){
          processacmd(buffer);
          buffer = "";
        } else {
           buffer=+c;
        }
        
      }
 
      delay(10);
    }
 
    client.stop();
    Serial.println("Client disconnected");
 
  }
}

void loop() { 
  le_srv();
  serialEvent();

}



/*
  SerialEvent occurs whenever a new data comes in the hardware serial RX. This
  routine is run between each time loop() runs, so using delay inside loop can
  delay response. Multiple bytes of data may be available.
*/
void serialEvent() {
  while (Serial.available()) {
    // get the new byte:
    char inChar = (char)Serial.read();
    // add it to the inputString:
    inputString += inChar;
    Serial.print(inChar);
    // if the incoming character is a newline, set a flag so the main loop can
    // do something about it:
    if (inChar == '\n') {
      stringComplete = true;
    }
  }
}
