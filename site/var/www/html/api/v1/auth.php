<?php
// CASA/JARVIS - autenticação de operador para aplicativos móveis.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'erro','mensagem'=>'Metodo nao permitido']);
    exit;
}

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_user_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_usuario BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expira_em DATETIME NOT NULL,
        ultimo_uso DATETIME NULL,
        ultimo_ip VARCHAR(45) NULL,
        revogado_em DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_mobile_session_token (token_hash),
        KEY idx_mobile_session_user (id_usuario, expira_em),
        CONSTRAINT fk_mobile_session_user FOREIGN KEY (id_usuario)
            REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    api_v1_json_response(503, ['status'=>'erro','mensagem'=>'Falha ao preparar sessao mobile']);
}

function mobile_auth_input(): array {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}

function mobile_auth_bearer(): string {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/Bearer\s+(\S+)/i', $auth, $m) ? trim($m[1]) : '';
}

function mobile_auth_session(PDO $pdo): array {
    $token = mobile_auth_bearer();
    if ($token === '') api_v1_json_response(401, ['status'=>'erro','mensagem'=>'Sessao ausente']);

    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        "SELECT s.id AS session_id,u.id,u.nome,u.login,u.email,u.perfil
         FROM mobile_user_sessions s
         JOIN usuarios u ON u.id=s.id_usuario
         WHERE s.token_hash=:h AND s.revogado_em IS NULL
           AND s.expira_em>NOW() AND u.ativo=1
         LIMIT 1"
    );
    $stmt->execute([':h'=>$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) api_v1_json_response(401, ['status'=>'erro','mensagem'=>'Sessao invalida ou expirada']);

    $pdo->prepare("UPDATE mobile_user_sessions SET ultimo_uso=NOW(),ultimo_ip=:ip WHERE id=:id")
        ->execute([':ip'=>api_v1_client_ip(), ':id'=>$row['session_id']]);
    return $row;
}

$in = mobile_auth_input();
$acao = trim((string)($in['acao'] ?? 'login'));

if ($acao === 'login') {
    $usuario = trim((string)($in['usuario'] ?? ''));
    $senha = (string)($in['senha'] ?? '');

    if ($usuario === '' || $senha === '') {
        api_v1_json_response(400, ['status'=>'erro','mensagem'=>'Informe usuario e senha']);
    }

    // Rate limit sem armazenar a senha.
    api_v1_rate_limit($pdo, 'mobile-login:' . strtolower($usuario), 12, 300);

    $stmt = $pdo->prepare(
        "SELECT id,nome,login,email,senha,perfil,ativo
         FROM usuarios
         WHERE (LOWER(login)=LOWER(:login) OR LOWER(email)=LOWER(:email))
           AND ativo=1 LIMIT 1"
    );
    $stmt->execute([':login'=>$usuario, ':email'=>$usuario]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $stored = $user ? (string)$user['senha'] : '';
    $ok = false;
    if ($user) {
        // Banco atual usa password_hash. Mantem compatibilidade temporaria
        // com instalações antigas que ainda tenham senha em texto.
        $info = password_get_info($stored);
        $ok = ($info['algo'] ?? 0) !== 0
            ? password_verify($senha, $stored)
            : hash_equals($stored, $senha);
    }

    if (!$ok) {
        api_v1_log($pdo, 'MOBILE_LOGIN_DENIED', 'ALTO', strtolower($usuario));
        api_v1_json_response(401, ['status'=>'erro','mensagem'=>'Usuario ou senha invalidos']);
    }

    $plain = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $hours = 12;
    $expires = date('Y-m-d H:i:s', time() + ($hours * 3600));

    // Limpa sessões expiradas e limita sessões antigas do mesmo usuário.
    $pdo->prepare("DELETE FROM mobile_user_sessions WHERE expira_em<=NOW() OR revogado_em IS NOT NULL")->execute();
    $pdo->prepare(
        "INSERT INTO mobile_user_sessions(id_usuario,token_hash,expira_em,ultimo_ip)
         VALUES(:u,:h,:e,:ip)"
    )->execute([
        ':u'=>$user['id'],
        ':h'=>$hash,
        ':e'=>$expires,
        ':ip'=>api_v1_client_ip(),
    ]);

    api_v1_log($pdo, 'MOBILE_LOGIN_OK', 'INFO', $user['login'], ['perfil'=>$user['perfil']]);

    echo json_encode([
        'status'=>'ok',
        'session_token'=>$plain,
        'expires_at'=>date('c', strtotime($expires)),
        'user'=>[
            'id'=>(int)$user['id'],
            'nome'=>$user['nome'],
            'login'=>$user['login'],
            'email'=>$user['email'],
            'perfil'=>$user['perfil'],
        ],
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($acao === 'validate') {
    $user = mobile_auth_session($pdo);
    echo json_encode([
        'status'=>'ok',
        'user'=>[
            'id'=>(int)$user['id'],
            'nome'=>$user['nome'],
            'login'=>$user['login'],
            'email'=>$user['email'],
            'perfil'=>$user['perfil'],
        ],
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($acao === 'logout') {
    $token = mobile_auth_bearer();
    if ($token !== '') {
        $hash = hash('sha256', $token);
        $pdo->prepare("UPDATE mobile_user_sessions SET revogado_em=NOW() WHERE token_hash=:h")
            ->execute([':h'=>$hash]);
    }
    echo json_encode(['status'=>'ok']);
    exit;
}

api_v1_json_response(400, ['status'=>'erro','mensagem'=>'Acao desconhecida']);
