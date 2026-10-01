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
require_once(__DIR__.'/../automation_common.php');
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
    $requestedBy = substr((string)($client['nome'] ?? 'api-client'), 0, 160);
    // Execucao compartilhada com o painel web e agendamentos (api/automation_common.php):
    // comandos liga/desliga para reles viram estado desejado aplicado pelo firmware.
    $r = automation_scene_execute($pdo, $scene, $requestedBy, !empty($in['confirm']));
    if ($r['http'] === 202) api_v1_log($pdo,'SCENE_EXECUTED','INFO',$requestedBy,['scene_id'=>$scene['id'],'run_id'=>$r['body']['run_id'],'queued'=>count($r['body']['queued']),'errors'=>count($r['body']['errors'])]);
    api_v1_json_response($r['http'], $r['body']);
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
