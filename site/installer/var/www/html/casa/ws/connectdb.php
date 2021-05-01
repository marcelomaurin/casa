<?php
	define("HOSTNAME","127.0.0.1");
	define("USERNAME","root");
 	define("PASSWORD","password");
	define("DATABASE","casadb");

	$dbhandle = new mysqli(HOSTNAME, USERNAME, PASSWORD, DATABASE) or die("Erro ao conectar no banco de dados");

?>
