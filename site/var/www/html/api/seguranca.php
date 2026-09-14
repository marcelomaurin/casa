<?php
// Módulo de Segurança & Proteção Anti-Intrusão do CASA/JARVIS - MySQL.

require_once(__DIR__ . '/db.php');

function get_client_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function verificar_bloqueio_ip() {
    $ip = get_client_ip();
    if ($ip === '127.0.0.1' || $ip === '::1') return true;

    $pdo = get_db_pdo();
    try {
        $stmt = $pdo->prepare("SELECT ip_address, motivo, bloqueado_ate FROM seguranca_ips_bloqueados WHERE ip_address = :ip");
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();

        if ($row && !empty($row['bloqueado_ate'])) {
            $bloq_time = strtotime($row['bloqueado_ate']);
            if ($bloq_time > time()) {
                registrar_evento_seguranca($ip, 'ACESSO_IP_BLOQUEADO', 'Tentativa de acesso negada. Motivo: ' . $row['motivo'], 'CRITICO', true);
                http_response_code(403);
                echo json_encode([
                    'status' => 'bloqueado',
                    'seguranca' => 'FIREWALL_ANTI_INTRUSAO_ATIVO',
                    'mensagem' => 'Acesso negado: IP temporariamente bloqueado.',
                    'bloqueado_ate' => $row['bloqueado_ate']
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address = :ip")->execute([':ip' => $ip]);
        }
    } catch (Throwable $e) {
        error_log('Erro verificando bloqueio de IP: ' . $e->getMessage());
    }
    return true;
}

function registrar_falha_seguranca($motivo, $severidade = 'AVISO') {
    $ip = get_client_ip();
    $pdo = get_db_pdo();

    try {
        registrar_evento_seguranca($ip, 'FALHA_AUTENTICACAO', $motivo, $severidade, false);

        $stmt = $pdo->prepare("SELECT tentativas_falhas FROM seguranca_ips_bloqueados WHERE ip_address = :ip");
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();
        $falhas = $row ? intval($row['tentativas_falhas']) + 1 : 1;
        $ate = $falhas >= 5 ? date('Y-m-d H:i:s', time() + 3600) : null;
        $msg = $falhas >= 5 ? "Múltiplas falhas consecutivas de autenticação ($motivo)" : $motivo;

        $up = $pdo->prepare("INSERT INTO seguranca_ips_bloqueados
            (ip_address, motivo, tentativas_falhas, bloqueado_ate)
            VALUES (:ip, :m, :f, :ate)
            ON DUPLICATE KEY UPDATE
                motivo = VALUES(motivo),
                tentativas_falhas = VALUES(tentativas_falhas),
                bloqueado_ate = VALUES(bloqueado_ate)");
        $up->execute([':ip' => $ip, ':m' => $msg, ':f' => $falhas, ':ate' => $ate]);

        if ($falhas >= 5) {
            registrar_evento_seguranca($ip, 'BLOQUEIO_IP_AUTOMATICO', "IP bloqueado por 1 hora após $falhas falhas consecutivas.", 'CRITICO', true);
        }
    } catch (Throwable $e) {
        error_log('Erro registrando falha de segurança: ' . $e->getMessage());
    }
}

function registrar_evento_seguranca($ip, $evento, $detalhes, $severidade = 'INFO', $bloqueado = false) {
    try {
        $pdo = get_db_pdo();
        $stmt = $pdo->prepare("INSERT INTO seguranca_logs (origem_ip, evento, detalhes, severidade, bloqueado)
            VALUES (:ip, :ev, :det, :sev, :bloq)");
        $stmt->execute([
            ':ip' => $ip,
            ':ev' => $evento,
            ':det' => $detalhes,
            ':sev' => $severidade,
            ':bloq' => $bloqueado ? 1 : 0
        ]);
    } catch (Throwable $e) {
        error_log('Erro gravando log de segurança: ' . $e->getMessage());
    }
}

function validar_hardware_token($token) {
    if (empty($token)) {
        registrar_falha_seguranca('Tentativa de comunicação de hardware com token ausente');
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

    registrar_falha_seguranca('Token de hardware inválido: ' . substr($token, 0, 10) . '...');
    return false;
}

function gerar_novo_hardware_token($prefixo = 'jarvis_dev') {
    return $prefixo . '_' . bin2hex(random_bytes(16));
}

verificar_bloqueio_ip();
?>
