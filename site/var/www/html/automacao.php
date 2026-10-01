<?php
// Cenas e regras agora são um módulo nativo do painel (lcars-site.js -> renderAutomation,
// API em api/automacao.php). Este endereço antigo apenas redireciona para lá.
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }
$dest = '/casa/index.php?grupo=' . rawurlencode('AUTOMAÇÃO') . '&item=automation-scenes';
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Cenas e regras</title></head>
<body><script>(window.top||window).location.replace(<?= json_encode($dest) ?>);</script>
<noscript><a href="<?= htmlspecialchars($dest, ENT_QUOTES, 'UTF-8') ?>">Abrir Cenas e regras</a></noscript></body></html>
