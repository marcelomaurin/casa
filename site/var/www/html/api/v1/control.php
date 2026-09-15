<?php
// CASA/JARVIS - lado controlador do Command Bus. Usado por Mobile/Site autorizados.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='OPTIONS'){http_response_code(200);exit;}
require_once(__DIR__.'/../db.php');
require_once(__DIR__.'/security_v1.php');
$pdo=get_db_pdo(); api_v1_basic_guard($pdo);
$action=$_GET['acao'] ?? 'devices';
$read=in_array($action,['devices','command_status','events'],true);
$client=api_v1_auth_client_any($pdo,$read?['devices.read','mobile.read','home.read']:['devices.write','mobile.write','home.write']);
$raw=file_get_contents('php://input'); $in=$raw?json_decode($raw,true):[]; if(!is_array($in))$in=[];

if($action==='devices'){
 $stmt=$pdo->query("SELECT device_id,nome,tipo,status,health,transport,local_ip,observed_ip,gateway_device_id,sinal_rssi,capabilities,localizacao,firmware_version,protocol_version,config_version,ultimo_heartbeat FROM dispositivos_cluster ORDER BY nome");
 api_v1_json_response(200,['status'=>'ok','devices'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
}
if($action==='enqueue'){
 $deviceId=trim((string)($in['device_id']??'')); $cmd=substr(trim((string)($in['command']??'')),0,120);
 if($deviceId===''||$cmd==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'device_id e command obrigatorios']);
 $stmt=$pdo->prepare("SELECT device_id,capabilities FROM dispositivos_cluster WHERE device_id=:d AND credential_revoked_at IS NULL LIMIT 1");$stmt->execute([':d'=>$deviceId]);$dev=$stmt->fetch(PDO::FETCH_ASSOC);
 if(!$dev)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Device nao encontrado ou revogado']);
 $priority=strtolower((string)($in['priority']??'normal'));if(!in_array($priority,['low','normal','high','critical'],true))$priority='normal';
 $corr='cmd_'.bin2hex(random_bytes(12)); $ttl=max(10,min(86400,(int)($in['ttl_seconds']??300)));
 $stmt=$pdo->prepare("INSERT INTO device_commands(device_id,comando,payload,prioridade,correlation_id,expira_em) VALUES(:d,:c,:p,:r,:x,DATE_ADD(NOW(),INTERVAL :ttl SECOND))");
 $stmt->bindValue(':d',$deviceId);$stmt->bindValue(':c',$cmd);$stmt->bindValue(':p',json_encode(is_array($in['payload']??null)?$in['payload']:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$stmt->bindValue(':r',$priority);$stmt->bindValue(':x',$corr);$stmt->bindValue(':ttl',$ttl,PDO::PARAM_INT);$stmt->execute();
 $id=(int)$pdo->lastInsertId();api_v1_log($pdo,'COMMAND_ENQUEUED','INFO',$client['nome']??null,['id'=>$id,'device_id'=>$deviceId,'command'=>$cmd]);
 api_v1_json_response(201,['status'=>'ok','id'=>$id,'correlation_id'=>$corr]);
}
if($action==='command_status'){
 $id=(int)($_GET['id']??0);if($id<=0)api_v1_json_response(400,['status'=>'erro','mensagem'=>'id invalido']);
 $stmt=$pdo->prepare("SELECT id,device_id,comando,prioridade,correlation_id,status,criado_em,expira_em,entregue_em,ack_em,concluido_em,resultado,erro FROM device_commands WHERE id=:id");$stmt->execute([':id'=>$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
 if(!$row)api_v1_json_response(404,['status'=>'erro','mensagem'=>'Comando nao encontrado']);api_v1_json_response(200,['status'=>'ok','command'=>$row]);
}
if($action==='events'){
 $after=max(0,(int)($_GET['after']??0));$limit=max(1,min(100,(int)($_GET['limit']??50)));
 $stmt=$pdo->prepare("SELECT id,device_id,tipo,prioridade,correlation_id,dados,criado_em FROM device_events WHERE id>:a ORDER BY id ASC LIMIT {$limit}");$stmt->execute([':a'=>$after]);api_v1_json_response(200,['status'=>'ok','events'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
}
api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida']);
