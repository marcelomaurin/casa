<?php
// Módulo Avançado de Segurança & Proteção Anti-Intrusão do JARVIS
// Responsável por:
// 1. Bloqueio automático de IPs invasores (Fail2Ban nativo)
// 2. Validação de Tokens de Hardware para Microcontroladores (ESP32, Arduino)
// 3. Auditoria e Logs de Segurança no PostgreSQL (seguranca_logs)
// 4. Rate Limiting

require_once(__DIR__ . '/db.php');

function get_client_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

function verificar_bloqueio_ip() {
    $ip = get_client_ip();
    
    // IPs locais de confiança do próprio cluster nunca são banidos
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }

    $pdo = get_db_pdo();
    try {
        $stmt = $pdo->prepare("SELECT ip_address, motivo, bloqueado_ate FROM seguranca_ips_bloqueados WHERE ip_address = :ip");
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();

        if ($row && !empty($row['bloqueado_ate'])) {
            $bloq_time = strtotime($row['bloqueado_ate']);
            if ($bloq_time > time()) {
                registrar_evento_seguranca($ip, 'ACESSO_IP_BLOQUEADO', "Tentativa de acesso negada pelo firewall do JARVIS. Motivo: " . $row['motivo'], 'CRITICO', true);
                http_response_code(403);
                echo json_encode([
                    'status' => 'bloqueado',
                    'seguranca' => 'FIREWALL_ANTI_INTRUSAO_ATIVO',
                    'mensagem' => 'Acesso negado: Seu IP foi temporariamente bloqueado devido a atividades suspeitas ou violação de autenticação.',
                    'bloqueado_ate' => $row['bloqueado_ate']
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                // Período de bloqueio expirou, limpar ban
                $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address = :ip")->execute([':ip' => $ip]);
            }
        }
    } catch (Exception $e) {
        error_log("Erro verificando bloqueio de IP: " . $e->getMessage());
    }
    return true;
}

function registrar_falha_seguranca($motivo, $severidade = 'AVISO') {
    $ip = get_client_ip();
    $pdo = get_db_pdo();

    try {
        // 1. Registrar no histórico de logs
        registrar_evento_seguranca($ip, 'FALHA_AUTENTICACAO', $motivo, $severidade, false);

        // 2. Incrementar contagem no controle de IPs
        $stmt = $pdo->prepare("SELECT tentativas_falhas FROM seguranca_ips_bloqueados WHERE ip_address = :ip");
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();

        $falhas = $row ? intval($row['tentativas_falhas']) + 1 : 1;

        if ($falhas >= 5) {
            // 5 falhas consecutivas: Banir por 1 hora
            $ate = date('Y-m-d H:i:s', time() + 3600);
            $up = $pdo->prepare("INSERT INTO seguranca_ips_bloqueados (ip_address, motivo, tentativas_falhas, bloqueado_ate) 
                VALUES (:ip, :m, :f, :ate) 
                ON CONFLICT (ip_address) 
                DO UPDATE SET tentativas_falhas = :f, motivo = :m, bloqueado_ate = :ate");
            $up->execute([
                ':ip' => $ip,
                ':m' => "Múltiplas falhas consecutivas de autenticação ($motivo)",
                ':f' => $falhas,
                ':ate' => $ate
            ]);
            registrar_evento_seguranca($ip, 'BLOQUEIO_IP_AUTOMATICO', "IP bloqueado por 1 hora após $falhas falhas consecutivas.", 'CRITICO', true);
        } else {
            $up = $pdo->prepare("INSERT INTO seguranca_ips_bloqueados (ip_address, motivo, tentativas_falhas, bloqueado_ate) 
                VALUES (:ip, :m, :f, NULL) 
                ON CONFLICT (ip_address) 
                DO UPDATE SET tentativas_falhas = :f, motivo = :m");
            $up->execute([':ip' => $ip, ':m' => $motivo, ':f' => $falhas]);
        }
    } catch (Exception $e) {
        error_log("Erro registrando falha de segurança: " . $e->getMessage());
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
            ':bloq' => $bloqueado ? 'true' : 'false'
        ]);
    } catch (Exception $e) {
        error_log("Erro gravando log de segurança: " . $e->getMessage());
    }
}

function validar_hardware_token($token) {
    if (empty($token)) {
        registrar_falha_seguranca("Tentativa de comunicação de hardware com token ausente");
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
    } else {
        registrar_falha_seguranca("Token de hardware inválido: " . substr($token, 0, 10) . "...");
        return false;
    }
}

function gerar_novo_hardware_token($prefixo = 'jarvis_dev') {
    return $prefixo . '_' . bin2hex(random_bytes(16));
}

// Executa verificação de IP em qualquer inclusão
verificar_bloqueio_ip();
?>
