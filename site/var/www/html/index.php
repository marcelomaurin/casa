<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }
$authName = !empty($_SESSION['auth_name']) ? $_SESSION['auth_name'] : ($_SESSION['auth_user'] ?? 'Usuário');
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
  <title>CASA / JARVIS</title>
  <link rel="stylesheet" href="/casa/lcars-framework.css?v=1.2.0">
  <link rel="stylesheet" href="/casa/lcars-site.css?v=1.2.0">
</head>
<body>
  <div class="ja-site-tools" aria-label="Ferramentas do sistema">
    <button class="home" type="button" onclick="CASASite.home()">GRUPOS</button>
    <a href="/casa/login.php?logout=1">SAIR · <?= htmlspecialchars($authName, ENT_QUOTES, 'UTF-8') ?></a>
  </div>
  <div id="app"></div>
  <noscript>O CASA/JARVIS precisa de JavaScript habilitado para a interface adaptativa.</noscript>
  <script src="/casa/lcars-framework.js?v=1.2.0"></script>
  <script src="/casa/lcars-site.js?v=1.2.0"></script>
</body>
</html>
