<?php
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';
require __DIR__ . '/../../site/var/www/html/api/task_engine.php';

$pdo = get_db_pdo();
$base = rtrim(getenv('CONTROL_PLANE_BASE_URL') ?: 'http://127.0.0.1:8099/api/v1', '/');
$suffix = bin2hex(random_bytes(5));
$deviceId = '';
$deviceToken = '';
$clientToken = 'client_' . bin2hex(random_bytes(16));
$ruleSlug = 'ci-http-event-' . $suffix;

function fail_http_test(string $message, $details = null): void {
    fwrite(STDERR, "[FAIL] {$message}\n");
    if ($details !== null) {
        fwrite(STDERR, is_string($details) ? $details . "\n" : json_encode($details, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(20);
}

function assert_true($condition, string $message, $details = null): void {
    if (!$condition) fail_http_test($message, $details);
}

function http_json(string $method, string $url, string $token, ?array $body = null, array $headers = []): array {
    $ch = curl_init($url);
    $httpHeaders = array_merge([
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ], $headers);
    if ($body !== null) $httpHeaders[] = 'Content-Type: application/json';

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) fail_http_test("Falha HTTP para {$url}: {$error}");
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        fail_http_test("Resposta nao JSON de {$url} (HTTP {$status})", $raw);
    }
    return ['status'=>$status, 'json'=>$json, 'raw'=>$raw];
}

$pdo->prepare("INSERT INTO api_client_tokens(nome,token_hash,scopes,ativo)
    VALUES(:n,:h,:s,1)")
    ->execute([
        ':n'=>'CI HTTP Controller ' . $suffix,
        ':h'=>hash('sha256',$clientToken),
        ':s'=>json_encode(['devices.read','devices.write','devices.provision','home.read','home.write'], JSON_UNESCAPED_SLASHES),
    ]);

echo "1/10 Provisionamento + heartbeat real via API...\n";
$provision=http_json('POST', "{$base}/provision.php?acao=create", $clientToken, [
    'type'=>'tv',
    'name'=>'CI HTTP Device '.$suffix,
    'location'=>'CI Lab',
    'mac'=>'02:00:00:'.strtoupper(substr($suffix,0,2)).':'.strtoupper(substr($suffix,2,2)).':'.strtoupper(substr($suffix,4,2)),
    'capabilities'=>['power'=>true,'dangerous_power'=>true]
]);
assert_true($provision['status']===200 && ($provision['json']['status']??'')==='ok', 'Provisionamento do device falhou', $provision);
$deviceId=(string)($provision['json']['device']['device_id']??'');
$deviceToken=(string)($provision['json']['device']['token']??'');
assert_true($deviceId!=='' && $deviceToken!=='', 'Provisionamento nao retornou identidade/token', $provision);

$pdo->prepare("UPDATE device_capabilities SET risk_level=4 WHERE device_id=:d AND capability='dangerous_power'")
    ->execute([':d'=>$deviceId]);

$hb = http_json('POST', "{$base}/device.php?acao=heartbeat", $deviceToken, [
    'device_id'=>$deviceId,
    'transport'=>'ci-http',
    'local_ip'=>'192.0.2.50',
    'health'=>'ok',
    'firmware_version'=>'ci-1.0.0',
    'protocol_version'=>'CASA/1.0',
    'battery'=>87,
    'rssi'=>-51,
    'uptime_sec'=>123,
    'capabilities'=>['power'=>true,'dangerous_power'=>true],
    'data'=>['source'=>'github-actions'],
]);
assert_true($hb['status']===200 && ($hb['json']['status']??'')==='ok', 'Heartbeat falhou', $hb);
$devRow=$pdo->prepare("SELECT status,health,transport,battery_pct,sinal_rssi,firmware_version,protocol_version,ultimo_heartbeat FROM dispositivos_cluster WHERE device_id=:d");
$devRow->execute([':d'=>$deviceId]);
$dev=$devRow->fetch(PDO::FETCH_ASSOC);
assert_true(($dev['status']??'')==='online' && ($dev['health']??'')==='ok' && !empty($dev['ultimo_heartbeat']), 'Heartbeat nao atualizou registry', $dev);
$hbCount=$pdo->prepare("SELECT COUNT(*) FROM device_heartbeats WHERE device_id=:d");
$hbCount->execute([':d'=>$deviceId]);
assert_true((int)$hbCount->fetchColumn()>=1, 'Heartbeat nao foi historizado');

echo "2/10 Enqueue + idempotencia...\n";
$idem='idem_' . $suffix;
$rootCorr='http_' . $suffix;
$enqueue = http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,
    'command'=>'power_on',
    'payload'=>['source'=>'ci-http'],
    'required_capability'=>'power',
    'risk_level'=>1,
    'ttl_seconds'=>120,
    'idempotency_key'=>$idem,
    'correlation_id'=>$rootCorr,
], ['X-Correlation-ID: '.$rootCorr]);
assert_true($enqueue['status']===201 && ($enqueue['json']['lifecycle_status']??'')==='QUEUED', 'Enqueue falhou', $enqueue);
$commandId=(int)($enqueue['json']['id']??0);
assert_true($commandId>0, 'Enqueue nao retornou command id', $enqueue);
assert_true(($enqueue['json']['correlation_id']??'')===$rootCorr, 'Enqueue nao preservou correlation_id fornecido', $enqueue);

$duplicate = http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,
    'command'=>'power_on',
    'payload'=>['source'=>'ci-http-duplicate'],
    'required_capability'=>'power',
    'risk_level'=>1,
    'ttl_seconds'=>120,
    'idempotency_key'=>$idem,
    'correlation_id'=>$rootCorr,
], ['X-Correlation-ID: '.$rootCorr]);
assert_true($duplicate['status']===200 && !empty($duplicate['json']['duplicate']) && (int)$duplicate['json']['id']===$commandId, 'Idempotencia nao reutilizou comando', $duplicate);

echo "3/10 Entrega -> ACK -> START -> RESULT success...\n";
$delivery = http_json('GET', "{$base}/device.php?acao=commands&device_id=".rawurlencode($deviceId)."&limit=10", $deviceToken);
$ids=array_map(fn($c)=>(int)($c['id']??0), $delivery['json']['commands']??[]);
assert_true($delivery['status']===200 && in_array($commandId,$ids,true), 'Device nao recebeu comando enfileirado', $delivery);
$state=$pdo->query("SELECT lifecycle_status,retry_count FROM device_commands WHERE id={$commandId}")->fetch(PDO::FETCH_ASSOC);
assert_true(($state['lifecycle_status']??'')==='SENT' && (int)($state['retry_count']??0)===1, 'Entrega nao marcou SENT/retry_count', $state);

$ack=http_json('POST', "{$base}/device.php?acao=command_ack", $deviceToken, ['device_id'=>$deviceId,'id'=>$commandId]);
assert_true($ack['status']===200 && ($ack['json']['lifecycle_status']??'')==='ACKNOWLEDGED' && (int)($ack['json']['updated']??0)===1, 'ACK falhou', $ack);

$start=http_json('POST', "{$base}/device.php?acao=command_start", $deviceToken, ['device_id'=>$deviceId,'id'=>$commandId]);
assert_true($start['status']===200 && ($start['json']['lifecycle_status']??'')==='EXECUTING' && (int)($start['json']['updated']??0)===1, 'START falhou', $start);

$done=http_json('POST', "{$base}/device.php?acao=command_result", $deviceToken, [
    'device_id'=>$deviceId,'id'=>$commandId,'status'=>'success','result'=>['power'=>'on','source'=>'ci-http']
]);
assert_true($done['status']===200 && ($done['json']['lifecycle_status']??'')==='DONE' && (int)($done['json']['updated']??0)===1, 'RESULT success falhou', $done);

echo "4/10 RESULT failure...\n";
$failEnqueue=http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,'command'=>'power_off','required_capability'=>'power','risk_level'=>1,'ttl_seconds'=>120,
]);
$failId=(int)($failEnqueue['json']['id']??0);
assert_true($failEnqueue['status']===201 && $failId>0, 'Enqueue do cenario de falha falhou', $failEnqueue);
$delivery2=http_json('GET', "{$base}/device.php?acao=commands&device_id=".rawurlencode($deviceId), $deviceToken);
$ids2=array_map(fn($c)=>(int)($c['id']??0), $delivery2['json']['commands']??[]);
assert_true(in_array($failId,$ids2,true), 'Comando do cenario de falha nao foi entregue', $delivery2);
http_json('POST', "{$base}/device.php?acao=command_ack", $deviceToken, ['device_id'=>$deviceId,'id'=>$failId]);
http_json('POST', "{$base}/device.php?acao=command_start", $deviceToken, ['device_id'=>$deviceId,'id'=>$failId]);
$failed=http_json('POST', "{$base}/device.php?acao=command_result", $deviceToken, [
    'device_id'=>$deviceId,'id'=>$failId,'status'=>'error','error'=>'falha controlada de CI','result'=>['code'=>'CI_FAIL']
]);
assert_true($failed['status']===200 && ($failed['json']['lifecycle_status']??'')==='FAILED', 'RESULT failure nao marcou FAILED', $failed);
$failedRow=$pdo->query("SELECT lifecycle_status,status,erro FROM device_commands WHERE id={$failId}")->fetch(PDO::FETCH_ASSOC);
assert_true(($failedRow['lifecycle_status']??'')==='FAILED' && ($failedRow['status']??'')==='error' && str_contains((string)$failedRow['erro'],'falha controlada'), 'Falha nao foi persistida corretamente', $failedRow);

echo "5/10 TTL expirado...\n";
$ttlCorr='ttl_' . $suffix;
$pdo->prepare("INSERT INTO device_commands(device_id,comando,payload,prioridade,correlation_id,status,lifecycle_status,max_retries,requested_by,risk_level,expira_em)
    VALUES(:d,'expired_test',JSON_OBJECT(),'normal',:c,'pending','QUEUED',3,'ci-http',1,DATE_SUB(NOW(),INTERVAL 2 SECOND))")
    ->execute([':d'=>$deviceId,':c'=>$ttlCorr]);
$ttlId=(int)$pdo->lastInsertId();
http_json('GET', "{$base}/device.php?acao=commands&device_id=".rawurlencode($deviceId), $deviceToken);
$ttlRow=$pdo->query("SELECT lifecycle_status,status,erro FROM device_commands WHERE id={$ttlId}")->fetch(PDO::FETCH_ASSOC);
assert_true(($ttlRow['lifecycle_status']??'')==='EXPIRED' && ($ttlRow['status']??'')==='expired', 'TTL nao expirou comando', $ttlRow);

echo "6/10 Capability invalida...\n";
$badCap=http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,'command'=>'invalid_cap_test','required_capability'=>'capability_inexistente','risk_level'=>1
]);
assert_true($badCap['status']===404 && ($badCap['json']['mensagem']??'')==='Capability nao encontrada', 'Capability invalida nao foi bloqueada', $badCap);

echo "7/10 Risco 3/4 exige confirmacao...\n";
$riskBlocked=http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,'command'=>'dangerous_test','required_capability'=>'dangerous_power','risk_level'=>4
]);
assert_true($riskBlocked['status']===409 && ($riskBlocked['json']['status']??'')==='confirmacao_necessaria', 'Risco alto sem confirmacao nao foi bloqueado', $riskBlocked);

$riskAllowed=http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,'command'=>'dangerous_test','required_capability'=>'dangerous_power','risk_level'=>4,'confirm'=>true
]);
assert_true($riskAllowed['status']===201 && (int)($riskAllowed['json']['risk_level']??0)===4, 'Risco alto confirmado nao foi aceito', $riskAllowed);

echo "8/10 Evento dispara regra permitida...\n";
$trigger=json_encode(['event_type'=>'ci.motion','device_id'=>$deviceId], JSON_UNESCAPED_SLASHES);
$conditions=json_encode([['field'=>'data.active','op'=>'eq','value'=>true]], JSON_UNESCAPED_SLASHES);
$pdo->prepare("INSERT INTO automation_rules(slug,nome,descricao,enabled,trigger_type,trigger_config,conditions_json,cooldown_seconds,last_triggered_at)
    VALUES(:s,'CI HTTP Event Rule','Regra E2E',1,'event',:t,:c,0,NULL)")
    ->execute([':s'=>$ruleSlug,':t'=>$trigger,':c'=>$conditions]);
$ruleId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO automation_rule_actions(rule_id,ordem,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds,enabled)
    VALUES(:r,10,:d,'power_on',JSON_OBJECT('source','event-rule'),'normal','power',1,120,1)")
    ->execute([':r'=>$ruleId,':d'=>$deviceId]);

$eventCorr='event_' . $suffix;
$event=http_json('POST', "{$base}/device.php?acao=event", $deviceToken, [
    'device_id'=>$deviceId,
    'type'=>'ci.motion',
    'priority'=>'normal',
    'correlation_id'=>$eventCorr,
    'data'=>['active'=>true],
], ['X-Correlation-ID: '.$eventCorr]);
assert_true($event['status']===200 && (int)($event['json']['event_id']??0)>0, 'Evento nao foi registrado', $event);
$runs=$event['json']['automation_runs']??[];
$matched=false;
foreach($runs as $run){
    if((int)($run['rule_id']??0)===$ruleId && in_array(($run['status']??''),['QUEUED','PARTIAL'],true) && !empty($run['commands'])){
        $matched=true; break;
    }
}
assert_true($matched, 'Evento nao disparou regra permitida', $event);
$ruleStmt=$pdo->prepare("SELECT id,correlation_id FROM device_commands WHERE requested_by=:r ORDER BY id DESC LIMIT 1");
$ruleStmt->execute([':r'=>'RULE:'.$ruleSlug]);
$ruleCommand=$ruleStmt->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($ruleCommand), 'Regra de evento nao gerou comando no Command Bus');
assert_true(($ruleCommand['correlation_id']??'')===$eventCorr, 'Regra quebrou correlation_id do evento', $ruleCommand);

$ruleRunStmt=$pdo->prepare("SELECT correlation_id FROM automation_rule_runs WHERE rule_id=:r ORDER BY id DESC LIMIT 1");
$ruleRunStmt->execute([':r'=>$ruleId]);
assert_true((string)$ruleRunStmt->fetchColumn()===$eventCorr, 'automation_rule_run nao preservou correlation_id do evento');

$eventTrace=http_json('GET',"{$base}/trace.php?correlation_id=".rawurlencode($eventCorr),$clientToken);
assert_true($eventTrace['status']===200, 'Trace do evento falhou', $eventTrace);
$eventSummary=$eventTrace['json']['summary']??[];
assert_true((int)($eventSummary['events']??0)>=1 && (int)($eventSummary['rule_runs']??0)>=1 && (int)($eventSummary['commands']??0)>=1,
    'Trace nao reconstruiu evento -> regra -> comando', $eventSummary);

echo "9/10 Auditoria de lifecycle...\n";
$audit=$pdo->prepare("SELECT lifecycle_status,correlation_id FROM device_command_audit WHERE command_id=:id ORDER BY id");
$audit->execute([':id'=>$commandId]);
$auditRows=$audit->fetchAll(PDO::FETCH_ASSOC);
$auditStates=array_column($auditRows,'lifecycle_status');
foreach($auditRows as $auditRow){
    assert_true(($auditRow['correlation_id']??'')===$rootCorr, 'Auditoria perdeu correlation_id do comando', $auditRows);
}
foreach(['QUEUED','SENT','ACKNOWLEDGED','EXECUTING','DONE'] as $expected){
    assert_true(in_array($expected,$auditStates,true), "Auditoria sem estado {$expected}", $auditStates);
}

echo "HTTP E2E Control Plane OK: heartbeat, enqueue, delivery, ACK, start, success/failure, TTL, idempotency, capability, risk e event-rule validados.\n";


echo "10/10 Fluxo completo Task -> Action -> HTTP Device -> Trace...\n";
$e2eCtx=te_begin($pdo,'CI E2E: ligar TV e confirmar resultado','CI_HTTP','e2e',null,'e2e_'.$suffix);
$e2eTask=te_add_subtask($pdo,$e2eCtx,'Ligar TV via device real simulado','dispositivo',[
    'device_id'=>$deviceId,'command'=>'power_on'
],null,'EXECUTANDO');
$e2eAction=te_device_action($pdo,$e2eCtx,$e2eTask,[
    'device_id'=>$deviceId,
    'command'=>'power_on',
    'payload'=>['source'=>'http-e2e'],
    'required_capability'=>'power',
    'risk_level'=>1,
    'ttl_seconds'=>120
]);
$e2eCommandId=(int)($e2eAction['command_id']??0);
assert_true($e2eCommandId>0, 'Task Engine nao criou comando E2E', $e2eAction);

$e2eDelivery=http_json('GET', "{$base}/device.php?acao=commands&device_id=".rawurlencode($deviceId)."&limit=20", $deviceToken);
$e2eIds=array_map(fn($x)=>(int)($x['id']??0),$e2eDelivery['json']['commands']??[]);
assert_true(in_array($e2eCommandId,$e2eIds,true),'Device nao recebeu comando originado do Task Engine',$e2eDelivery);

$e2eAck=http_json('POST',"{$base}/device.php?acao=command_ack",$deviceToken,['device_id'=>$deviceId,'id'=>$e2eCommandId]);
$e2eStart=http_json('POST',"{$base}/device.php?acao=command_start",$deviceToken,['device_id'=>$deviceId,'id'=>$e2eCommandId]);
$e2eDone=http_json('POST',"{$base}/device.php?acao=command_result",$deviceToken,[
    'device_id'=>$deviceId,
    'id'=>$e2eCommandId,
    'status'=>'success',
    'result'=>['power'=>'on','simulated'=>true,'source'=>'http-e2e']
]);
assert_true(($e2eAck['json']['lifecycle_status']??'')==='ACKNOWLEDGED','E2E ACK falhou',$e2eAck);
assert_true(($e2eStart['json']['lifecycle_status']??'')==='EXECUTING','E2E START falhou',$e2eStart);
assert_true(($e2eDone['json']['lifecycle_status']??'')==='DONE','E2E RESULT falhou',$e2eDone);

$taskRow=$pdo->query("SELECT status,resultado,erro FROM jarvis_tarefas WHERE id=".$e2eTask)->fetch(PDO::FETCH_ASSOC);
$actionRow=$pdo->query("SELECT status,resultado,erro FROM jarvis_acoes WHERE id=".(int)$e2eAction['action_id'])->fetch(PDO::FETCH_ASSOC);
assert_true(($taskRow['status']??'')==='CONCLUIDA','Resultado do device nao concluiu tarefa E2E',$taskRow);
assert_true(($actionRow['status']??'')==='DONE','Resultado do device nao concluiu action E2E',$actionRow);

te_finish($pdo,$e2eCtx,'TV ligada com sucesso no teste E2E',['command_id'=>$e2eCommandId]);

$taskApi=http_json('GET',"{$base}/tasks.php?acao=status&id_plano=".(int)$e2eCtx['id_plano'],$clientToken);
assert_true($taskApi['status']===200 && ($taskApi['json']['task_status']['derived_status']??'')==='CONCLUIDO','API de progresso nao refletiu conclusao E2E',$taskApi);

$trace=http_json('GET',"{$base}/trace.php?correlation_id=".rawurlencode((string)$e2eCtx['correlation_id']),$clientToken);
assert_true($trace['status']===200 && ($trace['json']['correlation_id']??'')===$e2eCtx['correlation_id'],'Trace API nao encontrou fluxo E2E',$trace);
$summary=$trace['json']['summary']??[];
assert_true((int)($summary['plans']??0)>=1 && (int)($summary['tasks']??0)>=2 && (int)($summary['actions']??0)>=1 && (int)($summary['commands']??0)>=1,'Trace E2E incompleto',$summary);
$stages=array_column($trace['json']['timeline']??[],'stage');
foreach(['plan','task','action','command'] as $stage){
    assert_true(in_array($stage,$stages,true),"Trace E2E sem stage {$stage}",$trace['json']['timeline']??[]);
}

echo "HTTP E2E Full Trace OK: request -> plan -> task -> action -> command -> device -> result -> trace validado.\n";
