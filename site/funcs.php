<?php
	//Libs utilizadas no sistema para execu?ao de tarefas auxiliares
	//Desenvolvido por Marcelo Maurin Martins
	//05/03/2006

	/*retorna o iplocal do usuario*/
	function get_ip()
	{
	   $variables = array('REMOTE_ADDR',
                      'HTTP_X_FORWARDED_FOR',
                      'HTTP_X_FORWARDED',
                      'HTTP_FORWARDED_FOR',
                      'HTTP_FORWARDED',
                      'HTTP_X_COMING_FROM',
                      'HTTP_COMING_FROM',
                      'HTTP_CLIENT_IP');

	   $return = 'Unknown';

	   foreach ($variables as $variable)
	   {
	       if (isset($_SERVER[$variable]))
	       {
	           $return = $_SERVER[$variable];
	           break;
	       }
	   }
   
	   return $return;
	}
	
	//Verrifica se esta na rede interna
	function ConfereIPExtInt()
	{

		$ip_server = $_SERVER['SERVER_ADDR'];
		$ip_remoto = $_SERVER['REMOTE_ADDR'];

		$array_ip_server = explode(".",$ip_server);
		$array_ip_remoto = explode(".",$ip_remoto);

		if (($array_ip_server[0] == $array_ip_remoto[0]) || ($array_ip_server[1] == $array_ip_remoto[1])){

			return 0; //ip interno

		}else{

			return 1; //ip externo
		}


	}	
    
	//verifica sub sistema
	function get_subsis($opcao1)
	{
		$resultado = 0;
		$sql = "select opc from subsystem where opc = ".$opcao1." and status=1 ";
		//echo($sql);
		$resultado = qryCount($sql);
		
		
		return $resultado;
	}
	
	
	//include('config.php');
	//Apresenta uma tela de erro padronizada
	function ShowErro($msg,$tela)
	{
		include('config.php');
		echo "<table border='1'>";
		echo "<tr>";
		echo "<td>";
		echo "<b><div align='center'>";
		echo $URLLABEL." - Erro na pagina!";
		echo "</div></b>";
		echo "</td>";
		echo "</tr>";
		echo "<tr>";
		echo "<td>";
		echo "Caro usuario,";
		echo "<br>";
		echo "Ocorrreu o seguinte erro durante a execucao desta pagina!";
		echo "<br> <b>";
		echo "Erro:".$msg;
		echo "</b> <br>";
		echo "Caso o erro persista peco que entre em contato com o administrador deste site!";
		echo "<br>";
		echo "email: <a href='".$emailreply."'> Suporte tecnico</a>";
		echo "<br>";
		echo 'Tela:'.$tela;
		echo "<br>";
		echo('$dbhost:'.$dbhost);
		echo "<br>";
		echo('$dbuser:'.$dbuser);
		echo "<br>";
		echo('$database:'.$database);
		echo "<br>";
		echo "</td></tr>";
		echo "</table>";
	}
	
		
	//Apresenta uma tela de aviso padronizado padronizada
	function ShowWarnning($msg)
	{
		echo "<script type='text/javascript'>";
		echo "alert('".$msg."');";
		echo "</script>";
	}
	
	
	//Implenta busca quando se necessita apenas saber o nro de linhas da pesquisa
	function qryCount($sql)	
	{
		//echo($sql);
		include('config.php');
		
		if((!isset($conperm)) || (!isset($dbhost)))
		{
    			//Conectando no banco
    			$conperm = mysqli_connect($dbhost,$dbuser,$dbpassword)
		or die (Showerro("Não foi possível conectar ao banco de dados","index.php"));

    			$dbperm = mysqli_select_db($conperm,$database) or die       (
				Showerro("Nao foi possivel selecionar banco de dados","funcs.php"));
		}
		
		$sql_result = mysqli_query($conperm, $sql ) or die (Showerro("Nao foi possivel executar a consulta:".$sql, "qryCount"));
		
		$resultado= mysqli_num_rows($sql_result); 
		
		mysqli_free_result($sql_result);
		return $resultado;
			
	}
	//Retorna o primeiro elemento do campo selecionado
	function qryRow($sql, $fieldname)
	{
		include('config.php');
		if((!isset($conperm)) || (!isset($dbhost)))
		{
			//echo("<br/>");	
			//echo("$dbhost:".$dbhost);
			//echo("<br/>");
			//echo("$dbuser:".$dbuser);
			//echo("<br/>");
			//echo("$dbpassword:".$dbpassword);
			//echo("<br/>");
			//echo("$database:".$database);
			//echo("<br/>");

    			//Conectando no banco
    			$conperm = mysqli_connect($dbhost,$dbuser,$dbpassword)
		or die (Showerro("Não foi possível conectar ao banco de dados","index.php"));

    			$dbperm = mysqli_select_db($conperm, $database) or die       (
				Showerro("Nao foi possivel selecionar banco de dados","funcs.php"));
		}


		$sql_result = mysqli_query($conperm, $sql) or die (Showerro("Nao foi possivel executar a consulta:".$sql, "qryRow"));
		//echo("Executou pesquisa:<br/>");

		if  (!$sql_result)
		{
			//echo("sem resultado<br/>");

			return 0;
			
		}
		 else
		{	
                        //echo("Com resultado<br/>");
			
			$rows =  mysqli_fetch_array($sql_result);
			//echo("Retornou:".$rows[$fieldname]."<br/>");

			return $rows[$fieldname];	
		}	
		//mysql_free_result($sql_result);	
	}
	
	function Ligar() 
	{
				echo "Ligou a irrigação!";
				qryExec("update devpar set devvalue = 1 where iddevice = 2 and devparname = 'dev1' ");
				exit;
	}

	function Desligar() 
	{
				echo "Desligou a irrigação!";
				qryExec("update devpar set devvalue = 0 where iddevice = 2 and devparname = 'dev1' ");
				exit;
	}
	
	function Ligarluz() 
	{
				echo "Ligou a iluminação!";
				qryExec("update devpar set devvalue = 1 where iddevice = 1 and devparname = 'dev1' ");
				exit;
	}
	
	function Desligarluz() 
	{
				echo "Desligou a iluminação!";
				qryExec("update devpar set devvalue = 0 where iddevice = 1 and devparname = 'dev1' ");
				exit;
	}
	
	function DesligarluzLat() 
	{
				echo "Desligou a iluminação!";
				qryExec("update devpar set devvalue = 0 where iddevice = 2 and devparname = 'dev5' ");
				exit;
	}
	
	function LigarluzLat() 
	{
				echo "Ligou a iluminação!";
				qryExec("update devpar set devvalue = 1 where iddevice = 2 and devparname = 'dev5' ");
				exit;
	}
		
	function LigarluzExt() 
	{
				echo "Ligou a iluminação!";
				qryExec("update devpar set devvalue = 1 where iddevice = 2 and devparname = 'dev5' ");
				exit;
	}

	function DesligarluzExt() 
	{
				echo "Desligou a iluminação!";
				qryExec("update devpar set devvalue = 0 where iddevice = 2 and devparname = 'dev5' ");
				exit;
	}
	
	//Executa um qrery de acao
	function qryExec($sql)
	{
		include('config.php');
		if((!isset($conperm)) || (!isset($dbhost)))
		{
			//echo("$dbhost:".$dbhost);
			//echo("$dbuser:".$dbuser);
			//echo("$dbpassword:".$dbpassword);
			//echo("$database:".$database);

    			//Conectando no banco
    			$conperm = mysqli_connect($dbhost,$dbuser,$dbpassword)
		or die (Showerro("Não foi possível conectar ao banco de dados","index.php"));

    			$dbperm = mysqli_select_db($conperm, $database) or die       (Showerro("Nao foi possivel selecionar banco de dados","funcs.php"));
		}
		
		$sql_result = mysqli_query($conperm, $sql) or die (Showerro("Nao foi possivel executar a consulta:".$sql, "QryExec:".$sql ));
		if  (!$sql_result)
		{
			return 0;
		}
		 else
		{	
			return 1;
		}
		
		mysqli_free_result($sql_result);	
	}
    	//Executa um qrery de acao
	function qryReport($Titulo, $sql, $SelItem)
	{
	    include('config.php');
	    $sql_result = mysqli_query($conperm, $sql) or die (Showerro("Nao foi possivel executar a consulta", "qryReport:".$sql));
	    if  (!$sql_result)
	    {
			return 0;
	    }
		 else
	    {
		echo "<table border=0>";
		echo "<tr>";
		echo "<td>";
		echo "<h1> <center> <b>";
		echo $Titulo;
		echo "</b> </center> </h1> </td> </tr>";
		echo " <tr> <td> <br>";
		echo "</td> </tr>";
		echo "<tr> <td>";
		//Arquivo
		echo "</td> ";
		echo "<td>";
		
		//Resumo
		echo "</td> </tr>";
		$sql_result = mysqli_query($conperm, $sql) or die (Showerro("Nao foi possivel executar a consulta", "qryReport"));
		if  (!$sql_result)
		{
				return 0;
			}
			else
			{
				$num= mysqli_num_rows($sql_result); 
				if (!$num)
				{
					return "0";
					echo "</table>";
					echo "<br>";
					echo "Nenhum registro foi encontrado";
				}
			else
			{
			//Cria descrição de header
			echo "<tr>";
			echo "<td>";
			
			echo "</td>";
			echo "</tr>";
			//varre todos as linhas
			while ($rows =  mysqli_fetch_array($sql_result));
			{
				echo "<tr>";
				//for($a
				echo "<td>";
				echo $row[$fieldname];
				echo "</td>";
				echo "</tr>";
			}
			echo "/table";
			echo "<br>";
			echo "Foram encontrados ".$num." registros";
			}
		}
	    return 1;
	    }
	    mysqli_free_result($sql_result);
	}

	function iif($expression, $returntrue, $returnfalse = '') {
    		return ($expression ? $returntrue : $returnfalse);
	} 

	function SendEmail($email,$subject,$body)
	{
	  include('config.php');
	  $headers = "Content-type: text/html; charset=utf-8\r\n";
	  if (mail($email, $subject, $body, $headers)) 
	  {
		//incluir registro de envio de email no banco de dados

		//retornando sucesso
		$resultado = 1;
  	  } 
	  else 
	  {
		$resultado = 0;
          }
          return($resultado);
	}
	function SendEmailtoPerson($idpessoa,$subject,$body)
	{	
		include('config.php');
		$sql = 'select email from pessoas where idpessoa = '.$idpessoa;
		
		$sql_result = mysqli_query($conperm, $sql) or die (Showerro("Nao foi possivel executar a consulta", "SendEmailtoPerson"));
		if  (!$sql_result)
		{
			return 0;
		}
		 else
		{	
			$num= mysqli_num_rows($sql_result); 
			if (!$num)
			{
				return "0";
			}
				else
			{
				
				$rows =  mysqli_fetch_array($sql_result);
				$email = $rows['email'];
				SendEmail($email,$subject,$body);
				

			}
		}		mysqli_free_result($sql_result);
                return($resultado);
	}

	//registra uma ocorrencia no cadastro de eventos
	function LogEvent($msg, $idpessoa)
	{
	  include('config.php');
	  if(!isset($idpessoa))
	  {
		$idpessoa = 'null';
          }
	  $sql = "insert into logevents (dtcad, idpessoa, event) values (sysdate(),".$idpessoa.",'".$msg."');";
	  //echo $sql;
	  qryExec($sql);
	}
	
	//registra uma ocorrencia no cadastro de acesso
	function LogAccess($ip, $opcr, $opcr2)
	{
		if((!$opcr=="")&&(!$opcr2==""))
		{
			//$opcr = 0;
			//$opcr2 = 0;
		//}
			$sql = " insert into logaccess (dtcad, opc, opc2, ip) values ".
			" ( now(),".$opcr.",".$opcr2.",'".$ip."')";
			qryExec($sql);
		}
	}

	//Captura um parametro
	function GetParam($Paramname)
	{
		$resultado = '';
		$sql = 'select value from params where paramname = "'.$Paramname.'" ';
		//echo($sql);
		$resultado = qryRow($sql,'value');
		//echo($resultado);
		return($resultado);
	}
	
	
	//verifica nivel no grupo  de direito
	//por enquanto apenas de root
	function GetGrupodir($IdUser)
	{
		$sql = 'select  idgrupodir from users  where idUser  = '.$IdUser;
		//echo($sql);
		$resultado = qryRow($sql,'idgrupodir');
		//echo($resultado);
		return($resultado);
	}
	
	
	//deve ser melhorado pois busca por id
	function IsRoot($IdGrupoDir)
	{
	       $resultado = false;
	       if ($IdGrupoDir == 1)
	       {
	       		$resultado = true;
	       }
	       else
	       {
	       		$resultado = false;
	       }
		//echo($resultado);
		return($resultado);
	}
?>
