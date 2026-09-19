<?php
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';

$pdo = get_db_pdo();
$base = rtrim(getenv('CONTROL_PLANE_BASE_URL') ?: 'http://127.0.0.1:8099/api/v1', '/');
$suffix = bin2hex(random_bytes(5));
$deviceId = 'ci_http_' . $suffix;
$deviceToken = 'devtok_' . bin2hex(random_bytes(16));
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
        ':s'=>json_encode(['devices.read','devices.write'], JSON_UNESCAPED_SLASHES),
    ]);

$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,health,metadata,ultimo_heartbeat)
    VALUES(:d,:n,'simulator',:t,'offline','unknown',JSON_OBJECT(),NULL)")
    ->execute([
        ':d'=>$deviceId,
        ':n'=>'CI HTTP Device ' . $suffix,
        ':t'=>$deviceToken,
    ]);

$pdo->prepare("INSERT INTO device_capabilities(device_id,capability,enabled,risk_level)
    VALUES(:d,'power',1,1),(:d,'dangerous_power',1,4)")
    ->execute([':d'=>$deviceId]);

echo "1/9 Heartbeat real via API...\n";
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

echo "2/9 Enqueue + idempotencia...\n";
$idem='idem_' . $suffix;
$enqueue = http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,
    'command'=>'power_on',
    'payload'=>['source'=>'ci-http'],
    'required_capability'=>'power',
    'risk_level'=>1,
    'ttl_seconds'=>120,
    'idempotency_key'=>$idem,
]);
assert_true($enqueue['status']===201 && ($enqueue['json']['lifecycle_status']??'')==='QUEUED', 'Enqueue falhou', $enqueue);
$commandId=(int)($enqueue['json']['id']??0);
assert_true($commandId>0, 'Enqueue nao retornou command id', $enqueue);

$duplicate = http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,
    'command'=>'power_on',
    'payload'=>['source'=>'ci-http-duplicate'],
    'required_capability'=>'power',
    'risk_level'=>1,
    'ttl_seconds'=>120,
    'idempotency_key'=>$idem,
]);
assert_true($duplicate['status']===200 && !empty($duplicate['json']['duplicate']) && (int)$duplicate['json']['id']===$commandId, 'Idempotencia nao reutilizou comando', $duplicate);

echo "3/9 Entrega -> ACK -> START -> RESULT success...\n";
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

echo "4/9 RESULT failure...\n";
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

echo "5/9 TTL expirado...\n";
$ttlCorr='ttl_' . $suffix;
$pdo->prepare("INSERT INTO device_commands(device_id,comando,payload,prioridade,correlation_id,status,lifecycle_status,max_retries,requested_by,risk_level,expira_em)
    VALUES(:d,'expired_test',JSON_OBJECT(),'normal',:c,'pending','QUEUED',3,'ci-http',1,DATE_SUB(NOW(),INTERVAL 2 SECOND))")
    ->execute([':d'=>$deviceId,':c'=>$ttlCorr]);
$ttlId=(int)$pdo->lastInsertId();
http_json('GET', "{$base}/device.php?acao=commands&device_id=".rawurlencode($deviceId), $deviceToken);
$ttlRow=$pdo->query("SELECT lifecycle_status,status,erro FROM device_commands WHERE id={$ttlId}")->fetch(PDO::FETCH_ASSOC);
assert_true(($ttlRow['lifecycle_status']??'')==='EXPIRED' && ($ttlRow['status']??'')==='expired', 'TTL nao expirou comando', $ttlRow);

echo "6/9 Capability invalida...\n";
$badCap=http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,'command'=>'invalid_cap_test','required_capability'=>'capability_inexistente','risk_level'=>1
]);
assert_true($badCap['status']===404 && ($badCap['json']['mensagem']??'')==='Capability nao encontrada', 'Capability invalida nao foi bloqueada', $badCap);

echo "7/9 Risco 3/4 exige confirmacao...\n";
$riskBlocked=http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,'command'=>'dangerous_test','required_capability'=>'dangerous_power','risk_level'=>4
]);
assert_true($riskBlocked['status']===409 && ($riskBlocked['json']['status']??'')==='confirmacao_necessaria', 'Risco alto sem confirmacao nao foi bloqueado', $riskBlocked);

$riskAllowed=http_json('POST', "{$base}/control.php?acao=enqueue", $clientToken, [
    'device_id'=>$deviceId,'command'=>'dangerous_test','required_capability'=>'dangerous_power','risk_level'=>4,'confirm'=>true
]);
assert_true($riskAllowed['status']===201 && (int)($riskAllowed['json']['risk_level']??0)===4, 'Risco alto confirmado nao foi aceito', $riskAllowed);

echo "8/9 Evento dispara regra permitida...\n";
$trigger=json_encode(['event_type'=>'ci.motion','device_id'=>$deviceId], JSON_UNESCAPED_SLASHES);
$conditions=json_encode([['field'=>'data.active','op'=>'eq','value'=>true]], JSON_UNESCAPED_SLASHES);
$pdo->prepare("INSERT INTO automation_rules(slug,nome,descricao,enabled,trigger_type,trigger_config,conditions_json,cooldown_seconds,last_triggered_at)
    VALUES(:s,'CI HTTP Event Rule','Regra E2E',1,'event',:t,:c,0,NULL)")
    ->execute([':s'=>$ruleSlug,':t'=>$trigger,':c'=>$conditions]);
$ruleId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO automation_rule_actions(rule_id,ordem,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds,enabled)
    VALUES(:r,10,:d,'power_on',JSON_OBJECT('source','event-rule'),'normal','power',1,120,1)")
    ->execute([':r'=>$ruleId,':d'=>$deviceId]);

$event=http_json('POST', "{$base}/device.php?acao=event", $deviceToken, [
    'device_id'=>$deviceId,
    'type'=>'ci.motion',
    'priority'=>'normal',
    'correlation_id'=>'event_' . $suffix,
    'data'=>['active'=>true],
]);
assert_true($event['status']===200 && (int)($event['json']['event_id']??0)>0, 'Evento nao foi registrado', $event);
$runs=$event['json']['automation_runs']??[];
$matched=false;
foreach($runs as $run){
    if((int)($run['rule_id']??0)===$ruleId && in_array(($run['status']??''),['QUEUED','PARTIAL'],true) && !empty($run['commands'])){
        $matched=true; break;
    }
}
assert_true($matched, 'Evento nao disparou regra permitida', $event);
$ruleStmt=$pdo->prepare("SELECT COUNT(*) FROM device_commands WHERE requested_by=:r");
$ruleStmt->execute([':r'=>'RULE:'.$ruleSlug]);
assert_true((int)$ruleStmt->fetchColumn()>=1, 'Regra de evento nao gerou comando no Command Bus');

echo "9/9 Auditoria de lifecycle...\n";
$audit=$pdo->prepare("SELECT lifecycle_status FROM device_command_audit WHERE command_id=:id ORDER BY id");
$audit->execute([':id'=>$commandId]);
$auditStates=$audit->fetchAll(PDO::FETCH_COLUMN);
foreach(['QUEUED','SENT','ACKNOWLEDGED','EXECUTING','DONE'] as $expected){
    assert_true(in_array($expected,$auditStates,true), "Auditoria sem estado {$expected}", $auditStates);
}

echo "HTTP E2E Control Plane OK: heartbeat, enqueue, delivery, ACK, start, success/failure, TTL, idempotency, capability, risk e event-rule validados.\n";
