#include <sys/types.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <netdb.h>
#include <stdio.h>
#include <unistd.h> /* close() */
#include <string.h> /* memset() */
#include <stdbool.h> //Booleando
#include<arpa/inet.h>
#include<sys/socket.h>
#include<stdlib.h> /*/exit(0);*/
#include <termios.h>
#include <unistd.h>
#include <sys/select.h>


#define MAX_MSG 100
#define BUFLEN 512  /*/Max length of buffer*/
#define PORT 8070   /*/The port on which to listen for incoming data*/

struct termios orig_termios;


char Versao[20] = "01000001000001";
bool flgsair = false;
char Buffer[500] = "\0"; /*Buffer de teclado*/
char BufferUDP[500] = "\0"; /*Buffer de UDP*/

struct sockaddr_in si_me, si_other;
int s, i, slen , recv_len;

char buf[BUFLEN];

void die(char *s)
{
    perror(s);
    exit(1);
}

void reset_terminal_mode()
{
    tcsetattr(0, TCSANOW, &orig_termios);
}

void set_conio_terminal_mode()
{
    struct termios new_termios;

    /* take two copies - one for now, one for later */
    tcgetattr(0, &orig_termios);
    memcpy(&new_termios, &orig_termios, sizeof(new_termios));

    /* register cleanup handler, and set the new terminal mode */
    atexit(reset_terminal_mode);
    cfmakeraw(&new_termios);
    tcsetattr(0, TCSANOW, &new_termios);
}

int getch()
{
    int r;
    unsigned char c;
    if ((r = read(0, &c, sizeof(c))) < 0) {
        return r;
    } else {
        return c;
    }
}


int kbhit()
{
    struct timeval tv = { 0L, 0L };
    fd_set fds;
    FD_ZERO(&fds);
    FD_SET(0, &fds);
    return select(1, &fds, NULL, NULL, &tv);
}


void Wellcome()
{
	printf("Bem vindo ao servidor COMLINK\n");
	printf("Versao %s\n",Versao);
}

void Start_Ethernet()
{
   slen = sizeof(si_other);
   /*create a tcp socket*/
   if ((s=socket(AF_INET, SOCK_STREAM,0 )) == -1)
   {
        die("socket");
   }
   /* zero out the structure*/
   memset((char *) &si_me, 0, sizeof(si_me));
     
   si_me.sin_family = AF_INET;
   si_me.sin_port = htons(PORT);
   si_me.sin_addr.s_addr = htonl(INADDR_ANY);

   /*bind socket to port*/
   if( bind(s , (struct sockaddr*)&si_me, sizeof(si_me) ) == -1)
   {
        die("bind");
   }
}

void Setup()
{
	Wellcome();
	set_conio_terminal_mode();
        Start_Ethernet();
}

/*Executa Processamento de comandos*/
void CMDEXEC()
{
	printf("Rodou comando: %s\n",Buffer);
     	if (strcmp(Buffer,"SAIR")==0)
	{
		printf("Encontrou Sair\n");
   		flgsair = true;
	}
        sprintf(Buffer,"\0"); /*Reseta Buffer*/
}

void Le_TCP()
{
	bool flgErroClient = false; /*Controla falha na conexao*/
	printf("Waiting for data...");
    fflush(stdout); /*limpa buffer stdout*/
    int sock_client = accept(s, (struct sockaddr*) &si_other, &slen);

    if (sock_client==-1)
	{
		printf("Erro accept:\n");
		flgErroClient = true;
	}
    /*try to receive some data, this is a blocking call*/
    if(recv_len = recv(s, buf, BUFLEN, MSG_DONTWAIT) > 0)
    {        
			/*print details of the client/peer and the data received*/
			printf("Received packet from %s:%d\n", inet_ntoa(si_other.sin_addr), ntohs(si_other.sin_port));
			printf("Data: %s\n" , buf);
         
			/*now reply the client with the same data*/
			/*
			 if (sendto(s, buf, recv_len, 0, (struct sockaddr*) &si_other, slen) == -1)
			{
				die("sendto()");
			}
	     	*/   
	}
}

void Le_Teclado()
{
        char c = getch();
	if(c != EOF)
	{
	  if (c != '\n')
	  {
	    sprintf(Buffer,"%s%c\0",Buffer,c);
	  }
	   else
	 {
	   CMDEXEC(); /*Processa Arquivo*/
	 }
	}
}

void Leituras()
{
	Le_Teclado(); /*Le teclado*/
	Le_TCP(); /*Le dispositivo UDP*/
	printf("Teste\n");
}


void Loop()
{
	Leituras(); 
	
}

void Shutdown()
{
	close(s);
}

int main(int argc, char *argv[])
{
	/*Roda Startup do programa*/
	Setup();
    while(!flgsair)
    {
		/*Roda programa*/
		Loop();
    }
	Shutdown();	
	return(0);
}
