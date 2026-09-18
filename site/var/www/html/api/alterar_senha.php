<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode(['status'=>'erro','mensagem'=>'Sessão expirada. Entre novamente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'erro','mensagem'=>'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once(__DIR__ . '/db.php');

$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in)) $in=[];

$senhaAtual=(string)($in['senha_atual']??'');
$novaSenha=(string)($in['nova_senha']??'');
$confirmar=(string)($in['confirmar_senha']??'');

function senha_out(int $http,array $data): void {
    http_response_code($http);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if($senhaAtual===''||$novaSenha===''||$confirmar===''){
    senha_out(400,['status'=>'erro','mensagem'=>'Preencha a senha atual, a nova senha e a confirmação.']);
}
if(strlen($novaSenha)<8){
    senha_out(400,['status'=>'erro','mensagem'=>'A nova senha deve ter pelo menos 8 caracteres.']);
}
if($novaSenha!==$confirmar){
    senha_out(400,['status'=>'erro','mensagem'=>'A confirmação da nova senha não confere.']);
}
if(hash_equals($senhaAtual,$novaSenha)){
    senha_out(400,['status'=>'erro','mensagem'=>'A nova senha deve ser diferente da senha atual.']);
}

try{
    $pdo=get_db_pdo();
    $stmt=$pdo->prepare("SELECT id,login,senha,ativo FROM usuarios WHERE LOWER(login)=LOWER(:login) LIMIT 1");
    $stmt->execute([':login'=>(string)$_SESSION['auth_user']]);
    $user=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$user || empty($user['ativo'])){
        senha_out(403,['status'=>'erro','mensagem'=>'Usuário autenticado não está disponível.']);
    }

    $stored=(string)$user['senha'];
    $info=password_get_info($stored);
    $senhaValida=(($info['algo']??0)!==0)
        ? password_verify($senhaAtual,$stored)
        : hash_equals($stored,$senhaAtual);

    if(!$senhaValida){
        usleep(250000);
        senha_out(401,['status'=>'erro','mensagem'=>'A senha atual está incorreta.']);
    }

    $novoHash=password_hash($novaSenha,PASSWORD_DEFAULT);
    if($novoHash===false){
        senha_out(500,['status'=>'erro','mensagem'=>'Não foi possível gerar a nova senha.']);
    }

    $pdo->beginTransaction();
    $up=$pdo->prepare("UPDATE usuarios SET senha=:senha WHERE id=:id");
    $up->execute([':senha'=>$novoHash,':id'=>$user['id']]);

    // Revoga sessões mobile existentes deste usuário, quando a tabela estiver disponível.
    try{
        $rev=$pdo->prepare("UPDATE mobile_user_sessions SET revogado_em=NOW() WHERE id_usuario=:id AND revogado_em IS NULL");
        $rev->execute([':id'=>$user['id']]);
    }catch(Throwable $ignored){}

    try{
        $log=$pdo->prepare("INSERT INTO comandos_log (comando,origem,resultado) VALUES (:cmd,'SEGURANCA',:res)");
        $log->execute([
            ':cmd'=>'Alteração de senha do próprio usuário',
            ':res'=>'Sucesso - usuário: '.$user['login']
        ]);
    }catch(Throwable $ignored){}

    $pdo->commit();
    $_SESSION['auth_time']=time();

    senha_out(200,['status'=>'sucesso','mensagem'=>'Senha alterada com sucesso.']);
}catch(Throwable $e){
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Falha ao alterar senha do usuário autenticado: '.$e->getMessage());
    senha_out(500,['status'=>'erro','mensagem'=>'Falha ao alterar a senha.']);
}
