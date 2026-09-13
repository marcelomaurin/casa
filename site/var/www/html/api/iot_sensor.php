<?php
// JARVIS RESIDENCIAL - API DE SENSORES IOT
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');

$pdo = get_db_pdo();

function sensor_json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$token = isset($_SERVER['HTTP_X_DEVICE_TOKEN']) ? trim($_SERVER['HTTP_X_DEVICE_TOKEN']) : '';
$dev = validar_hardware_token($token);
if (!$dev) {
    http_response_code(401);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Token de hardware inválido']);
    exit;
}

$in = sensor_json_input();
$tipo = trim($in['tipo_sensor'] ?? 'dht22');
$temp = array_key_exists('temperatura_c', $in) ? floatval($in['temperatura_c']) : null;
$umid = array_key_exists('umidade_pct', $in) ? floatval($in['umidade_pct']) : null;
$rssi = array_key_exists('rssi', $in) ? intval($in['rssi']) : null;

if ($temp === null && $umid === null) {
    http_response_code(400);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Leitura vazia']);
    exit;
}

$stmt = $pdo->prepare("INSERT INTO iot_leituras
    (id_dispositivo, tipo_sensor, temperatura_c, umidade_pct, rssi, dados)
    VALUES (:d, :t, :temp, :umid, :rssi, CAST(:dados AS jsonb))
    RETURNING id, data_hora");
$stmt->execute([
    ':d' => $dev['id'],
    ':t' => $tipo,
    ':temp' => $temp,
    ':umid' => $umid,
    ':rssi' => $rssi,
    ':dados' => json_encode($in, JSON_UNESCAPED_UNICODE)
]);
$row = $stmt->fetch();

try {
    $stmt2 = $pdo->prepare("UPDATE dispositivos_cluster
        SET ultimo_heartbeat = CURRENT_TIMESTAMP,
            ip_address = :ip,
            sinal_rssi = COALESCE(:rssi, sinal_rssi),
            status = 'online'
        WHERE id = :id");
    $stmt2->execute([
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':rssi' => $rssi,
        ':id' => $dev['id']
    ]);
} catch (Exception $e) {}

echo json_encode([
    'status' => 'ok',
    'id_leitura' => intval($row['id']),
    'data_hora' => $row['data_hora'],
    'temperatura_c' => $temp,
    'umidade_pct' => $umid
], JSON_UNESCAPED_UNICODE);
