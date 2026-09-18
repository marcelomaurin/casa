<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }
$authName = !empty($_SESSION['auth_name']) ? $_SESSION['auth_name'] : ($_SESSION['auth_user'] ?? 'Usuário');
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
  <title>CASA / COMPUTER</title>
  <link rel="stylesheet" href="/casa/lcars-framework.css?v=1.2.0">
  <link rel="stylesheet" href="/casa/lcars-site.css?v=1.4.4">
</head>
<body>
  <div class="ja-admin-template" id="ja-admin-template" aria-hidden="true">
    <button class="home" type="button">GRUPOS</button>
    <button class="password" type="button">ALTERAR SENHA</button>
    <a class="logout" href="/casa/login.php?logout=1">SAIR · <?= htmlspecialchars($authName, ENT_QUOTES, 'UTF-8') ?></a>
  </div>
  <div id="app"></div>
  <noscript>O CASA/COMPUTER precisa de JavaScript habilitado para a interface adaptativa.</noscript>
  <script src="/casa/lcars-framework.js?v=1.3.0"></script>
  <script src="/casa/lcars-site.js?v=1.4.4"></script>
</body>
</html>
