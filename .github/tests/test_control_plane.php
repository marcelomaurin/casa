<?php
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';
require __DIR__ . '/../../site/var/www/html/api/v1/rules_scheduler.php';
require __DIR__ . '/../../site/var/www/html/api/task_engine.php';
$pdo = get_db_pdo();

function fail_test(string $msg): void { fwrite(STDERR, $msg . "\n"); exit(10); }

$deviceId='sim_ci_tv';
$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,health,metadata,ultimo_heartbeat)
VALUES(:d,'TV CI','tv','token_ci','online','ok',JSON_OBJECT('power','off'),NOW())
ON DUPLICATE KEY UPDATE nome=VALUES(nome),status='online',health='ok',ultimo_heartbeat=NOW()")
    ->execute([':d'=>$deviceId]);

$pdo->prepare("INSERT INTO device_capabilities(device_id,capability,enabled,risk_level) VALUES(:d,'power',1,1)
ON DUPLICATE KEY UPDATE enabled=1,risk_level=1")->execute([':d'=>$deviceId]);


$registryDevice=registry_get($pdo,$deviceId,false);
if(!$registryDevice || ($registryDevice['device_id']??'')!==$deviceId) fail_test('Registry nao resolveu device');
$powerCaps=array_values(array_filter($registryDevice['capabilities']??[],fn($x)=>($x['name']??'')==='power'));
if(count($powerCaps)!==1 || empty($powerCaps[0]['enabled'])) fail_test('Registry nao resolveu capability power');
if(!array_key_exists('routing',$registryDevice)) fail_test('Registry nao expos roteamento');

$legacyId='sim_ci_legacy_caps';
$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,health,capabilities,ultimo_heartbeat)
VALUES(:d,'Legacy Caps','sensor','token_legacy_caps','online','ok',JSON_ARRAY('temperature','humidity'),NOW())
ON DUPLICATE KEY UPDATE capabilities=VALUES(capabilities),ultimo_heartbeat=NOW()")->execute([':d'=>$legacyId]);
$legacyRegistry=registry_get($pdo,$legacyId,false);
$legacyNames=array_column($legacyRegistry['capabilities']??[],'name');
if(!in_array('temperature',$legacyNames,true)||!in_array('humidity',$legacyNames,true)) fail_test('Registry nao normalizou capabilities legadas');

$missingCapabilityBlocked=false;
try{registry_require_capability($pdo,$deviceId,'capability_que_nao_existe');}catch(RuntimeException $e){$missingCapabilityBlocked=$e->getMessage()==='capability_not_found';}
if(!$missingCapabilityBlocked) fail_test('Registry permitiu capability inexistente');

echo "Device Registry OK: identidade, capabilities e roteamento validados\n";

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


// Task Engine -> Action -> Command Bus -> Task lifecycle
$pdo->prepare("INSERT INTO jarvis_planos(demanda_original,origem,status,resumo,dados_plano)
VALUES('CI acao device','CI','EM_EXECUCAO','Teste bridge',JSON_OBJECT())")->execute();
$taskPlan=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO jarvis_tarefas(id_plano,ordem,titulo,tipo,executor,payload,status,iniciado_em)
VALUES(:p,1,'Acender TV','IMEDIATA','dispositivo',JSON_OBJECT(),'EXECUTANDO',NOW())")->execute([':p'=>$taskPlan]);
$taskId=(int)$pdo->lastInsertId();
$taskCtx=['id_plano'=>$taskPlan,'id_tarefa_raiz'=>$taskId,'current_task_id'=>$taskId,'origem'=>'CI','modulo'=>'test'];

$bridge=te_device_action($pdo,$taskCtx,$taskId,[
    'device_id'=>$deviceId,
    'command'=>'power_on',
    'payload'=>['source'=>'ci'],
    'required_capability'=>'power',
    'risk_level'=>1,
    'ttl_seconds'=>120
]);
if((int)($bridge['command_id']??0)<=0 || (int)($bridge['action_id']??0)<=0) fail_test('Task bridge nao criou action/command');

$taskStatus=$pdo->query("SELECT status FROM jarvis_tarefas WHERE id={$taskId}")->fetchColumn();
if($taskStatus!=='AGUARDANDO') fail_test('Task nao ficou AGUARDANDO apos enqueue: '.$taskStatus);

$bridgeCmd=(int)$bridge['command_id'];
$pdo->prepare("UPDATE device_commands SET status='executing',lifecycle_status='EXECUTING',iniciado_em=NOW() WHERE id=:id")->execute([':id'=>$bridgeCmd]);
te_sync_device_command($pdo,$bridgeCmd);
$taskStatus=$pdo->query("SELECT status FROM jarvis_tarefas WHERE id={$taskId}")->fetchColumn();
if($taskStatus!=='EXECUTANDO') fail_test('Task nao refletiu EXECUTING: '.$taskStatus);

$pdo->prepare("UPDATE device_commands SET status='success',lifecycle_status='DONE',resultado=JSON_OBJECT('ok',true),concluido_em=NOW() WHERE id=:id")->execute([':id'=>$bridgeCmd]);
te_sync_device_command($pdo,$bridgeCmd);
$taskRow=$pdo->query("SELECT status,resultado,erro FROM jarvis_tarefas WHERE id={$taskId}")->fetch(PDO::FETCH_ASSOC);
if(($taskRow['status']??'')!=='CONCLUIDA') fail_test('Task nao concluiu com device DONE');
$actionRow=$pdo->query("SELECT status,command_id FROM jarvis_acoes WHERE id=".(int)$bridge['action_id'])->fetch(PDO::FETCH_ASSOC);
if(($actionRow['status']??'')!=='DONE' || (int)($actionRow['command_id']??0)!==$bridgeCmd) fail_test('Action nao sincronizou com command DONE');

echo "Task Action Bridge OK: task -> action -> command -> task validado\n";


// Universal task context: nested modules must reuse the same plan/root task.
$ctxRoot=te_begin($pdo,'CI universal task context','CI','root',null);
$plansBefore=(int)$pdo->query("SELECT COUNT(*) FROM jarvis_planos")->fetchColumn();
$ctxNested=te_begin($pdo,'subchamada interna','CI_INTERNAL','nested',te_public_context($ctxRoot));
$plansAfter=(int)$pdo->query("SELECT COUNT(*) FROM jarvis_planos")->fetchColumn();
if($plansBefore!==$plansAfter) fail_test('Subchamada interna criou plano duplicado');
if((int)$ctxNested['id_plano']!==(int)$ctxRoot['id_plano']) fail_test('Contexto interno mudou id_plano');
if((int)$ctxNested['id_tarefa_raiz']!==(int)$ctxRoot['id_tarefa_raiz']) fail_test('Contexto interno mudou tarefa raiz');

$nestedTask=te_add_subtask($pdo,$ctxNested,'Subtarefa interna de teste','ci',['evidence'=>'ok'],null,'EXECUTANDO');
te_complete($pdo,$nestedTask,['evidence'=>'ok']);
$snapshot=te_plan_snapshot($pdo,$ctxRoot);
if((int)($snapshot['counts']['total']??0)<2) fail_test('Snapshot nao listou raiz/subtarefa');
if(!isset($snapshot['progress_pct'])) fail_test('Snapshot nao informou progresso');

$failedTask=te_add_subtask($pdo,$ctxNested,'Subtarefa com falha','ci',['test'=>true],$nestedTask,'EXECUTANDO');
te_fail_with_plan($pdo,$ctxRoot,$failedTask,'falha controlada de CI',['evidence'=>'failure']);
$snapshot=te_plan_snapshot($pdo,$ctxRoot);
if(($snapshot['derived_status']??'')!=='DEGRADADO') fail_test('Falha de subtarefa nao degradou status derivado');
if((int)($snapshot['counts']['erros']??0)<1) fail_test('Falha de subtarefa nao apareceu no snapshot');

te_finish($pdo,$ctxRoot,'CI concluido com evidencias',['nested_task'=>$nestedTask,'failed_task'=>$failedTask]);
$attached=te_attach_context(['status'=>'ok'],$ctxRoot,$pdo);
if((int)($attached['id_plano']??0)!==(int)$ctxRoot['id_plano'] || empty($attached['task_context'])) fail_test('Resposta nao recebeu task_context universal');

echo "Universal Task Context OK: reutilizacao, progresso e falhas validados\n";
