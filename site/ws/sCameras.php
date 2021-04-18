<?php
    /*phpinfo();*/
	/*Registra webservice para processamento de jobs*/
	ini_set('display_errors', 'On');
	error_reporting(E_ALL);
	include "./../sessao.php";
	
	//include "connectdb.php";
	include "./../config.php";
		
		
	$conperm = new mysqli($dbhost, $dbuser, $dbpassword, $database) or die("Erro ao conectar no banco de dados");

	
		
	//header('Cache-Control: no-cache, must-revalidate');
	//$data = var_dump(json_decode(file_get_contents("php://input")));
	if ($_SESSION["login"] == true)
	{
		if(isset($_GET['camera'])){
			$camera = $conperm->real_escape_string($_GET['camera']);	
		} else 		
		{
			$camera = "2";
			echo "Camera =2, default";
		}
		
		
		if(isset($_GET['Retornos'])){
			$Retornos = $conperm->real_escape_string($_GET['Retornos']);	
		} else {		
			$Retornos = '10';
			echo "Retornos = 10, default";
		}
		
		
		if(isset($_GET['DataInicio'])){
			$DataInicio= $conperm->real_escape_string($_GET['DataInicio']);	
		}
		
		if(isset($_GET['DataFim'])){
			$DataFim= $conperm->real_escape_string($_GET['DataFim']);	
		}
		
		$Retornos = '10';
		
		if(isset($_GET['Retornos'])){		
			$Retornos= $conperm->real_escape_string($_GET['Retornos']);	
			if($Retornos==""){
				echo "Vazio<br/>";
				$Retornos= '10';
				}
		}		
		
		//$query = "SELECT * FROM `security` where camera = '".$camera."' and time_stamp >= date_sub(now(),INTERVAL 1 day) order by time_stamp desc LIMIT ".$Retornos;
		$query = "SELECT * FROM security where camera = '".$camera."' and time_stamp >= date_sub(now(),INTERVAL 2 day) order by time_stamp desc LIMIT ".$Retornos;
		
		//$query = SELECT * FROM `security` WHERE camera = '1'  and time_stamp between ub(date_sub(now(),INTERVAL 1 day) order by time_stamp desc limit 40
		if(isset($DataInicio) && !isset($DataFim)) {
			$query = "SELECT * FROM security where camera = '".$camera."' and (time_stamp between STR_TO_DATE(".$DataInicio.",'%d/%m/%Y') and now()) order by time_stamp desc LIMIT ".$Retornos;
		}
		if(isset($DataInicio) && isset($DataFim)){
			$query = "SELECT * FROM security where camera = '".$camera."' and (time_stamp between STR_TO_DATE('".$DataInicio."','%d/%m/%Y') and STR_TO_DATE('".$DataFim."','%d/%m/%Y')) order by time_stamp desc LIMIT ".$Retornos;
		}
		
		if(!isset($DataInicio) && isset($DataFim)){
			$query = "SELECT * FROM security where camera = '".$camera."' and (time_stamp between STR_TO_DATE('2016-01-01','%Y-%m-%d') and STR_TO_DATE('".$DataFim."','%d/%m/%Y')) order by time_stamp desc LIMIT ".$Retornos;
		} 
		//echo $query;
		//echo ('OK4');
		
		//break;
		$rs = $conperm->query($query);
		//$sql_result = mysqli_query($conperm, $query ) or die (Showerro("Nao foi possivel executar a consulta:".$sql, "qryCount"));
		



		$cont = 0;
		//$rs= mysqli_num_rows($sql_result); 
		while($row=$rs->fetch_assoc())
		{
			$cont ++;			
			//$data[]=$row;			
			$row["filename"]=str_replace("/var/www/html/","/",$row["filename"]);
			//echo $row["filename"];
			$data[]=$row;
			
		  	
		}
		
		if ($cont>0)
		{
			print json_encode($data);
		}
		
			
	}
	else
	{
	echo "Please, make login!";	
	}
	
?>