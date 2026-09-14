<?php
// CASA/JARVIS - API EXTERNA v1 PARA APP ANDROID - MySQL
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

function mobile_v1_json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$acao = $_GET['acao'] ?? 'status';
$readActions = ['notificacoes','status','watch_status'];
$client = api_v1_auth_client_any($pdo, in_array($acao,$readActions,true)
    ? ['mobile.read','family.read']
    : ['mobile.write','family.write']);
$in = mobile_v1_json_input();

$deviceId = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM dispositivos_cluster WHERE nome = :n LIMIT 1");
    $stmt->execute([':n' => $client['nome']]);
    $deviceId = $stmt->fetchColumn();
} catch (Throwable $e) {}

if ($acao === 'status') {
    $channel = family_channel($pdo, 'familia');
    $watchOnline = 0;
    try {
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM family_presence WHERE canal_id=:c AND plataforma='watch' AND ultimo_ping>=DATE_SUB(NOW(),INTERVAL 90 SECOND)");
        $stmt->execute([':c'=>$channel['id']]);
        $watchOnline=(int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    echo json_encode([
        'status' => 'ok',
        'cliente' => $client['nome'],
        'device_id' => $deviceId ? intval($deviceId) : null,
        'server_time' => date('c'),
        'canonical_base' => 'https://maurinsoft.com.br/casa',
        'family_channel' => 'familia',
        'watch_online' => $watchOnline,
        'endpoints' => [
            'family' => '/api/v1/family.php',
            'watch' => '/api/v1/watch.php',
            'command' => '/api/v1/comando'
        ],
        'capabilities' => [
            'watch_gateway'=>true,
            'family_broadcast'=>true,
            'gps_gateway'=>true,
            'camera_gateway'=>true,
            'voice_gateway'=>true,
            'notifications'=>true
        ]
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($acao === 'presence') {
    $channel=family_channel($pdo,$in['channel'] ?? 'familia');
    $metadata=is_array($in['metadata'] ?? null)?$in['metadata']:[];
    family_presence($pdo,(int)$channel['id'],$client['nome'],'mobile',$in['device'] ?? 'JARVIS Mobile',$metadata);
    if ($deviceId) {
        try {
            $stmt=$pdo->prepare("UPDATE dispositivos_cluster SET status='online',metadata=:m,ultimo_heartbeat=NOW() WHERE id=:id");
            $stmt->execute([':m'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':id'=>$deviceId]);
        } catch (Throwable $e) {}
    }
    echo json_encode(['status'=>'ok','server_time'=>date('c')]); exit;
}

if ($acao === 'watch_status') {
    try {
        $stmt=$pdo->query("SELECT cliente,bateria_pct,passos,rssi_ble,rssi_wifi,wifi_ssid,transporte,minutos_sem_movimento,modo_energia,alerta_ativo,data_hora
            FROM watch_telemetria ORDER BY id DESC LIMIT 1");
        $row=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        echo json_encode(['status'=>'ok','watch'=>$row],JSON_UNESCAPED_UNICODE); exit;
    } catch (Throwable $e) {
        echo json_encode(['status'=>'ok','watch'=>null]); exit;
    }
}

if ($acao === 'network_event') {
    $tipo = substr(trim($in['tipo'] ?? 'NETWORK'), 0, 80);
    $descricao = substr(trim($in['descricao'] ?? 'Evento de rede Android'), 0, 500);
    $dados = $in['dados'] ?? [];
    try {
        $stmt = $pdo->prepare("INSERT INTO mobile_eventos (id_dispositivo, tipo, descricao, dados) VALUES (:d, :t, :x, :j)");
        $stmt->execute([
            ':d' => $deviceId ?: null,
            ':t' => $tipo,
            ':x' => $descricao,
            ':j' => json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
        api_v1_log($pdo, 'MOBILE_NETWORK_EVENT', 'INFO', $client['nome'], ['tipo'=>$tipo]);
        echo json_encode(['status' => 'ok']);
    } catch (Throwable $e) {
        api_v1_json_response(500, ['status'=>'erro','mensagem'=>'Falha ao registrar evento móvel']);
    }
    exit;
}

if ($acao === 'watch_event') {
    $tipo=substr(trim((string)($in['type'] ?? 'WATCH_EVENT')),0,80);
    $descricao=substr(trim((string)($in['message'] ?? 'Evento encaminhado pelo JARVIS Mobile')),0,500);
    $dados=is_array($in['data'] ?? null)?$in['data']:[];
    try {
        $stmt=$pdo->prepare("INSERT INTO mobile_eventos(id_dispositivo,tipo,descricao,dados) VALUES(:d,:t,:x,:j)");
        $stmt->execute([':d'=>$deviceId?:null,':t'=>'WATCH_'.$tipo,':x'=>$descricao,':j'=>json_encode($dados,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        echo json_encode(['status'=>'ok','id'=>(int)$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        api_v1_json_response(500,['status'=>'erro','mensagem'=>'Falha ao registrar evento do relógio']);
    }
    exit;
}

if ($acao === 'notificacoes') {
    try {
        if ($deviceId) {
            $stmt = $pdo->prepare("SELECT id, titulo, mensagem, audio_url, prioridade, data_hora FROM mobile_notificacoes
                WHERE (id_dispositivo IS NULL OR id_dispositivo = :id) AND entregue = 0
                ORDER BY CASE prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END, data_hora ASC LIMIT 20");
            $stmt->execute([':id' => intval($deviceId)]);
        } else {
            $stmt = $pdo->query("SELECT id, titulo, mensagem, audio_url, prioridade, data_hora FROM mobile_notificacoes
                WHERE id_dispositivo IS NULL AND entregue = 0
                ORDER BY CASE prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END, data_hora ASC LIMIT 20");
        }
        echo json_encode(['status'=>'ok','notificacoes'=>$stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        api_v1_json_response(500, ['status'=>'erro','mensagem'=>'Falha ao consultar notificações']);
    }
    exit;
}

if ($acao === 'ack') {
    $id = intval($in['id'] ?? 0);
    if ($id <= 0) api_v1_json_response(400, ['status'=>'erro','mensagem'=>'ID de notificação inválido']);
    try {
        if ($deviceId) {
            $stmt = $pdo->prepare("UPDATE mobile_notificacoes SET entregue=1,lida=1,data_entrega=NOW(),data_leitura=NOW()
                WHERE id=:nid AND (id_dispositivo IS NULL OR id_dispositivo=:did)");
            $stmt->execute([':nid'=>$id, ':did'=>intval($deviceId)]);
        } else {
            $stmt = $pdo->prepare("UPDATE mobile_notificacoes SET entregue=1,lida=1,data_entrega=NOW(),data_leitura=NOW() WHERE id=:nid AND id_dispositivo IS NULL");
            $stmt->execute([':nid'=>$id]);
        }
        echo json_encode(['status'=>'ok']);
    } catch (Throwable $e) {
        api_v1_json_response(500, ['status'=>'erro','mensagem'=>'Falha ao confirmar notificação']);
    }
    exit;
}

api_v1_json_response(404, [
    'status'=>'erro',
    'mensagem'=>'Ação mobile v1 desconhecida',
    'acoes'=>['status','presence','watch_status','network_event','watch_event','notificacoes','ack']
]);
