<?php
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';
require __DIR__ . '/../../site/var/www/html/api/v1/rules_scheduler.php';
$pdo = get_db_pdo();

function fail_test(string $msg): void { fwrite(STDERR, $msg . "\n"); exit(10); }

$deviceId='sim_ci_tv';
$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,health,metadata,ultimo_heartbeat)
VALUES(:d,'TV CI','tv','token_ci','online','ok',JSON_OBJECT('power','off'),NOW())
ON DUPLICATE KEY UPDATE nome=VALUES(nome),status='online',health='ok',ultimo_heartbeat=NOW()")
    ->execute([':d'=>$deviceId]);

$pdo->prepare("INSERT INTO device_capabilities(device_id,capability,enabled,risk_level) VALUES(:d,'power',1,1)
ON DUPLICATE KEY UPDATE enabled=1,risk_level=1")->execute([':d'=>$deviceId]);

$corr='ci_'.bin2hex(random_bytes(6));
$idem='idem_'.bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO device_commands(device_id,comando,payload,prioridade,correlation_id,idempotency_key,status,lifecycle_status,max_retries,requested_by,risk_level,expira_em)
VALUES(:d,'power_on',JSON_OBJECT(),'normal',:c,:i,'pending','QUEUED',3,'ci-test',3,DATE_ADD(NOW(),INTERVAL 5 MINUTE))")
    ->execute([':d'=>$deviceId,':c'=>$corr,':i'=>$idem]);
$commandId=(int)$pdo->lastInsertId();
if($commandId<=0) fail_test('Comando nao foi criado');

$row=$pdo->query("SELECT authorized_client,confirmed_by,confirmed_at FROM device_commands WHERE id={$commandId}")->fetch(PDO::FETCH_ASSOC);
if(($row['authorized_client']??'')!=='ci-test') fail_test('authorized_client nao foi registrado');
if(($row['confirmed_by']??'')!=='ci-test' || empty($row['confirmed_at'])) fail_test('confirmacao de risco nao foi auditada');

$states=['SENT','ACKNOWLEDGED','EXECUTING','DONE'];
foreach($states as $state){
    $legacy=match($state){'SENT'=>'pending','ACKNOWLEDGED'=>'ack','EXECUTING'=>'executing','DONE'=>'success',default=>'pending'};
    $pdo->prepare("UPDATE device_commands SET lifecycle_status=:s,status=:l WHERE id=:id")->execute([':s'=>$state,':l'=>$legacy,':id'=>$commandId]);
}
$auditCount=(int)$pdo->query("SELECT COUNT(*) FROM device_command_audit WHERE command_id={$commandId}")->fetchColumn();
if($auditCount<5) fail_test('Auditoria do lifecycle incompleta: '.$auditCount);

$duplicateBlocked=false;
try{
    $pdo->prepare("INSERT INTO device_commands(device_id,comando,payload,prioridade,correlation_id,idempotency_key,status,lifecycle_status,requested_by,risk_level) VALUES(:d,'power_on',JSON_OBJECT(),'normal',:c,:i,'pending','QUEUED','ci-test',1)")
        ->execute([':d'=>$deviceId,':c'=>'ci_dup_'.bin2hex(random_bytes(4)),':i'=>$idem]);
}catch(Throwable $e){$duplicateBlocked=true;}
if(!$duplicateBlocked) fail_test('Idempotencia nao bloqueou duplicata');

$slug='ci-schedule-rule';
$trigger=json_encode(['hour'=>(int)date('G'),'minute'=>(int)date('i'),'days'=>[(int)date('N')]],JSON_UNESCAPED_SLASHES);
$pdo->prepare("INSERT INTO automation_rules(slug,nome,descricao,enabled,trigger_type,trigger_config,conditions_json,cooldown_seconds,last_triggered_at)
VALUES(:s,'CI Schedule','Regra de teste',1,'schedule',:t,JSON_ARRAY(),0,NULL)
ON DUPLICATE KEY UPDATE enabled=1,trigger_type='schedule',trigger_config=VALUES(trigger_config),conditions_json=JSON_ARRAY(),cooldown_seconds=0,last_triggered_at=NULL")
    ->execute([':s'=>$slug,':t'=>$trigger]);
$ruleId=(int)$pdo->query("SELECT id FROM automation_rules WHERE slug='ci-schedule-rule'")->fetchColumn();
$pdo->prepare("DELETE FROM automation_rule_actions WHERE rule_id=:r")->execute([':r'=>$ruleId]);
$pdo->prepare("INSERT INTO automation_rule_actions(rule_id,ordem,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds,enabled)
VALUES(:r,10,:d,'power_on',JSON_OBJECT(),'normal','power',1,300,1)")
    ->execute([':r'=>$ruleId,':d'=>$deviceId]);

$runs=rules_scheduler_tick($pdo,true);
$matched=false;
foreach($runs as $run){if((int)($run['rule_id']??0)===$ruleId && in_array($run['status']??'', ['QUEUED','PARTIAL'],true)){$matched=true;break;}}
if(!$matched) fail_test('Regra agendada nao disparou');

$ruleCommand=(int)$pdo->query("SELECT COUNT(*) FROM device_commands WHERE requested_by='RULE:ci-schedule-rule'")->fetchColumn();
if($ruleCommand<1) fail_test('Regra nao gerou comando no Command Bus');

echo "Control Plane OK: lifecycle, idempotencia, auditoria e schedule validados\n";
