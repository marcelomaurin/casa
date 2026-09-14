<?php
// CASA/JARVIS - API externa v1 para JARVIS Watch.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');
require_once(__DIR__ . '/../family_common.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
family_ensure_schema($pdo);

function watch_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function watch_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS watch_telemetria (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      cliente VARCHAR(120) NOT NULL,
      bateria_pct INT NULL,
      passos BIGINT UNSIGNED NULL,
      rssi_ble INT NULL,
      rssi_wifi INT NULL,
      wifi_ssid VARCHAR(120) NULL,
      transporte VARCHAR(20) NULL,
      minutos_sem_movimento INT NULL,
      modo_energia VARCHAR(20) NULL,
      alerta_ativo TINYINT(1) NOT NULL DEFAULT 0,
      dados JSON NULL,
      data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_watch_tel_cliente_data (cliente, data_hora),
      KEY idx_watch_tel_data (data_hora)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function watch_device_id(PDO $pdo, string $clientName): ?int {
    try {
        $stmt = $pdo->prepare("SELECT id FROM dispositivos_cluster WHERE nome=:n LIMIT 1");
        $stmt->execute([':n'=>$clientName]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}

watch_ensure_schema($pdo);
$action = $_GET['acao'] ?? 'status';
$readActions = ['status','notificacoes','family_poll','call_current'];
$client = api_v1_auth_client_any($pdo, in_array($action,$readActions,true)
    ? ['watch.read','mobile.read','family.read']
    : ['watch.write','mobile.write','family.write']);
$input = watch_input();
$clientName = (string)$client['nome'];
$deviceId = watch_device_id($pdo, $clientName);

if ($action === 'status') {
    $pending = 0;
    try {
        if ($deviceId) {
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM watch_notificacoes WHERE entregue=0 AND (id_dispositivo IS NULL OR id_dispositivo=:id)");
            $stmt->execute([':id'=>$deviceId]);
            $pending=(int)$stmt->fetchColumn();
        } else {
            $pending=(int)$pdo->query("SELECT COUNT(*) FROM watch_notificacoes WHERE entregue=0 AND id_dispositivo IS NULL")->fetchColumn();
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'status'=>'ok',
        'sistema'=>'CASA_JARVIS',
        'api'=>'watch-v1',
        'cliente'=>$clientName,
        'device_id'=>$deviceId,
        'server_time'=>date('c'),
        'canonical_base'=>'https://maurinsoft.com.br/casa',
        'family_channel'=>'familia',
        'pending_notifications'=>$pending,
        'capabilities'=>[
            'family_broadcast'=>true,
            'assistance'=>true,
            'notifications'=>true,
            'telemetry'=>true,
            'wifi_fallback'=>true,
            'voice_gateway'=>true
        ]
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'presence') {
    $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
    $metadata['transport'] = $input['transport'] ?? ($metadata['transport'] ?? null);
    $metadata['battery'] = $input['battery'] ?? ($metadata['battery'] ?? null);
    $channel = family_channel($pdo, $input['channel'] ?? 'familia');
    family_presence($pdo,(int)$channel['id'],$clientName,'watch',$input['device'] ?? 'JARVIS Watch',$metadata);

    if ($deviceId) {
        try {
            $stmt=$pdo->prepare("UPDATE dispositivos_cluster SET status='online', sinal_rssi=:r, metadata=:m, ultimo_heartbeat=NOW() WHERE id=:id");
            $stmt->execute([
                ':r'=>(int)($input['rssi'] ?? 0),
                ':m'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ':id'=>$deviceId
            ]);
        } catch (Throwable $e) {}
    }
    echo json_encode(['status'=>'ok','server_time'=>date('c')]); exit;
}

if ($action === 'telemetry') {
    $dados = is_array($input['data'] ?? null) ? $input['data'] : [];
    try {
        $stmt=$pdo->prepare("INSERT INTO watch_telemetria
            (cliente,bateria_pct,passos,rssi_ble,rssi_wifi,wifi_ssid,transporte,minutos_sem_movimento,modo_energia,alerta_ativo,dados)
            VALUES(:c,:b,:p,:rb,:rw,:s,:t,:m,:e,:a,:d)");
        $stmt->execute([
            ':c'=>$clientName,
            ':b'=>isset($input['battery'])?(int)$input['battery']:null,
            ':p'=>isset($input['steps'])?(int)$input['steps']:null,
            ':rb'=>isset($input['rssi_ble'])?(int)$input['rssi_ble']:null,
            ':rw'=>isset($input['rssi_wifi'])?(int)$input['rssi_wifi']:null,
            ':s'=>substr((string)($input['wifi_ssid'] ?? ''),0,120) ?: null,
            ':t'=>substr((string)($input['transport'] ?? ''),0,20) ?: null,
            ':m'=>isset($input['minutes_without_movement'])?(int)$input['minutes_without_movement']:null,
            ':e'=>substr((string)($input['power_mode'] ?? ''),0,20) ?: null,
            ':a'=>!empty($input['alert_active'])?1:0,
            ':d'=>json_encode($dados,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ]);
        api_v1_log($pdo,'WATCH_TELEMETRY','INFO',$clientName,['transport'=>$input['transport'] ?? null]);
        echo json_encode(['status'=>'ok','id'=>(int)$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        api_v1_json_response(500,['status'=>'erro','mensagem'=>'Falha ao registrar telemetria do relógio']);
    }
    exit;
}

if ($action === 'notificacoes') {
    try {
        if ($deviceId) {
            $stmt=$pdo->prepare("SELECT id,titulo,mensagem,audio_url,prioridade,data_hora FROM watch_notificacoes
                WHERE entregue=0 AND (id_dispositivo IS NULL OR id_dispositivo=:id)
                ORDER BY CASE prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END,data_hora ASC LIMIT 20");
            $stmt->execute([':id'=>$deviceId]);
        } else {
            $stmt=$pdo->query("SELECT id,titulo,mensagem,audio_url,prioridade,data_hora FROM watch_notificacoes
                WHERE entregue=0 AND id_dispositivo IS NULL
                ORDER BY CASE prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END,data_hora ASC LIMIT 20");
        }
        echo json_encode(['status'=>'ok','notificacoes'=>$stmt->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE); exit;
    } catch (Throwable $e) {
        api_v1_json_response(500,['status'=>'erro','mensagem'=>'Falha ao consultar notificações do relógio']);
    }
}

if ($action === 'ack') {
    $id=(int)($input['id'] ?? 0);
    if ($id<=0) api_v1_json_response(400,['status'=>'erro','mensagem'=>'ID inválido']);
    if ($deviceId) {
        $stmt=$pdo->prepare("UPDATE watch_notificacoes SET entregue=1,lida=1,data_entrega=NOW(),data_leitura=NOW()
            WHERE id=:n AND (id_dispositivo IS NULL OR id_dispositivo=:d)");
        $stmt->execute([':n'=>$id,':d'=>$deviceId]);
    } else {
        $stmt=$pdo->prepare("UPDATE watch_notificacoes SET entregue=1,lida=1,data_entrega=NOW(),data_leitura=NOW() WHERE id=:n AND id_dispositivo IS NULL");
        $stmt->execute([':n'=>$id]);
    }
    echo json_encode(['status'=>'ok']); exit;
}

if ($action === 'assist_event') {
    $type=substr(trim((string)($input['type'] ?? 'CHECKIN')),0,40);
    $severity=substr(trim((string)($input['severity'] ?? 'info')),0,20);
    $message=trim((string)($input['message'] ?? 'Evento de assistência do relógio'));
    $id=family_alert($pdo,$type,$severity,$message,$input['data'] ?? [],$input['person'] ?? null,$input['device'] ?? $clientName);
    echo json_encode(['status'=>'ok','event_id'=>$id]); exit;
}

if ($action === 'family_send') {
    $channel=family_channel($pdo,$input['channel'] ?? 'familia');
    $id=family_send($pdo,(int)$channel['id'],$clientName,'watch',$input['type'] ?? 'texto',trim((string)($input['message'] ?? '')),$input['data'] ?? []);
    echo json_encode(['status'=>'ok','id'=>$id]); exit;
}

if ($action === 'family_poll') {
    $channel=family_channel($pdo,$_GET['channel'] ?? 'familia');
    $after=max(0,(int)($_GET['after'] ?? 0));
    $stmt=$pdo->prepare("SELECT id,remetente,origem,tipo,mensagem,dados,criado_em FROM family_messages WHERE canal_id=:c AND id>:a ORDER BY id ASC LIMIT 50");
    $stmt->execute([':c'=>$channel['id'],':a'=>$after]);
    echo json_encode(['status'=>'ok','messages'=>$stmt->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'call_start') {
    $channel=family_channel($pdo,$input['channel'] ?? 'familia');
    $mode=in_array(($input['mode'] ?? 'audio'),['audio','video'],true)?$input['mode']:'audio';
    $stmt=$pdo->prepare("INSERT INTO family_calls(canal_id,iniciado_por,modo,status) VALUES(:c,:u,:m,'chamando')");
    $stmt->execute([':c'=>$channel['id'],':u'=>$clientName,':m'=>$mode]);
    $callId=(int)$pdo->lastInsertId();
    family_send($pdo,(int)$channel['id'],$clientName,'watch','call','Chamada familiar iniciada pelo relógio',['call_id'=>$callId,'mode'=>$mode]);
    echo json_encode(['status'=>'ok','call_id'=>$callId,'mode'=>$mode,'video_source'=>'phone_or_web']); exit;
}

api_v1_json_response(404,[
    'status'=>'erro',
    'mensagem'=>'Ação watch v1 desconhecida',
    'acoes'=>['status','presence','telemetry','notificacoes','ack','assist_event','family_send','family_poll','call_start']
]);
