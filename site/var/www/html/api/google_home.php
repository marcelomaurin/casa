<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__.'/db.php');
verify_api_auth();

function gh_out($data,$code=200){http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function gh_call($method,$path,$payload=null,$timeout=55){
    $url='http://127.0.0.1:8100'.$path;
    $ch=curl_init($url);
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>['Content-Type: application/json']];
    if($payload!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch,$opts);
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    $j=$raw!==false?json_decode((string)$raw,true):null;
    if($raw!==false&&$http>=200&&$http<300&&is_array($j))return ['ok'=>true,'data'=>$j];
    $msg=$err?:($j['detail']??$j['mensagem']??('HTTP '.$http));
    return ['ok'=>false,'erro'=>is_string($msg)?$msg:json_encode($msg,JSON_UNESCAPED_UNICODE),'http'=>$http];
}

$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in))$in=[];
$acao=strtolower(trim((string)($in['acao']??($_GET['acao']??'status'))));

if($acao==='status'){
    $r=gh_call('GET','/status',null,10);
    if(!$r['ok'])gh_out(['status'=>'erro','online'=>false,'mensagem'=>$r['erro'],'devices'=>[],'hardware_status'=>'OFFLINE'],200);
    $pdo=get_db_pdo();
    $h='NAO PROVISIONADO';
    try{
        $st=$pdo->query("SELECT status,ultimo_heartbeat FROM dispositivos_cluster WHERE tipo='google_home' ORDER BY id DESC LIMIT 1");
        $row=$st->fetch(PDO::FETCH_ASSOC);
        if($row)$h=strtoupper((string)$row['status']).(!empty($row['ultimo_heartbeat'])?' · '.$row['ultimo_heartbeat']:'');
    }catch(Throwable $e){}
    $d=$r['data'];$d['hardware_status']=$h;gh_out($d);
}
if($acao==='comando'){
    $cmd=trim((string)($in['comando']??''));if($cmd==='')gh_out(['status'=>'erro','mensagem'=>'Comando vazio.'],400);
    $r=gh_call('POST','/command',['comando'=>$cmd,'device'=>trim((string)($in['device']??''))?:null,'speaker'=>'padrao'],60);
    if(!$r['ok'])gh_out(['status'=>'erro','mensagem'=>$r['erro']],502);
    gh_out($r['data']);
}
gh_out(['status'=>'erro','mensagem'=>'Ação desconhecida.'],400);
