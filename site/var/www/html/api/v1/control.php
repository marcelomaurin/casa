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
$pdo=get_db_pdo(); api_v1_basic_guard($pdo);
$action=$_GET['acao'] ?? 'devices';
$read=in_array($action,['devices','device','capabilities','command_status','events'],true);
$client=api_v1_auth_client_any($pdo,$read?['devices.read','mobile.read','home.read']:['devices.write','mobile.write','home.write']);
$raw=file_get_contents('php://input'); $in=$raw?json_decode($raw,true):[]; if(!is_array($in))$in=[];

function control_decode_json($value,$fallback=[]){
 if(is_array($value))return $value;
 if(!is_string($value)||$value==='')return $fallback;
 $d=json_decode($value,true); return is_array($d)?$d:$fallback;
}
function control_capabilities(PDO $pdo,string $deviceId,array $fallback=[]):array{
 try{
  $stmt=$pdo->prepare("SELECT capability,enabled,risk_level,config FROM device_capabilities WHERE device_id=:d ORDER BY capability");
  $stmt->execute([':d'=>$deviceId]); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
  if($rows){
   return array_map(function($r){return ['name'=>$r['capability'],'enabled'=>(bool)$r['enabled'],'risk_level'=>(int)$r['risk_level'],'config'=>control_decode_json($r['config'],[])];},$rows);
  }
 }catch(Throwable $e){}
 $out=[];
 foreach($fallback as $k=>$v){
  if(is_int($k))$out[]=['name'=>(string)$v,'enabled'=>true,'risk_level'=>1,'config'=>[]];
  else $out[]=['name'=>(string)$k,'enabled'=>(bool)$v,'risk_level'=>1,'config'=>[]];
 }
 return $out;
}
function control_fetch_device(PDO $pdo,string $deviceId){
 $stmt=$pdo->prepare("SELECT device_id,nome,tipo,status,health,transport,local_ip,observed_ip,gateway_device_id,sinal_rssi,battery_pct,capabilities,metadata,localizacao,manufacturer,model,firmware_version,protocol_version,config_version,ultimo_heartbeat,CASE WHEN ultimo_heartbeat IS NOT NULL AND ultimo_heartbeat>=DATE_SUB(NOW(),INTERVAL 120 SECOND) THEN 1 ELSE 0 END AS online FROM dispositivos_cluster WHERE device_id=:d LIMIT 1");
 $stmt->execute([':d'=>$deviceId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
 if(!$row)return null;
 $fallback=control_decode_json($row['capabilities']??null,[]);
 $row['capabilities']=control_capabilities($pdo,$deviceId,$fallback);
 $row['metadata']=control_decode_json($row['metadata']??null,[]);
 $row['online']=(bool)$row['online'];
 $row['battery_pct']=$row['battery_pct']===null?null:(int)$row['battery_pct'];
 return $row;
}

if($action==='devices'){
 $stmt=$pdo->query("SELECT device_id FROM dispositivos_cluster WHERE device_id IS NOT NULL AND device_id<>'' ORDER BY nome");
 $devices=[]; foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id){$d=control_fetch_device($pdo,(string)$id);if($d)$devices[]=$d;}
 api_v1_json_response(200,['status'=>'ok','devices'=>$devices,'server_time'=>date('c')]);
}
if($action==='device'){
 $deviceId=trim((string)($_GET['device_id']??$in['device_id']??''));
 if($deviceId==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'device_id obrigatorio']);
 $dev=control_fetch_device($pdo,$deviceId);
 if(!$dev)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Device nao encontrado']);
 api_v1_json_response(200,['status'=>'ok','device'=>$dev,'server_time'=>date('c')]);
}
if($action==='capabilities'){
 $deviceId=trim((string)($_GET['device_id']??$in['device_id']??''));
 if($deviceId==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'device_id obrigatorio']);
 $dev=control_fetch_device($pdo,$deviceId);
 if(!$dev)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Device nao encontrado']);
 api_v1_json_response(200,['status'=>'ok','device_id'=>$deviceId,'capabilities'=>$dev['capabilities']]);
}
if($action==='enqueue'){
 $deviceId=trim((string)($in['device_id']??'')); $cmd=substr(trim((string)($in['command']??'')),0,120);
 if($deviceId===''||$cmd==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'device_id e command obrigatorios']);
 $stmt=$pdo->prepare("SELECT device_id,capabilities FROM dispositivos_cluster WHERE device_id=:d AND credential_revoked_at IS NULL LIMIT 1");$stmt->execute([':d'=>$deviceId]);$dev=$stmt->fetch(PDO::FETCH_ASSOC);
 if(!$dev)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Device nao encontrado ou revogado']);
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
  $stmt=$pdo->prepare("SELECT enabled,risk_level FROM device_capabilities WHERE device_id=:d AND capability=:c LIMIT 1");$stmt->execute([':d'=>$deviceId,':c'=>$requiredCapability]);$cap=$stmt->fetch(PDO::FETCH_ASSOC);
  if($cap){
   if(!(bool)$cap['enabled'])api_v1_json_response(409,['status'=>'erro','mensagem'=>'Capability desabilitada']);
   $riskLevel=max($riskLevel,(int)$cap['risk_level']);
  }
 }
 if($riskLevel>=3 && empty($in['confirm']))api_v1_json_response(409,['status'=>'confirmacao_necessaria','mensagem'=>'Acao sensivel exige confirm=true','risk_level'=>$riskLevel]);
 $corr='cmd_'.bin2hex(random_bytes(12));
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
