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
if (preg_match('#api/v1/(.*)#', $path, $m)) {
    $subpath = trim($m[1], '/');
}
if ($subpath === 'gateway.php' || $subpath === 'index.php') $subpath = '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$scope = 'status.read';

if ($subpath === '' || $subpath === 'status') {
    $scope = 'status.read';
} elseif ($subpath === 'comando') {
    $scope = 'jarvis.command';
} elseif ($subpath === 'dispositivos/acionar') {
    $scope = 'devices.write';
} elseif ($subpath === 'dispositivos') {
    $scope = 'devices.read';
} elseif ($subpath === 'sensores') {
    $scope = 'sensors.read';
} elseif ($subpath === 'clima') {
    $scope = 'climate.read';
} elseif ($subpath === 'camera/snapshot') {
    $scope = 'camera.read';
}

$client = api_v1_auth_client($pdo, [$scope]);

// O roteador antigo ainda possui uma segunda autenticação. Depois que o novo
// middleware aprova o cliente, usamos apenas internamente a chave mestre para
// atravessar essa camada sem expor a chave ao aplicativo ou à Internet.
$stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave='external_api_key' LIMIT 1");
$stmt->execute();
$master = $stmt->fetchColumn();
if (!$master) {
    $master = 'jarvis_sec_v1_' . bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO configuracoes_sistema(chave,valor) VALUES('external_api_key',:v) ON CONFLICT(chave) DO NOTHING")
        ->execute([':v'=>$master]);
}

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $master;
unset($_SERVER['HTTP_X_API_KEY']);
unset($_GET['api_key']);

api_v1_log($pdo, 'REQUEST_ALLOWED', 'INFO', $client['nome'] ?? null, [
    'scope' => $scope,
    'subpath' => $subpath,
    'method' => $method
]);

require(__DIR__ . '/index.php');
