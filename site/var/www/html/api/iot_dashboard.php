<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/db.php';
if (empty($_SESSION['auth_user'])) {
    verify_api_auth();
}
$pdo=get_db_pdo();
function out($code,$data){http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function caps_array($v){$a=json_decode((string)$v,true);return is_array($a)?$a:[];}
function json_array($v){$a=json_decode((string)$v,true);return is_array($a)?$a:[];}
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
  try{
    $sql="SELECT d.device_id,d.nome,d.tipo,d.model,d.localizacao,d.status,d.ip_address,d.mac_address,d.sinal_rssi,d.ultimo_heartbeat,d.capabilities,d.metadata,s.desired_state,s.updated_at AS desired_updated FROM dispositivos_cluster d LEFT JOIN device_desired_state s ON s.device_id=d.device_id WHERE d.tipo NOT IN ('linux-arm', 'cluster', 'server') AND d.device_id NOT LIKE 'raspberry%' AND d.device_id NOT LIKE 'cubie%' ORDER BY COALESCE(d.nome,d.model,d.device_id),d.device_id";
    $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $latest=[];
    $q=$pdo->query("SELECT r.device_id,r.temperature_c,r.humidity_pct,r.sensor_type,r.observed_at FROM device_environment_readings r INNER JOIN (SELECT device_id,MAX(id) id FROM device_environment_readings GROUP BY device_id) x ON x.id=r.id");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$latest[$r['device_id']]=$r;
    $devices=[];
    foreach($rows as $r){
      $caps=caps_array($r['capabilities']??'[]');
      if(in_array('arm-agent',$caps,true)||in_array('linux-arm',$caps,true)||$r['tipo']==='linux-arm') continue;$meta=json_array($r['metadata']??'{}');$desired=json_array($r['desired_state']??'{}');$env=$latest[$r['device_id']]??null;
      $devices[]=[
        'device_id'=>$r['device_id'],'nome'=>$r['nome'],'tipo'=>$r['tipo'],'model'=>$r['model'],'localizacao'=>$r['localizacao'],'status'=>$r['status'],'ip_address'=>$r['ip_address'],'sinal_rssi'=>$r['sinal_rssi'],'ultimo_heartbeat'=>$r['ultimo_heartbeat'],'capabilities'=>$caps,
        'is_relay'=>in_array('relay',$caps,true)||in_array('switch',$caps,true),
        'is_environment'=>($env!==null)||in_array('temperature',$caps,true)||in_array('humidity',$caps,true),
        'relay_actual'=>array_key_exists('relay_on',$meta)?(bool)$meta['relay_on']:null,
        'relay_desired'=>array_key_exists('relay_on',$desired)?(bool)$desired['relay_on']:null,
        'desired_updated'=>$r['desired_updated'],
        'environment'=>$env
      ];
    }
    out(200,['status'=>'ok','devices'=>$devices]);
  }catch(Throwable $e){out(500,['status'=>'erro','mensagem'=>'Falha ao carregar dispositivos IoT.']);}
}
if($method==='POST'){
  $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=[];
  if(($input['acao']??'')!=='relay_state')out(400,['status'=>'erro','mensagem'=>'Ação inválida.']);
  $deviceId=trim((string)($input['device_id']??''));$relay=$input['relay_on']??null;
  if($deviceId===''||!is_bool($relay))out(400,['status'=>'erro','mensagem'=>'device_id e relay_on booleano são obrigatórios.']);
  try{
    $st=$pdo->prepare("SELECT capabilities FROM dispositivos_cluster WHERE device_id=:d LIMIT 1");$st->execute([':d'=>$deviceId]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row)out(404,['status'=>'erro','mensagem'=>'Dispositivo não encontrado.']);
    $caps=caps_array($row['capabilities']??'[]');if(!in_array('relay',$caps,true)&&!in_array('switch',$caps,true))out(400,['status'=>'erro','mensagem'=>'Dispositivo não anuncia capacidade de relé.']);
    $json=json_encode(['relay_on'=>$relay],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO device_desired_state(device_id,desired_state,updated_at) VALUES(:d,:s,NOW()) ON DUPLICATE KEY UPDATE desired_state=VALUES(desired_state),updated_at=NOW()")->execute([':d'=>$deviceId,':s'=>$json]);
    out(200,['status'=>'ok','device_id'=>$deviceId,'relay_on'=>$relay,'mensagem'=>'Estado desejado atualizado.']);
  }catch(Throwable $e){out(500,['status'=>'erro','mensagem'=>'Falha ao atualizar o relé.']);}
}
out(405,['status'=>'erro','mensagem'=>'Método não permitido.']);
