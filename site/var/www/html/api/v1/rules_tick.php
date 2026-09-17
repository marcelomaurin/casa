<?php
// CASA/JARVIS - Tick manual/cron para regras schedule/state.
// Usa o mesmo scheduler acionado oportunisticamente pelos heartbeats.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once(__DIR__.'/../db.php');
require_once(__DIR__.'/security_v1.php');
require_once(__DIR__.'/rules_scheduler.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
api_v1_auth_client_any($pdo, ['home.write','devices.write']);

$runs = rules_scheduler_tick($pdo, true);
api_v1_json_response(200,[
    'status'=>'ok',
    'runs'=>$runs,
    'processed'=>count($runs),
    'timestamp'=>date('c')
]);
