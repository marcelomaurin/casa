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

// Cada operador do painel vê e limpa apenas a própria conversa (coluna usuario, migração 1.33).
$usuario=substr((string)$_SESSION['auth_user'],0,120);
function hist_tem_coluna_usuario(PDO $pdo):bool{
    try{
        $st=$pdo->query("SHOW COLUMNS FROM llm_conversas LIKE 'usuario'");
        return (bool)$st->fetch();
    }catch(Throwable $e){return false;}
}

try{
    $pdo=get_db_pdo();
    if(!hist_tem_coluna_usuario($pdo)){
        http_response_code(503);
        echo json_encode(['status'=>'erro','mensagem'=>'Banco sem a migração 1.33 (histórico por usuário). Recarregue o painel para aplicar.'],JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $in=json_decode(file_get_contents('php://input'),true);
        if(!is_array($in)) $in=[];
        $acao=strtolower(trim((string)($in['acao']??'')));

        if($acao!=='limpar'){
            http_response_code(400);
            echo json_encode(['status'=>'erro','mensagem'=>'Ação inválida.'],JSON_UNESCAPED_UNICODE);
            exit;
        }

        $cnt=$pdo->prepare("SELECT COUNT(*) FROM llm_conversas WHERE usuario=:u");
        $cnt->execute([':u'=>$usuario]);
        $qtd=(int)$cnt->fetchColumn();
        $pdo->beginTransaction();
        $del=$pdo->prepare("DELETE FROM llm_conversas WHERE usuario=:u");
        $del->execute([':u'=>$usuario]);

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
          WHERE usuario=:u
          ORDER BY id DESC
          LIMIT ".$limite;
    $st=$pdo->prepare($sql);
    $st->execute([':u'=>$usuario]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
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
