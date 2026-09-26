// Arduino / LILYGO T5 V2.4 / GDEM0213B74
#include <WiFi.h>
#include <SPI.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <GxEPD.h>
#include <GxGDEM0213B74/GxGDEM0213B74.h>
#include <GxIO/GxIO_SPI/GxIO_SPI.h>
#include <qrcode.h>

GxIO_SPI displayIO(SPI,5,17,16);
GxGDEM0213B74 display(displayIO,16,4);
Preferences prefs;
WiFiServer server(8090);
WiFiClient clients[4];
String input[4];
bool overflow[4]={}, authenticated[4]={};
uint32_t lastInput[4]={};
String deviceId, deviceName="FATEC-RP", ssid, password, token;
String lines[4]={"FATEC-RP","Equipamento de teste","",""};
String qrText="FATEC-RP", lastRequest, apName;
bool dirty=true, applyNetwork=false, apOn=false;
uint32_t lastDraw=0, changedAt=0, apUntil=0, retryAt=0, buttonAt=0;
bool buttonHandled=false;

String plainText(String value){
  const char *accents[]={"á","à","â","ã","ä","é","ê","è","ë","í","ì","î","ï","ó","ô","õ","ò","ö","ú","ù","û","ü","ç","Á","À","Â","Ã","Ä","É","Ê","È","Ë","Í","Ì","Î","Ï","Ó","Ô","Õ","Ò","Ö","Ú","Ù","Û","Ü","Ç"};
  const char *plain[]={"a","a","a","a","a","e","e","e","e","i","i","i","i","o","o","o","o","o","u","u","u","u","c","A","A","A","A","A","E","E","E","E","I","I","I","I","O","O","O","O","O","U","U","U","U","C"};
  for(size_t i=0;i<sizeof(accents)/sizeof(accents[0]);i++)value.replace(accents[i],plain[i]);
  return value;
}
bool validLine(const String &s){
  if(s.length()>26)return false;
  for(size_t i=0;i<s.length();i++)if((uint8_t)s[i]<32||(uint8_t)s[i]>126)return false;
  return true;
}
void saveDisplay(){
  StaticJsonDocument<768> doc;
  JsonArray a=doc.createNestedArray("lines");for(auto &s:lines)a.add(s);
  doc["qr"]=qrText;doc["request_id"]=lastRequest;
  String data;serializeJson(doc,data);prefs.putString("display",data);
}
void startAP(){
  WiFi.mode(WIFI_AP_STA);
  if(!apOn){
    WiFi.softAPConfig(IPAddress(192,168,4,1),IPAddress(192,168,4,1),IPAddress(255,255,255,0));
    apOn=WiFi.softAP(apName.c_str(),"fatec1234");
  }
  apUntil=millis()+600000UL;
}
void connectNetwork(){
  if(ssid.isEmpty())return;
  WiFi.setHostname(deviceName.c_str());
  WiFi.config(INADDR_NONE,INADDR_NONE,INADDR_NONE);
  WiFi.begin(ssid.c_str(),password.c_str());retryAt=millis();
}
void reply(uint8_t slot,JsonDocument &doc){serializeJson(doc,clients[slot]);clients[slot].print('\n');}
void errorReply(uint8_t slot,const char *message){
  StaticJsonDocument<256> doc;doc["ok"]=false;doc["error"]=message;reply(slot,doc);
}
void statusReply(uint8_t slot){
  StaticJsonDocument<1536> doc;
  doc["ok"]=true;doc["product"]="FATEC_EPD";doc["protocol"]=2;
  doc["id"]=deviceId;doc["name"]=deviceName;doc["ip"]=WiFi.localIP().toString();
  doc["connected"]=WiFi.status()==WL_CONNECTED;doc["configured"]=!token.isEmpty();
  doc["ssid"]=ssid;doc["ap_ssid"]=apName;doc["port"]=8090;
  doc["max_line"]=26;doc["max_qr_bytes"]=53;
  JsonArray a=doc.createNestedArray("lines");for(auto &s:lines)a.add(s);
  doc["qr"]=qrText;doc["request_id"]=lastRequest;
  reply(slot,doc);
}
bool setupClient(uint8_t slot){return apOn&&clients[slot].localIP()==WiFi.softAPIP();}
void command(uint8_t slot,const String &raw){
  if(raw=="INFO"||raw=="STATUS"){statusReply(slot);return;}
  if(raw.startsWith("AUTH=")){
    authenticated[slot]=!token.isEmpty()&&raw.substring(5)==token;
    clients[slot].println(authenticated[slot]?"OK":"ERR auth");return;
  }
  if(raw.startsWith("TEXT")||raw.startsWith("QR=")){
    if(!token.isEmpty()&&!authenticated[slot]){clients[slot].println("ERR auth");return;}
    if(token.isEmpty()&&!setupClient(slot)){clients[slot].println("ERR setup_only");return;}
    int row=raw.length()>5&&raw[4]>='1'&&raw[4]<='4'&&raw[5]=='='?raw[4]-'1':-1;
    bool isQr=raw.startsWith("QR=");
    if(row<0&&!isQr){clients[slot].println("ERR command");return;}
    String value=raw.substring(isQr?3:6);
    if(value.startsWith("[")&&value.endsWith("]"))value=value.substring(1,value.length()-1);
    if(!isQr)value=plainText(value);
    if((isQr&&value.length()>53)||(!isQr&&!validLine(value))){clients[slot].println("ERR length_or_charset");return;}
    if(isQr)qrText=value;else lines[row]=value;
    lastRequest="";saveDisplay();dirty=true;changedAt=millis();clients[slot].println("OK");return;
  }
  StaticJsonDocument<2048> doc;
  if(deserializeJson(doc,raw)){errorReply(slot,"invalid_json");return;}
  String op=doc["op"]|"";
  if(op=="discover"||op=="status"){statusReply(slot);return;}
  if(!token.isEmpty()&&String(doc["token"]|"")!=token){errorReply(slot,"auth");return;}
  if(op=="configure"){
    if(!setupClient(slot)){errorReply(slot,"setup_wifi_required");return;}
    String n=doc["name"]|"",s=doc["ssid"]|"",p=doc["password"]|"",t=doc["new_token"]|"";
    if(n.isEmpty()||n.length()>32||s.isEmpty()||s.length()>32||p.length()>63||(!p.isEmpty()&&p.length()<8)||t.length()!=32){errorReply(slot,"invalid_configuration");return;}
    for(size_t i=0;i<n.length();i++)if(!isalnum((unsigned char)n[i])&&n[i]!='-'){errorReply(slot,"invalid_name");return;}
    if(n[0]=='-'||n[n.length()-1]=='-'){errorReply(slot,"invalid_name");return;}
    StaticJsonDocument<768> cfg;cfg["name"]=n;cfg["ssid"]=s;cfg["password"]=p;cfg["token"]=t;
    String saved;serializeJson(cfg,saved);
    if(prefs.putString("config",saved)!=saved.length()){errorReply(slot,"storage");return;}
    deviceName=n;ssid=s;password=p;token=t;
    applyNetwork=true;statusReply(slot);return;
  }
  if(op=="set"){
    if(token.isEmpty()&&!setupClient(slot)){errorReply(slot,"setup_wifi_required");return;}
    JsonArray a=doc["lines"].as<JsonArray>();
    if(a.size()!=4||!doc["qr"].is<const char*>()){errorReply(slot,"four_lines_and_qr_required");return;}
    String candidate[4];for(int i=0;i<4;i++){
      if(!a[i].is<const char*>()){errorReply(slot,"invalid_line");return;}
      candidate[i]=plainText(a[i].as<String>());
      if(!validLine(candidate[i])){errorReply(slot,"line_max_26_ascii");return;}
    }
    String qr=doc["qr"].as<String>(),id=doc["request_id"]|"";
    if(qr.length()>53||id.length()>64){errorReply(slot,"qr_or_request_too_long");return;}
    if(id.isEmpty()||id!=lastRequest){
      for(int i=0;i<4;i++)lines[i]=candidate[i];qrText=qr;lastRequest=id;
      saveDisplay();dirty=true;changedAt=millis();
    }
    statusReply(slot);return;
  }
  errorReply(slot,"unknown_operation");
}
void networkClients(){
  for(uint8_t i=0;i<4;i++){
    if(!clients[i])continue;
    size_t budget=512;
    while(clients[i].available()&&budget--){
      char c=clients[i].read();lastInput[i]=millis();
      if(c=='\n'||c=='\r'){
        if(overflow[i])errorReply(i,"command_too_long");else if(input[i].length())command(i,input[i]);
        input[i]="";overflow[i]=false;
      }else if(!overflow[i]){
        if(input[i].length()<2048)input[i]+=c;else{input[i]="";overflow[i]=true;}
      }
    }
    if((!clients[i].connected()&&!clients[i].available())||millis()-lastInput[i]>60000){clients[i].stop();input[i]="";authenticated[i]=false;}
  }
  WiFiClient c=server.available();if(!c)return;
  for(int i=0;i<4;i++)if(!clients[i]){clients[i]=c;clients[i].setNoDelay(true);input[i]="";overflow[i]=false;authenticated[i]=false;lastInput[i]=millis();return;}
  c.println("{\"ok\":false,\"error\":\"busy\"}");c.stop();
}
void drawScreen(){
  display.fillScreen(GxEPD_WHITE);display.setTextColor(GxEPD_BLACK);display.setFont(nullptr);display.setTextWrap(false);
  if(!qrText.isEmpty()){
    QRCode qr;uint8_t buf[256];
    if(qrcode_initText(&qr,buf,3,ECC_LOW,qrText.c_str())==0){
      // QR v3: 29 modulos + quiet zone de 4 por lado = 74 pixels a escala 2.
      const int x0=4+8,y0=(display.height()-74)/2+8;
      for(int y=0;y<qr.size;y++)for(int x=0;x<qr.size;x++)
        if(qrcode_getModule(&qr,x,y))display.fillRect(x0+x*2,y0+y*2,2,2,GxEPD_BLACK);
    }
  }
  display.drawFastVLine(83,4,display.height()-8,GxEPD_BLACK);
  for(int i=0;i<4;i++){
    uint8_t size=lines[i].length()<=13?2:1;
    display.setTextSize(size);display.setCursor(89,16+i*30-4*size);display.print(lines[i]);
  }
  display.update();dirty=false;lastDraw=millis();
}
void setup(){
  Serial.begin(115200);pinMode(12,OUTPUT);digitalWrite(12,HIGH);pinMode(39,INPUT);
  delay(100);SPI.begin(18,-1,23,5);display.init(115200);display.setRotation(1);
  WiFi.mode(WIFI_AP_STA);WiFi.persistent(false);
  deviceId=WiFi.macAddress();apName="FATEC-EPD-"+deviceId.substring(9);apName.replace(":","");
  prefs.begin("agendador",false);
  StaticJsonDocument<1024> doc;
  if(!deserializeJson(doc,prefs.getString("config","{}"))){deviceName=String(doc["name"]|"FATEC-RP");ssid=String(doc["ssid"]|"");password=String(doc["password"]|"");token=String(doc["token"]|"");}
  doc.clear();if(!deserializeJson(doc,prefs.getString("display","{}"))){
    if(doc["lines"].size()==4)for(int i=0;i<4;i++)lines[i]=doc["lines"][i].as<String>();
    qrText=String(doc["qr"]|"FATEC-RP");lastRequest=String(doc["request_id"]|"");
  }
  startAP();connectNetwork();server.begin();drawScreen();
  Serial.printf("Setup Wi-Fi: %s / fatec1234\nTCP: 8090; IP setup: 192.168.4.1\n",apName.c_str());
}
void loop(){
  networkClients();
  if(applyNetwork){applyNetwork=false;connectNetwork();}
  if(!ssid.isEmpty()&&WiFi.status()!=WL_CONNECTED&&millis()-retryAt>30000){startAP();connectNetwork();}
  if(apOn&&WiFi.status()==WL_CONNECTED&&(int32_t)(millis()-apUntil)>=0){WiFi.softAPdisconnect(true);apOn=false;}
  if(digitalRead(39)==LOW){
    if(!buttonAt)buttonAt=millis();
    if(!buttonHandled&&millis()-buttonAt>=3000){startAP();buttonHandled=true;}
  }else{buttonAt=0;buttonHandled=false;}
  if(dirty&&millis()-changedAt>=250&&millis()-lastDraw>=2000)drawScreen();
  delay(2);
}
