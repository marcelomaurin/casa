<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');
$pdo=get_db_pdo(); api_v1_basic_guard($pdo);
$client=api_v1_auth_client_any($pdo,['devices.provision','mobile.write']);
function provision_input():array{$raw=file_get_contents('php://input');$j=$raw?json_decode($raw,true):[];return is_array($j)?$j:[];}
function provision_scopes(string $type):array{
 $base=['device.read','device.write','commands.read','events.write','telemetry.write'];
 if($type==='watch')return array_merge($base,['watch.read','watch.write','family.read','family.write']);
 if($type==='tv')return ['device.read','device.write','commands.read','events.write','home.read','alerts.read','camera.read','family.read'];
 if($type==='esp32cam')return array_merge($base,['camera.write']);
 return $base;
}
$action=$_GET['acao']??'list';
if($action==='list'){
 $stmt=$pdo->query("SELECT id,device_id,nome,tipo,local_ip,observed_ip,transport,gateway_device_id,status,health,sinal_rssi,capabilities,firmware_version,protocol_version,config_version,ultimo_heartbeat,localizacao,criado_em FROM dispositivos_cluster ORDER BY criado_em DESC LIMIT 100");
 echo json_encode(['status'=>'ok','devices'=>$stmt->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
if($action==='create'){
 $in=provision_input();$type=strtolower(trim((string)($in['type']??'device')));$allowed=['esp32cam','watch','esp32','esp8266','sensor','gateway','tv','mobile','device'];
 if(!in_array($type,$allowed,true))api_v1_json_response(400,['status'=>'erro','mensagem'=>'Tipo de device invalido']);
 $name=mb_substr(trim((string)($in['name']??'')),0,120);if($name==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'Nome do device obrigatorio']);
 $location=mb_substr(trim((string)($in['location']??'Residencia')),0,120);$mac=preg_replace('/[^0-9A-Fa-f:]/','',(string)($in['mac']??''));
 $deviceId=$type.'-'.bin2hex(random_bytes(6));$token='casa_dev_'.bin2hex(random_bytes(32));$hash=hash('sha256',$token);$prefix=substr($token,0,20);
 $caps=is_array($in['capabilities']??null)?$in['capabilities']:[];$scopes=provision_scopes($type);
 $metadata=['provisioned_by'=>$client['nome']??'mobile','provisioned_at'=>date('c'),'transport_setup'=>'ble','transport_runtime'=>in_array($type,['watch','mobile'],true)?'ble_or_wifi':'wifi'];
 try{
  $pdo->beginTransaction();
  $stmt=$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,mac_address,device_token,device_token_hash,status,health,capabilities,metadata,localizacao) VALUES(:did,:n,:t,:m,'HASHED',:h,'offline','unknown',:cap,:meta,:loc)");
  $stmt->execute([':did'=>$deviceId,':n'=>$name,':t'=>$type,':m'=>$mac!==''?$mac:null,':h'=>$hash,':cap'=>json_encode($caps,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':meta'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':loc'=>$location?:'Residencia']);
  $internalId=(int)$pdo->lastInsertId();
  $stmt=$pdo->prepare("INSERT INTO api_client_tokens(nome,device_id,token_hash,token_prefix,scopes,ativo) VALUES(:n,:d,:h,:p,:s,1)");
  $stmt->execute([':n'=>$name,':d'=>$deviceId,':h'=>$hash,':p'=>$prefix,':s'=>json_encode($scopes,JSON_UNESCAPED_UNICODE)]);
  $pdo->commit();
  api_v1_log($pdo,'DEVICE_PROVISION_CREATED','INFO',$client['nome']??null,['device_id'=>$deviceId,'type'=>$type]);
  echo json_encode(['status'=>'ok','device'=>['id'=>$internalId,'device_id'=>$deviceId,'name'=>$name,'type'=>$type,'location'=>$location,'token'=>$token,'scopes'=>$scopes],'notice'=>'Credencial exibida uma unica vez. O servidor armazena somente o hash.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();api_v1_log($pdo,'DEVICE_PROVISION_ERROR','ALTO',$client['nome']??null,['error'=>$e->getMessage()]);api_v1_json_response(500,['status'=>'erro','mensagem'=>'Falha ao criar identidade do device']);}exit;
}
if($action==='revoke'){
 $in=provision_input();$did=trim((string)($in['device_id']??''));if($did==='')api_v1_json_response(400,['status'=>'erro','mensagem'=>'device_id obrigatorio']);
 $pdo->beginTransaction();try{$pdo->prepare("UPDATE dispositivos_cluster SET credential_revoked_at=NOW(),status='revoked',health='revoked' WHERE device_id=:d")->execute([':d'=>$did]);$pdo->prepare("UPDATE api_client_tokens SET ativo=0,revogado_em=NOW() WHERE device_id=:d")->execute([':d'=>$did]);$pdo->commit();api_v1_log($pdo,'DEVICE_REVOKED','ALTO',$client['nome']??null,['device_id'=>$did]);api_v1_json_response(200,['status'=>'ok','device_id'=>$did]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();api_v1_json_response(500,['status'=>'erro','mensagem'=>'Falha ao revogar device']);}
}
api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao de provisionamento desconhecida']);
