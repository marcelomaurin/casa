<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (empty($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode(['status'=>'erro', 'mensagem'=>'Sessão expirada.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status'=>'erro', 'mensagem'=>'Método não permitido.']);
    exit;
}
require_once __DIR__.'/db.php';
require_once __DIR__.'/arm_nodes_registry.php';
try {
    $nodes = arm_nodes_list(get_db_pdo());
    echo json_encode(['status'=>'ok', 'nodes'=>$nodes, 'total'=>count($nodes), 'online'=>count(array_filter($nodes, fn($n)=>$n['online']))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('ARM nodes list failed: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'erro', 'mensagem'=>'Não foi possível carregar os agentes ARM. Tente atualizar a lista.']);
}
