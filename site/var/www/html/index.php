<?php
/**
 * CASA/JARVIS - entrada principal responsiva com tema LCARS.
 *
 * O dashboard funcional original foi preservado em index_legacy.php.
 * Este arquivo aplica o tema LCARS diretamente na resposta HTML, sem depender
 * de mod_substitute no Apache/Hostinger.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['auth_user'])) {
    header('Location: /casa/login.php');
    exit;
}

ob_start();
require __DIR__ . '/index_legacy.php';
$html = ob_get_clean();

$lcarsLink = '<link rel="stylesheet" href="/casa/lcars.css?v=20260914b">';
if (stripos($html, '/casa/lcars.css') === false) {
    $html = str_ireplace('</head>', "  {$lcarsLink}\n</head>", $html);
}

// Ajustes de implantação sob /casa e remoção de referências fixas do cabeçalho.
$html = str_replace('/login.php?logout=1', '/casa/login.php?logout=1', $html);
$html = str_replace('ONLINE • 192.168.2.12', 'CASA DISTRIBUÍDA • ONLINE', $html);

// Marca explicitamente o dashboard para o CSS responsivo LCARS.
$html = str_ireplace('<body>', '<body class="lcars-dashboard">', $html);

echo $html;
