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
    // 'espcam' e o tipo gravado pelo gravafirmware/camera_discovery: antes ficava
    // de fora e as ESP32-CAM registradas nao apareciam na TV. O estado vem do
    // sinal de vida (90 s, como no painel), nao do campo status gravado.
    $stmt = $pdo->query("SELECT id,device_id,nome,tipo,ip_address,sinal_rssi,ultimo_heartbeat,localizacao,metadata,
            (ultimo_heartbeat IS NOT NULL AND ultimo_heartbeat >= NOW() - INTERVAL 90 SECOND) AS online
        FROM dispositivos_cluster
        WHERE tipo IN ('espcam','esp32cam','esp32_cam','camera','ip_camera','camera_ip')
        ORDER BY online DESC, nome");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $meta = json_decode((string)($r['metadata'] ?? ''), true);
        if (!is_array($meta)) $meta = [];
        $ip = trim((string)($r['ip_address'] ?? ''));
        $snap = (string)($meta['snapshot_url'] ?? '');
        $stream = (string)($meta['stream_url'] ?? '');
        $cameras[] = [
            'id' => (int)$r['id'],
            'device_id' => $r['device_id'],
            'nome' => $r['nome'],
            'tipo' => $r['tipo'],
            'localizacao' => $r['localizacao'],
            'ip_address' => $r['ip_address'],
            'sinal_rssi' => $r['sinal_rssi'],
            'ultimo_heartbeat' => $r['ultimo_heartbeat'],
            'status' => $r['online'] ? 'online' : 'offline',
            // Endereco na rede da casa (a TV busca a imagem direto na camera).
            'capture_url' => str_starts_with($snap, 'http') ? $snap : ($ip !== '' ? 'http://' . $ip . '/capture' : null),
            'stream_url' => str_starts_with($stream, 'http') ? $stream : ($ip !== '' ? 'http://' . $ip . '/stream' : null),
            // metadata nao e repassado: contem o token da camera.
        ];
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
