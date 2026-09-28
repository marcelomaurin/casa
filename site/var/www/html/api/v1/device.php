<?php
// CASA/JARVIS - API universal de devices (Control Plane).
// NOTE: existing control-plane implementation plus desired-state support.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/device_common.php');
$pdo=get_db_pdo(); api_v1_basic_guard($pdo);
$action=$_GET['acao'] ?? 'status'; $input=device_v1_input();
$deviceId=(string)($input['device_id'] ?? ($_GET['device_id'] ?? ''));
$dev=device_v1_load($pdo,$deviceId,in_array($action,['heartbeat','event','command_ack','command_result','command_start','desired_state_set'],true));
$deviceId=$dev['device_id'];
if($action==='desired_state'){
  $stmt=$pdo->prepare("SELECT desired_state,updated_at FROM device_desired_state WHERE device_id=:d LIMIT 1");$stmt->execute([':d'=>$deviceId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
  api_v1_json_response(200,['status'=>'ok','device_id'=>$deviceId,'desired_state'=>$row?json_decode($row['desired_state'],true):new stdClass(),'updated_at'=>$row['updated_at']??null]);
}
if($action==='desired_state_set'){
  $state=is_array($input['desired_state']??null)?$input['desired_state']:null;if($state===null)api_v1_json_response(400,['status'=>'erro','mensagem'=>'desired_state obrigatorio']);
  $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $stmt=$pdo->prepare("INSERT INTO device_desired_state(device_id,desired_state,updated_at) VALUES(:d,:s,NOW()) ON DUPLICATE KEY UPDATE desired_state=VALUES(desired_state),updated_at=NOW()");$stmt->execute([':d'=>$deviceId,':s'=>$json]);
  api_v1_json_response(200,['status'=>'ok','device_id'=>$deviceId,'desired_state'=>$state]);
}
// Preserve compatibility while desired-state rollout is completed.
if($action==='heartbeat'){ $data=is_array($input['data']??null)?$input['data']:[];$pdo->prepare("UPDATE dispositivos_cluster SET status='online',ultimo_heartbeat=NOW(),metadata=:m WHERE id=:id")->execute([':m'=>json_encode($data),':id'=>$dev['id']]);api_v1_json_response(200,['status'=>'ok','device_id'=>$deviceId]); }
api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida']);
