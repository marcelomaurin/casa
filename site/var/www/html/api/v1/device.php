<?php
// CASA/JARVIS - API universal de devices (Control Plane).
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/device_common.php');
$pdo=get_db_pdo();
api_v1_basic_guard($pdo);

$action=$_GET['acao'] ?? 'status';
$input=device_v1_input();
$deviceId=(string)($input['device_id'] ?? ($_GET['device_id'] ?? ''));
$writeActions=['heartbeat','event','command_ack','command_result'];
$dev=device_v1_load($pdo,$deviceId,in_array($action,$writeActions,true));
$deviceId=$dev['device_id'];

if ($action==='status') {
    $pending=0;
    try {
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM device_commands WHERE device_id=:d AND status='pending' AND (expira_em IS NULL OR expira_em>NOW())");
        $stmt->execute([':d'=>$deviceId]); $pending=(int)$stmt->fetchColumn();
    } catch(Throwable $e) {}
    api_v1_json_response(200,[
        'status'=>'ok','api'=>'device-control-plane-v1','device_id'=>$deviceId,
        'server_time'=>date('c'),'commands_pending'=>$pending,
        'config_version'=>(int)($dev['config_version'] ?? 1),
        'capabilities'=>$dev['capabilities'] ?? [],
        'endpoints'=>[
            'heartbeat'=>'/api/v1/device.php?acao=heartbeat',
            'events'=>'/api/v1/device.php?acao=event',
            'commands'=>'/api/v1/device.php?acao=commands'
        ]
    ]);
}

if ($action==='heartbeat') {
    $transport=substr((string)($input['transport'] ?? ''),0,30) ?: null;
    $localIp=filter_var($input['local_ip'] ?? '',FILTER_VALIDATE_IP) ? $input['local_ip'] : null;
    $observed=api_v1_client_ip();
    $gateway=device_v1_safe_id((string)($input['gateway_device_id'] ?? '')) ?: null;
    $health=substr((string)($input['health'] ?? 'ok'),0,30);
    $firmware=substr((string)($input['firmware_version'] ?? ''),0,60) ?: null;
    $protocol=substr((string)($input['protocol_version'] ?? ''),0,30) ?: null;
    $rssi=isset($input['rssi'])?(int)$input['rssi']:0;
    $battery=isset($input['battery'])?max(0,min(100,(int)$input['battery'])):null;
    $uptime=isset($input['uptime_sec'])?max(0,(int)$input['uptime_sec']):null;
    $caps=is_array($input['capabilities'] ?? null)?$input['capabilities']:null;
    $data=is_array($input['data'] ?? null)?$input['data']:[];

    $sql="UPDATE dispositivos_cluster SET status='online',transport=:t,local_ip=:lip,observed_ip=:oip,gateway_device_id=:g,health=:h,firmware_version=:f,protocol_version=:p,sinal_rssi=:r,ultimo_heartbeat=NOW(),ip_address=COALESCE(:lip2,ip_address)";
    $params=[':t'=>$transport,':lip'=>$localIp,':oip'=>$observed,':g'=>$gateway,':h'=>$health,':f'=>$firmware,':p'=>$protocol,':r'=>$rssi,':lip2'=>$localIp,':id'=>$dev['id']];
    if ($caps!==null) { $sql.=",capabilities=:c"; $params[':c']=json_encode($caps,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    $sql.=" WHERE id=:id";
    $pdo->prepare($sql)->execute($params);

    try {
        $stmt=$pdo->prepare("INSERT INTO device_heartbeats(device_id,transport,local_ip,observed_ip,gateway_device_id,rssi,battery_pct,uptime_sec,health,firmware_version,protocol_version,dados)
            VALUES(:d,:t,:l,:o,:g,:r,:b,:u,:h,:f,:p,:j)");
        $stmt->execute([':d'=>$deviceId,':t'=>$transport,':l'=>$localIp,':o'=>$observed,':g'=>$gateway,':r'=>$rssi,':b'=>$battery,':u'=>$uptime,':h'=>$health,':f'=>$firmware,':p'=>$protocol,':j'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    } catch(Throwable $e) {}

    $stmt=$pdo->prepare("SELECT COUNT(*) FROM device_commands WHERE device_id=:d AND status='pending' AND (expira_em IS NULL OR expira_em>NOW())");
    $stmt->execute([':d'=>$deviceId]);
    api_v1_json_response(200,['status'=>'ok','device_id'=>$deviceId,'server_time'=>date('c'),'commands_pending'=>(int)$stmt->fetchColumn(),'config_version'=>(int)($dev['config_version'] ?? 1)]);
}

if ($action==='event') {
    $type=substr(trim((string)($input['type'] ?? 'device.event')),0,120);
    if ($type==='') api_v1_json_response(400,['status'=>'erro','mensagem'=>'type obrigatorio']);
    $priority=device_v1_priority((string)($input['priority'] ?? 'normal'));
    $corr=substr(trim((string)($input['correlation_id'] ?? '')),0,80) ?: null;
    $data=is_array($input['data'] ?? null)?$input['data']:[];
    $stmt=$pdo->prepare("INSERT INTO device_events(device_id,tipo,prioridade,correlation_id,dados,observado_ip) VALUES(:d,:t,:p,:c,:j,:ip)");
    $stmt->execute([':d'=>$deviceId,':t'=>$type,':p'=>$priority,':c'=>$corr,':j'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':ip'=>api_v1_client_ip()]);
    $id=(int)$pdo->lastInsertId();
    api_v1_log($pdo,'DEVICE_EVENT','INFO',$deviceId,['event_id'=>$id,'type'=>$type,'priority'=>$priority]);
    api_v1_json_response(200,['status'=>'ok','event_id'=>$id]);
}

if ($action==='commands') {
    $limit=max(1,min(20,(int)($_GET['limit'] ?? 10)));
    $stmt=$pdo->prepare("SELECT id,comando,payload,prioridade,correlation_id,criado_em,expira_em FROM device_commands
        WHERE device_id=:d AND status='pending' AND (expira_em IS NULL OR expira_em>NOW())
        ORDER BY FIELD(prioridade,'critical','high','normal','low'),criado_em ASC LIMIT {$limit}");
    $stmt->execute([':d'=>$deviceId]);
    api_v1_json_response(200,['status'=>'ok','commands'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'server_time'=>date('c')]);
}

if ($action==='command_ack' || $action==='command_result') {
    $id=(int)($input['id'] ?? 0);
    if ($id<=0) api_v1_json_response(400,['status'=>'erro','mensagem'=>'id do comando invalido']);
    if ($action==='command_ack') {
        $stmt=$pdo->prepare("UPDATE device_commands SET status='ack',entregue_em=COALESCE(entregue_em,NOW()),ack_em=NOW() WHERE id=:id AND device_id=:d AND status='pending'");
        $stmt->execute([':id'=>$id,':d'=>$deviceId]);
        api_v1_json_response(200,['status'=>'ok','updated'=>$stmt->rowCount()]);
    }
    $resultStatus=strtolower((string)($input['status'] ?? 'success'));
    $ok=in_array($resultStatus,['success','ok'],true);
    $result=is_array($input['result'] ?? null)?$input['result']:[];
    $error=$ok?null:substr((string)($input['error'] ?? 'Falha informada pelo device'),0,2000);
    $stmt=$pdo->prepare("UPDATE device_commands SET status=:s,concluido_em=NOW(),resultado=:r,erro=:e WHERE id=:id AND device_id=:d AND status IN('pending','ack','executing')");
    $stmt->execute([':s'=>$ok?'success':'error',':r'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':e'=>$error,':id'=>$id,':d'=>$deviceId]);
    api_v1_json_response(200,['status'=>'ok','updated'=>$stmt->rowCount()]);
}

api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida','acoes'=>['status','heartbeat','event','commands','command_ack','command_result']]);
