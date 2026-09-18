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

try{
    $pdo=get_db_pdo();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $in=json_decode(file_get_contents('php://input'),true);
        if(!is_array($in)) $in=[];
        $acao=strtolower(trim((string)($in['acao']??'')));

        if($acao!=='limpar'){
            http_response_code(400);
            echo json_encode(['status'=>'erro','mensagem'=>'Ação inválida.'],JSON_UNESCAPED_UNICODE);
            exit;
        }

        $qtd=(int)$pdo->query("SELECT COUNT(*) FROM llm_conversas")->fetchColumn();
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM llm_conversas");

        try{
            $log=$pdo->prepare("INSERT INTO comandos_log (comando,origem,resultado) VALUES (:cmd,'COMPUTER',:res)");
            $log->execute([
                ':cmd'=>'Limpar histórico de conversas',
                ':res'=>'Sucesso - '.$qtd.' conversa(s) removida(s) por '.($_SESSION['auth_user']??'usuario')
            ]);
        }catch(Throwable $ignored){}

        $pdo->commit();
        echo json_encode([
            'status'=>'sucesso',
            'mensagem'=>'Histórico limpo.',
            'removidos'=>$qtd
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }

    $limite=(int)($_GET['limite']??25);
    $limite=max(1,min(100,$limite));
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
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Falha no histórico COMPUTER: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'erro','mensagem'=>'Falha ao processar o histórico.'],JSON_UNESCAPED_UNICODE);
}
