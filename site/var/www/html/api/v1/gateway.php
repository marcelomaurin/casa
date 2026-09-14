<?php
// Gateway seguro da API externa v1.
// Valida cliente, escopo, rate limit e payload antes de encaminhar ao roteador legado.

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$path = trim($uri, '/');
$subpath = '';
if (preg_match('#api/v1/(.*)#', $path, $m)) $subpath = trim($m[1], '/');
if ($subpath === 'gateway.php' || $subpath === 'index.php') $subpath = '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$acceptedScopes = ['status.read'];

if ($subpath === '' || $subpath === 'status') {
    $acceptedScopes = ['status.read','mobile.read','watch.read'];
} elseif ($subpath === 'comando') {
    $acceptedScopes = ['jarvis.command','mobile.write','watch.write'];
} elseif ($subpath === 'dispositivos/acionar') {
    $acceptedScopes = ['devices.write','mobile.write'];
} elseif ($subpath === 'dispositivos') {
    $acceptedScopes = ['devices.read','mobile.read','watch.read'];
} elseif ($subpath === 'sensores') {
    $acceptedScopes = ['sensors.read','mobile.read'];
} elseif ($subpath === 'clima') {
    $acceptedScopes = ['climate.read','mobile.read'];
} elseif ($subpath === 'camera/snapshot') {
    $acceptedScopes = ['camera.read','mobile.read'];
}

$client = api_v1_auth_client_any($pdo, $acceptedScopes);

// O roteador legado possui uma segunda autenticação. A chave mestre é usada
// somente dentro do servidor depois que o token individual já foi validado.
$stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave='external_api_key' LIMIT 1");
$stmt->execute();
$master = $stmt->fetchColumn();
if (!$master) {
    $candidate = 'jarvis_sec_v1_' . bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO configuracoes_sistema(chave,valor,descricao)
        VALUES('external_api_key',:v,'Chave mestre interna da API externa')
        ON DUPLICATE KEY UPDATE valor=valor")
        ->execute([':v'=>$candidate]);
    $stmt->execute();
    $master = $stmt->fetchColumn() ?: $candidate;
}

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $master;
unset($_SERVER['HTTP_X_API_KEY']);
unset($_GET['api_key']);

api_v1_log($pdo, 'REQUEST_ALLOWED', 'INFO', $client['nome'] ?? null, [
    'accepted_scopes' => $acceptedScopes,
    'subpath' => $subpath,
    'method' => $method
]);

require(__DIR__ . '/index.php');
