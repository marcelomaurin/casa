#Criando Tabela Usuarios
use casadb;


CREATE TABLE usuarios (
        idusuario INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(25),
        senha VARCHAR(25)
       );
