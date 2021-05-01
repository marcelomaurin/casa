all: Get Drop Banco Tabelas Registros

Get:
	echo "Digite seu usuario do banco:"
	read Usuario
	echo "Digite sua senha:"
	read Senha
	echo "Digite o endereço do seu servidor:"
	read Host

Drop:
	mysql -h $Host  -u$Usuario -p$Senha < dropall.sql

Banco:
	mysql -h $Host -u$Usuario -p$Senha < casadb.sql


Tabelas:
	mysql -h $Host -u$Usuario -p$Senha < usuarios.sql
	mysql -h $Host -u$Usuario -p$Senha < devices.sql
	mysql -h $Host -u$Usuario -p$Senha < frases.sql

Registros:
	mysql -h $Host -u$Usuario -p$Senha < ins_usuarios.sql
	mysql -h $Host -u$Usuario -p$Senha < ins_devices.sql
	mysql -h $Host -u$Usuario -p$Senha < ins_frases.sql


