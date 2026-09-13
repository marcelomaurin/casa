<?php
// Módulo Central de Banco de Dados e Segurança do JARVIS
// Totalmente independente de código legado

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define("DB_HOST", "127.0.0.1");
define("DB_PORT", "5432");
define("DB_NAME", "casadb");
define("DB_USER", "casadb_user");
define("DB_PASS", "casadb_password_2026");

define("DB_SEC_HOST", "192.168.2.8");
define("DB_SEC_PORT", "5432");

function get_db_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    return $pdo;
}

function get_secondary_db_pdo() {
    static $sec_pdo = null;
    if ($sec_pdo === null) {
        try {
            $dsn = "pgsql:host=" . DB_SEC_HOST . ";port=" . DB_SEC_PORT . ";dbname=" . DB_NAME;
            $sec_pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 2
            ]);
        } catch (Exception $e) {
            $sec_pdo = false;
        }
    }
    return $sec_pdo;
}

function qryExec($sql) {
    $pdo = get_db_pdo();
    return $pdo->exec($sql);
}

function get_system_api_token() {
    try {
        $pdo = get_db_pdo();
        $stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave = 'system_api_token' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['valor'])) {
            return $row['valor'];
        }
    } catch (Exception $e) {}
    return "jarvis_secret_token_2026";
}

// Guarda de Autenticação para APIs
function verify_api_auth() {
    // 1. Sessão web ativa
    if (!empty($_SESSION['auth_user'])) {
        return true;
    }

    // 2. Requisições internas locais ou da rede de nós ARM confiáveis
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.2.') === 0) {
        return true;
    }

    // 3. Verificação por Token de API nos cabeçalhos
    $expected_token = get_system_api_token();
    
    // Header Authorization: Bearer <token>
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth_header, $m)) {
        if ($m[1] === $expected_token) {
            return true;
        }
    }

    // Header X-API-Key: <token>
    if (isset($_SERVER['HTTP_X_API_KEY']) && $_SERVER['HTTP_X_API_KEY'] === $expected_token) {
        return true;
    }

    // Se nenhuma autenticação for válida, bloquear com 401
    http_response_code(401);
    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'Acesso negado: Autenticação requerida (Sessão inválida ou Token de API ausente)'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
