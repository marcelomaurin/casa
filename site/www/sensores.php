<?php
  //Controla o Debug no projeto
  ini_set('display_errors', 'On');
  
  include "sessao.php";
  include "config.php";
  include "funcs.php";
?>
<!DOCTYPE html > 
<!--[if lt IE 7 ]><html class="ie ie6" lang="en"> <![endif]-->
<!--[if IE 7 ]><html class="ie ie7" lang="en"> <![endif]-->
<!--[if IE 8 ]><html class="ie ie8" lang="en"> <![endif]-->
<!--[if (gte IE 9)|!(IE)]><!--><html class="not-ie" lang="pt"> <!--<![endif]-->
	<script src="https://ajax.googleapis.com/ajax/libs/angularjs/1.5.6/angular.min.js"></script>
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
	<!-- jQuery library -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
	<script src="/casa/js/cmGauge.js"></script>
	<!-- Latest compiled JavaScript -->
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
	<script src="/casa/js/industrial.js"></script> 
	<script type="text/javascript" src="/casa/js/Sensoresfuncs.js">	</script>
	<link rel="stylesheet" href="/casa/css/industrial.css">
	<link rel="stylesheet" href="/casa/css/introjs.min.css">
	<link rel="stylesheet" href="/casa/css/foundation.css">
	<link rel="stylesheet" href="/casa/css/cmGauge.css">
<head>
	<!-- Basic Meta Tags --> 
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <meta name="date" content="0">
  <meta http-equiv="pragma" content="no-cache">
  <meta http-equiv="Cache-Control" content="no-cache, must-reva lidate">
  <meta http-equiv="expires" content="0">

  <title>Maurinsoft</title>
    <!-- <meta http-equiv="refresh" content="15" />-->
	<meta name="description" content="MINETT - Responsive HTML Template by entiri">
	<meta name="keywords" content="minett, entiri, theme, template, corporate, clean, modern, bootstrap, creative, design">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!--[if (gte IE 9)|!(IE)]>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Type" content="text/html;charset=utf-8">
  <![endif]--> 

  <!-- Favicon -->
  <link href="img/favicon.ico" rel="icon" type="image/png">

  <!-- Styles -->
  <link href="css/styles.css" rel="stylesheet">
  <link href="css/bootstrap-override.css" rel="stylesheet">

  <!-- Font Avesome Styles -->
  <link href="css/font-awesome/font-awesome.css" rel="stylesheet">
	<!--[if IE 7]>
		<link href="css/font-awesome/font-awesome-ie7.min.css" rel="stylesheet">
	<![endif]-->

  <!-- FlexSlider Style -->
  <link rel="stylesheet" href="css/flexslider.css" type="text/css" media="screen">

	<!-- Internet Explorer condition - HTML5 shim, for IE6-8 support of HTML5 elements -->
	<!--[if lt IE 9]>
		<script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
	<![endif]-->   
	<?php
	include "login.php";
	//phpinfo();
	?>

</head>       
<body onload="atualizaValores();" >
	<div class="container-fluid" >
		<!-- Titlebar	================================================== -->
		<section id="titlebar">
			<!-- Container -->
			<div class="container-fluid">
			
				<div class="eight columns">
					<h3 class="left">Área Restrita</h3>
				</div>
				<?php
				if ($_SESSION["login"] == true)
				{
				
				?>
				<form name="frmlogout" method="post" action="index.php">
				<div class="eight columns">
					<nav id="breadcrumbs">
						<ul>
							<li></li>
							<li></input></li>
							<li><li><input type="hidden" id="senha" name="frmlogout"></input></li></li>
							<li></input></li>
							<button class="btn btn-gray" onClick="flogout();">Logout</button>
						</ul>
					</nav>
				</div>
				</form>
				<?php
				}
				?>
			</div>
			<!-- Container / End -->
		</section>
		<!-- Header -->
		<header id="header">
			<div class="container-fluid">
				<div class="row t-container">

					<!-- Logo -->
					<div class="span3">
					  <div class="logo">
						<a href="index.php"><img src="img/logo-header.png" alt=""></a>
					  </div>            
					</div>

					<div class="span12">
						<div class="row space60"></div>
							<nav id="nav" role="navigation">
							 <?php
								if ($_SESSION["login"] == true)
								{
							 ?>
								<a href="#nav" title="Show navigation">Show navigation</a>
								<a href="#" title="Hide navigation">Hide navigation</a>
								<ul class="clearfix">
								<li class="active"><a href="/casa/index.php" title="">Principal</a></li>
								<li><a href="/casa/sensores.php" title="">Sensores</a></li>      
								<li><a href="/casa/cameras.php" title="">Hist. Cameras</a></li>  
								<li><a href="/casa/sms.php" title="">SMS</a></li>   				
								<li><a href="/casa/fala.php" title="">Sint. Fala</a></li>   				
							   </ul>
							   <?php
								}
								else
								{
							   ?>
							   <a href="#nav" title="Show navigation">Show navigation</a>
								<a href="#" title="Hide navigation">Hide navigation</a>
								<ul class="clearfix">
								<li class="active"><a href="/index.php" title="">E-Comerce</a></li>
								<li><a href="index.php" title="">Principal</a></li>                
							   </ul>
							   <?php
								}
							   ?>
							</nav>
						</div>
					</div> 
				</div> 
			</div> 
			
		</header><!-- Fim do Header-->
		<div class="col-sm-12 text-center" id="header">	   
			   <?php
			   if ($_SESSION["login"] == false)
				{
					echo ($_SESSION["msg"]);
				}
			   ?>
		</div>
	  
	  <?php
			if ($_SESSION["login"] == true)
			{
				
		
	   ?>
	   
	   <div class= "container-fluid bg-1 text-center"  ng-app="CASA_Sensor" ng-controller="cntrl">
			<div class="col-sm-12">			
				<div class="col-sm-4">
					<div class="row">
						<h2>Sala</h2>
					</div>
				
					<div class="row">
						<b>Iluminação:</b>
					
					<?php
							$status = qryRow('select devvalue from devpar where iddevice = 2 and devparname = "dev5"','devvalue');
							if ($status=="1" )
							{
								echo ("Ligado!");
							}
							else
							{
								echo ("Desligado!");
							}
					?>
					</div>
					<div class="row">
						<form action="sensores.php"  method="post">			
							<input type="submit" class="button" name="luzExt" value="Ligar" />
							<input type="submit" class="button" name="luzExt" value="Desligar" />
						</form>
					</div>
					<?php	
				   //echo(var_dump($_REQUEST));
					if (isset($_REQUEST['luzExt'])) 
					{
						if (($_REQUEST['luzExt'])=='Ligar') 
						{
								LigarluzExt();
						}
					
				
						if (($_REQUEST['luzExt'])=='Desligar') 
						{
								DesligarluzExt();
						}
					}		
					?>
				</div>
				<div class="col-sm-4">
					<div class="row">
						<h2>Area Externa</h2>
					</div>
					<div class="row">
						<b>Iluminação:</b>
						
					   <?php
									$status = qryRow('select devvalue from devpar where iddevice = 1 and devparname = "dev1"','devvalue');
									if ($status=="1" )
									{
										echo ("Ligado!");
									}
									else
									{
										echo ("Desligado!");
									}
							?>
					</div>
					<div class="row">		
						 <form action="sensores.php"  method="post">			
								<input type="submit" class="button" name="luz" value="Ligar" />
								<input type="submit" class="button" name="luz" value="Desligar" />
						</form>
					</div>
					<?php	
						//echo(var_dump($_REQUEST));
							if (isset($_REQUEST['luz'])) 
							{
								if (($_REQUEST['luz'])=='Ligar') 
								{
										Ligarluz();
								}
							
						
								if (($_REQUEST['luz'])=='Desligar') 
								{
										Desligarluz();
								}
							}		
					?>  
					
				</div>
			
				<div class="col-sm-4">
						<div class="row">
							<h2>Luz Corredor</h2>
						</div>
						<div class="row">
							<b>Iluminação:</b>
							
						   <?php
										$status = qryRow('select devvalue from devpar where iddevice = 2 and devparname = "dev5"','devvalue');
										if ($status=="1" )
										{
											echo ("Ligado!");
										}
										else
										{
											echo ("Desligado!");
										}
								?>
						</div>
						<div class="row">		
							 <form action="sensores.php"  method="post">			
									<input type="submit" class="button" name="luzLat" value="Ligar" />
									<input type="submit" class="button" name="luzLat" value="Desligar" />
							</form>
						</div>
						<?php	
							//echo(var_dump($_REQUEST));
								if (isset($_REQUEST['luzLat'])) 
								{
									if (($_REQUEST['luzLat'])=='Ligar') 
									{
											LigarluzLat();
									}
								
							
									if (($_REQUEST['luzLat'])=='Desligar') 
									{
											DesligarluzLat();
									}
								}		
						?>  
						
				</div>
			</div>
			
		</div>
		
		
		<div class= "container-fluid bg-1 text-center">
			<div class="col-sm-12">
				<div class="row text-center">
					<h2>Irrigação</h2>
				</div>
			</div>
			<div class="col-sm-12">		
				<div class="row">				
					<div class="col-sm-12 text-center ">
						<b>Status:</b>
						<?php
						   $status = qryRow('select devvalue from devpar where iddevice = 2 and devparname = "dev1"','devvalue');
									if ($status=="1" )
									{
										echo ("Ligado!");
									}
									else
									{
										echo ("Desligado!");
									}
						?>
					</div>
				</div>
				<div class="row">				
					<div class="col-sm-12 text-center">
					   
						<form action="sensores.php"  method="post">			
								<input type="submit" class="button" name="Ligar" value="Ligar" />
								<input type="submit" class="button" name="Desligar" value="Desligar" />
						</form>
					</div>
				</div>
			</div>
			
			
			
			

			<?Item painel?>
			<div class="col-sm-12">
				<div class="col-sm-6">
					<div class="row">
						<h3>Humidade do solo:</h3>	
					</div>
					<div class="row">
						<?php
						   
						   $status = qryRow('select devvalue from devpar where iddevice = 2 and devparname = "dev2"','devvalue');
						   //$devvalue = (((1024-$status)/1024)*100);
						   $devvalue = $status;
						   $gaugecolor = "gauge-green";
						   if($status>75)
						   {
							   $solo = "Solo Seco!";	
							   $gaugecolor = "gauge-cyan";	
						   }
						   else
						   {
							   if($status>25)
							   {
								   $solo = "Úmido!";
								   $gaugecolor = "gauge-green";
								   
							   }
							   else
							   {
									$solo ="Solo Molhado!";
									
									$gaugecolor = "gauge-blue";
							   }
						   }
						   
							
						  // echo ("<p>Valor ref.::".$status."</p>");
							
						?>
						
					</div>
					<div class="row">
						<div id="gaugeHumidade" class="gauge gauge-big <?php echo $gaugecolor;?>" value="<?php /*echo $devvalue;*/ ?>{{Humidade}}">
							<div class="gauge-arrow" data-percentage="<?php echo ($devvalue); ?>" 
								style="transform: rotate(0deg);">
							</div>
						</div>

					</div>
					<div id="valHumidade" class="row meter green">
						<?php echo ($devvalue); ?>%
						
					</div>
					<div class="row">
						<?php echo $solo; ?>
					</div>
				</div>
			
			
				<div class="col-sm-6">	
					<div class="row">
						<h3>Sensor de chuva:</h3>
					</div>
					<?php
						   $status = qryRow('select devvalue from devpar where iddevice = 2 and devparname = "dev3"','devvalue');
						   //$devvalue = (((1024-$status)/1024)*100);
						   $devvalue = $status;
						   if($status>75)
						   {
							   $sensor = "Tempo Seco!";
							   $gaugecolor = "gauge-cyan";	
						   }
						   else
						   {
							   if($status>20)
							   {
								   $sensor = "Chuva recente!";
									$gaugecolor = "gauge-green";	
							   }
							   else
							   {
									  $sensor = "Chuvendo!";
									  $gaugecolor = "gauge-blue";	
							   }
						   }
						  // echo ("<p>Valor ref.::".$status."</p>");
							
					?>

					<div class="row">
						<div id="gaugeSensor" class="gauge gauge-big <?php echo $gaugecolor;?>" value="<?php echo $devvalue; ?>">
							<div class="gauge-arrow" data-percentage="<?php echo ($devvalue); ?>"  
								style="transform: rotate(0deg);">
							</div>
						</div>
					</div> 
					<div id="valSensor" class="row meter green"> 			
						<?php echo $devvalue;?>%
					</div>				
					<div class="row"> 			
						<?php 
						echo $sensor;
						?>
					</div>		
					<?php
							//echo(var_dump($_REQUEST));
							if (isset($_REQUEST['Ligar'])) 
							{
								if (($_REQUEST['Ligar'])=='Ligar') 
								{
										Ligar();
								}
							}
							if (isset($_REQUEST['Desligar'])) 
							{
								if (($_REQUEST['Desligar'])=='Desligar') 
								{
										Desligar();
								}
							}		
					?>
				</div>
			</div>
		</div>
		
						<?php
					
						}
						
						?>
				
		</div>
			<? *** Controler *** ?>
			<script>
				var app = angular.module('CASA_Sensor',[]);
				app.controller('cntrl', function($scope,$http)
				{
					//Inicializa Humidade
					$scope.Humidade = 0;
					
					//Função que atualiza a cada segundo
					$interval(function () {
						AtualizaDados();
						
					}, 5000);
					
					$scope.AtualizaDados= function()
					{
						//Atualiza dados de Humidade
						//iddevice = 2 and devparname = "dev2"
						displayHumidade(2,"dev2");						
						
					}
					
					$scope.displayHumidade=function(iddevice,devparname)
					{
						var params = {"iddevice": iddevice, "devparname":devparname };
						var config = {params: params};
						
						
						$http.get("/ws/selDEVPAR.php",config)
						.success(function(data)
						{
							$scope.Humidade=data;							
						})
						.error(function()
						{							
							$scope.Humidade=null;
						}) 
					}
					
				});
			</script>
</body>
</html>
  