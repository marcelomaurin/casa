all: Drop Banco Tabelas Registros

Drop:
	mysql -uroot -p226468 < dropall.sql

Banco:
	mysql -uroot -p226468 < jornadadb.sql


Tabelas:
	mysql -uroot -p226468 < users.sql
	mysql -uroot -p226468 < devices.sql
	mysql -uroot -p226468 < frases.sql
	mysql -uroot -p226468 < security.sql

Registros:
	mysql -uroot -p226468 < ins_users.sql
	mysql -uroot -p226468 < ins_devices.sql
	mysql -uroot -p226468 < ins_frases.sql


