<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');
require_once(__DIR__ . '/../family_common.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
family_ensure_schema($pdo);
$client = api_v1_auth_client_any($pdo, ['tv.read','mobile.read','family.read']);
$action = $_GET['acao'] ?? 'dashboard';

if ($action !== 'dashboard') api_v1_json_response(404, ['status'=>'erro','mensagem'=>'Ação TV desconhecida']);

$cameras = [];
try {
    $stmt = $pdo->query("SELECT id,device_id,nome,ip_address,status,sinal_rssi,ultimo_heartbeat,localizacao,metadata FROM dispositivos_cluster WHERE tipo IN ('esp32cam','esp32_cam','camera') ORDER BY CASE status WHEN 'online' THEN 0 ELSE 1 END, nome");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ip = trim((string)($r['ip_address'] ?? ''));
        $r['capture_url'] = $ip !== '' ? 'http://' . $ip . '/capture' : null;
        $r['stream_url'] = $ip !== '' ? 'http://' . $ip . '/stream' : null;
        $cameras[] = $r;
    }
} catch (Throwable $e) {}

$alerts = [];
try {
    $stmt = $pdo->query("SELECT id,tipo,severidade,mensagem,dispositivo_ref AS dispositivo,criado_em FROM assistencia_eventos WHERE confirmado=0 ORDER BY CASE severidade WHEN 'critica' THEN 0 WHEN 'alta' THEN 1 ELSE 2 END, criado_em DESC LIMIT 20");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $alerts[] = $r;
} catch (Throwable $e) {}

try {
    $stmt = $pdo->query("SELECT id,'NOTIFICACAO' AS tipo,prioridade AS severidade,CONCAT(titulo,': ',mensagem) AS mensagem,'CASA' AS dispositivo,data_hora AS criado_em FROM mobile_notificacoes WHERE entregue=0 ORDER BY data_hora DESC LIMIT 10");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $alerts[] = $r;
} catch (Throwable $e) {}

$messages = [];
try {
    $channel = family_channel($pdo, 'familia');
    $stmt = $pdo->prepare("SELECT id,remetente,origem,tipo,mensagem,criado_em FROM family_messages WHERE canal_id=:c ORDER BY id DESC LIMIT 10");
    $stmt->execute([':c'=>$channel['id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

echo json_encode([
    'status'=>'ok',
    'client'=>$client['nome'] ?? 'TV',
    'server_time'=>date('c'),
    'role'=>'display',
    'capabilities'=>['alerts','cameras','status','family_messages'],
    'alerts'=>$alerts,
    'cameras'=>$cameras,
    'family_messages'=>$messages
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
