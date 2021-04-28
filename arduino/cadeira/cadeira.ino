
/*
    This sketch establishes a TCP connection to a "quote of the day" service.
    It sends a "hello" message, and then prints received data.
*/
#include <NTPClient.h>
#include <ESP8266WiFi.h>
#include <SoftwareSerial.h>
#include <WiFiUdp.h>

#ifndef STASSID
#define STASSID "maurinsrv_1"
#define STAPSK  "1425361425"
#endif

const long utcOffsetInSeconds = -10800; //- 3h * 60 * 60
char daysOfTheWeek[7][12] = {"Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"};

const uint16_t portClient = 8090;
//const char * host = "192.168.0.213";

// Define NTP Client to get time
WiFiUDP ntpUDP;
NTPClient timeClient(ntpUDP, "south-america.pool.ntp.org", utcOffsetInSeconds);




SoftwareSerial swSer;

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

#ifdef ESP32
#define BAUD_RATE 9600
#else
#define BAUD_RATE 9600
#endif

const char* ssid     = STASSID;
const char* password = STAPSK;

const char* host = "maurinsoft.com.br";
const uint16_t port = 17;

String Buffer;
int flag;
int flgImprime;

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

void myip(){
  Serial.println("");
  Serial.println("WiFi connected");
  Serial.println("IP address: ");
  Serial.println(WiFi.localIP());
}


void set_time() {
   timeClient.begin();
}
void set_serial() {
  
  Serial.begin(9600);
  //swSer.begin(BAUD_RATE, SWSERIAL_8N1, D5, D6, false, 95, 11);
  swSer.begin(BAUD_RATE, SWSERIAL_8N1, D5, D6, false, 128);
  
}

void Mainpage(){
  char info[40];
  sprintf(info,"page Main%c%c%c",0xFF,0xFF,0xFF);
  swSer.println(info);
  
}

void setup() {
  set_serial();
  // We start by connecting to a WiFi network
  set_wifi();
  set_time();
  myip();
  Buffer = "";
  flag = 0;
  flgImprime = 0;
  Mainpage();
}

void readsite(){
  Serial.print("connecting to ");
  Serial.print(host);
  Serial.print(':');
  Serial.println(port);

  // Use WiFiClient class to create TCP connections
  WiFiClient client;
  if (!client.connect(host, port)) {
    Serial.println("connection failed");
    delay(5000);
    return;
  }

  // This will send a string to the server
  Serial.println("sending data to server");
  if (client.connected()) {
    client.println("hello from ESP8266");
  }

  // wait for data to be available
  unsigned long timeout = millis();
  while (client.available() == 0) {
    if (millis() - timeout > 5000) {
      Serial.println(">>> Client Timeout !");
      client.stop();
      delay(60000);
      return;
    }
  }

  // Read all the lines of the reply from server and print them to Serial
  Serial.println("receiving from remote server");
  // not testing 'client.connected()' since we do not need to send data here
  while (client.available()) {
    char ch = static_cast<char>(client.read());
    Serial.print(ch);
  }

  // Close the connection
  Serial.println();
  Serial.println("closing connection");
  client.stop();
}

void EntraTXT(char *device, char *valor){
  char info[40];
  sprintf(info,"%s.txt=%c%s%c%c%c%c",device,0x22,valor,0x22,0xFF,0xFF,0xFF);
  swSer.print(info);
}


void Atualizahora(){
  char info[40];
  char hora[20];
  //Serial.print(daysOfTheWeek[timeClient.getDay()]);
  //sprintf(info,"%s %s:%s:%s,\0xff\0xff\0xff", daysOfTheWeek[timeClient.getDay()],timeClient.getHours(),timeClient.getMinutes(),timeClient.getSeconds());
  timeClient.getFormattedTime().toCharArray(hora, timeClient.getFormattedTime().length()+1);
  EntraTXT("hora",hora);
  //sprintf(info,"hora.txt=%c%s%c%c%c%c",0x22,hora,0x22,0xFF,0xFF,0xFF);
  //swSer.print(info);
}


void SendCMD(String cmd){
    Serial.println(" ");
    WiFiClient client; 
    if (!client.connect(host, portClient)) {
        Serial.println("Connection to host failed");
        delay(1000);
        return;
    } 
    Serial.println("Connected to server successful!");
    client.print(cmd);
    Serial.print("Comando:");
    Serial.println(cmd);
    Serial.println("Disconnecting...");
    client.stop();
    
}

void ProcessaCMD(String cmd){
  char info3[80];
  Buffer.toCharArray(info3,Buffer.length());
  //sprintf(info3,"123");
  String pcmd =  cmd+0x10;
  SendCMD(pcmd);
  //Serial.print(info3); //Send data recived from software serial to hardware serial   
}


void loop() {
  timeClient.update();
  Atualizahora();  
  while(swSer.available() > 0) {  //wait for data at software serial
    char c =swSer.read();
    //Serial.print(c);
    if (c=='e') {
      flag = 1;
    }
    if (c==0xFF) {
      flag = 0;
    }
    if(flag==0){  
      if((c != 0xFF) &&(c!=0x0A)){
        Buffer +=c;   
      }
      if(c==0x0A){
        flgImprime = 1;
      }
    }    
  }
  if(Buffer!=""){
    if(flgImprime !=0){
      ProcessaCMD(Buffer);
      flgImprime =0;
    }
    Buffer="";
  }  
}
