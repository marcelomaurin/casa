<?php
// CASA/JARVIS - API de regras deterministicas. Nao usa IA.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once(__DIR__.'/../db.php');
require_once(__DIR__.'/security_v1.php');
require_once(__DIR__.'/rules_engine.php');
$pdo=get_db_pdo(); api_v1_basic_guard($pdo);
$action=$_GET['acao'] ?? 'list';
$write=in_array($action,['save','enable','disable','test_event'],true);
$client=api_v1_auth_client_any($pdo,$write?['home.write','devices.write']:['home.read','devices.read']);
$raw=file_get_contents('php://input'); $in=$raw?json_decode($raw,true):[]; if(!is_array($in))$in=[];

if($action==='list'){
    $stmt=$pdo->query("SELECT r.id,r.slug,r.nome,r.descricao,r.enabled,r.trigger_type,r.trigger_config,r.conditions_json,r.cooldown_seconds,r.last_triggered_at,r.criado_em,r.atualizado_em,COUNT(a.id) AS actions_total FROM automation_rules r LEFT JOIN automation_rule_actions a ON a.rule_id=r.id AND a.enabled=1 GROUP BY r.id ORDER BY r.nome");
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$r){$r['enabled']=(bool)$r['enabled'];$r['trigger_config']=rules_json($r['trigger_config'],[]);$r['conditions']=rules_json($r['conditions_json'],[]);unset($r['conditions_json']);$r['actions_total']=(int)$r['actions_total'];}
    api_v1_json_response(200,['status'=>'ok','rules'=>$rows]);
}

if($action==='detail'){
    $id=(int)($_GET['id']??0); if($id<=0)api_v1_json_response(400,['status'=>'erro','mensagem'=>'id invalido']);
    $stmt=$pdo->prepare("SELECT * FROM automation_rules WHERE id=:id");$stmt->execute([':id'=>$id]);$rule=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$rule)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Regra nao encontrada']);
    $rule['enabled']=(bool)$rule['enabled'];$rule['trigger_config']=rules_json($rule['trigger_config'],[]);$rule['conditions']=rules_json($rule['conditions_json'],[]);unset($rule['conditions_json']);
    $stmt=$pdo->prepare("SELECT id,ordem,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds,enabled FROM automation_rule_actions WHERE rule_id=:r ORDER BY ordem,id");$stmt->execute([':r'=>$id]);$actions=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($actions as &$a){$a['payload']=rules_json($a['payload'],[]);$a['enabled']=(bool)$a['enabled'];$a['risk_level']=(int)$a['risk_level'];}
    $rule['actions']=$actions;api_v1_json_response(200,['status'=>'ok','rule'=>$rule]);
}

if($action==='save'){
    $id=(int)($in['id']??0);$name=trim((string)($in['name']??$in['nome']??''));if($name==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'name obrigatorio']);
    $slug=strtolower(trim((string)($in['slug']??$name)));$slug=preg_replace('/[^a-z0-9\-]+/','-',iconv('UTF-8','ASCII//TRANSLIT',$slug)?:$slug);$slug=trim($slug,'-');
    $triggerType=strtolower((string)($in['trigger_type']??'event'));if(!in_array($triggerType,['event','state','schedule'],true))$triggerType='event';
    $trigger=is_array($in['trigger_config']??null)?$in['trigger_config']:[];$conditions=is_array($in['conditions']??null)?$in['conditions']:[];$cooldown=max(0,min(86400,(int)($in['cooldown_seconds']??0)));$enabled=(int)(bool)($in['enabled']??true);$desc=trim((string)($in['description']??$in['descricao']??''));
    if($id>0){$stmt=$pdo->prepare("UPDATE automation_rules SET slug=:s,nome=:n,descricao=:d,enabled=:e,trigger_type=:t,trigger_config=:tc,conditions_json=:c,cooldown_seconds=:cd WHERE id=:id");$stmt->execute([':s'=>$slug,':n'=>$name,':d'=>$desc,':e'=>$enabled,':t'=>$triggerType,':tc'=>json_encode($trigger,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':c'=>json_encode($conditions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':cd'=>$cooldown,':id'=>$id]);if($stmt->rowCount()===0){$chk=$pdo->prepare('SELECT id FROM automation_rules WHERE id=:id');$chk->execute([':id'=>$id]);if(!$chk->fetchColumn())api_v1_json_response(404,['status'=>'erro','mensagem'=>'Regra nao encontrada']);}}
    else{$stmt=$pdo->prepare("INSERT INTO automation_rules(slug,nome,descricao,enabled,trigger_type,trigger_config,conditions_json,cooldown_seconds) VALUES(:s,:n,:d,:e,:t,:tc,:c,:cd)");try{$stmt->execute([':s'=>$slug,':n'=>$name,':d'=>$desc,':e'=>$enabled,':t'=>$triggerType,':tc'=>json_encode($trigger,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':c'=>json_encode($conditions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':cd'=>$cooldown]);}catch(Throwable $e){api_v1_json_response(409,['status'=>'erro','mensagem'=>'Slug ja existe']);}$id=(int)$pdo->lastInsertId();}
    if(isset($in['actions'])&&is_array($in['actions'])){$pdo->beginTransaction();try{$pdo->prepare('DELETE FROM automation_rule_actions WHERE rule_id=:r')->execute([':r'=>$id]);$ins=$pdo->prepare("INSERT INTO automation_rule_actions(rule_id,ordem,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds,enabled) VALUES(:r,:o,:d,:c,:p,:pr,:cap,:risk,:ttl,:en)");$order=10;foreach($in['actions'] as $a){if(!is_array($a))continue;$dev=trim((string)($a['device_id']??''));$cmd=substr(trim((string)($a['command']??$a['comando']??'')),0,120);if($dev===''||$cmd==='')continue;$pr=strtolower((string)($a['priority']??'normal'));if(!in_array($pr,['low','normal','high','critical'],true))$pr='normal';$ins->execute([':r'=>$id,':o'=>(int)($a['order']??$order),':d'=>$dev,':c'=>$cmd,':p'=>json_encode(is_array($a['payload']??null)?$a['payload']:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':pr'=>$pr,':cap'=>substr(trim((string)($a['required_capability']??'')),0,120)?:null,':risk'=>max(0,min(4,(int)($a['risk_level']??1))),':ttl'=>max(10,min(86400,(int)($a['ttl_seconds']??300))),':en'=>(int)(bool)($a['enabled']??true)]);$order+=10;}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
    api_v1_log($pdo,'AUTOMATION_RULE_SAVED','INFO',$client['nome']??null,['rule_id'=>$id,'slug'=>$slug]);api_v1_json_response(200,['status'=>'ok','rule_id'=>$id,'slug'=>$slug]);
}

if($action==='enable'||$action==='disable'){$id=(int)($in['id']??$_GET['id']??0);if($id<=0)api_v1_json_response(400,['status'=>'erro','mensagem'=>'id invalido']);$enabled=$action==='enable'?1:0;$stmt=$pdo->prepare('UPDATE automation_rules SET enabled=:e WHERE id=:id');$stmt->execute([':e'=>$enabled,':id'=>$id]);api_v1_json_response(200,['status'=>'ok','updated'=>$stmt->rowCount(),'enabled'=>(bool)$enabled]);}

if($action==='test_event'){
    $event=['id'=>null,'device_id'=>(string)($in['device_id']??''),'type'=>(string)($in['type']??'manual.test'),'data'=>is_array($in['data']??null)?$in['data']:[]];
    $runs=rules_engine_process_event($pdo,$event);api_v1_json_response(200,['status'=>'ok','runs'=>$runs]);
}

api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida','acoes'=>['list','detail','save','enable','disable','test_event']]);
