all: Drop Banco Tabelas Registros

Drop:
	mysql -u$1 -p$2 < dropall.sql

Banco:
	mysql -u$1 -p$2 < casadb.sql


Tabelas:
	mysql -u$1 -p$2 < usuarios.sql
	mysql -u$1 -p$2 < devices.sql
	mysql -u$1 -p$2 < frases.sql

Registros:
	mysql -u$1 -p$2 < ins_usuarios.sql
	mysql -u$1 -p$2 < ins_devices.sql
	mysql -u$1 -p$2 < ins_frases.sql


