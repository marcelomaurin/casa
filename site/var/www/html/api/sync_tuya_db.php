<?php
// Cadastro manual do sensor Tuya da sala. Uso: php sync_tuya_db.php
// Pela web, so o administrador (sessao admin ou chave do sistema) pode executar.
require_once __DIR__ . '/db.php';
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    verify_api_auth();
    $tipoAuth = (string)($GLOBALS['casa_auth_tipo'] ?? '');
    $perfil = strtolower((string)($_SESSION['auth_perfil'] ?? ''));
    if (!($tipoAuth === 'sistema' || ($tipoAuth === 'sessao' && in_array($perfil, ['admin', 'administrador'], true)))) {
        http_response_code(403);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Acesso restrito ao administrador']);
        exit;
    }
}

try {
    $pdo = get_db_pdo();
    $devId = 'tuya-sensor-192-168-2-3';
    $nome = 'Sensor Tuya Temperatura/Umidade';
    $ip = '192.168.2.3';
    $mac = '2e:6b:ad:23:ec:eb';
    $caps = json_encode(['sensor', 'temperature', 'humidity', 'battery', 'tuya-wifi'], JSON_UNESCAPED_UNICODE);
    $meta = json_encode([
        'origem' => 'tuya_wifi',
        'modelo' => 'Tuya Backlight Hygrometer Thermometer',
        'porta' => 8101
    ], JSON_UNESCAPED_UNICODE);

    $st = $pdo->prepare("SELECT id FROM dispositivos_cluster WHERE device_id = :did");
    $st->execute([':did' => $devId]);
    $existing = $st->fetchColumn();

    if ($existing) {
        $up = $pdo->prepare("UPDATE dispositivos_cluster SET nome = :nome, ip_address = :ip, mac_address = :mac, capabilities = :caps, metadata = :meta, localizacao = 'Sala', status = 'online', ultimo_heartbeat = NOW() WHERE id = :id");
        $up->execute([':nome' => $nome, ':ip' => $ip, ':mac' => $mac, ':caps' => $caps, ':meta' => $meta, ':id' => $existing]);
        echo json_encode(['status' => 'atualizado', 'id' => (int)$existing]);
    } else {
        // O token deste sensor nao e usado; guarda so o resumo e um marcador unico.
        $token = bin2hex(random_bytes(16));
        $ins = $pdo->prepare("INSERT INTO dispositivos_cluster (device_id, nome, tipo, ip_address, mac_address, device_token, device_token_hash, status, capabilities, metadata, localizacao, ultimo_heartbeat) VALUES (:did, :nome, 'tuya_sensor', :ip, :mac, :token, :hash, 'online', :caps, :meta, 'Sala', NOW())");
        $ins->execute([
            ':did' => $devId,
            ':nome' => $nome,
            ':ip' => $ip,
            ':mac' => $mac,
            ':token' => 'HASHED:' . $devId,
            ':hash' => hash('sha256', $token),
            ':caps' => $caps,
            ':meta' => $meta
        ]);
        echo json_encode(['status' => 'criado', 'id' => (int)$pdo->lastInsertId()]);
    }
} catch (Throwable $e) {
    error_log('sync_tuya_db: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') http_response_code(500);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Falha ao sincronizar o sensor Tuya']);
}

