<?php
require_once __DIR__.'/../db.php';
require_once __DIR__.'/security_v1.php';
require_once __DIR__.'/../google_home_bridge.php';
$pdo=get_db_pdo();api_v1_basic_guard($pdo);
$client=api_v1_auth_client_any($pdo,['tasks.worker']);
if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') api_v1_json_response(405,['status'=>'erro','mensagem'=>'Use POST']);
$in=json_decode(file_get_contents('php://input'),true);
if (!is_array($in) || ($in['acao']??'')!=='heartbeat') api_v1_json_response(400,['status'=>'erro','mensagem'=>'Use acao=heartbeat']);
$snapshot=gh_bridge_snapshot($in,time());
$pdo->prepare("INSERT INTO configuracoes_sistema(chave,valor,descricao) VALUES('google_home_agent_status',:v,'Estado recente do agente Google Home') ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
    ->execute([':v'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
api_v1_json_response(200,['status'=>'ok']);
