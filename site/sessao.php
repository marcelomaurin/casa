<?php
    
	session_start();

	if (!(isset($_SESSION['login']) ) )
	{
		$_SESSION["login"] = false;
		//$logado = false;
		$_SESSION["msg"] = "Bem vindo ao sistema, digite o login e a senha para acessar a area restrita!";
		$_SESSION["count"] = 1;
		$_SESSION["pusuario"] = "";
		$_SESSION["iduser"] = 0;
		$_SESSION["idEmpresa"] = 0;
		$_SESSION["idRoleGrp"]  =0;
		$_SESSION["security_life"] = false;
		$_SESSION["utf8_control"] = false;
		
    }
	
?>
