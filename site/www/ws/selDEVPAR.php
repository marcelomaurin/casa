<?php
    /*phpinfo();*/
	/*Registra webservice para processamento de jobs*/
	ini_set('display_errors', 'On');
	/*error_reporting(E_ALL);*/
	
	include "connectdb.php";
	include "./../sessao.php";
	
	//header('Cache-Control: no-cache, must-revalidate');
	//$data = var_dump(json_decode(file_get_contents("php://input")));
	
	//$devparname = "dev1";
	$devname = $dbhandle->real_escape_string($_GET['devname']);	
	//$iddevice = "2";
	$devparname = $dbhandle->real_escape_string($_GET['devparname']);	
	if ($_SESSION["login"] == true)
	{
		
		//$status = qryRow('select devvalue from devpar where iddevice = 2 and devparname = "dev1"','devvalue');
		$query = "select p.devvalue from devpar p, devices d where d.iddevice = p.iddevice and d.devname = '".$devname."' and p.devparname = '".$devparname. "'";
		
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
	}
	
	
		
?>