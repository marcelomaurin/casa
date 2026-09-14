<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
$client = api_v1_auth_client($pdo, ['mobile.write']);

function provision_input(): array {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : [];
    return is_array($j) ? $j : [];
}

$action = $_GET['acao'] ?? 'list';

if ($action === 'list') {
    $stmt = $pdo->query("SELECT id,device_id,nome,tipo,ip_address,mac_address,status,sinal_rssi,capabilities,metadata,ultimo_heartbeat,localizacao,criado_em FROM dispositivos_cluster ORDER BY criado_em DESC LIMIT 100");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'ok','devices'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'create') {
    $in = provision_input();
    $type = strtolower(trim((string)($in['type'] ?? 'device')));
    $allowed = ['esp32cam','watch','esp32','esp8266','sensor','gateway','tv'];
    if (!in_array($type, $allowed, true)) api_v1_json_response(400, ['status'=>'erro','mensagem'=>'Tipo de device inválido']);

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') api_v1_json_response(400, ['status'=>'erro','mensagem'=>'Nome do device obrigatório']);
    $name = mb_substr($name, 0, 120);
    $location = mb_substr(trim((string)($in['location'] ?? 'Residencia')), 0, 120);
    $mac = preg_replace('/[^0-9A-Fa-f:]/', '', (string)($in['mac'] ?? ''));
    $deviceId = $type . '-' . bin2hex(random_bytes(6));
    $token = 'casa_dev_' . bin2hex(random_bytes(32));
    $capabilities = $in['capabilities'] ?? [];
    if (!is_array($capabilities)) $capabilities = [];
    $metadata = [
        'provisioned_by' => $client['nome'] ?? 'mobile',
        'provisioned_at' => date('c'),
        'transport_setup' => 'ble',
        'transport_runtime' => 'wifi'
    ];

    try {
        $stmt = $pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,mac_address,device_token,status,capabilities,metadata,localizacao) VALUES(:did,:n,:t,:m,:tok,'offline',:cap,:meta,:loc)");
        $stmt->execute([
            ':did'=>$deviceId, ':n'=>$name, ':t'=>$type, ':m'=>$mac !== '' ? $mac : null,
            ':tok'=>$token,
            ':cap'=>json_encode($capabilities, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':meta'=>json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':loc'=>$location !== '' ? $location : 'Residencia'
        ]);
        api_v1_log($pdo, 'DEVICE_PROVISION_CREATED', 'INFO', $client['nome'] ?? null, ['device_id'=>$deviceId,'type'=>$type]);
        echo json_encode([
            'status'=>'ok',
            'device'=>[
                'id'=>(int)$pdo->lastInsertId(),
                'device_id'=>$deviceId,
                'name'=>$name,
                'type'=>$type,
                'location'=>$location,
                'token'=>$token
            ],
            'notice'=>'O token é exibido para provisionamento e deve ser armazenado somente no device.'
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        api_v1_log($pdo, 'DEVICE_PROVISION_ERROR', 'ALTO', $client['nome'] ?? null, ['error'=>$e->getMessage()]);
        api_v1_json_response(500, ['status'=>'erro','mensagem'=>'Falha ao criar identidade do device']);
    }
    exit;
}

api_v1_json_response(404, ['status'=>'erro','mensagem'=>'Ação de provisionamento desconhecida']);
