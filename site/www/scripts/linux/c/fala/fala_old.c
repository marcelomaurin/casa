/*
Criado por Marcelo Maurin Martins
Programa fala.c
Respons√bilidade: Falar frase 
Porta:7091
*/ 

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
char dat[80];
char buf[80]; //buffer de dados generico
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

int flgSair = 0;

void Falar( char * Info)
{
   sprintf(buff,"/projetos/robotinics/espeak/falar.sh %s",Info);
   system(buff);
}

//Informacoes iniciais do programa
void usage(void) 
{
   printf("Usage: fala  [OPTIONS]\n");
   printf("Adaptado por Marcelo Maurin Martins\n");
   printf("\n");
   printf("Options:\n");
   printf("  -h, --help                   Print this help message\n");
   printf("\n");
}

//descritor de boa vinda do cliente
void Welcome()
{
       sprintf(buff,"\nBem vindo ao sub protocol fala\n");
       write(conn_desc,buff,sizeof(buff));
       sprintf(buff,"\n$>",2);
       write(conn_desc,buff,sizeof(buff));
}

//Rotina que ir· mostra versao da maquina
void Srv_Versao()
{
   printf("fala V.1.0");
}

//Escuta porta TCP 
void EscutaTCP()
{
   //inicializacao do servico
   printf("\nIniciando criacao do servico\n\n");
   sock_descriptor = socket(AF_INET, SOCK_STREAM, 0);
   if(sock_descriptor < 0)
      printf("Erro falha Carga Socket\n\n");

   bzero((char *)&serv_addr, sizeof(serv_addr));
   serv_addr.sin_family = AF_INET;
   serv_addr.sin_addr.s_addr = INADDR_ANY;
   serv_addr.sin_port = htons(7091);
   //abre o descritor do socket
   if (bind(sock_descriptor, (struct sockaddr *)&serv_addr, sizeof(serv_addr)) < 0)
   {
      printf("Falha to Bind!\n");
   }
   strcpy(bufTCP,"");

   //comeca a escutar porta
   listen(sock_descriptor, 5);

   int flgSair = 1;
   while(flgSair!=0)
   {
     printf("Esperando conexao");
     int size = sizeof( client_addr);
     conn_desc = accept(sock_descriptor, (struct sockaddr *) &client_addr, (socklen_t*)&size);         
	  
     if (conn_desc == -1)
     {
       printf("Falha ao aceitar conexao");
     }
     else
     {
       printf("Cliente conectado!");
       Welcome();
     }
     if ( read(conn_desc, buff, sizeof(buff)-1) > 0)
     {
          printf("Received %s", buff);
     }
     else
     {
         printf("Falha de recebimento ");
         //flgSair ==0;
     }
     //fecha o cliente
     //close(conn_desc);




     //fecha o cliente 
     //close(conn_desc);

   }
   printf("Fechando o servico\n\n");
   close(conn_desc);
   close(sock_descriptor); //fecha socket
}

int main(int argc, char *argv[]) 
{
    usage(); //mostra opcoes de comando
    EscutaTCP();

    exit(EXIT_SUCCESS);    
 } // end main



