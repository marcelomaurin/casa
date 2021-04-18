<?php
	define("HOSTNAME","192.168.1.213");
	define("USERNAME","root");
 	define("PASSWORD","226468");
	define("DATABASE","casadb");

	$dbhandle = new mysqli(HOSTNAME, USERNAME, PASSWORD, DATABASE) or die("Erro ao conectar no banco de dados");

?>
