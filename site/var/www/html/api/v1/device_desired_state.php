<?php
// CASA/JARVIS - Desired state por device.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/device_common.php');
$pdo=get_db_pdo(); api_v1_basic_guard($pdo);
$input=device_v1_input();
$deviceId=(string)($input['device_id'] ?? ($_GET['device_id'] ?? ''));
$dev=device_v1_load($pdo,$deviceId,false); $deviceId=$dev['device_id'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
 $s=$pdo->prepare("SELECT desired_state,updated_at FROM device_desired_state WHERE device_id=:d LIMIT 1");$s->execute([':d'=>$deviceId]);$r=$s->fetch(PDO::FETCH_ASSOC);
 api_v1_json_response(200,['status'=>'ok','device_id'=>$deviceId,'desired_state'=>$r?json_decode($r['desired_state'],true):new stdClass(),'updated_at'=>$r['updated_at']??null]);
}
$state=is_array($input['desired_state']??null)?$input['desired_state']:null;
if($state===null)api_v1_json_response(400,['status'=>'erro','mensagem'=>'desired_state obrigatorio']);
// Por enquanto o atuador suportado aqui e o rele booleano.
if(array_key_exists('relay_on',$state)&&!is_bool($state['relay_on']))api_v1_json_response(400,['status'=>'erro','mensagem'=>'relay_on deve ser boolean']);
$json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$s=$pdo->prepare("INSERT INTO device_desired_state(device_id,desired_state,updated_at) VALUES(:d,:s,NOW()) ON DUPLICATE KEY UPDATE desired_state=VALUES(desired_state),updated_at=NOW()");$s->execute([':d'=>$deviceId,':s'=>$json]);
api_v1_json_response(200,['status'=>'ok','device_id'=>$deviceId,'desired_state'=>$state,'updated_at'=>date('c')]);
