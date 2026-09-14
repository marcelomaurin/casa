<?php
// Banco central do JARVIS/CASA.
// Produção Hostinger: MySQL/MariaDB configurado por variáveis de ambiente.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function env_value(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function get_db_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('JARVIS_DB_HOST', '127.0.0.1');
    $port = env_value('JARVIS_DB_PORT', '3306');
    $name = env_value('JARVIS_DB_NAME', 'casadb');
    $user = env_value('JARVIS_DB_USER', '');
    $pass = env_value('JARVIS_DB_PASS', '');
    $charset = env_value('JARVIS_DB_CHARSET', 'utf8mb4');

    if ($user === '' || $pass === '') {
        throw new RuntimeException('Banco MySQL não configurado no ambiente.');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function get_secondary_db_pdo() {
    // Serviços locais devem acessar a Hostinger pela API, não pelo banco.
    return false;
}

function qryExec($sql) {
    return get_db_pdo()->exec($sql);
}

function get_system_api_token(): string {
    $envToken = env_value('JARVIS_SYSTEM_API_TOKEN', '');
    if ($envToken !== '') {
        return $envToken;
    }

    try {
        $pdo = get_db_pdo();
        $stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave = 'system_api_token' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['valor'])) {
            return $row['valor'];
        }
    } catch (Throwable $e) {
        // Não revelar detalhes de banco na resposta HTTP.
    }

    return '';
}

function verify_api_auth() {
    if (!empty($_SESSION['auth_user'])) {
        return true;
    }

    $expectedToken = get_system_api_token();
    if ($expectedToken === '') {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'erro',
            'mensagem' => 'API ainda não configurada no ambiente de produção.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m) && hash_equals($expectedToken, $m[1])) {
        return true;
    }

    if (isset($_SERVER['HTTP_X_API_KEY']) && hash_equals($expectedToken, $_SERVER['HTTP_X_API_KEY'])) {
        return true;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'Acesso negado: autenticação requerida.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
