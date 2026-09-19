<?php
// CASA/JARVIS - lado controlador do Command Bus. Usado por Mobile/Site autorizados.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Idempotency-Key');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='OPTIONS'){http_response_code(200);exit;}
require_once(__DIR__.'/../db.php');
require_once(__DIR__.'/security_v1.php');
require_once(__DIR__.'/device_registry.php');
$pdo=get_db_pdo(); api_v1_basic_guard($pdo);
$action=$_GET['acao'] ?? 'devices';
$read=in_array($action,['devices','device','capabilities','command_status','events'],true);
$client=api_v1_auth_client_any($pdo,$read?['devices.read','mobile.read','home.read']:['devices.write','mobile.write','home.write']);
$raw=file_get_contents('php://input'); $in=$raw?json_decode($raw,true):[]; if(!is_array($in))$in=[];

if($action==='devices'){
 $devices=registry_list($pdo,false);
 api_v1_json_response(200,['status'=>'ok','devices'=>$devices,'server_time'=>date('c'),'registry'=>'dispositivos_cluster']);
}
if($action==='device'){
 $deviceId=trim((string)($_GET['device_id']??$in['device_id']??''));
 if($deviceId==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'device_id obrigatorio']);
 $dev=registry_get($pdo,$deviceId,false);
 if(!$dev)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Device nao encontrado']);
 api_v1_json_response(200,['status'=>'ok','device'=>$dev,'server_time'=>date('c')]);
}
if($action==='capabilities'){
 $deviceId=trim((string)($_GET['device_id']??$in['device_id']??''));
 if($deviceId==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'device_id obrigatorio']);
 $dev=registry_get($pdo,$deviceId,false);
 if(!$dev)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Device nao encontrado']);
 api_v1_json_response(200,['status'=>'ok','device_id'=>$deviceId,'capabilities'=>$dev['capabilities']]);
}
if($action==='enqueue'){
 $deviceId=trim((string)($in['device_id']??'')); $cmd=substr(trim((string)($in['command']??'')),0,120);
 if($deviceId===''||$cmd==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'device_id e command obrigatorios']);
 try{$dev=registry_require($pdo,$deviceId);}catch(Throwable $e){api_v1_json_response(404,['status'=>'erro','mensagem'=>'Device nao encontrado ou revogado']);}
 $priority=strtolower((string)($in['priority']??'normal'));if(!in_array($priority,['low','normal','high','critical'],true))$priority='normal';
 $ttl=max(10,min(86400,(int)($in['ttl_seconds']??300)));
 $maxRetries=max(1,min(20,(int)($in['max_retries']??3)));
 $requestedBy=substr((string)($client['nome']??'api-client'),0,160);
 $idem=trim((string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY']??$in['idempotency_key']??'')); $idem=$idem!==''?substr($idem,0,120):null;
 if($idem!==null){
  $stmt=$pdo->prepare("SELECT id,correlation_id,status,lifecycle_status FROM device_commands WHERE device_id=:d AND idempotency_key=:k LIMIT 1");$stmt->execute([':d'=>$deviceId,':k'=>$idem]);$old=$stmt->fetch(PDO::FETCH_ASSOC);
  if($old)api_v1_json_response(200,['status'=>'ok','duplicate'=>true,'id'=>(int)$old['id'],'correlation_id'=>$old['correlation_id'],'command_status'=>$old['status'],'lifecycle_status'=>$old['lifecycle_status']]);
 }
 $requiredCapability=substr(trim((string)($in['required_capability']??'')),0,120);
 $riskLevel=max(0,min(4,(int)($in['risk_level']??1)));
 if($requiredCapability!==''){
  try{$resolved=registry_require_capability($pdo,$deviceId,$requiredCapability);$riskLevel=max($riskLevel,(int)$resolved['risk_level']);}
  catch(RuntimeException $e){
   $m=$e->getMessage();
   api_v1_json_response($m==='capability_disabled'?409:404,['status'=>'erro','mensagem'=>$m==='capability_disabled'?'Capability desabilitada':'Capability nao encontrada']);
  }
 }
 if($riskLevel>=3 && empty($in['confirm']))api_v1_json_response(409,['status'=>'confirmacao_necessaria','mensagem'=>'Acao sensivel exige confirm=true','risk_level'=>$riskLevel]);
 $corr=api_v1_correlation_id($in['correlation_id'] ?? null);
 $stmt=$pdo->prepare("INSERT INTO device_commands(device_id,comando,payload,prioridade,correlation_id,idempotency_key,status,lifecycle_status,max_retries,requested_by,risk_level,expira_em) VALUES(:d,:c,:p,:r,:x,:i,'pending','QUEUED',:mr,:rb,:risk,DATE_ADD(NOW(),INTERVAL :ttl SECOND))");
 $stmt->bindValue(':d',$deviceId);$stmt->bindValue(':c',$cmd);$stmt->bindValue(':p',json_encode(is_array($in['payload']??null)?$in['payload']:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$stmt->bindValue(':r',$priority);$stmt->bindValue(':x',$corr);$stmt->bindValue(':i',$idem);$stmt->bindValue(':mr',$maxRetries,PDO::PARAM_INT);$stmt->bindValue(':rb',$requestedBy);$stmt->bindValue(':risk',$riskLevel,PDO::PARAM_INT);$stmt->bindValue(':ttl',$ttl,PDO::PARAM_INT);$stmt->execute();
 $id=(int)$pdo->lastInsertId();api_v1_log($pdo,'COMMAND_ENQUEUED','INFO',$requestedBy,['id'=>$id,'device_id'=>$deviceId,'command'=>$cmd,'risk_level'=>$riskLevel,'idempotency_key'=>$idem]);
 api_v1_json_response(201,['status'=>'ok','id'=>$id,'correlation_id'=>$corr,'lifecycle_status'=>'QUEUED','risk_level'=>$riskLevel]);
}
if($action==='command_status'){
 $id=(int)($_GET['id']??0);if($id<=0)api_v1_json_response(400,['status'=>'erro','mensagem'=>'id invalido']);
 $stmt=$pdo->prepare("SELECT id,device_id,comando,prioridade,correlation_id,idempotency_key,status,lifecycle_status,retry_count,max_retries,requested_by,risk_level,criado_em,expira_em,entregue_em,last_attempt_at,ack_em,iniciado_em,concluido_em,resultado,erro FROM device_commands WHERE id=:id");$stmt->execute([':id'=>$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
 if(!$row)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Comando nao encontrado']);api_v1_json_response(200,['status'=>'ok','command'=>$row]);
}
if($action==='events'){
 $after=max(0,(int)($_GET['after']??0));$limit=max(1,min(100,(int)($_GET['limit']??50)));
 $stmt=$pdo->prepare("SELECT id,device_id,tipo,prioridade,correlation_id,dados,criado_em FROM device_events WHERE id>:a ORDER BY id ASC LIMIT {$limit}");$stmt->execute([':a'=>$after]);api_v1_json_response(200,['status'=>'ok','events'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'next_after'=>($rows=$stmt->rowCount())?$after+$rows:$after]);
}
api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida','acoes'=>['devices','device','capabilities','enqueue','command_status','events']]);
