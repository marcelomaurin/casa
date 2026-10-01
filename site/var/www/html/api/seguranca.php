<?php
// Módulo de Segurança & Proteção Anti-Intrusão do CASA/JARVIS - MySQL.
//
// - seguranca_logs: todos os eventos (falhas de login do painel e da API, bloqueios,
//   desbloqueios, chaves de API, hardware), com origem e usuário quando houver.
// - seguranca_ips_bloqueados: contador de falhas por IP (janela de 15 min) e o
//   bloqueio ativo, com a data do bloqueio (bloqueado_em) e o fim (bloqueado_ate).
// Ao incluir este arquivo o IP é conferido; páginas HTML (login) definem
// CASA_SEGURANCA_SEM_AUTOCHECK para tratar o bloqueio com mensagem própria.

require_once(__DIR__ . '/db.php');

const SEG_MAX_FALHAS = 5;            // falhas dentro da janela para bloquear
const SEG_JANELA_MIN = 15;           // janela de contagem das falhas (minutos)
const SEG_BLOQUEIO_MIN = 60;         // duração do bloqueio automático (minutos)

function get_client_ip() {
    // Mesmo critério da API v1: o cabeçalho X-Forwarded-For é ignorado por ser
    // definido pelo próprio cliente (permitiria escapar do bloqueio ou bloquear terceiros).
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

/** Bloqueio ativo do IP (ou null). Comparação feita pelo relógio do MySQL. */
function seguranca_bloqueio_ativo(PDO $pdo, string $ip): ?array {
    $stmt = $pdo->prepare("SELECT ip_address, motivo, bloqueado_em, bloqueado_ate, tentativas_falhas FROM seguranca_ips_bloqueados WHERE ip_address = :ip AND bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()");
    $stmt->execute([':ip' => $ip]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Remove bloqueios vencidos registrando o desbloqueio automático. */
function seguranca_liberar_vencido(PDO $pdo, string $ip): void {
    $stmt = $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address = :ip AND bloqueado_ate IS NOT NULL AND bloqueado_ate <= NOW()");
    $stmt->execute([':ip' => $ip]);
    if ($stmt->rowCount() > 0) registrar_evento_seguranca($ip, 'DESBLOQUEIO_AUTOMATICO', 'Fim do bloqueio temporário.', 'INFO', false, 'sistema');
}

function verificar_bloqueio_ip() {
    $ip = get_client_ip();
    if ($ip === '127.0.0.1' || $ip === '::1') return true;
    try {
        $pdo = get_db_pdo();
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
 * o IP é bloqueado por SEG_BLOQUEIO_MIN minutos. Retorna o bloqueio criado, se houver.
 */
function registrar_falha_seguranca($motivo, $severidade = 'AVISO', $origem = 'api', $usuario = null) {
    $ip = get_client_ip();
    try {
        $pdo = get_db_pdo();
        registrar_evento_seguranca($ip, 'FALHA_AUTENTICACAO', $motivo, $severidade, false, $origem, $usuario);
        seguranca_liberar_vencido($pdo, $ip);

        // Falhas antigas (fora da janela) não somam.
        $stmt = $pdo->prepare("SELECT tentativas_falhas, (ultima_falha IS NOT NULL AND ultima_falha >= DATE_SUB(NOW(), INTERVAL " . SEG_JANELA_MIN . " MINUTE)) AS recente FROM seguranca_ips_bloqueados WHERE ip_address = :ip");
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $falhas = ($row && (int)$row['recente'] === 1) ? (int)$row['tentativas_falhas'] + 1 : 1;
        $bloquear = $falhas >= SEG_MAX_FALHAS;
        $msg = $bloquear ? "Múltiplas falhas consecutivas de autenticação ($motivo)" : $motivo;

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
            registrar_evento_seguranca($ip, 'BLOQUEIO_IP_AUTOMATICO', "IP bloqueado por " . SEG_BLOQUEIO_MIN . " minutos após $falhas falhas em " . SEG_JANELA_MIN . " minutos. Última: $motivo", 'CRITICO', true, $origem, $usuario);
            return seguranca_bloqueio_ativo($pdo, $ip);
        }
    } catch (Throwable $e) {
        error_log('Erro registrando falha de segurança: ' . $e->getMessage());
    }
    return null;
}

/** Acesso bem-sucedido: zera o contador de falhas do IP (não remove bloqueio ativo). */
function seguranca_limpar_falhas($ip = null) {
    try {
        $pdo = get_db_pdo();
        $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address = :ip AND (bloqueado_ate IS NULL OR bloqueado_ate <= NOW())")
            ->execute([':ip' => $ip ?? get_client_ip()]);
    } catch (Throwable $e) {
        error_log('Erro limpando falhas de segurança: ' . $e->getMessage());
    }
}

function registrar_evento_seguranca($ip, $evento, $detalhes, $severidade = 'INFO', $bloqueado = false, $origem = null, $usuario = null) {
    try {
        $pdo = get_db_pdo();
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
        error_log('Erro gravando log de segurança: ' . $e->getMessage());
    }
}

function validar_hardware_token($token) {
    if (empty($token)) {
        registrar_falha_seguranca('Tentativa de comunicação de hardware com token ausente', 'AVISO', 'hardware');
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

    registrar_falha_seguranca('Token de hardware inválido: ' . substr($token, 0, 10) . '...', 'AVISO', 'hardware');
    return false;
}

function gerar_novo_hardware_token($prefixo = 'jarvis_dev') {
    return $prefixo . '_' . bin2hex(random_bytes(16));
}

if (!defined('CASA_SEGURANCA_SEM_AUTOCHECK')) verificar_bloqueio_ip();
