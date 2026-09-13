#Criando Tabela Usuarios
use casadb;


CREATE TABLE usuarios (
        idusuario INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
		ip VARCHAR(25),
        NOME VARCHAR(25),
        EMAIL VARCHAR(500)
       );
