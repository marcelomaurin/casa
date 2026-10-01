<?php
// CASA/JARVIS - Painel Segurança › Atualizações (sessão do painel).
// GET  ?acao=estado[&refresh=1]          -> commit mais recente da branch e situação de cada nó
// POST {acao:'forcar', device_id}        -> pede atualização forçada a um nó
// POST {acao:'forcar_todos'}             -> pede atualização forçada a todos os nós listados
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/v1/updates_common.php';
verify_api_auth();
$pdo = get_db_pdo();

function cu_out(int $code, array $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$acao = (string)($in['acao'] ?? ($_GET['acao'] ?? 'estado'));

if ($method === 'POST' && empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['HTTP_X_API_KEY']) && !empty($_SESSION['auth_user'])) {
    $sent = (string)($_SERVER['HTTP_X_CASA_SESSION_TOKEN'] ?? '');
    $tok = (string)($_SESSION['jarvis_session_token'] ?? '');
    if ($sent === '' || $tok === '' || !hash_equals($tok, $sent)) cu_out(403, ['status' => 'erro', 'mensagem' => 'Sessão inválida. Recarregue a página.']);
}
$actor = 'WEB:' . substr(is_string($_SESSION['auth_user'] ?? null) ? $_SESSION['auth_user'] : 'api', 0, 60);

try {
    if ($method === 'GET' && $acao === 'estado') {
        cu_out(200, ['status' => 'ok'] + updates_overview($pdo, !empty($_GET['refresh'])));
    }
    if ($method !== 'POST') cu_out(405, ['status' => 'erro', 'mensagem' => 'Método não permitido.']);
    if ($acao === 'forcar') {
        $id = trim((string)($in['device_id'] ?? ''));
        if ($id === '') cu_out(400, ['status' => 'erro', 'mensagem' => 'Informe o nó.']);
        $n = updates_request_force($pdo, [$id], $actor);
        cu_out($n ? 200 : 400, $n ? ['status' => 'ok', 'nodes' => $n] : ['status' => 'erro', 'mensagem' => 'Identificador inválido.']);
    }
    if ($acao === 'forcar_todos') {
        $ids = array_column(updates_overview($pdo)['nodes'], 'device_id');
        cu_out(200, ['status' => 'ok', 'nodes' => updates_request_force($pdo, $ids, $actor)]);
    }
    cu_out(400, ['status' => 'erro', 'mensagem' => 'Ação desconhecida.']);
} catch (Throwable $e) {
    error_log('cluster_updates.php: ' . $e->getMessage());
    cu_out(500, ['status' => 'erro', 'mensagem' => 'Falha ao consultar as atualizações.']);
}
