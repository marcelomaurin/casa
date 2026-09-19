<?php
// CASA/JARVIS - consulta de progresso de planos e tarefas.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once(__DIR__.'/../db.php');
require_once(__DIR__.'/security_v1.php');
require_once(__DIR__.'/../task_engine.php');

$pdo=get_db_pdo();
api_v1_basic_guard($pdo);
$client=api_v1_auth_client_any($pdo,['home.read','mobile.read','devices.read']);
$action=$_GET['acao']??'status';

if($action==='status'){
    $planId=(int)($_GET['id_plano']??0);
    if($planId<=0)api_v1_json_response(400,['status'=>'erro','mensagem'=>'id_plano obrigatorio']);
    $st=$pdo->prepare("SELECT id FROM jarvis_tarefas WHERE id_plano=:p ORDER BY ordem,id LIMIT 1");
    $st->execute([':p'=>$planId]);
    $root=(int)$st->fetchColumn();
    if($root<=0)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Plano nao encontrado']);
    $ctx=['id_plano'=>$planId,'id_tarefa_raiz'=>$root,'current_task_id'=>$root,'origem'=>'API','modulo'=>'tasks'];
    api_v1_json_response(200,['status'=>'ok','task_status'=>te_plan_snapshot($pdo,$ctx)]);
}

if($action==='recent'){
    $limit=max(1,min(50,(int)($_GET['limit']??20)));
    $rows=$pdo->query("SELECT id,demanda_original,origem,status,resumo,criado_em,concluido_em
        FROM jarvis_planos ORDER BY id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
    api_v1_json_response(200,['status'=>'ok','plans'=>$rows,'client'=>$client['nome']??null]);
}

api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida','acoes'=>['status','recent']]);
