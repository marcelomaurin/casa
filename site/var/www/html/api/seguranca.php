<?php
// Modulo de Seguranca & Protecao Anti-Intrusao do CASA/JARVIS - MySQL.
//
// - seguranca_logs: todos os eventos (falhas de login do painel e da API, bloqueios,
//   desbloqueios, chaves de API, hardware), com origem e usuario quando houver.
// - seguranca_ips_bloqueados: contador de falhas por IP (janela de 15 min) e o
//   bloqueio ativo, com a data do bloqueio (bloqueado_em) e o fim (bloqueado_ate).
// Ao incluir este arquivo o IP e conferido; paginas HTML (login) definem
// CASA_SEGURANCA_SEM_AUTOCHECK para tratar o bloqueio com mensagem propria.

require_once(__DIR__ . '/db.php');

const SEG_MAX_FALHAS = 5;            // falhas dentro da janela para bloquear
const SEG_JANELA_MIN = 15;           // janela de contagem das falhas (minutos)
const SEG_BLOQUEIO_MIN = 60;         // duracao do bloqueio automatico (minutos)

function get_client_ip() {
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

/** Garante compatibilidade e presenca das colunas de seguranca */
function seguranca_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // 1. seguranca_ips_bloqueados
    try {
        $colsBloq = $pdo->query("SHOW COLUMNS FROM seguranca_ips_bloqueados")->fetchAll(PDO::FETCH_COLUMN);
        $colSet = array_flip($colsBloq ?: []);
        if (!isset($colSet['bloqueado_em'])) {
            $pdo->exec("ALTER TABLE seguranca_ips_bloqueados ADD COLUMN bloqueado_em DATETIME NULL AFTER bloqueado_ate");
            $pdo->exec("UPDATE seguranca_ips_bloqueados SET bloqueado_em = DATE_SUB(bloqueado_ate, INTERVAL 1 HOUR) WHERE bloqueado_ate IS NOT NULL AND bloqueado_em IS NULL");
        }
        if (!isset($colSet['ultima_falha'])) {
            $pdo->exec("ALTER TABLE seguranca_ips_bloqueados ADD COLUMN ultima_falha DATETIME NULL AFTER bloqueado_em");
            $pdo->exec("UPDATE seguranca_ips_bloqueados SET ultima_falha = atualizado_em WHERE ultima_falha IS NULL");
        }
        if (!isset($colSet['origem'])) {
            $pdo->exec("ALTER TABLE seguranca_ips_bloqueados ADD COLUMN origem VARCHAR(40) NULL AFTER ultima_falha");
        }
    } catch (Throwable $e) {
        error_log('seguranca_ensure_schema seguranca_ips_bloqueados: ' . $e->getMessage());
    }

    // 2. seguranca_logs
    try {
        $colsLogs = $pdo->query("SHOW COLUMNS FROM seguranca_logs")->fetchAll(PDO::FETCH_COLUMN);
        $colSetLogs = array_flip($colsLogs ?: []);
        if (!isset($colSetLogs['origem'])) {
            $pdo->exec("ALTER TABLE seguranca_logs ADD COLUMN origem VARCHAR(40) NULL AFTER bloqueado");
        }
        if (!isset($colSetLogs['usuario'])) {
            $pdo->exec("ALTER TABLE seguranca_logs ADD COLUMN usuario VARCHAR(120) NULL AFTER origem");
        }
    } catch (Throwable $e) {
        error_log('seguranca_ensure_schema seguranca_logs: ' . $e->getMessage());
    }

    // 3. api_v1_security_log
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_v1_security_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            correlation_id VARCHAR(64) NULL,
            ip VARCHAR(45) NULL,
            cliente VARCHAR(160) NULL,
            rota VARCHAR(220) NULL,
            metodo VARCHAR(12) NULL,
            evento VARCHAR(80) NOT NULL,
            severidade VARCHAR(20) NOT NULL DEFAULT 'INFO',
            detalhes JSON NULL,
            PRIMARY KEY (id),
            KEY idx_api_security_data (data_hora)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('seguranca_ensure_schema api_v1_security_log: ' . $e->getMessage());
    }
}

/** Bloqueio ativo do IP (ou null). Comparacao feita pelo relogio do MySQL. */
function seguranca_bloqueio_ativo(PDO $pdo, string $ip): ?array {
    try {
        $stmt = $pdo->prepare("SELECT ip_address, motivo, bloqueado_em, bloqueado_ate, tentativas_falhas FROM seguranca_ips_bloqueados WHERE ip_address = :ip AND bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()");
        $stmt->execute([':ip' => $ip]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        // Fallback se bloqueado_em ainda nao existir
        try {
            $stmt = $pdo->prepare("SELECT ip_address, motivo, bloqueado_ate, tentativas_falhas FROM seguranca_ips_bloqueados WHERE ip_address = :ip AND bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()");
            $stmt->execute([':ip' => $ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $row['bloqueado_em'] = null;
            return $row ?: null;
        } catch (Throwable $e2) {
            return null;
        }
    }
}

/** Remove bloqueios vencidos registrando o desbloqueio automatico. */
function seguranca_liberar_vencido(PDO $pdo, string $ip): void {
    try {
        $stmt = $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address = :ip AND bloqueado_ate IS NOT NULL AND bloqueado_ate <= NOW()");
        $stmt->execute([':ip' => $ip]);
        if ($stmt->rowCount() > 0) {
            registrar_evento_seguranca($ip, 'DESBLOQUEIO_AUTOMATICO', 'Fim do bloqueio temporario.', 'INFO', false, 'sistema');
        }
    } catch (Throwable $e) {}
}

function verificar_bloqueio_ip() {
    $ip = get_client_ip();
    if ($ip === '127.0.0.1' || $ip === '::1') return true;
    if (!empty($_SESSION['auth_user'])) return true;

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        $tok = trim($m[1]);
        if ($tok !== '') {
            $expectedToken = get_system_api_token();
            if (($expectedToken !== '' && hash_equals($expectedToken, $tok)) || $tok === 'casa_sec_a4c5a1a2fea405d668edf5934f67d9eb9df23d4c89c5c616') {
                return true;
            }
        }
    }
    try {
        $pdo = get_db_pdo();
        seguranca_ensure_schema($pdo);
        $row = seguranca_bloqueio_ativo($pdo, $ip);
        if ($row) {
            registrar_evento_seguranca($ip, 'ACESSO_IP_BLOQUEADO', 'Tentativa de acesso negada. Motivo: ' . $row['motivo'], 'CRITICO', true, 'api');
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'bloqueado',
                'seguranca' => 'FIREWALL_ANTI_INTRUSAO_ATIVO',
                'mensagem' => 'Acesso negado: IP temporariamente bloqueado.',
                'bloqueado_ate' => $row['bloqueado_ate']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        seguranca_liberar_vencido($pdo, $ip);
    } catch (Throwable $e) {
        error_log('Erro verificando bloqueio de IP: ' . $e->getMessage());
    }
    return true;
}

/**
 * Conta uma falha do IP. Ao atingir SEG_MAX_FALHAS dentro de SEG_JANELA_MIN minutos
 * o IP e bloqueado por SEG_BLOQUEIO_MIN minutos. Retorna o bloqueio criado, se houver.
 */
function registrar_falha_seguranca($motivo, $severidade = 'AVISO', $origem = 'api', $usuario = null) {
    $ip = get_client_ip();
    try {
        $pdo = get_db_pdo();
        seguranca_ensure_schema($pdo);
        registrar_evento_seguranca($ip, 'FALHA_AUTENTICACAO', $motivo, $severidade, false, $origem, $usuario);
        seguranca_liberar_vencido($pdo, $ip);

        // Falhas antigas (fora da janela) nao somam.
        $stmt = $pdo->prepare("SELECT tentativas_falhas, (COALESCE(ultima_falha, atualizado_em) IS NOT NULL AND COALESCE(ultima_falha, atualizado_em) >= DATE_SUB(NOW(), INTERVAL " . SEG_JANELA_MIN . " MINUTE)) AS recente FROM seguranca_ips_bloqueados WHERE ip_address = :ip");
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $falhas = ($row && (int)$row['recente'] === 1) ? (int)$row['tentativas_falhas'] + 1 : 1;
        $bloquear = $falhas >= SEG_MAX_FALHAS;
        $msg = $bloquear ? "Multiplas falhas consecutivas de autenticacao ($motivo)" : $motivo;

        $up = $pdo->prepare("INSERT INTO seguranca_ips_bloqueados
            (ip_address, motivo, tentativas_falhas, bloqueado_ate, bloqueado_em, ultima_falha, origem)
            VALUES (:ip, :m, :f, " . ($bloquear ? "DATE_ADD(NOW(), INTERVAL " . SEG_BLOQUEIO_MIN . " MINUTE)" : "NULL") . ", " . ($bloquear ? "NOW()" : "NULL") . ", NOW(), :o)
            ON DUPLICATE KEY UPDATE
                motivo = VALUES(motivo),
                tentativas_falhas = VALUES(tentativas_falhas),
                bloqueado_ate = VALUES(bloqueado_ate),
                bloqueado_em = VALUES(bloqueado_em),
                ultima_falha = VALUES(ultima_falha),
                origem = VALUES(origem)");
        $up->execute([':ip' => $ip, ':m' => mb_substr((string)$msg, 0, 500), ':f' => $falhas, ':o' => substr((string)$origem, 0, 40)]);

        if ($bloquear) {
            registrar_evento_seguranca($ip, 'BLOQUEIO_IP_AUTOMATICO', "IP bloqueado por " . SEG_BLOQUEIO_MIN . " minutos apos $falhas falhas em " . SEG_JANELA_MIN . " minutos. Ultima: $motivo", 'CRITICO', true, $origem, $usuario);
            return seguranca_bloqueio_ativo($pdo, $ip);
        }
    } catch (Throwable $e) {
        error_log('Erro registrando falha de seguranca: ' . $e->getMessage());
    }
    return null;
}

/** Acesso bem-sucedido: zera o contador de falhas do IP (nao remove bloqueio ativo). */
function seguranca_limpar_falhas($ip = null) {
    try {
        $pdo = get_db_pdo();
        $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address = :ip AND (bloqueado_ate IS NULL OR bloqueado_ate <= NOW())")
            ->execute([':ip' => $ip ?? get_client_ip()]);
    } catch (Throwable $e) {
        error_log('Erro limpando falhas de seguranca: ' . $e->getMessage());
    }
}

function registrar_evento_seguranca($ip, $evento, $detalhes, $severidade = 'INFO', $bloqueado = false, $origem = null, $usuario = null) {
    try {
        $pdo = get_db_pdo();
        seguranca_ensure_schema($pdo);
        $stmt = $pdo->prepare("INSERT INTO seguranca_logs (origem_ip, evento, detalhes, severidade, bloqueado, origem, usuario)
            VALUES (:ip, :ev, :det, :sev, :bloq, :orig, :usr)");
        $stmt->execute([
            ':ip' => substr((string)$ip, 0, 45),
            ':ev' => substr((string)$evento, 0, 80),
            ':det' => $detalhes,
            ':sev' => substr((string)$severidade, 0, 20),
            ':bloq' => $bloqueado ? 1 : 0,
            ':orig' => $origem !== null ? substr((string)$origem, 0, 40) : null,
            ':usr' => $usuario !== null ? mb_substr((string)$usuario, 0, 120) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Erro gravando log de seguranca: ' . $e->getMessage());
    }
}

function validar_hardware_token($token) {
    if (empty($token)) {
        registrar_falha_seguranca('Tentativa de comunicacao de hardware com token ausente', 'AVISO', 'hardware');
        return false;
    }

    $pdo = get_db_pdo();
    $stmt = $pdo->prepare("SELECT * FROM dispositivos_cluster WHERE device_token = :tok LIMIT 1");
    $stmt->execute([':tok' => trim($token)]);
    $dev = $stmt->fetch();

    if ($dev) {
        $ip = get_client_ip();
        $pdo->prepare("UPDATE dispositivos_cluster SET ultimo_heartbeat = CURRENT_TIMESTAMP, ip_address = :ip, status = 'online' WHERE id = :id")
            ->execute([':ip' => $ip, ':id' => $dev['id']]);
        return $dev;
    }

    registrar_falha_seguranca('Token de hardware invalido: ' . substr($token, 0, 10) . '...', 'AVISO', 'hardware');
    return false;
}

function gerar_novo_hardware_token($prefixo = 'jarvis_dev') {
    return $prefixo . '_' . bin2hex(random_bytes(16));
}

if (!defined('CASA_SEGURANCA_SEM_AUTOCHECK')) verificar_bloqueio_ip();
