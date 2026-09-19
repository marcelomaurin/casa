<?php
// CASA/JARVIS - rastreamento ponta a ponta por correlation_id.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once(__DIR__.'/../db.php');
require_once(__DIR__.'/security_v1.php');

$pdo=get_db_pdo();
api_v1_basic_guard($pdo);
$client=api_v1_auth_client_any($pdo,['home.read','mobile.read','devices.read']);

$corr=trim((string)($_GET['correlation_id']??''));
if($corr==='' || !preg_match('/^[A-Za-z0-9._:-]{8,80}$/',$corr)){
    api_v1_json_response(400,['status'=>'erro','mensagem'=>'correlation_id invalido']);
}

function trace_rows(PDO $pdo,string $sql,array $params): array {
    try{
        $st=$pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }catch(Throwable $e){
        return [];
    }
}

$plans=trace_rows($pdo,
    "SELECT id,correlation_id,demanda_original,origem,status,resumo,criado_em,concluido_em
     FROM jarvis_planos WHERE correlation_id=:c ORDER BY id",
    [':c'=>$corr]);

$tasks=trace_rows($pdo,
    "SELECT id,id_plano,correlation_id,ordem,titulo,tipo,executor,status,depende_de,tarefa_pai_id,erro,criado_em,iniciado_em,concluido_em
     FROM jarvis_tarefas WHERE correlation_id=:c ORDER BY id_plano,ordem,id",
    [':c'=>$corr]);

$actions=trace_rows($pdo,
    "SELECT id,id_plano,id_tarefa,tipo,device_id,comando,required_capability,prioridade,risk_level,
            correlation_id,command_id,status,erro,criado_em,iniciado_em,concluido_em
     FROM jarvis_acoes WHERE correlation_id=:c ORDER BY id",
    [':c'=>$corr]);

$commands=trace_rows($pdo,
    "SELECT id,device_id,comando,prioridade,correlation_id,status,lifecycle_status,retry_count,max_retries,
            requested_by,risk_level,criado_em,expira_em,entregue_em,ack_em,iniciado_em,concluido_em,erro
     FROM device_commands WHERE correlation_id=:c ORDER BY id",
    [':c'=>$corr]);

$commandAudit=trace_rows($pdo,
    "SELECT id,command_id,correlation_id,device_id,event_type,lifecycle_status,actor,details,criado_em
     FROM device_command_audit WHERE correlation_id=:c ORDER BY id",
    [':c'=>$corr]);

$events=trace_rows($pdo,
    "SELECT id,device_id,tipo,prioridade,correlation_id,dados,observado_ip,criado_em,processado,processado_em
     FROM device_events WHERE correlation_id=:c ORDER BY id",
    [':c'=>$corr]);

$telemetry=trace_rows($pdo,
    "SELECT id,correlation_id,data_hora,finalizado_em,origem,canal,ip_cliente,operacao,solicitacao,
            resposta_ia,acao_executada,status,modelo,duracao_ms,detalhes
     FROM telemetria_operacional WHERE correlation_id=:c ORDER BY id",
    [':c'=>$corr]);

$security=trace_rows($pdo,
    "SELECT id,correlation_id,data_hora,ip,cliente,rota,metodo,evento,severidade,detalhes
     FROM api_v1_security_log WHERE correlation_id=:c ORDER BY id",
    [':c'=>$corr]);

$ruleRuns=trace_rows($pdo,
    "SELECT id,rule_id,source_event_id,correlation_id,status,details,criado_em,concluido_em
     FROM automation_rule_runs WHERE correlation_id=:c ORDER BY id",
    [':c'=>$corr]);

$sceneRuns=trace_rows($pdo,
    "SELECT id,scene_id,requested_by,status,correlation_id,resumo,criado_em,iniciado_em,concluido_em
     FROM scene_runs WHERE correlation_id=:c ORDER BY id",
    [':c'=>$corr]);

foreach([$commandAudit,$events,$telemetry,$security,$ruleRuns,$sceneRuns] as &$group){
    foreach($group as &$row)$row=api_v1_redact($row);
    unset($row);
}
unset($group);

$stages=[];
foreach($plans as $x)$stages[]=['stage'=>'plan','id'=>(int)$x['id'],'status'=>$x['status'],'time'=>$x['criado_em']];
foreach($tasks as $x)$stages[]=['stage'=>'task','id'=>(int)$x['id'],'status'=>$x['status'],'time'=>$x['criado_em'],'error'=>$x['erro']];
foreach($actions as $x)$stages[]=['stage'=>'action','id'=>(int)$x['id'],'status'=>$x['status'],'time'=>$x['criado_em'],'error'=>$x['erro']];
foreach($commands as $x)$stages[]=['stage'=>'command','id'=>(int)$x['id'],'status'=>$x['lifecycle_status'],'time'=>$x['criado_em'],'error'=>$x['erro']];
foreach($events as $x)$stages[]=['stage'=>'event','id'=>(int)$x['id'],'status'=>$x['tipo'],'time'=>$x['criado_em']];
foreach($telemetry as $x)$stages[]=['stage'=>'telemetry','id'=>(int)$x['id'],'status'=>$x['status'],'time'=>$x['data_hora']];
foreach($security as $x)$stages[]=['stage'=>'security','id'=>(int)$x['id'],'status'=>$x['evento'],'time'=>$x['data_hora']];

usort($stages,function($a,$b){
    $ta=strtotime((string)($a['time']??''))?:0;
    $tb=strtotime((string)($b['time']??''))?:0;
    if($ta===$tb)return strcmp($a['stage'].sprintf('%012d',$a['id']),$b['stage'].sprintf('%012d',$b['id']));
    return $ta<=>$tb;
});

$errors=array_values(array_filter($stages,fn($s)=>!empty($s['error']) || in_array(strtoupper((string)$s['status']),['ERRO','FAILED','EXPIRED','BLOQUEADO'],true)));

api_v1_json_response(200,[
    'status'=>'ok',
    'correlation_id'=>$corr,
    'client'=>$client['nome']??null,
    'summary'=>[
        'plans'=>count($plans),
        'tasks'=>count($tasks),
        'actions'=>count($actions),
        'commands'=>count($commands),
        'events'=>count($events),
        'telemetry'=>count($telemetry),
        'security_logs'=>count($security),
        'rule_runs'=>count($ruleRuns),
        'scene_runs'=>count($sceneRuns),
        'errors'=>count($errors)
    ],
    'timeline'=>$stages,
    'errors'=>$errors,
    'plan'=>$plans,
    'tasks'=>$tasks,
    'actions'=>$actions,
    'commands'=>$commands,
    'command_audit'=>$commandAudit,
    'events'=>$events,
    'telemetry'=>$telemetry,
    'security'=>$security,
    'rule_runs'=>$ruleRuns,
    'scene_runs'=>$sceneRuns
]);
