#Criando Tabela Usuarios
use jornadadb;

#Cria tabela security
CREATE TABLE security (
	camera int, filename char(80) not null,
	frame int, file_type int,
	time_stamp timestamp, 
	event_time_stamp timestamp
	);

