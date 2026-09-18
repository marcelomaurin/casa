<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode(['status'=>'erro','mensagem'=>'Sessão expirada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once(__DIR__ . '/db.php');

$limite=(int)($_GET['limite']??25);
$limite=max(1,min(100,$limite));

try{
    $pdo=get_db_pdo();
    $sql="SELECT id,user_msg,bot_msg,contexto,data_hora
          FROM llm_conversas
          ORDER BY id DESC
          LIMIT ".$limite;
    $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $rows=array_reverse($rows);
    echo json_encode([
        'status'=>'sucesso',
        'dados'=>$rows,
        'total'=>count($rows)
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    error_log('Falha ao carregar histórico COMPUTER: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'erro','mensagem'=>'Não foi possível carregar o histórico.'],JSON_UNESCAPED_UNICODE);
}
