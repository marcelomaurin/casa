<?php
// CASA/JARVIS - Estado dos servicos em segundo plano (somente leitura).
// GET /casa/api/servicos_status.php  -> {status, servicos:[...], resumo:{...}}
// Exige a sessao do painel. A logica fica em servicos_common.php.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/servicos_common.php');

if (empty($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Sessao do painel obrigatoria.'], JSON_UNESCAPED_UNICODE);
    exit;
}
session_write_close();

$lista = servicos_status(get_db_pdo());
echo json_encode(['status' => 'ok', 'gerado_em' => date('c'), 'servicos' => $lista, 'resumo' => servicos_resumo($lista)],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
