#Criando Tabela Devices
use jornadadb;


CREATE TABLE devices (
        iddevice INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        devname VARCHAR(25),
	devdesc text,
        devtype int,
        devcon VARCHAR(255),
	devstatus boolean       
       );
