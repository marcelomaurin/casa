<?php
// CASA/JARVIS - API de Cenas e Rotinas.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Idempotency-Key');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once(__DIR__.'/../db.php');
require_once(__DIR__.'/security_v1.php');
$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
$action = $_GET['acao'] ?? 'list';
$write = in_array($action, ['execute','create','update'], true);
$client = api_v1_auth_client_any($pdo, $write ? ['home.write','devices.write','mobile.write'] : ['home.read','devices.read','mobile.read']);
$raw = file_get_contents('php://input');
$in = $raw ? json_decode($raw, true) : [];
if (!is_array($in)) $in = [];

function scene_json($v, $fallback = []) {
    if (is_array($v)) return $v;
    if (!is_string($v) || $v === '') return $fallback;
    $d = json_decode($v, true);
    return is_array($d) ? $d : $fallback;
}

function scene_load(PDO $pdo, $id, $slug = '') {
    if ((int)$id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM scenes WHERE id=:id LIMIT 1');
        $stmt->execute([':id'=>(int)$id]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM scenes WHERE slug=:slug LIMIT 1');
        $stmt->execute([':slug'=>$slug]);
    }
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function scene_actions(PDO $pdo, $sceneId) {
    $stmt = $pdo->prepare("SELECT id,ordem,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds,enabled FROM scene_actions WHERE scene_id=:s ORDER BY ordem,id");
    $stmt->execute([':s'=>(int)$sceneId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['payload'] = scene_json($r['payload'] ?? null, []);
        $r['enabled'] = (bool)$r['enabled'];
        $r['risk_level'] = (int)$r['risk_level'];
        $r['ttl_seconds'] = (int)$r['ttl_seconds'];
    }
    return $rows;
}

if ($action === 'list') {
    $stmt = $pdo->query("SELECT s.id,s.slug,s.nome,s.descricao,s.ativo,s.stop_on_error,s.risk_level,s.criado_em,s.atualizado_em,COUNT(a.id) AS actions_total FROM scenes s LEFT JOIN scene_actions a ON a.scene_id=s.id AND a.enabled=1 GROUP BY s.id ORDER BY s.nome");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['ativo'] = (bool)$r['ativo'];
        $r['stop_on_error'] = (bool)$r['stop_on_error'];
        $r['risk_level'] = (int)$r['risk_level'];
        $r['actions_total'] = (int)$r['actions_total'];
    }
    api_v1_json_response(200, ['status'=>'ok','scenes'=>$rows,'server_time'=>date('c')]);
}

if ($action === 'detail') {
    $scene = scene_load($pdo, $_GET['id'] ?? 0, trim((string)($_GET['slug'] ?? '')));
    if (!$scene) api_v1_json_response(404, ['status'=>'erro','mensagem'=>'Cena nao encontrada']);
    $scene['ativo'] = (bool)$scene['ativo'];
    $scene['stop_on_error'] = (bool)$scene['stop_on_error'];
    $scene['risk_level'] = (int)$scene['risk_level'];
    $scene['actions'] = scene_actions($pdo, $scene['id']);
    api_v1_json_response(200, ['status'=>'ok','scene'=>$scene]);
}

if ($action === 'create' || $action === 'update') {
    $name = trim((string)($in['name'] ?? $in['nome'] ?? ''));
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    $slug = preg_replace('/[^a-z0-9\-]+/', '-', iconv('UTF-8','ASCII//TRANSLIT',$slug ?: $name) ?: ($slug ?: $name));
    $slug = trim($slug, '-');
    if ($name === '' || $slug === '') api_v1_json_response(400, ['status'=>'erro','mensagem'=>'name/nome obrigatorio']);
    $desc = trim((string)($in['description'] ?? $in['descricao'] ?? ''));
    $active = !array_key_exists('active',$in) && !array_key_exists('ativo',$in) ? 1 : (int)(bool)($in['active'] ?? $in['ativo']);
    $stop = (int)(bool)($in['stop_on_error'] ?? false);
    $risk = max(0, min(4, (int)($in['risk_level'] ?? 1)));

    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO scenes(slug,nome,descricao,ativo,stop_on_error,risk_level) VALUES(:s,:n,:d,:a,:e,:r)");
        try { $stmt->execute([':s'=>$slug,':n'=>$name,':d'=>$desc,':a'=>$active,':e'=>$stop,':r'=>$risk]); }
        catch(Throwable $e) { api_v1_json_response(409,['status'=>'erro','mensagem'=>'Slug de cena ja existe']); }
        $sceneId = (int)$pdo->lastInsertId();
    } else {
        $sceneId = (int)($in['id'] ?? 0);
        if ($sceneId <= 0) api_v1_json_response(400,['status'=>'erro','mensagem'=>'id obrigatorio']);
        $stmt=$pdo->prepare("UPDATE scenes SET slug=:s,nome=:n,descricao=:d,ativo=:a,stop_on_error=:e,risk_level=:r WHERE id=:id");
        $stmt->execute([':s'=>$slug,':n'=>$name,':d'=>$desc,':a'=>$active,':e'=>$stop,':r'=>$risk,':id'=>$sceneId]);
        if ($stmt->rowCount()===0 && !scene_load($pdo,$sceneId)) api_v1_json_response(404,['status'=>'erro','mensagem'=>'Cena nao encontrada']);
    }

    if (isset($in['actions']) && is_array($in['actions'])) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM scene_actions WHERE scene_id=:s')->execute([':s'=>$sceneId]);
            $ins=$pdo->prepare("INSERT INTO scene_actions(scene_id,ordem,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds,enabled) VALUES(:s,:o,:d,:c,:p,:pr,:cap,:r,:ttl,:en)");
            $order=10;
            foreach($in['actions'] as $a){
                if(!is_array($a)) continue;
                $dev=trim((string)($a['device_id']??'')); $cmd=substr(trim((string)($a['command']??$a['comando']??'')),0,120);
                if($dev===''||$cmd==='') continue;
                $priority=strtolower((string)($a['priority']??$a['prioridade']??'normal'));
                if(!in_array($priority,['low','normal','high','critical'],true))$priority='normal';
                $cap=substr(trim((string)($a['required_capability']??'')),0,120) ?: null;
                $arisk=max(0,min(4,(int)($a['risk_level']??1)));
                $ttl=max(10,min(86400,(int)($a['ttl_seconds']??300)));
                $ins->execute([':s'=>$sceneId,':o'=>(int)($a['order']??$a['ordem']??$order),':d'=>$dev,':c'=>$cmd,':p'=>json_encode(is_array($a['payload']??null)?$a['payload']:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':pr'=>$priority,':cap'=>$cap,':r'=>$arisk,':ttl'=>$ttl,':en'=>(int)(bool)($a['enabled']??true)]);
                $order += 10;
            }
            $pdo->commit();
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    api_v1_log($pdo,'SCENE_SAVED','INFO',$client['nome']??null,['scene_id'=>$sceneId,'slug'=>$slug]);
    api_v1_json_response($action==='create'?201:200,['status'=>'ok','scene_id'=>$sceneId,'slug'=>$slug]);
}

if ($action === 'execute') {
    $scene = scene_load($pdo, $in['id'] ?? 0, trim((string)($in['slug'] ?? '')));
    if (!$scene) api_v1_json_response(404,['status'=>'erro','mensagem'=>'Cena nao encontrada']);
    if (!(bool)$scene['ativo']) api_v1_json_response(409,['status'=>'erro','mensagem'=>'Cena desabilitada']);
    $actions = array_values(array_filter(scene_actions($pdo,$scene['id']), fn($a)=>$a['enabled']));
    if (!$actions) api_v1_json_response(409,['status'=>'erro','mensagem'=>'Cena sem acoes habilitadas']);
    $maxRisk=(int)$scene['risk_level']; foreach($actions as $a)$maxRisk=max($maxRisk,(int)$a['risk_level']);
    if ($maxRisk>=3 && empty($in['confirm'])) api_v1_json_response(409,['status'=>'confirmacao_necessaria','risk_level'=>$maxRisk,'mensagem'=>'Cena contem acao sensivel; envie confirm=true']);

    $requestedBy=substr((string)($client['nome']??'api-client'),0,160);
    $runCorr='scene_'.bin2hex(random_bytes(12));
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare("INSERT INTO scene_runs(scene_id,requested_by,status,correlation_id,iniciado_em) VALUES(:s,:r,'QUEUED',:c,NOW())");
        $stmt->execute([':s'=>$scene['id'],':r'=>$requestedBy,':c'=>$runCorr]);
        $runId=(int)$pdo->lastInsertId();
        $queued=[]; $errors=[];
        foreach($actions as $a){
            $devStmt=$pdo->prepare("SELECT device_id FROM dispositivos_cluster WHERE device_id=:d AND credential_revoked_at IS NULL LIMIT 1");
            $devStmt->execute([':d'=>$a['device_id']]);
            if(!$devStmt->fetchColumn()){
                $errors[]=['action_id'=>$a['id'],'device_id'=>$a['device_id'],'error'=>'device_not_found'];
                $pdo->prepare("INSERT INTO scene_run_actions(run_id,scene_action_id,device_id,comando,status,erro) VALUES(:r,:a,:d,:c,'FAILED','Device nao encontrado ou revogado')")->execute([':r'=>$runId,':a'=>$a['id'],':d'=>$a['device_id'],':c'=>$a['comando']]);
                if((bool)$scene['stop_on_error'])break; else continue;
            }
            if(!empty($a['required_capability'])){
                $capStmt=$pdo->prepare("SELECT enabled,risk_level FROM device_capabilities WHERE device_id=:d AND capability=:c LIMIT 1");
                $capStmt->execute([':d'=>$a['device_id'],':c'=>$a['required_capability']]); $cap=$capStmt->fetch(PDO::FETCH_ASSOC);
                if($cap && !(bool)$cap['enabled']){
                    $errors[]=['action_id'=>$a['id'],'device_id'=>$a['device_id'],'error'=>'capability_disabled'];
                    $pdo->prepare("INSERT INTO scene_run_actions(run_id,scene_action_id,device_id,comando,status,erro) VALUES(:r,:a,:d,:c,'FAILED','Capability desabilitada')")->execute([':r'=>$runId,':a'=>$a['id'],':d'=>$a['device_id'],':c'=>$a['comando']]);
                    if((bool)$scene['stop_on_error'])break; else continue;
                }
            }
            $corr='cmd_'.bin2hex(random_bytes(12));
            $idem='scene_'.$runId.'_action_'.$a['id'];
            $cmd=$pdo->prepare("INSERT INTO device_commands(device_id,comando,payload,prioridade,correlation_id,idempotency_key,status,lifecycle_status,max_retries,requested_by,risk_level,expira_em) VALUES(:d,:c,:p,:pr,:x,:i,'pending','QUEUED',3,:rb,:risk,DATE_ADD(NOW(),INTERVAL :ttl SECOND))");
            $cmd->bindValue(':d',$a['device_id']); $cmd->bindValue(':c',$a['comando']); $cmd->bindValue(':p',json_encode($a['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); $cmd->bindValue(':pr',$a['prioridade']); $cmd->bindValue(':x',$corr); $cmd->bindValue(':i',$idem); $cmd->bindValue(':rb',$requestedBy); $cmd->bindValue(':risk',(int)$a['risk_level'],PDO::PARAM_INT); $cmd->bindValue(':ttl',(int)$a['ttl_seconds'],PDO::PARAM_INT); $cmd->execute();
            $commandId=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO scene_run_actions(run_id,scene_action_id,command_id,device_id,comando,status) VALUES(:r,:a,:cmd,:d,:c,'QUEUED')")->execute([':r'=>$runId,':a'=>$a['id'],':cmd'=>$commandId,':d'=>$a['device_id'],':c'=>$a['comando']]);
            $queued[]=['action_id'=>(int)$a['id'],'command_id'=>$commandId,'device_id'=>$a['device_id'],'correlation_id'=>$corr];
        }
        $status=$errors ? ($queued ? 'PARTIAL' : 'FAILED') : 'QUEUED';
        $summary=['queued'=>$queued,'errors'=>$errors,'risk_level'=>$maxRisk];
        $pdo->prepare("UPDATE scene_runs SET status=:s,resumo=:j,concluido_em=CASE WHEN :terminal=1 THEN NOW() ELSE NULL END WHERE id=:id")->execute([':s'=>$status,':j'=>json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':terminal'=>$status==='FAILED'?1:0,':id'=>$runId]);
        $pdo->commit();
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); api_v1_json_response(500,['status'=>'erro','mensagem'=>'Falha ao enfileirar cena']); }
    api_v1_log($pdo,'SCENE_EXECUTED','INFO',$requestedBy,['scene_id'=>$scene['id'],'run_id'=>$runId,'queued'=>count($queued),'errors'=>count($errors)]);
    api_v1_json_response(202,['status'=>'ok','scene'=>['id'=>(int)$scene['id'],'slug'=>$scene['slug'],'name'=>$scene['nome']],'run_id'=>$runId,'correlation_id'=>$runCorr,'run_status'=>$status,'queued'=>$queued,'errors'=>$errors]);
}

if ($action === 'run_status') {
    $id=(int)($_GET['id']??0); if($id<=0)api_v1_json_response(400,['status'=>'erro','mensagem'=>'id invalido']);
    $stmt=$pdo->prepare("SELECT r.id,r.scene_id,s.slug,s.nome,r.requested_by,r.status,r.correlation_id,r.resumo,r.criado_em,r.iniciado_em,r.concluido_em FROM scene_runs r JOIN scenes s ON s.id=r.scene_id WHERE r.id=:id");$stmt->execute([':id'=>$id]);$run=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$run)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Execucao nao encontrada']);
    $run['resumo']=scene_json($run['resumo']??null,[]);
    $stmt=$pdo->prepare("SELECT ra.id,ra.scene_action_id,ra.command_id,ra.device_id,ra.comando,COALESCE(dc.lifecycle_status,ra.status) AS status,COALESCE(dc.erro,ra.erro) AS erro,dc.resultado FROM scene_run_actions ra LEFT JOIN device_commands dc ON dc.id=ra.command_id WHERE ra.run_id=:r ORDER BY ra.id");$stmt->execute([':r'=>$id]);$items=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$it)$it['resultado']=scene_json($it['resultado']??null,[]);
    api_v1_json_response(200,['status'=>'ok','run'=>$run,'actions'=>$items]);
}

api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida','acoes'=>['list','detail','create','update','execute','run_status']]);
