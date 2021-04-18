#Criando Tabela Users
use jornadadb;


CREATE TABLE users (
        iduser INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        login VARCHAR(25),
		idPessoa int not null,
        password VARCHAR(25),
		status int,
		idRoleGrp int,
		idEmpresa int
       );
