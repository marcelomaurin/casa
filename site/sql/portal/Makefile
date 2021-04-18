all: Drop Banco Tabelas Registros

Drop:
	mysql -uroot -p226468 < dropall.sql

Banco:
	mysql -uroot -p226468 < casadb.sql


Tabelas:
	mysql -uroot -p226468 < usuarios.sql
	mysql -uroot -p226468 < devices.sql
	mysql -uroot -p226468 < frases.sql

Registros:
	mysql -uroot -p226468 < ins_usuarios.sql
	mysql -uroot -p226468 < ins_devices.sql
	mysql -uroot -p226468 < ins_frases.sql


