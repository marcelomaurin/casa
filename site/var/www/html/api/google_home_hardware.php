<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__.'/db.php');
require_once(__DIR__.'/seguranca.php');

function ghw_out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function ghw_input(){ $j=json_decode(file_get_contents('php://input'),true); return is_array($j)?$j:[]; }
function ghw_computer($command){
    $token=get_system_api_token();
    $ch=curl_init('http://127.0.0.1/api/jarvis.php');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['comando'=>$command,'origem'=>'GOOGLE_HOME_HARDWARE'],JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Key: '.$token],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>50]);
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    $j=$raw!==false?json_decode((string)$raw,true):null;
    if($raw!==false&&$http>=200&&$http<300&&is_array($j))return ['ok'=>true,'data'=>$j];
    return ['ok'=>false,'erro'=>$err?:($j['mensagem']??('HTTP '.$http))];
}
function ghw_speak($text,$device=''){
    $ch=curl_init('http://127.0.0.1:8100/speak');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['texto'=>$text,'device'=>$device?:null,'speaker'=>'padrao'],JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>35]);
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return $raw!==false&&$http>=200&&$http<300;
}

$in=ghw_input();
$acao=strtolower(trim((string)($in['acao']??($_GET['acao']??''))));
$pdo=get_db_pdo();

if($acao==='provisionar'){
    verify_api_auth();
    $nome=trim((string)($in['nome']??'Google Home / COMPUTER'))?:'Google Home / COMPUTER';
    $deviceId='google-home-'.bin2hex(random_bytes(6));
    $token=gerar_novo_hardware_token('computer_google_home');
    $caps=json_encode(['google-home','cast-speaker','computer-command','voice-bridge'],JSON_UNESCAPED_UNICODE);
    $meta=json_encode(['provisionado_por'=>$_SESSION['auth_user']??'api','nota'=>'Token exibido apenas no provisionamento'],JSON_UNESCAPED_UNICODE);
    $st=$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,capabilities,metadata,localizacao) VALUES(:d,:n,'google_home',:t,'offline',:c,:m,'Residencia')");
    $st->execute([':d'=>$deviceId,':n'=>$nome,':t'=>$token,':c'=>$caps,':m'=>$meta]);
    ghw_out(['status'=>'sucesso','device_id'=>$deviceId,'device_token'=>$token,'mensagem'=>'Hardware Google Home provisionado. Guarde o token agora.']);
}

$token=trim((string)($_SERVER['HTTP_X_DEVICE_TOKEN']??''));
$dev=validar_hardware_token($token);
if(!$dev)ghw_out(['status'=>'erro','mensagem'=>'Token de hardware inválido.'],401);

if($acao==='heartbeat'){
    $caps=$in['capabilities']??['google-home','cast-speaker','computer-command'];
    $meta=['devices'=>$in['devices']??[],'bridge'=>'google-home-agent','ultimo_evento'=>'heartbeat'];
    $st=$pdo->prepare("UPDATE dispositivos_cluster SET status='online',capabilities=:c,metadata=:m,ultimo_heartbeat=NOW(),ip_address=:ip WHERE id=:id");
    $st->execute([':c'=>json_encode($caps,JSON_UNESCAPED_UNICODE),':m'=>json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':ip'=>get_client_ip(),':id'=>$dev['id']]);
    ghw_out(['status'=>'sucesso','device_id'=>$dev['device_id'],'mensagem'=>'Heartbeat recebido.']);
}

if($acao==='comando'){
    $cmd=trim((string)($in['comando']??''));if($cmd==='')ghw_out(['status'=>'erro','mensagem'=>'Comando vazio.'],400);
    try{
        $log=$pdo->prepare("INSERT INTO comandos_log(iddevice,comando,origem,resultado) VALUES(:id,:c,'GOOGLE_HOME_HARDWARE','RECEBIDO')");
        $log->execute([':id'=>$dev['id'],':c'=>mb_substr($cmd,0,255,'UTF-8')]);
    }catch(Throwable $e){}
    $r=ghw_computer($cmd);
    if(!$r['ok'])ghw_out(['status'=>'erro','mensagem'=>$r['erro']],502);
    $resp=(string)($r['data']['resposta']??$r['data']['mensagem']??'');
    $falou=false;
    if(!empty($in['falar_resposta'])&&$resp!=='')$falou=ghw_speak($resp,trim((string)($in['device']??'')));
    try{
        $log=$pdo->prepare("INSERT INTO comandos_log(iddevice,comando,origem,resultado) VALUES(:id,:c,'GOOGLE_HOME_HARDWARE',:r)");
        $log->execute([':id'=>$dev['id'],':c'=>'Resposta COMPUTER',':r'=>mb_substr($resp,0,2000,'UTF-8')]);
    }catch(Throwable $e){}
    ghw_out(['status'=>'sucesso','device_id'=>$dev['device_id'],'comando'=>$cmd,'resposta'=>$resp,'falou'=>$falou,'dados'=>$r['data']]);
}

ghw_out(['status'=>'erro','mensagem'=>'Ação desconhecida.'],400);
