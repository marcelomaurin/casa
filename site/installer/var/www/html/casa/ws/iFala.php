<?php
    /*phpinfo();*/
	/*Registra webservice para processamento de jobs*/
	ini_set('display_errors', 'On');
	/*error_reporting(E_ALL);*/
	
	include "connectdb.php";
	
	$data = json_decode(file_get_contents("php://input"));
	
	
	$mensagem = $dbhandle->real_escape_string($data->mensagem);
	$query= "INSERT into falas (mensagem, status) values ( '".$mensagem."', 0)";
	$dbhandle->query($query);
?>
 