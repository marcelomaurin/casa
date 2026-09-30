<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__.'/db.php';
require_once __DIR__.'/arm_nodes_registry.php';

// Permite autenticação via sessão web ou Bearer / API token
if (empty($_SESSION['auth_user'])) {
    verify_api_auth();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status'=>'erro', 'mensagem'=>'Método não permitido.']);
    exit;
}

try {
    $nodes = arm_nodes_list(get_db_pdo());
    echo json_encode(['status'=>'ok', 'nodes'=>$nodes, 'total'=>count($nodes), 'online'=>count(array_filter($nodes, fn($n)=>$n['online']))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('ARM nodes list failed: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'erro', 'mensagem'=>'Não foi possível carregar os agentes ARM. Tente atualizar a lista.']);
}
