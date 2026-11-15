<?php
require_once __DIR__ . '/db.php';

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
        $token = bin2hex(random_bytes(16));
        $ins = $pdo->prepare("INSERT INTO dispositivos_cluster (device_id, nome, tipo, ip_address, mac_address, device_token, status, capabilities, metadata, localizacao, ultimo_heartbeat) VALUES (:did, :nome, 'tuya_sensor', :ip, :mac, :token, 'online', :caps, :meta, 'Sala', NOW())");
        $ins->execute([
            ':did' => $devId,
            ':nome' => $nome,
            ':ip' => $ip,
            ':mac' => $mac,
            ':token' => $token,
            ':caps' => $caps,
            ':meta' => $meta
        ]);
        echo json_encode(['status' => 'criado', 'id' => (int)$pdo->lastInsertId()]);
    }
} catch (Throwable $e) {
    echo json_encode(['status' => 'erro', 'mensagem' => $e->getMessage()]);
}
