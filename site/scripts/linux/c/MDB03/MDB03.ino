

#include <SPI.h>
#include <Ethernet.h>
// include the library code:
#include <LiquidCrystal.h>



/*
Padrao display
 Info = Produto/Temperatura / Humidade / Hora
 Info2  = Status de operacao
 
 */

#define DHT11_PIN 0      // define anlog  port 0

String BufferCMD = " ";  //Buffer para armazenamento de comandos da serial

//Versao do produto
char Versao = '3';
char Release = '1';

//XXX - Produto - XXX - Lote - XXX - nro 
String NroSerie = "003001001";
String Empresa = "Maurinsoft automacao inteligente para sua casa";
String EMAIL = "comercial@maurinsoft.com";
String SITE = "http://maurinsoft.com";
String Produto = "MBR03 "+ String(Versao)+"."+String(Release); 


//Mensagens do LDC
String Msg1 = Produto;
String Msg2 = Empresa;

// initialize the library with the numbers of the interface pins
LiquidCrystal lcd(7, 6, 5, 4, 3, 2);

String Info = "";
String Info2 = "";
bool flgLuz = false;
bool flgAlarmeBloc = false;
bool flgMovimento = false;
bool statuscheck = false;

int Temperatura = 0;
int Humidade = 0;

// Enter a MAC address and IP address for your controller below.
// The IP address will be dependent on your local network.
// gateway and subnet are optional:
byte mac[] = { 
  0xDE, 0xAD, 0xAE, 0xEF, 0xFE, 0xED };
byte ip[] = { 
  192,168,0, 171 };
byte gateway[] = { 
  192,168,0, 1 };
byte subnet[] = { 
  255, 255, 0, 0 };

// telnet defaults to port 23
EthernetServer server(2023);
boolean gotAMessage = false; // whether or not you got a message from the client yet
//Server webserver(8080);

int pinsound = 12;


void setup() {

  Serial.begin(19200); //Serial Padrao 
  Serial.println(Produto);
  Serial.println(Empresa);
  Serial.print("Nro Serie:");
  Serial.println(NroSerie);
  Serial.print("Site:");
  Serial.println(SITE);
  Serial.println(EMAIL);
  Serial.println(SITE);
  Serial.println("Bem vindo ");
  Serial.println("Iniciando Ethernet...");
  Ethernet_Start();
  Serial.println("Iniciando Portas...");


  Serial.println("Iniciando LDC...");
  //Inicia trato de LDC
  LDC_Start();
  Serial.println("Imprimindo LDC..."); 
  Serial.println("Setup finalizado...");
  ImprimeConsole();


}

void Ethernet_Start()
{
  // initialize the ethernet device
  Ethernet.begin(mac, ip, gateway, subnet); 
  //webserver.begin();
  server.begin();
}




//Inicia LDC
void LDC_Start()
{
  // set up the LCD's number of columns and rows:
  lcd.begin(16, 2);
  //lcd.autoscroll();

  Msg1 = Produto;
  Msg2 = "Servico Online ";
  LDC();
  delay(2000);
}






void LDC()
{
  if ((Msg1 != Info) || (Msg2 != Info2))
  {
    lcd.setCursor(0, 0);

    // Print a message to the LCD.
    Info = Msg1; 
    lcd.print(Info);
    //Serial.println("LCD Msg1:"+Info);


    lcd.setCursor(0, 1);
    //lcd.autoscroll();
    // Print a message to the LCD.
    Info2 = Msg2;
    lcd.print(Info2);
    //Serial.println("LCD Msg2:"+Info2);
  }

}






void SensorMovimento() 
{
  int value = false;
  if (HIGH == value) 
  {   // compara 
    flgMovimento = true;
    Msg2 = "mov detectado ";
  } 
  else 
  {
    flgMovimento = false;
    Msg2 = "Sem movimento";
  }

}




//CSS padrao
void WebCSS(EthernetClient client)
{
  client.println(" <style type='text/css'>");
  client.println("#container {");
  client.println("border: 2px dashed #444;");
  client.println("height: 125px;");
  client.println("text-align: justify;");
  client.println("-ms-text-justify: distribute-all-lines;");
  client.println("    text-justify: distribute-all-lines;");
  client.println("    min-width: 612px;");
  client.println("}");

  client.println(".page, .menu, .corpo {");
  client.println("    width: 150px;");
  client.println("    height: 125px;");
  client.println("    vertical-align: top;");
  client.println("    display: inline-block;");
  client.println("    *display: inline;");
  client.println("    zoom: 1");
  client.println("}");
  client.println(".stretch {");
  client.println("    width: 100%;");
  client.println("    display: inline-block;");
  client.println("    font-size: 0;");
  client.println("    line-height: 0");
  client.println("}");
  client.println(".menu, .corpo {");
  client.println("    background: #ccc");
  client.println("}");
  client.println(".page {");
  client.println("    background: #0ff");
  client.println("}");
  client.println("</style>");


}


//Pagina principal 
void WebMain(EthernetClient client)
{
  client.print("<CENTER><H1>Monitoramento Sala</H1></CENTER>");                  
  client.println("<br />");

  client.print("<h3>Leitura dos dispositivos</h3>");          
  client.println("<br />");

  //Dispositivo de Porta
  client.print("Porta Sala:");
  //client.print(Rele02==true?"Ligado":"Desligado");
  client.print("Desligado");
  client.println("<br />");

  //Dispositivo Luz Sala
  client.print("Luz Sala:");
  client.print(flgLuz==true?"Ligado":"Desligado");
  client.println("<br/>");

  //String statuscheck = boAlarme==true?"Ligado":"Desligado";
  client.println("Alarme:"+ statuscheck);
  client.println("<br/>");

  statuscheck = flgAlarmeBloc==true?"Ligado":"Desligado";
  client.println("Bloqueio Alarme:"+statuscheck);         
  client.println("<br/>");

  statuscheck = flgMovimento==true?"Ligado":"Desligado";
  client.println("Movimento Detector:"+statuscheck);         
  client.println("<br/>");
  client.println("<br/>");

  //Temperatura
  client.print("Temperatura:");
  client.print(Temperatura);
  client.println("<br />");

  //Humidade da sala
  client.print("Humidade da Sala:");
  client.print(Humidade);
  client.println("<br />"); 
}


void EthernetMainPage(EthernetClient client)
{
  // send a standard http response header
  client.println("HTTP/1.1 200 OK");
  client.println("Content-Type: text/html");
  client.println();
  //WebCSS(client);
  client.println("<head>");
  client.println("<meta http-equiv='Refresh' content='5;url=pagina.ext'>");
  client.println("<title>"+Produto+"</title>");
  client.println("</head>");
  client.println("<body>");
  client.println("<div id='corpo' class='corpo'>");
  client.println("<div id='menu' class='menu'>");
  client.println("</div>");
  client.println("<div id='page' class='page'>");

  //WebMain(client);
  client.println("</div>");
  client.println("</div>");

  client.println("</body>"); 

  client.println("<br/>");
  client.println("<form method='get'>");         



  client.println("</form>");


  client.println("<br/>");    
  //EthernetSet(client);
}

//Analisa conexao web
void Web()
{
  // listen for incoming clients
  EthernetClient clientweb = server.available();
  if (clientweb) {
    // an http request ends with a blank line
    boolean currentLineIsBlank = true;
    while (clientweb.connected()) {
      if (clientweb.available()) {
        char c = clientweb.read();
        // if you've gotten to the end of the line (received a newline
        // character) and the line is blank, the http request has ended,
        // so you can send a reply
        if (c == '\n' && currentLineIsBlank) {
          EthernetMainPage(clientweb);

        }
        if (c == '\n') {
          // you're starting a new line
          currentLineIsBlank = true;
        } 
        else if (c != '\r') {
          // you've gotten a character on the current line
          currentLineIsBlank = false;
        }
      }
    }
    // give the web browser time to receive the data
    delay(1);
    // close the connection:
    clientweb.stop();
  }
}



//Menu de opcoes canal serial
void man()
{
  Serial.println("Manual:"); 
  Serial.println("man - Opcoes"); 
  Serial.println("status - Retorna a Humidade do local");
  Serial.println("unlock - Desbloqueia rele ao sensor");
  Serial.println("lock - Bloqueia rele ao sensor");
  Serial.println("releon - Acende a luz"); 
  Serial.println("releoff - Desliga a luz"); 
  Serial.println(" ");

}


//Menu de opcoes canal serial
void manEthernet(EthernetClient client)
{
  client.println("Manual:"); 
  client.println("man - Opcoes"); 
  client.println("status - Retorna a Humidade do local");
  client.println("unlock - Desbloqueia rele ao sensor");
  client.println("lock - Bloqueia rele ao sensor");
  client.println("releon - Acende a luz"); 
  client.println("releoff - Desliga a luz"); 
  client.println(" ");

}


//Status da Serial
void Status()
{
  Serial.print("Empresa:");
  Serial.println(Empresa);
  Serial.print("Email:");
  Serial.println(EMAIL);
  Serial.print("Site:");
  Serial.println(SITE);  
  Serial.print("Produto:");
  Serial.println(Produto);
  Serial.print("Nro Serie:");
  Serial.println(NroSerie);
  Serial.println("Status:");  
  Serial.print("Movimento:");
  Serial.println(((flgMovimento==true)?"On":"Off")); 
  Serial.println(" ");
}

//Status da Serial
void StatusEthernet(EthernetClient client)
{
  client.print("Empresa:");
  client.println(Empresa);
  client.println(EMAIL);
  client.println(SITE);
  client.print("Produto:");
  client.println(Produto);
  client.print("Nro Serie:");
  client.println(NroSerie);
  client.println("Status:"); 
  client.print("Movimento:");
  client.println(((flgMovimento==true)?"On":"Off")); 
  client.println(" ");
}


//Analisa comando
void AnalisaCMDEthernet(EthernetClient client)
{

  //Comando de liga luz
  if (BufferCMD.indexOf("man") >=0)
  {        
    Msg2 = "man by Ethernet"; 
    manEthernet(client);
  } 
  else

      //Comando de liga luz
    if (BufferCMD.indexOf("status") >=0)
    {        
      Msg2 = "status by serial"; 
      StatusEthernet(client);
    } 


}

//Analisa comando
void AnalisaCMD()
{
  if (Serial.available() > 0) {
    // get incoming byte:
    BufferCMD = BufferCMD + Serial.read();
  } 
  else
    //Comando de liga luz
    if (BufferCMD.indexOf("man") >=0)
    {        
      Msg2 = "man by serial"; 
      man();
    } 
    else

        //Comando de liga luz
      if (BufferCMD.indexOf("status") >=0)
      {        
        Msg2 = "status by serial"; 
        Status();
      }           
}

//Imprime padrão demonstrando entrada de comando
void ImprimeConsole()
{
  Serial.print("$>"); 
}

//Imprime padrão demonstrando entrada de comando
void ImprimeConsoleEthernet(EthernetClient client)
{
  client.print("$>"); 
}

//Le dados da Serial
void LendoSerial()
{
  // if we get a valid byte, read analog ins:
  if (Serial.available() > 0) {
    // get incoming byte:
    char inChar = Serial.read();
    if (inChar == '\n')
    {
      Serial.println(" ");
      AnalisaCMD();
      BufferCMD = "";
      ImprimeConsole();
    }

    else
    {
      Serial.print(inChar); //Echo
      BufferCMD +=inChar;
    }
  } 

}

//Registra log em cartão SD
void logOcorrencia(String info)
{
  //Nao faz nada por enquanto
  Serial.println(info);

}

//Monitora requisicoes via ethernet porta 22
void LendoEthernet()
{

  EthernetClient client = server.available();  
  // when the client sends the first byte, say hello:
  if (client) {
    if (!gotAMessage) {
      logOcorrencia("Conectou client"); //registra logOcorrencia em Cartão SD
      client.println("Bem vindo ao "+Produto); 
      gotAMessage = true;
      ImprimeConsoleEthernet(client);
    }


    // read the bytes incoming from the client:
    char thisChar = client.read();

    if (thisChar == '\n')
    {
      client.println(" ");
      AnalisaCMDEthernet(client);
      BufferCMD = "";
      ImprimeConsoleEthernet(client);
    }
    else
    {
      //client.print(inChar); //Echo
      BufferCMD +=thisChar;
    }
  }

}



//Loop de varredura
void loop() {
  SensorMovimento();

  //Le Serial
  LendoSerial();
  //Le Ethernet
  LendoEthernet();
  Web();
  LDC(); 
}













