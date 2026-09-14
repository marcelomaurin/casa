<?php
// CASA/JARVIS - Diagnostico simples da instalacao.
// Nao exibe phpinfo(), senhas, tokens ou detalhes sensiveis do servidor.
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$checks = [];
$overall = true;

function add_check(&$checks, &$overall, $nome, $ok, $detalhe = '') {
    $checks[] = ['nome' => $nome, 'ok' => (bool)$ok, 'detalhe' => $detalhe];
    if (!$ok) $overall = false;
}

add_check($checks, $overall, 'PHP', true, PHP_VERSION);
add_check($checks, $overall, 'PDO', extension_loaded('pdo'), extension_loaded('pdo') ? 'carregado' : 'nao carregado');
add_check($checks, $overall, 'PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'carregado' : 'nao carregado');
add_check($checks, $overall, 'cURL', extension_loaded('curl'), extension_loaded('curl') ? 'carregado' : 'nao carregado');
add_check($checks, $overall, 'JSON', extension_loaded('json'), extension_loaded('json') ? 'carregado' : 'nao carregado');

$dbOk = false;
$dbDetail = 'nao testado';
$tableCount = null;
$adminOk = false;

try {
    require_once(__DIR__ . '/api/db.php');
    $pdo = get_db_pdo();
    $dbOk = true;
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $tableCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE login = 'admin' AND ativo = 1");
    $stmt->execute();
    $adminOk = ((int)$stmt->fetchColumn() > 0);
    $dbDetail = 'conectado em ' . $dbName;
} catch (Throwable $e) {
    // Nao expor host, usuario, senha, DSN ou stack trace.
    $dbDetail = 'falha de conexao ou inicializacao';
}

add_check($checks, $overall, 'MySQL CASA', $dbOk, $dbDetail);
add_check($checks, $overall, 'Tabelas', $dbOk && $tableCount > 0, $dbOk ? ($tableCount . ' tabela(s) encontrada(s)') : 'indisponivel');
add_check($checks, $overall, 'Usuario admin', $dbOk && $adminOk, $adminOk ? 'instalado e ativo' : 'nao encontrado');

http_response_code($overall ? 200 : 503);
$status = $overall ? 'ONLINE' : 'ATENCAO';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CASA/JARVIS - Diagnostico</title>
<style>
:root{--bg:#f4e8d1;--panel:#fff8e8;--ink:#241f24;--orange:#e98b52;--salmon:#d96c75;--lav:#8d78a8;--blue:#617fa3;--ok:#397a4a;--bad:#a93e45}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--bg);color:var(--ink);font-family:Arial,sans-serif;padding:32px}.lcars{max-width:900px;margin:auto;display:grid;grid-template-columns:150px 1fr}.rail{background:var(--orange);border-radius:42px 0 0 42px;padding:34px 16px;display:flex;flex-direction:column;gap:12px}.rail span{display:block;padding:12px;background:var(--salmon);border-radius:18px 0 0 18px;font-weight:bold}.rail span:nth-child(2){background:var(--lav)}.rail span:nth-child(3){background:var(--blue)}.content{background:var(--panel);border:12px solid var(--orange);border-left:0;border-radius:0 36px 36px 0;padding:30px}h1{margin-top:0;letter-spacing:2px}.status{font-size:24px;font-weight:900;margin-bottom:24px}.online{color:var(--ok)}.attention{color:var(--bad)}table{width:100%;border-collapse:collapse}td{padding:12px;border-bottom:1px solid #d8c9ae}td:nth-child(2){font-weight:bold}.ok{color:var(--ok)}.bad{color:var(--bad)}.foot{margin-top:24px;font-size:13px;opacity:.75}@media(max-width:650px){body{padding:12px}.lcars{grid-template-columns:72px 1fr}.rail{padding:24px 8px}.rail span{font-size:0;min-height:38px}.content{padding:20px}}
</style>
</head>
<body>
<div class="lcars">
  <aside class="rail"><span>CASA</span><span>JARVIS</span><span>INFO</span></aside>
  <main class="content">
    <h1>CASA / JARVIS</h1>
    <div class="status <?= $overall ? 'online' : 'attention' ?>">SISTEMA <?= htmlspecialchars($status) ?></div>
    <table>
      <?php foreach ($checks as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['nome']) ?></td>
        <td class="<?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? 'OK' : 'FALHA' ?></td>
        <td><?= htmlspecialchars($c['detalhe']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div class="foot">Dominio esperado: casa.maurinsoft.com.br · <?= htmlspecialchars(date('Y-m-d H:i:s')) ?></div>
  </main>
</div>
</body>
</html>
