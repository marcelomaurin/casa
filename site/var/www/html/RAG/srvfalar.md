# srvFalar — ficha tecnica RAG

## Identificacao
Repositorio: marcelomaurin/srvFalar
Fonte: documentacao do proprio repositorio.

## Documentacao, funcionalidades e aspectos tecnicos

# Portuguese

Objetivo:
Permitir criar um serviço de sintetização de voz.

Instalação:
Instale o pacote mais atual, com o seguinte comando:
> sudo apt install ./srvfalar-1.2_amd64.deb
Caso exista versão mais nova, use-a.

Ferramentas de utilização:
Existe uma aplicação desk top, WINDOWS que permite a simulação.
O ToolFalar é uma aplicação cliente que envia dados de voz via porta 8096.

Enviando dados por aplicação própria:
Conecte a aplicação cliente através de um socket, na porta 8096 , e envie os dados a serem ditos, com terminador ASCII 13.

Parte do material, foi obtido através do git: felipefacundes.github.io/brasiltts/

Obrigado felipe por me auxiliar no desenvolvimento deste software.


# Requisitos de compilação

## UBUNTU
sudo apt install espeak libespeak-dev




Duvidas ou Sugestões:
marcelomaurinmartins@gmail.com



# English

Goal:
Allow to create a speech synthesis service.

Installation:
Install the most current package, with the following command:
> sudo apt install ./srvfalar-1.2_amd64.deb
If there is a newer version, use it.

Usage tools:
There is a desk top application, WINDOWS that allows the simulation.
ToolFalar is a client application that sends voice data via port 8096.

Sending data by own application:
Connect the client application through a socket, on port 8096, and send the data to be said, with ASCII terminator 13.

Questions or Suggestions:
marcelomaurinmartins@gmail.com

# Spanish

Meta:
Permitir crear un servicio de síntesis de voz.

Instalación:
Instale el paquete más actual, con el siguiente comando:
> sudo apt install ./srvfalar-1.2_amd64.deb
Si hay una versión más nueva, úsela.

Herramientas de uso:
Existe una aplicación de escritorio, WINDOWS, que permite la simulación.
ToolFalar es una aplicación cliente que envía datos de voz a través del puerto 8096.

Envío de datos por aplicación propia:
Conectar la aplicación cliente a través de un socket, en el puerto 8096, y enviar los datos a ser dicho, con terminador ASCII 13.

Preguntas o Sugerencias:
marcelomaurinmartins@gmail.com


## Como usar este documento
O JARVIS deve usar esta ficha para conversar sobre objetivo, linguagem, bibliotecas, hardware, protocolos, funcoes, arquitetura, instalacao e uso do projeto conforme os dados documentados. Nao inventar recursos ausentes.
