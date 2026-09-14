<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');
require_once(__DIR__ . '/../family_common.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
family_ensure_schema($pdo);

function family_input(): array {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : [];
    return is_array($j) ? $j : [];
}

$action = $_GET['acao'] ?? 'status';
$readActions = ['status','poll','presence','call_poll','call_current'];
$client = api_v1_auth_client_any($pdo, in_array($action,$readActions,true)
    ? ['family.read','mobile.read','watch.read']
    : ['family.write','mobile.write','watch.write']);
$input = family_input();
$channel = family_channel($pdo, $input['channel'] ?? ($_GET['channel'] ?? 'familia'));
$clientName = $client['nome'] ?? 'cliente';

if ($action === 'status') {
    $stmt = $pdo->prepare("SELECT cliente,dispositivo,plataforma,metadata,ultimo_ping FROM family_presence WHERE canal_id=:c AND ultimo_ping >= DATE_SUB(NOW(), INTERVAL 90 SECOND) ORDER BY ultimo_ping DESC");
    $stmt->execute([':c'=>$channel['id']]);
    echo json_encode(['status'=>'ok','channel'=>$channel,'online'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'presence') {
    family_presence($pdo,(int)$channel['id'],$clientName,$input['platform'] ?? 'mobile',$input['device'] ?? null,$input['metadata'] ?? []);
    echo json_encode(['status'=>'ok','server_time'=>date('c')]); exit;
}

if ($action === 'send') {
    $message = trim((string)($input['message'] ?? ''));
    $type = trim((string)($input['type'] ?? 'texto'));
    $id = family_send($pdo,(int)$channel['id'],$clientName,$input['origin'] ?? 'mobile',$type,$message,$input['data'] ?? []);
    echo json_encode(['status'=>'ok','id'=>$id]); exit;
}

if ($action === 'poll') {
    $after = max(0,(int)($_GET['after'] ?? 0));
    $stmt = $pdo->prepare("SELECT id,remetente,origem,tipo,mensagem,dados,criado_em FROM family_messages WHERE canal_id=:c AND id>:a ORDER BY id ASC LIMIT 100");
    $stmt->execute([':c'=>$channel['id'], ':a'=>$after]);
    echo json_encode(['status'=>'ok','messages'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'assist_event') {
    $type = substr(trim((string)($input['type'] ?? 'CHECKIN')),0,40);
    $severity = substr(trim((string)($input['severity'] ?? 'info')),0,20);
    $message = trim((string)($input['message'] ?? 'Evento de assistência registrado'));
    $id = family_alert($pdo,$type,$severity,$message,$input['data'] ?? [],$input['person'] ?? null,$input['device'] ?? $clientName);
    echo json_encode(['status'=>'ok','event_id'=>$id]); exit;
}

if ($action === 'assist_ack') {
    $id=(int)($input['id'] ?? 0);
    $stmt=$pdo->prepare("UPDATE assistencia_eventos SET confirmado=1, confirmado_em=NOW() WHERE id=:id");
    $stmt->execute([':id'=>$id]);
    echo json_encode(['status'=>'ok']); exit;
}

if ($action === 'call_start') {
    $mode = in_array(($input['mode'] ?? 'video'),['audio','video'],true) ? $input['mode'] : 'video';
    $stmt=$pdo->prepare("INSERT INTO family_calls(canal_id,iniciado_por,modo,status) VALUES(:c,:u,:m,'chamando')");
    $stmt->execute([':c'=>$channel['id'],':u'=>$clientName,':m'=>$mode]);
    $callId=(int)$pdo->lastInsertId();
    family_send($pdo,(int)$channel['id'],$clientName,$input['origin'] ?? 'mobile','call',"Chamada familiar iniciada",['call_id'=>$callId,'mode'=>$mode]);
    echo json_encode(['status'=>'ok','call_id'=>$callId,'mode'=>$mode]); exit;
}

if ($action === 'call_current') {
    $stmt=$pdo->prepare("SELECT * FROM family_calls WHERE canal_id=:c AND status IN ('chamando','ativa') ORDER BY id DESC LIMIT 1");
    $stmt->execute([':c'=>$channel['id']]);
    echo json_encode(['status'=>'ok','call'=>$stmt->fetch(PDO::FETCH_ASSOC) ?: null], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'call_join') {
    $callId=(int)($input['call_id'] ?? 0);
    $pdo->prepare("UPDATE family_calls SET status='ativa' WHERE id=:id AND canal_id=:c AND status='chamando'")->execute([':id'=>$callId,':c'=>$channel['id']]);
    family_presence($pdo,(int)$channel['id'],$clientName,$input['platform'] ?? 'mobile',$input['device'] ?? null,['call_id'=>$callId]);
    echo json_encode(['status'=>'ok','call_id'=>$callId]); exit;
}

if ($action === 'call_signal') {
    $callId=(int)($input['call_id'] ?? 0);
    $type=substr(trim((string)($input['type'] ?? 'signal')),0,30);
    $payload=json_encode($input['payload'] ?? new stdClass(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $stmt=$pdo->prepare("INSERT INTO family_call_signals(call_id,remetente,destino,tipo,payload) VALUES(:c,:r,:d,:t,:p)");
    $stmt->execute([':c'=>$callId,':r'=>$clientName,':d'=>$input['target'] ?? null,':t'=>$type,':p'=>$payload]);
    echo json_encode(['status'=>'ok','id'=>(int)$pdo->lastInsertId()]); exit;
}

if ($action === 'call_poll') {
    $callId=(int)($_GET['call_id'] ?? 0); $after=max(0,(int)($_GET['after'] ?? 0));
    $stmt=$pdo->prepare("SELECT id,remetente,destino,tipo,payload,criado_em FROM family_call_signals WHERE call_id=:c AND id>:a AND (destino IS NULL OR destino='' OR destino=:me) ORDER BY id ASC LIMIT 100");
    $stmt->execute([':c'=>$callId,':a'=>$after,':me'=>$clientName]);
    echo json_encode(['status'=>'ok','signals'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'call_end') {
    $callId=(int)($input['call_id'] ?? 0);
    $pdo->prepare("UPDATE family_calls SET status='encerrada', encerrado_em=NOW() WHERE id=:id AND canal_id=:c")->execute([':id'=>$callId,':c'=>$channel['id']]);
    family_send($pdo,(int)$channel['id'],$clientName,$input['origin'] ?? 'mobile','call','Chamada familiar encerrada',['call_id'=>$callId]);
    echo json_encode(['status'=>'ok']); exit;
}

api_v1_json_response(404,['status'=>'erro','mensagem'=>'Ação familiar desconhecida']);
