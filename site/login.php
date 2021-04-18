
<?php   
	include('config.php');
	$_SESSION["msg"] = "";
    //Realiza a valida?ao do login e senha do usuario
	if(isset($_POST['pusuario']))
	{
	    //echo($_POST['pusuario']);
	    if(isset($_POST['pusuario']))
	    {
			//Conectando no banco
    		$conperm = mysqli_connect($dbhost,$dbuser,$dbpassword);
			$db = mysqli_select_db($conperm,$database) or die (Showerro("Não foi possível selecionar banco de dados","login.php"));
			
			$sql = "select * from users where login = '".$_POST['pusuario']."' and password = '".$_POST['psenha']."' and status = 1;";
			//echo $sql;
			$sql_result_login = mysqli_query( $conperm, $sql) or die (Showerro("Não foi possível executar a consulta","login.php"));
			if  (!isset($sql_result_login))
			{
			  //$msg = "Login invalido por favor, tente novamente!";
			  $_SESSION["msg"] = "Usuario/Senha não consta no nosso banco de dados";
			  //echo $msg;
			 
			  $_SESSION["login"]=false;
			}
			 else
			{
			  $num= mysqli_num_rows($sql_result_login);
			  //echo 'num:'.$num;
			  if ($num==0)
			  {
				//LogEvent('Usuario:'.$pusuario.' Tentou acesso sem sucesso! ',0);
				$_SESSION["msg"] = "Login invalido por favor, tente novamente!";
				//echo $_SESSION["msg"];
				
				$_SESSION["login"] = false;
				
			   }
			   else
			  {
				 if ($num==1) 
				 {
					$rows =  mysqli_fetch_array($sql_result_login);
					//$count++;
					//indica que esta logado
					$_SESSION["login"] = true;
					$_SESSION["pusuario"] = $_POST['pusuario'];
					$_SESSION["iduser"] =  $rows["iduser"];
					//$_SESSION["idRoleGrp"]  = $rows["idRoleGrp"];
					//$_SESSION["idEmpresa"]  = $rows["idEmpresa"];
					//Registra evento	
					//LogEvent('Usuario conectado:'.$pusuario,$idpessoa);
					$_SESSION["msg"] = "Bem vindo ao sistema ".$_SESSION["pusuario"];
				  }
			  }
			
			}
			mysqli_free_result($sql_result_login);
	    }
	}
	else
	{
		$_SESSION["msg"] = "Antes de tentar logar, digite o usuario e a senha do mesmo";
	}
	//Botao  logout
	if(isset($_POST['frmlogout']))
	{
	    //echo("Fechou!");	    
		$_SESSION["login"] = false;
	
	}

