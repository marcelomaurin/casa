<?php
  //Controla o Debug no projeto
  ini_set('display_errors', 'On');
  
  include "sessao.php";
  include "config.php";
  include "funcs.php";
?>
<!DOCTYPE html>
<!--[if lt IE 7 ]><html class="ie ie6" lang="en"> <![endif]-->
<!--[if IE 7 ]><html class="ie ie7" lang="en"> <![endif]-->
<!--[if IE 8 ]><html class="ie ie8" lang="en"> <![endif]-->
<!--[if (gte IE 9)|!(IE)]><!--><html class="not-ie" lang="pt"> <!--<![endif]-->
<head>
	<!-- Basic Meta Tags -->
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <meta name="date" content="0">
  <meta http-equiv="pragma" content="no-cache">
  <meta http-equiv="Cache-Control" content="no-cache, must-reva lidate">


  <title>Maurinsoft</title>
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
	<link href="css/font-awesome/font-awesome.css" rel="stylesheet">
	<link rel="stylesheet" href="css/flexslider.css" type="text/css" media="screen">
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
	<link href="css/bootstrap-override.css" rel="stylesheet">
	  

    <!-- Font Avesome Styles -->
  
	<!--[if IE 7]>
		<link href="css/font-awesome/font-awesome-ie7.min.css" rel="stylesheet">
	<![endif]-->


  

	<!-- Internet Explorer condition - HTML5 shim, for IE6-8 support of HTML5 elements -->
	<!--[if lt IE 9]>
		<script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
	<![endif]-->  
	<script src="https://ajax.googleapis.com/ajax/libs/angularjs/1.5.6/angular.min.js"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>	
	<?php
	include "login.php";
	//phpinfo();
	?>

</head>       
<body>
  <!-- Header -->
  <header id="header">
    <div class="container">
      <div class="row t-container">

        <!-- Logo -->
        <div class="span3">
          <div class="logo">
            <a href="index.php"><img src="img/logo-header.png" alt=""></a>
          </div>            
        </div>

        <div class="span9">
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
       <div class="row space40"></div>
          <div class="slider1 flexslider">  <!-- Slider -->
            <ul class="slides">
              <li>
    	    	    <img src="img/slider/1.jpg" alt="">
    	    		</li>
    	    		<li>
    	    	    <img src="img/slider/2.jpg" alt="">
    	    		</li>
    	    		<li>
    	    	    <img src="img/slider/3.jpg" alt="">
    	    		</li>
                    <li>
    	    	    <img src="img/slider/4.jpg" alt="">
    	    		</li>
            </ul>
          </div>  <!-- Slider End -->
  </div> 
</header>
<body>
 
   <!-- Titlebar
================================================== -->
<section id="titlebar">
	<!-- Container -->
	<div class="container-fluid">
		<div class="row">
			<div class="col-sm-12 text-center">
				<h3>Área Restrita</h3>
			</div>
		</div>
		
		
			<?php
			if ($_SESSION["login"] == false)
			{
			?>
		<div class="row">
			<form name="frmlogin" method="post" action="index.php">
				<div class="col-sm-2">
				
				</div>
				<div class="col-sm-8">							
					<div class="col-sm-3">
						<div class="input-group">
							<span class="input-group-addon"><i class="glyphicon glyphicon-user"></i></span>
							<input type="text" class="form-control"  id="login" name="pusuario" placeholder="Usuario" required></input>
						</div>
					</div>
					<div class="col-sm-1">
					</div>
					<div class="col-sm-3">
						<div class="input-group">							
							<span class="input-group-addon"><i class="glyphicon glyphicon-lock"></i></span>
							<input type="password" class="form-control"  id="senha" name="psenha" placeholder="Senha" required></input>
						</div>
					</div>
					<div class="col-sm-1">
					</div>
					<div class="col-sm-1">
							<button class="btn btn-green" onclick="flogin();">Logar</button> 
					</div>					
					
				</div>
			</form>
		</div>
			<?php
			}
			else
			{
			?>
		<div class="row">
			<form name="frmlogout" method="post" action="index.php">
			<div class="eight columns">
				<nav id="breadcrumbs">
					<ul>
						<li></li>
						<li></input></li>
						<li><li><input type="hidden" id="senha" name="frmlogout"></input></li></li>
						<li></input></li>
						<button class="btn btn-gray" onclick="flogout();">Logout</button>
					</ul>
				</nav>
			</div>
			</form>
		<div class="row">
			<?php
			}
			?>
		
	</div>
	<!-- Container / End -->
</section>
   <div id="header">
   
   
  </div>
  <?php
		if ($_SESSION["login"] == true)
		{
			if(ConfereIPExtInt()==1)
			{
   ?>
   <div class="container-fluid col-sm-4">
   <a href="http://maurinsoft.com.br:8081" target="_blank">  
	   <div class="row text-center">
		<h3>CPD</h3>
	   </div>  
	   
	   <div class="row">
			<!-- Camera WEB -->
                  
			<iframe id="mainUrl"  src="http://maurinsoft.com.br:8081" scrolling="no" frameborder="0" allowTransparency="true">
			</iframe>
            
		</div>
        </a>
	</div>
	<div class="container-fluid col-sm-4">
    <a href="http://maurinsoft.com.br:8082" target="_blank">        
	   <div class="row text-center">
	   
		<h3>Garagem</h3>
	   </div>
	   <div class="row">	
       
			<iframe id="mainUrl"  src="http://maurinsoft.com.br:8082" scrolling="no" frameborder="0" allowTransparency="true">
			</iframe>
            
		</div>
        </a>
	</div>
	<div class="container-fluid col-sm-4">
        <a href="http://maurinsoft.com.br:8083" target="_blank">
		  <div class="row text-center">
			<h3>Externa Interfone</h3>
		  </div>
		  <div class="row">
            
				<iframe id="mainUrl" src="http://maurinsoft.com.br:8083" scrolling="no" frameborder="0" allowTransparency="true"> 
				</iframe>
            
		  </div>
        </a>
	</div>
	<div class="container-fluid col-sm-4">
      <a href="http://maurinsoft.com.br:8084" target="_blank">
		  <div class="row text-center">
			<h3>Corredor Lateral</h3>
		  </div>
		  <div class="row">
                 
				    <iframe id="mainUrl" src="http://maurinsoft.com.br:8084" scrolling="no" frameborder="0" allowTransparency="true"> 
				    </iframe>
                
		  </div>
       </a>
	</div>
	 
    <?php
			}
			else
			{
	?>
				
   <div>
   Camera Frente
   </div>
   
   <!-- Camera WEB -->
  <iframe id="mainUrl" width="960" height="710" src="http://maurinsoft.com.br:8081" scrolling="no" frameborder="0" allowTransparency="true"></iframe>
   <div>
   Camera CPD
   </div>
    <!-- Camera CPD -->
  <iframe id="mainUrl" width="960" height="710" src="http://maurinsoft.com.br:8082" scrolling="no" frameborder="0" allowTransparency="true"></iframe>
   
				
				
	<?php
			}
		}
		else
		{
			//include "jornada/cadastro.php";
   ?>
   			<div class="row space40"></div>   
              
              <!-- Blog Item -->
              <div class="row">
                <div class="span1">
  
                  <div class="blog-icon">
                    <i class="icon-quote-right"></i><br>
                    <h5>Cadastro Online</h5>                  
                  </div>
                     
                </div>
                <div class="span8">
                  <div class="post-d-info">
                    <a href="blog-detail.htm"><h3>Casa Inteligente</h3></a>             
                      <p>Através  deste site é possivel gerenciarr todos os dispositivos da casa.</p>
					  <p>Este sistema é exclusivo para controle da casa</p>					  
                  </div>  
                </div>
              </div>
			</div>
			
    
   
    <?php
		}
   ?>
</body>
</html>
  
