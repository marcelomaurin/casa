<?php
// CASA/JARVIS - Health agregado de servicos internos.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once(__DIR__.'/../db.php');
require_once(__DIR__.'/security_v1.php');
$pdo=get_db_pdo();
api_v1_basic_guard($pdo);
api_v1_auth_client_any($pdo,['home.read','devices.read','mobile.read']);

function health_probe_url(?string $url, int $timeoutMs=1200): array {
    if (!$url) return ['configured'=>false,'ready'=>null,'status'=>'not_configured'];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>$timeoutMs,CURLOPT_TIMEOUT_MS=>$timeoutMs,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_NOBODY=>false,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    $ok=$body!==false && $code>=200 && $code<400;
    return ['configured'=>true,'ready'=>$ok,'status'=>$ok?'ready':'down','http_code'=>$code,'error'=>$ok?null:($err?:'HTTP '.$code)];
}

$version='';
try{$s=$pdo->prepare("SELECT valor FROM param WHERE chave='VERSAO' LIMIT 1");$s->execute();$version=(string)($s->fetchColumn()?:'');}catch(Throwable $e){}
$dbReady=false;$controlReady=false;
try{$pdo->query('SELECT 1')->fetchColumn();$dbReady=true;$q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('dispositivos_cluster','device_commands','device_events','scenes','automation_rules')");$controlReady=(int)$q->fetchColumn()>=5;}catch(Throwable $e){}

$services=[
 'api'=>['configured'=>true,'ready'=>$dbReady&&$controlReady&&$version==='1.20','status'=>($dbReady&&$controlReady&&$version==='1.20')?'ready':'not_ready'],
 'database'=>['configured'=>true,'ready'=>$dbReady,'status'=>$dbReady?'ready':'down'],
 'control_plane'=>['configured'=>true,'ready'=>$controlReady,'status'=>$controlReady?'ready':'not_ready'],
 'llm'=>health_probe_url(cfg_value('llm_health_url','JARVIS_LLM_HEALTH_URL','')),
 'tts'=>health_probe_url(cfg_value('tts_health_url','JARVIS_TTS_HEALTH_URL','')),
 'stt'=>health_probe_url(cfg_value('stt_health_url','JARVIS_STT_HEALTH_URL','')),
 'web_agent'=>health_probe_url(cfg_value('web_agent_health_url','JARVIS_WEB_AGENT_HEALTH_URL','')),
];
$requiredFailed=[];
foreach(['api','database','control_plane'] as $name){if(empty($services[$name]['ready']))$requiredFailed[]=$name;}
$ready=!$requiredFailed;
http_response_code($ready?200:503);
api_v1_json_response($ready?200:503,['status'=>$ready?'ready':'not_ready','schema_version'=>$version,'services'=>$services,'required_failed'=>$requiredFailed,'timestamp'=>date('c')]);
