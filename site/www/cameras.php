<?php
  //Controla o Debug no projeto
  ini_set('display_errors', 'On');
  
  include "sessao.php";
  include "config.php";
  include "funcs.php";
?>
<html>
<header>

	<!-- Basic Meta Tags -->
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  
  
  <!-- Styles -->
	<link href="css/styles.css" rel="stylesheet">
	<link href="css/font-awesome/font-awesome.css" rel="stylesheet">
	<link rel="stylesheet" href="css/flexslider.css" type="text/css" media="screen">
	
  
    <!-- Codigos -->
	<script src="https://ajax.googleapis.com/ajax/libs/angularjs/1.5.6/angular.min.js"></script>
	<!-- Latest compiled and minified CSS -->
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">

	<!-- jQuery library -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>

	<!-- Latest compiled JavaScript -->
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
	

  <title>Maurinsoft</title>

</header> 

	
<body >
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
				</div>
			</div>
			<div class="container-fluid">
				<div class="row t-container">
					<div class="span12">
						
					</div>
				</div> 
		</header">
		
		<div class="col-sm-12 text-center" id="header">	   
			   <?php
			   if ($_SESSION["login"] == false)
				{
					echo ($_SESSION["msg"]);
				}
			   ?>
		</div>
		
		<div class="col-sm-12" >	  
			<!--Inicio de codigo real-->
			<div ng-app="Camera" ng-controller="cntrl" >
				<div class="row t-container">
				    <div class='col-sm-8' >
					   
					   <div  class='col-sm-4'>
						   <form >
							   <div class="row t-container">
								   Camera:<br>
								   <div class='col-sm-12'>			        
										<select ng-model="nroCamera" ng-change="MudaView(nroCamera)" >
										  
										  <option value="1" >Garagem</option>
										  <option value="2" >Interfone</option>
										  <option value="3">Cor. Lateral</option>
										  <option value="4">Servidor</option>
										</select>
									</div>
							   </div>
							   <div class="row t-container">
								   Data Inicio:<br>
								   <div class='col-sm-12'>			        
										<input  type="text" ng-model="DataInicio" value=""  placeholder="dd/mm/yyyy">
									</div>
							   </div>
							   <div class="row t-container">
								   Data Fim:<br>
									<div  class='col-sm-12'>
									  
										<input  type="text" ng-model="DataFim" value="" placeholder="dd/mm/yyyy"  >
									</div>
							   </div>
							   	<div class="row t-container">
								   Retornos:<br>
									<div  class='col-sm-12'>
										<input  type="text" ng-model="Retornos" value="10" placeholder=""  >
									</div>
							   </div>
							   <div style="text-align: rigth;" class="row t-container">
								<input type="submit" value="Pesquisar" ng-click="displayCamera()" >
							   </div>
						   </form>
						</div>
						<div class='col-sm-2'>
						</div>
						<div class='col-sm-6'>
							<center>Vídeos Online: </center>
							<img  style="heigth:200px;width:200px;" class="img-responsive" src="{{urlImagem}}">	
							
						
						</div>				
					</div>
				</div>
				<div style="heigth:20px;" class="row t-container"> 			
					{{msg}}
				</div>
				<div class="row t-container" ng-style="disableGrid"> <?** Grid **?>
					<div class='col-sm-12' >
						<div class='col-sm-1' >
						</div>
						<div class='col-sm-10' >			
							<table class="table table-striped">
								<thead>
									<tr>
										<th class='col-sm-1'>Camera</th>								
										<th class='col-sm-1'>Tipo</th>
										<th class='col-sm-1'>Frame</th>						
										<th class='col-sm-1'>Hora</th>						
										<th class='col-sm-5'>Imagem</th>						
														
									<tr>
								</thead>
								<tbody>
									<tr ng-repeat="reg in data">
										<td>{{reg.camera}}</td>								
										<td>{{reg.file_type}}</td>
										<td>{{reg.frame}}</td>
										<td>{{reg.time_stamp}}</td>
										<td><img  class="img-responsive center-block" src="{{reg.filename}}">	</td>
																	
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>
				
				
			</div>  <?**Bloco do angular**?>
		</div>
	</DIV> 

<? *** Controler *** ?>
	<script>
		var app = angular.module('Camera',[]);
		app.controller('cntrl', function($scope,$http)
		{
			 $scope.disableGrid = {'display': 'none'}; //Atribui Edicao invisivel
			 $scope.urlImagem = "http://maurinsoft.com.br:8083/";
			 $scope.nroCamera = "2";
			 $scope.Retornos = "10";
			 //Funcao Visualizar
			 $scope.Visualizar=function(path)
			 {
					$scope.urlImagem = path;
			 }
			 
			 //Visualizador Real do que esta acontecendo
			 $scope.MudaView=function(camera)
			 {
				 if(camera=="1")
				 {
					  $scope.urlImagem = "http://maurinsoft.com.br:8082/";
				 }
				 if(camera=="2")
				 {
					  $scope.urlImagem = "http://maurinsoft.com.br:8083/";
				 }
				 if(camera=="3")
				 {
					  $scope.urlImagem = "http://maurinsoft.com.br:8084/";
				 }
				 if(camera=="4")
				 {
					  $scope.urlImagem = "http://maurinsoft.com.br:8081/";
				 }					 				 
			 }
			 //Funcao que trata MudaURL
			 $scope.MudaURL=function(data)  
			 { 
				change = data;			
				
				for(i in change) 
				{
					var item = change[i];
					change[i].filename = change[i].filename.replace("http://maurinsoft.com.br/var/www/htmldev", "http://maurinsoft.com.br/casa")
				}			
				return change; 
			 } 
			 
			 //Funcao displayCamera
			 $scope.displayCamera=function()
			{
									
				//$http.get("/ws/sJobSMS.php",config)
				
				var URLCompleto = "http://maurinsoft.com.br/casa/ws/sCameras.php?camera="+$scope.nroCamera;
				if($scope.DataInicio != undefined) {
					URLCompleto = URLCompleto + "&DataInicio="+$scope.DataInicio;
					}
				//alert($scope.DataFim);
				if($scope.DataFim != undefined) {
					URLCompleto = URLCompleto + "&DataFim="+$scope.DataFim;
					}	
				//alert($scope.Retornos);					
				if($scope.Retornos != undefined )  {
					URLCompleto = URLCompleto + "&Retornos="+$scope.Retornos;
					}						
				$http.get(URLCompleto)
				.success(function(data)
				{
					// /var/www/html/
					data = $scope.MudaURL(data);
					$scope.data = data;
					$scope.msg = "Pesquisa realizada!";
					$scope.disableGrid = {'display': 'block'}; 
				})
				.error(function()
				{
					$scope.msg = "Pesquisa retornou vazia";
					$scope.data = null;
				}) 
			}
		
		});	 
</script>		
   
</body>

</html>
  