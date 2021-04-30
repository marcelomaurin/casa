all: Capture Drop Banco Tabelas Registros

Capture:
	echo "Enter user name:"
	read Username
	echo "Enter Password:"
	read Password

Drop:
	mysql -u$Username -p$Password < dropall.sql

Banco:
	mysql -u$Username -p$Password < casadb.sql


Tabelas:
	mysql -u$Username -p$Password < usuarios.sql
	mysql -u$Username -p$Password < devices.sql
	mysql -u$Username -p$Password < frases.sql

Registros:
	mysql -u$Username -p$Password < ins_usuarios.sql
	mysql -u$Username -p$Password < ins_devices.sql
	mysql -u$Username -p$Password < ins_frases.sql


