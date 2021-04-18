<?php
    /*phpinfo();*/
	/*Registra webservice para processamento de jobs*/
	ini_set('display_errors', 'On');
	error_reporting(E_ALL);
	
	include "connectdb.php";
	
	//header('Cache-Control: no-cache, must-revalidate');
	//$data = var_dump(json_decode(file_get_contents("php://input")));
	
	
	
	$query = "select idfala, mensagem, status from falas where (status = 0);";
	//echo $query;
	
	$rs = $dbhandle->query($query);

	$cont = 0;
	
	while($row=$rs->fetch_assoc())
	{
		$cont ++;
		$data[]=$row;			
	}
	
	if ($cont>0)
	{
		print json_encode($data);
	}	
?>