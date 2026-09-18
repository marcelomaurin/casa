<?php
// Banco central do JARVIS/CASA.
// Produção Hostinger: MySQL/MariaDB via config.local.php privado ou variáveis de ambiente.
// A senha do MySQL nunca deve ser versionada no Git.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const CASA_SCHEMA_VERSION = '1.24';

function local_config(): array {
    static $cfg = null;
    if (is_array($cfg)) return $cfg;
    $file = __DIR__ . '/config.local.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) { $cfg = $loaded; return $cfg; }
    }
    $cfg = [];
    return $cfg;
}

function cfg_value(string $configKey, string $envKey, ?string $default = null): ?string {
    $cfg = local_config();
    if (array_key_exists($configKey, $cfg) && $cfg[$configKey] !== '') return (string)$cfg[$configKey];
    $value = getenv($envKey);
    return ($value === false || $value === '') ? $default : $value;
}

function cfg_bool(string $configKey, string $envKey, bool $default = true): bool {
    $value = strtolower((string)cfg_value($configKey, $envKey, $default ? '1' : '0'));
    return !in_array($value, ['0', 'false', 'off', 'no'], true);
}

function casa_required_tables(): array {
    return [
        'param',
        'configuracoes_sistema', 'usuarios', 'devices', 'devpar',
        'sensores_telemetria', 'falas', 'frases', 'llm_conversas',
        'comandos_log', 'arm_nodes', 'dispositivos_cluster', 'iot_leituras',
        'camera_eventos', 'seguranca_logs', 'seguranca_ips_bloqueados',
        'agentes_externos', 'api_client_tokens', 'api_v1_rate_limit',
        'api_v1_security_log', 'mobile_eventos', 'mobile_notificacoes',
        'watch_notificacoes', 'internet_pesquisas', 'jarvis_planos',
        'jarvis_tarefas', 'tarefas_agendadas', 'device_pairing_requests',
        'device_capabilities', 'device_commands', 'device_events', 'device_heartbeats',
        'device_command_audit',
        'scenes', 'scene_actions', 'scene_runs', 'scene_run_actions',
        'automation_rules', 'automation_rule_actions', 'automation_rule_runs',
        'automation_rule_run_actions', 'ia_modelos'
    ];
}

function casa_missing_tables(PDO $pdo): array {
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($dbName === '') throw new RuntimeException('Nenhum banco MySQL selecionado.');
    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db');
    $stmt->execute([':db' => $dbName]);
    $existing = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    $missing = [];
    foreach (casa_required_tables() as $table) if (!isset($existing[$table])) $missing[] = $table;
    return $missing;
}

function casa_execute_schema_file(PDO $pdo, string $file): void {
    if (!is_file($file)) throw new RuntimeException('Arquivo de schema MySQL não encontrado: ' . basename($file));
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) throw new RuntimeException('Não foi possível ler o schema MySQL.');

    $statement = '';
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || strpos($trim, '--') === 0) continue;
        $statement .= $line . "\n";
        if (substr(rtrim($trim), -1) === ';') {
            $sql = trim($statement);
            $statement = '';
            if ($sql !== '') $pdo->exec($sql);
        }
    }
    if (trim($statement) !== '') $pdo->exec($statement);
}

function casa_ensure_param_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS param (
        chave VARCHAR(120) NOT NULL,
        valor TEXT NULL,
        atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (chave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function casa_schema_version(PDO $pdo): ?string {
    casa_ensure_param_table($pdo);
    $stmt = $pdo->prepare("SELECT valor FROM param WHERE chave='VERSAO' LIMIT 1");
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return $value === false ? null : trim((string)$value);
}

function casa_set_schema_version(PDO $pdo, string $version): void {
    $stmt = $pdo->prepare("INSERT INTO param(chave,valor) VALUES('VERSAO',:v)
        ON DUPLICATE KEY UPDATE valor=VALUES(valor), atualizado_em=CURRENT_TIMESTAMP");
    $stmt->execute([':v' => $version]);
}

function casa_run_version_migrations(PDO $pdo, ?string $currentVersion): void {
    if ($currentVersion === CASA_SCHEMA_VERSION) return;
    $migrations = [
        '1.20' => [
            __DIR__ . '/migrations/1.20.sql',
            __DIR__ . '/migrations/1.20_audit.sql',
        ],
        '1.21' => [
            __DIR__ . '/migrations/1.21.sql',
        ],
        '1.22' => [
            __DIR__ . '/migrations/1.22.sql',
        ],
        '1.23' => [
            __DIR__ . '/migrations/1.23.sql',
        ],
        '1.24' => [
            __DIR__ . '/migrations/1.24.sql',
        ],
    ];
    foreach ($migrations as $version => $files) {
        if ($currentVersion !== null && version_compare($currentVersion, $version, '>=')) continue;
        foreach ($files as $file) casa_execute_schema_file($pdo, $file);
        casa_set_schema_version($pdo, $version);
        $currentVersion = $version;
    }
}

function ensure_database_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked || !cfg_bool('auto_init_db', 'JARVIS_AUTO_INIT_DB', true)) return;
    $checked = true;

    $lockName = 'casa_jarvis_schema_install';
    try {
        $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 20)');
        $lock->execute([':lock_name' => $lockName]);
        $acquired = (int)$lock->fetchColumn() === 1;
    } catch (Throwable $e) { $acquired = true; }
    if (!$acquired) throw new RuntimeException('Timeout aguardando instalação automática do banco.');

    try {
        casa_ensure_param_table($pdo);
        $version = casa_schema_version($pdo);
        if ($version === CASA_SCHEMA_VERSION) return;

        $baseRequired = ['configuracoes_sistema','usuarios','devices','devpar','dispositivos_cluster','api_client_tokens'];
        $missingLookup = array_fill_keys(casa_missing_tables($pdo), true);
        $needsBase = false;
        foreach ($baseRequired as $table) if (isset($missingLookup[$table])) { $needsBase = true; break; }
        if ($needsBase) casa_execute_schema_file($pdo, __DIR__ . '/schema_mysql.sql');

        casa_run_version_migrations($pdo, $version);
        $remaining = casa_missing_tables($pdo);
        if (!empty($remaining)) throw new RuntimeException('Instalação automática incompleta. Tabelas ausentes: ' . implode(', ', $remaining));
        casa_set_schema_version($pdo, CASA_SCHEMA_VERSION);
    } finally {
        try {
            $unlock = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $unlock->execute([':lock_name' => $lockName]);
        } catch (Throwable $e) {}
    }
}

function casa_ensure_ai_config_defaults(PDO $pdo): void {
    $defaults = [
        ['ia_provider','local','Provedor principal de IA: local, openai_compatible ou runpod'],
        ['ia_routing_mode','auto','Roteamento de IA'],
        ['local_ollama_url','http://127.0.0.1:11434','URL base do Ollama local'],
        ['local_model','jarvis-local:latest','Modelo do Ollama local'],
        ['openai_base_url','','URL base de API OpenAI-compatible, normalmente terminando em /v1'],
        ['openai_api_key','','Chave Bearer do provedor OpenAI-compatible'],
        ['openai_model','','Modelo exposto pelo endpoint OpenAI-compatible'],
        ['stt_base_url','https://api.openai.com/v1','URL base do serviço de transcrição compatível OpenAI'],
        ['stt_api_key','','Chave do serviço STT'],
        ['stt_model','whisper-1','Modelo de transcrição de áudio']
    ];
    $stmt = $pdo->prepare("INSERT INTO configuracoes_sistema(chave,valor,descricao)
        VALUES(:chave,:valor,:descricao)
        ON DUPLICATE KEY UPDATE chave=VALUES(chave)");
    foreach ($defaults as $d) {
        $stmt->execute([':chave'=>$d[0],':valor'=>$d[1],':descricao'=>$d[2]]);
    }
}

function get_db_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = cfg_value('db_host', 'JARVIS_DB_HOST', 'localhost');
    $port = cfg_value('db_port', 'JARVIS_DB_PORT', '3306');
    $name = cfg_value('db_name', 'JARVIS_DB_NAME', 'u820932905_casadb');
    $user = cfg_value('db_user', 'JARVIS_DB_USER', 'u820932905_root');
    $pass = cfg_value('db_pass', 'JARVIS_DB_PASS', '');
    $charset = cfg_value('db_charset', 'JARVIS_DB_CHARSET', 'utf8mb4');
    if ($pass === '') throw new RuntimeException('Senha do banco MySQL não configurada no ambiente.');
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    ensure_database_schema($pdo);
    casa_ensure_ai_config_defaults($pdo);
    return $pdo;
}

function get_secondary_db_pdo() { return false; }
function qryExec($sql) { return get_db_pdo()->exec($sql); }

function get_system_api_token(): string {
    $token = cfg_value('system_api_token', 'JARVIS_SYSTEM_API_TOKEN', '');
    if ($token !== '') return $token;
    try {
        $pdo = get_db_pdo();
        $stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave = 'system_api_token' LIMIT 1");
        $stmt->execute(); $row = $stmt->fetch();
        if ($row && !empty($row['valor'])) return $row['valor'];
    } catch (Throwable $e) {}
    return '';
}

function verify_api_auth() {
    if (!empty($_SESSION['auth_user'])) return true;
    $expectedToken = get_system_api_token();
    if ($expectedToken === '') {
        http_response_code(503); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'erro','mensagem'=>'API ainda não configurada no ambiente de produção.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m) && hash_equals($expectedToken, $m[1])) return true;
    if (isset($_SERVER['HTTP_X_API_KEY']) && hash_equals($expectedToken, $_SERVER['HTTP_X_API_KEY'])) return true;
    http_response_code(401); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'erro','mensagem'=>'Acesso negado: autenticação requerida.'], JSON_UNESCAPED_UNICODE); exit;
}
?>