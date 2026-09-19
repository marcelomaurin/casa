<?php
// CASA/JARVIS - API universal de devices (Control Plane).
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token, X-Correlation-ID');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/device_common.php');
require_once(__DIR__ . '/device_registry.php');
require_once(__DIR__ . '/rules_engine.php');
require_once(__DIR__ . '/rules_scheduler.php');
require_once(__DIR__ . '/../task_engine.php');
$pdo=get_db_pdo();
api_v1_basic_guard($pdo);

$action=$_GET['acao'] ?? 'status';
$input=device_v1_input();
$deviceId=(string)($input['device_id'] ?? ($_GET['device_id'] ?? ''));
$writeActions=['heartbeat','event','command_ack','command_result','command_start'];
$dev=device_v1_load($pdo,$deviceId,in_array($action,$writeActions,true));
$deviceId=$dev['device_id'];

if ($action==='status') {
    $pending=0;
    try {
        $pdo->prepare("UPDATE device_commands SET status='expired',lifecycle_status='EXPIRED',concluido_em=COALESCE(concluido_em,NOW()),erro=COALESCE(erro,'Comando expirado') WHERE device_id=:d AND status IN('pending','ack','executing') AND expira_em IS NOT NULL AND expira_em<=NOW()")
            ->execute([':d'=>$deviceId]);
        te_sync_device_actions_for_device($pdo,$deviceId);
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM device_commands WHERE device_id=:d AND lifecycle_status IN('QUEUED','SENT') AND (expira_em IS NULL OR expira_em>NOW())");
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
    $manufacturer=substr((string)($input['manufacturer'] ?? ''),0,80) ?: null;
    $model=substr((string)($input['model'] ?? ''),0,100) ?: null;
    $rssi=isset($input['rssi'])?(int)$input['rssi']:0;
    $battery=isset($input['battery'])?max(0,min(100,(int)$input['battery'])):null;
    $uptime=isset($input['uptime_sec'])?max(0,(int)$input['uptime_sec']):null;
    $caps=is_array($input['capabilities'] ?? null)?$input['capabilities']:null;
    $data=is_array($input['data'] ?? null)?$input['data']:[];

    $sql="UPDATE dispositivos_cluster SET status='online',transport=:t,local_ip=:lip,observed_ip=:oip,gateway_device_id=:g,health=:h,firmware_version=:f,protocol_version=:p,manufacturer=COALESCE(:mf,manufacturer),model=COALESCE(:m,model),battery_pct=:b,sinal_rssi=:r,ultimo_heartbeat=NOW(),ip_address=COALESCE(:lip2,ip_address),metadata=:meta";
    $params=[':t'=>$transport,':lip'=>$localIp,':oip'=>$observed,':g'=>$gateway,':h'=>$health,':f'=>$firmware,':p'=>$protocol,':mf'=>$manufacturer,':m'=>$model,':b'=>$battery,':r'=>$rssi,':lip2'=>$localIp,':meta'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':id'=>$dev['id']];
    if ($caps!==null) { $sql.=",capabilities=:c"; $params[':c']=json_encode($caps,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    $sql.=" WHERE id=:id";
    $pdo->prepare($sql)->execute($params);

    if ($caps!==null) {
        try { registry_sync_capabilities($pdo,$deviceId,$caps); } catch(Throwable $e) {}
    }

    try {
        $stmt=$pdo->prepare("INSERT INTO device_heartbeats(device_id,transport,local_ip,observed_ip,gateway_device_id,rssi,battery_pct,uptime_sec,health,firmware_version,protocol_version,dados)
            VALUES(:d,:t,:l,:o,:g,:r,:b,:u,:h,:f,:p,:j)");
        $stmt->execute([':d'=>$deviceId,':t'=>$transport,':l'=>$localIp,':o'=>$observed,':g'=>$gateway,':r'=>$rssi,':b'=>$battery,':u'=>$uptime,':h'=>$health,':f'=>$firmware,':p'=>$protocol,':j'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    } catch(Throwable $e) {}

    $scheduledRuns=[];
    try {
        $scheduledRuns=rules_scheduler_tick($pdo,false);
        if($scheduledRuns) api_v1_log($pdo,'AUTOMATION_SCHEDULED','INFO',$deviceId,['runs'=>$scheduledRuns]);
    } catch(Throwable $e) {
        api_v1_log($pdo,'AUTOMATION_SCHEDULER_ERROR','WARN',$deviceId,['error'=>substr($e->getMessage(),0,500)]);
    }

    $stmt=$pdo->prepare("SELECT COUNT(*) FROM device_commands WHERE device_id=:d AND lifecycle_status IN('QUEUED','SENT') AND (expira_em IS NULL OR expira_em>NOW())");
    $stmt->execute([':d'=>$deviceId]);
    api_v1_json_response(200,['status'=>'ok','device_id'=>$deviceId,'server_time'=>date('c'),'commands_pending'=>(int)$stmt->fetchColumn(),'config_version'=>(int)($dev['config_version'] ?? 1),'scheduled_runs'=>$scheduledRuns]);
}

if ($action==='event') {
    $type=substr(trim((string)($input['type'] ?? 'device.event')),0,120);
    if ($type==='') api_v1_json_response(400,['status'=>'erro','mensagem'=>'type obrigatorio']);
    $priority=device_v1_priority((string)($input['priority'] ?? 'normal'));
    $corr=api_v1_correlation_id($input['correlation_id'] ?? null);
    $data=is_array($input['data'] ?? null)?$input['data']:[];
    $stmt=$pdo->prepare("INSERT INTO device_events(device_id,tipo,prioridade,correlation_id,dados,observado_ip) VALUES(:d,:t,:p,:c,:j,:ip)");
    $stmt->execute([':d'=>$deviceId,':t'=>$type,':p'=>$priority,':c'=>$corr,':j'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':ip'=>api_v1_client_ip()]);
    $id=(int)$pdo->lastInsertId();
    api_v1_log($pdo,'DEVICE_EVENT','INFO',$deviceId,['event_id'=>$id,'type'=>$type,'priority'=>$priority],$corr);
    $automationRuns=[];
    try {
        $automationRuns=rules_engine_process_event($pdo,['id'=>$id,'device_id'=>$deviceId,'type'=>$type,'data'=>$data,'correlation_id'=>$corr]);
        if ($automationRuns) api_v1_log($pdo,'AUTOMATION_TRIGGERED','INFO',$deviceId,['event_id'=>$id,'runs'=>$automationRuns],$corr);
    } catch(Throwable $e) {
        api_v1_log($pdo,'AUTOMATION_ERROR','WARN',$deviceId,['event_id'=>$id,'error'=>substr($e->getMessage(),0,500)],$corr);
    }
    api_v1_json_response(200,['status'=>'ok','event_id'=>$id,'automation_runs'=>$automationRuns]);
}

if ($action==='commands') {
    $limit=max(1,min(20,(int)($_GET['limit'] ?? 10)));
    $pdo->prepare("UPDATE device_commands SET status='expired',lifecycle_status='EXPIRED',concluido_em=COALESCE(concluido_em,NOW()),erro=COALESCE(erro,'Comando expirado') WHERE device_id=:d AND lifecycle_status IN('QUEUED','SENT') AND expira_em IS NOT NULL AND expira_em<=NOW()")
        ->execute([':d'=>$deviceId]);
    $pdo->prepare("UPDATE device_commands SET status='error',lifecycle_status='FAILED',concluido_em=COALESCE(concluido_em,NOW()),erro=COALESCE(erro,'Limite de tentativas excedido') WHERE device_id=:d AND lifecycle_status='SENT' AND retry_count>=max_retries")
        ->execute([':d'=>$deviceId]);
    $stmt=$pdo->prepare("SELECT id,comando,payload,prioridade,correlation_id,criado_em,expira_em,retry_count,max_retries,risk_level FROM device_commands
        WHERE device_id=:d AND lifecycle_status IN('QUEUED','SENT') AND (expira_em IS NULL OR expira_em>NOW()) AND retry_count<max_retries
          AND (last_attempt_at IS NULL OR last_attempt_at<DATE_SUB(NOW(),INTERVAL 5 SECOND))
        ORDER BY FIELD(prioridade,'critical','high','normal','low'),criado_em ASC LIMIT {$limit}");
    $stmt->execute([':d'=>$deviceId]);
    $commands=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($commands) {
        $ids=array_map('intval',array_column($commands,'id'));
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $up=$pdo->prepare("UPDATE device_commands SET lifecycle_status='SENT',entregue_em=COALESCE(entregue_em,NOW()),last_attempt_at=NOW(),retry_count=retry_count+1 WHERE id IN ({$placeholders}) AND device_id=?");
        $params=$ids; $params[]=$deviceId; $up->execute($params);
    }
    api_v1_json_response(200,['status'=>'ok','commands'=>$commands,'server_time'=>date('c')]);
}

if ($action==='command_start') {
    $id=(int)($input['id'] ?? 0);
    if ($id<=0) api_v1_json_response(400,['status'=>'erro','mensagem'=>'id do comando invalido']);
    $stmt=$pdo->prepare("UPDATE device_commands SET status='executing',lifecycle_status='EXECUTING',iniciado_em=COALESCE(iniciado_em,NOW()) WHERE id=:id AND device_id=:d AND lifecycle_status IN('SENT','ACKNOWLEDGED')");
    $stmt->execute([':id'=>$id,':d'=>$deviceId]);
    $taskSync=te_sync_device_command($pdo,$id);
    api_v1_json_response(200,['status'=>'ok','updated'=>$stmt->rowCount(),'lifecycle_status'=>'EXECUTING','task_sync'=>$taskSync]);
}

if ($action==='command_ack' || $action==='command_result') {
    $id=(int)($input['id'] ?? 0);
    if ($id<=0) api_v1_json_response(400,['status'=>'erro','mensagem'=>'id do comando invalido']);
    if ($action==='command_ack') {
        $stmt=$pdo->prepare("UPDATE device_commands SET status='ack',lifecycle_status='ACKNOWLEDGED',entregue_em=COALESCE(entregue_em,NOW()),ack_em=NOW() WHERE id=:id AND device_id=:d AND lifecycle_status IN('QUEUED','SENT')");
        $stmt->execute([':id'=>$id,':d'=>$deviceId]);
        $taskSync=te_sync_device_command($pdo,$id);
        api_v1_json_response(200,['status'=>'ok','updated'=>$stmt->rowCount(),'lifecycle_status'=>'ACKNOWLEDGED','task_sync'=>$taskSync]);
    }
    $resultStatus=strtolower((string)($input['status'] ?? 'success'));
    $ok=in_array($resultStatus,['success','ok','done'],true);
    $result=is_array($input['result'] ?? null)?$input['result']:[];
    $error=$ok?null:substr((string)($input['error'] ?? 'Falha informada pelo device'),0,2000);
    $stmt=$pdo->prepare("UPDATE device_commands SET status=:s,lifecycle_status=:ls,concluido_em=NOW(),resultado=:r,erro=:e WHERE id=:id AND device_id=:d AND lifecycle_status IN('QUEUED','SENT','ACKNOWLEDGED','EXECUTING')");
    $stmt->execute([':s'=>$ok?'success':'error',':ls'=>$ok?'DONE':'FAILED',':r'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':e'=>$error,':id'=>$id,':d'=>$deviceId]);
    $taskSync=te_sync_device_command($pdo,$id);
    api_v1_json_response(200,['status'=>'ok','updated'=>$stmt->rowCount(),'lifecycle_status'=>$ok?'DONE':'FAILED','task_sync'=>$taskSync]);
}

api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida','acoes'=>['status','heartbeat','event','commands','command_ack','command_start','command_result']]);
