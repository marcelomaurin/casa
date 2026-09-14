<?php
// Banco central do JARVIS/CASA.
// Produção Hostinger: MySQL/MariaDB via config.local.php privado ou variáveis de ambiente.
// Banco atual: u820932905_casa | usuário: u820932905_mmaurin
// A senha nunca deve ser versionada no Git.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function local_config(): array {
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }

    $file = __DIR__ . '/config.local.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $cfg = $loaded;
            return $cfg;
        }
    }

    $cfg = [];
    return $cfg;
}

function cfg_value(string $configKey, string $envKey, ?string $default = null): ?string {
    $cfg = local_config();
    if (array_key_exists($configKey, $cfg) && $cfg[$configKey] !== '') {
        return (string)$cfg[$configKey];
    }

    $value = getenv($envKey);
    return ($value === false || $value === '') ? $default : $value;
}

function get_db_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Em hospedagem Hostinger o PHP normalmente acessa o MySQL localmente.
    // Se o hPanel informar outro host, configure JARVIS_DB_HOST/config.local.php.
    $host = cfg_value('db_host', 'JARVIS_DB_HOST', 'localhost');
    $port = cfg_value('db_port', 'JARVIS_DB_PORT', '3306');
    $name = cfg_value('db_name', 'JARVIS_DB_NAME', 'u820932905_casa');
    $user = cfg_value('db_user', 'JARVIS_DB_USER', 'u820932905_mmaurin');
    $pass = cfg_value('db_pass', 'JARVIS_DB_PASS', '');
    $charset = cfg_value('db_charset', 'JARVIS_DB_CHARSET', 'utf8mb4');

    if ($pass === '') {
        throw new RuntimeException('Senha do banco MySQL não configurada no ambiente.');
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
    // Serviços distribuídos acessam o domínio CASA pela API, não diretamente o MySQL.
    return false;
}

function qryExec($sql) {
    return get_db_pdo()->exec($sql);
}

function get_system_api_token(): string {
    $token = cfg_value('system_api_token', 'JARVIS_SYSTEM_API_TOKEN', '');
    if ($token !== '') {
        return $token;
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
