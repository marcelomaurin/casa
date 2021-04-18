#include <stdio.h>    /* Standard input/output definitions */
#include <stdlib.h> 
#include <stdint.h>   /* Standard types */
#include <string.h>   /* String function definitions */
#include <unistd.h>   /* UNIX standard function definitions */
#include <fcntl.h>    /* File control definitions */
#include <errno.h>    /* Error number definitions */
#include <termios.h>  /* POSIX terminal control definitions */
#include <sys/ioctl.h>
#include <getopt.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <errno.h>
#include <unistd.h>
#include <netdb.h>

//variaveis globais
int fd;
char dat[80];
char buf[80]; //buffer de dados generico
char bufSerial[255]; //buffer da serial capturada
char bufTCP[255];    //buffer do TCP capturado
char use[1];
int rc, n;
char buffer[100];
char *bufptr = buffer;
int buflen = sizeof(buffer);


//variavel do servidor
#define MAX_SIZE 50

//TCPIP publicas
int sock_descriptor, conn_desc;
struct sockaddr_in serv_addr, client_addr;
char buff[MAX_SIZE];
struct sockaddr_in serveraddr;
struct hostent *hostp;

void usage(void);

int serialport_init(const char* serialport, int baud);
int serialport_writebyte(int fd, uint8_t b);
int serialport_write(int fd, const char* str);
int serialport_read_until(int fd, char* buf, char *until);
int flgSair = 0;

//Dorme aguardando tempo
void Dormir(int Segundos)
{
   usleep(Segundos * 1000 * 1000); //iniciando o sistema do arduino
}

void Ler(char *Info,char *Info2)
{
   sprintf(buff,"/projetos/robotinics/espeak/ler.sh %s",Info);
   system(buff);
   sprintf(buff,"/sbin/ler.sh %s",Info2);
   system(buff);
}

void Falar( char * Info)
{
   sprintf(buff,"/projetos/robotinics/espeak/falar.sh %s",Info);
   system(Info);
}



void LCDMSG(char* info1, char* info2)
{
   //usleep(6000 * 1000); //iniciando o sistema do arduino
   Dormir(6);
   sprintf(dat,"LCDCLEAR\n\r");
   rc = serialport_write(fd,dat);
   sprintf(dat,"MSG1:%s\n\r",info1);
   rc = serialport_write(fd,dat);
   sprintf(dat,"MSG2:%s\n\r",info2);
   rc = serialport_write(fd,dat);
   Ler(info1,info2);
}

//Registra log
void Log(char *info)
{
   sprintf(buf,"echo '%s' > /var/log/srvMonitor",info);
   system(buf);
}


void usage(void) 
{
   printf("Usage: srvMonitor -p <serialport> [OPTIONS]\n");
   printf("Adaptado por Marcelo Maurin Martins\n");
   printf("\n");
   printf("Options:\n");
   printf("  -h, --help                   Print this help message\n");
   printf("  -p, --port=serialport        Serial port Arduino is on\n");
   printf("  -b, --baud=baudrate          Baudrate (bps) of Arduino\n");
   printf("  -s, --send=data              Send data to Arduino\n");
   printf("  -r, --receive                Receive data from Arduino & print it out\n");
   printf("  -n  --num=num                Send a number as a single byte\n");
   printf("  -d  --delay=millis           Delay for specified milliseconds\n");
   printf("\n");
   printf("Note: Order is important. Set '-b' before doing '-p'. \n");
   printf("      Used to make series of actions:  '-d 2000 -s hello -d 100 -r' \n");
   printf( "      means 'wait 2secs, send 'hello', wait 100msec, get reply'\n");
   printf( "\n");
}

void MostrarIP(void)
{
   sprintf(dat,"LCDCLEAR\n\r");
   rc = serialport_write(fd,dat);
   sprintf(dat,"MSG1:IP:191.168.0.15\n\r"); //provisorio depois mostra de verdade
   rc = serialport_write(fd,dat);
   sprintf(dat,"MSG2:MASK:255.255.255.0\n\r");
   rc = serialport_write(fd,dat);
}

//Rotina que irá mostra versao da maquina
void Srv_Versao()
{
   LCDMSG("srvMonitor","V. 1.0");
}

void Arduino_Versao()
{
   LCDMSG("Arduino ","V. 1.0");
}

void Start_TCP()
{
   Falar("/projetos/robotinics/espeak/socket");
   sock_descriptor = socket(AF_INET, SOCK_STREAM, 0);

   if(sock_descriptor < 0)
   LCDMSG("ERRO falha","Carga Socket");

   bzero((char *)&serv_addr, sizeof(serv_addr));
   serv_addr.sin_family = AF_INET;
   serv_addr.sin_addr.s_addr = INADDR_ANY;
   serv_addr.sin_port = htons(8095);
   if (bind(sock_descriptor, (struct sockaddr *)&serv_addr, sizeof(serv_addr)) < 0)
   {
      LCDMSG("Falha","to bind!");
   }
   strcpy(bufTCP,"");
 }

//Escuta porta TCP 
void EscutaTCP(void)
{
   listen(sock_descriptor, 5);
   system("/sbin/falar.sh  /projetos/robotinics/espeak/pronto");
   LCDMSG("Esperando","Conexao");
   //int size = sizeof(client_addr);
   int size = sizeof( client_addr);
   conn_desc = accept(sock_descriptor, (struct sockaddr *) &client_addr, (socklen_t*)&size);         
	  
   if (conn_desc == -1)
     LCDMSG("FALHA aceitar","conexao!");
   else
   {
     //printf("Connected\n");
     LCDMSG("Cliente","Conctado!");
     sprintf(buff,"\nBem vindo srvMonitor\n");
     write(conn_desc,buff,sizeof(buff));
     write(conn_desc,"%>",2);
   } 
   if ( read(conn_desc, buff, sizeof(buff)-1) > 0)
   {
      //printf("Received %s", buff);
      LCDMSG("Recebendo","Dados");
      //rc = serialport_write(fd,bufTCP);
		//serialport_read_until(fd, bufTCP, '\n');
		//write(conn_desc,buff,sizeof(buff));
	}
         else
		LCDMSG("Falha","recebimento");
	
        close(conn_desc);
}

void Start_Serial(void)
{
    Falar("/projetos/robotinics/espeak/carregando");
    fd = 0;
    char serialport[256];

    int baudrate = B57600;

    char cmd = 0xAA;
    char info = 0xBB;

    baudrate = B57600;
    
    fd = serialport_init("/dev/ttyACM0", baudrate);
    if(fd==-1)
    { 
	Ler("Erro Serial","nao localizada");
    }

    Falar("/projetos/robotinics/espeak/arduino");
    strcpy(bufSerial,"");
} 


void Analisa()
{
	rc = serialport_write(fd,bufTCP);
        serialport_read_until(fd, bufSerial, "$>");
        write(conn_desc,bufSerial,sizeof(buff));
}

//Looping infinito para rodar aplicação e analisar processos
int Looping()
{
  flgSair = 1;
  while(flgSair!=0)
  {
     EscutaTCP();
     Analisa(); //Analisa dados recebidos
  }

  return 0;
}

int main(int argc, char *argv[]) 
{
    printf("\n\n\nIniciando Serial...\n");
    Start_Serial();
    
    serialport_read_until(fd,bufSerial, "$>"); //aguarda terminar a inicializacao
    //Dormir(40);
    sprintf(buf,"\n\n\nRecebendo dados da Serial:%s\n",bufSerial);
    printf(buf);
    printf("\n\n\nIniciando TCPIP\n");
    Start_TCP();

    //Dormir(6);
    sprintf(dat,"LCDCLEAR\n\r");
    rc = serialport_write(fd,dat);
    sprintf(dat,"MSG1:Iniciando\n\r");
    rc = serialport_write(fd,dat);
    sprintf(dat,"MSG2:srvMonitor\n\r");
    rc = serialport_write(fd,dat);
    //usleep(3000 *1000);
    Dormir(3);
    printf("Mostrando IP\n");
    MostrarIP();
    //usleep(3000*1000);
    Dormir(3);
    Arduino_Versao();
    //usleep(3000*1000);
    Dormir(2);
    //EscutaTCP();
    Looping();
    
    close(fd);
    close(sock_descriptor); //fecha socket
    exit(EXIT_SUCCESS);    
 } // end main

int serialport_writebyte( int fd, uint8_t b)
{
   int n = write(fd,&b,1);
   if( n!=1)
       return -1;
   return 0;
}

int serialport_write(int fd, const char* str)
{
   printf("Escrevendo bytes %s",str);
   int len = strlen(str);
   int n = write(fd, str, len);
   if( n!=len ) 
       return -1;
   return n;
}

int serialport_read_until(int fd, char* buf, char *until)
{
   sprintf(bufSerial,"\0");
   printf("capturando informacoes da porta...");
   char b[1];
   int i=0;
   int posicao = 0;
   do { 
     int n = read(fd, buff, -1);  // read a char at a time
    
     printf(b);
     if( n==-1) return -1;    // couldn't read
     if( n==0 ) {
        usleep( 10 * 1000 ); // wait 10 msec try again
        continue;
     }
     else
     {
	//buf[i] = b[0]; i++;
	sprintf(bufSerial,"%s%s",bufSerial,buff);
	printf((strcmp(bufSerial,until)>0)?"\nacho!\n\n\n":"");
     }
   } while( strcmp(bufSerial,until) == 0);
   buf[i] = 0;  // null terminate the string
   printf("\n Saiu do laco \n");
   return i;
}

int serialport_init(const char* serialport, int baud)
{
    struct termios toptions;
    int fd;
    fd = open(serialport, O_RDWR | O_NOCTTY);
    if (fd == -1)  {
          perror("init_serialport: Unable to open port ");
          return -1;
    }
   if (tcgetattr(fd, &toptions) < 0) {
        perror("init_serialport: Couldn't get term attributes");
        return -1;
   }
   speed_t brate = baud; // let you override switch below if needed
   switch(baud) {
       case 4800:   brate=B4800;   break;
       case 9600:   brate=B9600;   break;
       case 19200:  brate=B19200;  break;
       case 38400:  brate=B38400;  break;
       case 57600:  brate=B57600;  break;
       case 115200: brate=B115200; break;
  }
  cfsetispeed(&toptions, brate);
  cfsetospeed(&toptions, brate);
  // 8N1
  toptions.c_cflag &= ~PARENB;
  toptions.c_cflag &= ~CSTOPB;
  toptions.c_cflag &= ~CSIZE;
  toptions.c_cflag |= CS8;
  // no flow control
  toptions.c_cflag &= ~CRTSCTS;
  toptions.c_cflag |= CREAD | CLOCAL;  // turn on READ & ignore ctrl lines
  toptions.c_iflag &= ~(IXON | IXOFF | IXANY); // turn off s/w flow ctrl
  toptions.c_lflag &= ~(ICANON | ECHO | ECHOE | ISIG); // make raw
  toptions.c_oflag &= ~OPOST; // make raw
  // see: http://unixwiz.net/techtips/termios-vmin-vtime.html
  toptions.c_cc[VMIN]  = 0;
  toptions.c_cc[VTIME] = 20;
  if( tcsetattr(fd, TCSANOW, &toptions) < 0) {
     perror("init_serialport: Couldn't set term attributes");
     return -1;
  }
  return fd;
}




