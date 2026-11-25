<?php
// Gateway seguro da API externa v1.
// Valida cliente, escopo, rate limit e payload antes de encaminhar ao roteador legado.

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../seguranca.php');
require_once(__DIR__ . '/security_v1.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$path = trim($uri, '/');
$subpath = '';
if (preg_match('#api/v1/(.*)#', $path, $m)) $subpath = trim($m[1], '/');
if ($subpath === 'gateway.php' || $subpath === 'index.php') $subpath = '';
if ($subpath === 'ia.php') $subpath = 'ia/atender';
$isAttendance = in_array($subpath, ['comando', 'ia/atender', 'sip/atender'], true);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$acceptedScopes = ['status.read'];
if ($subpath === '' || $subpath === 'status') $acceptedScopes = ['status.read','mobile.read','watch.read'];
elseif ($isAttendance) $acceptedScopes = ['jarvis.command','mobile.write','watch.write'];
elseif ($subpath === 'dispositivos/acionar') $acceptedScopes = ['devices.write','mobile.write'];
elseif ($subpath === 'dispositivos') $acceptedScopes = ['devices.read','mobile.read','watch.read'];
elseif ($subpath === 'sensores') $acceptedScopes = ['sensors.read','mobile.read'];
elseif ($subpath === 'clima') $acceptedScopes = ['climate.read','mobile.read'];
elseif ($subpath === 'camera/snapshot') $acceptedScopes = ['camera.read','mobile.read'];

$client = api_v1_auth_client_any($pdo, $acceptedScopes);
api_v1_log($pdo, 'REQUEST_ALLOWED', 'INFO', $client['nome'] ?? null, [
    'accepted_scopes' => $acceptedScopes,
    'subpath' => $subpath,
    'method' => $method
]);

if ($subpath === '' || $subpath === 'status') {
    $totalDev=$totalNodes=$pendingMobile=$pendingWatch=0;
    try { $totalDev=(int)$pdo->query("SELECT COUNT(*) FROM dispositivos_cluster WHERE status='online'")->fetchColumn(); } catch(Throwable $e){}
    try { $totalNodes=(int)$pdo->query("SELECT COUNT(*) FROM arm_nodes WHERE status='online'")->fetchColumn(); } catch(Throwable $e){}
    try { $pendingMobile=(int)$pdo->query("SELECT COUNT(*) FROM mobile_notificacoes WHERE entregue=0")->fetchColumn(); } catch(Throwable $e){}
    try { $pendingWatch=(int)$pdo->query("SELECT COUNT(*) FROM watch_notificacoes WHERE entregue=0")->fetchColumn(); } catch(Throwable $e){}
    api_v1_json_response(200,[
        'status'=>'sucesso',
        'sistema'=>'CASA_JARVIS',
        'versao_api'=>'v1.2.0',
        'dominio'=>'https://maurinsoft.com.br/casa',
        'cliente'=>$client['nome'] ?? 'cliente',
        'timestamp'=>date('c'),
        'endpoints'=>[
            'mobile'=>'/api/v1/mobile.php',
            'watch'=>'/api/v1/watch.php',
            'family'=>'/api/v1/family.php',
            'camera'=>'/api/v1/camera.php',
            'command'=>'/api/v1/comando',
            'ai_attendance'=>'/api/v1/ia/atender',
            'sip_attendance'=>'/api/v1/sip/atender'
        ],
        'resumo'=>[
            'dispositivos_online'=>$totalDev,
            'nos_online'=>$totalNodes,
            'notificacoes_mobile'=>$pendingMobile,
            'notificacoes_watch'=>$pendingWatch
        ]
    ]);
}

if ($isAttendance) {
    if ($method !== 'POST') {
        header('Allow: POST');
        api_v1_json_response(405, ['status'=>'erro','mensagem'=>'Use POST para solicitar atendimento.']);
    }
    require_once __DIR__ . '/ai_attendance.php';
    try {
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $payload = api_v1_attendance_payload((string)$raw, $subpath, api_v1_correlation_id());
        $correlationId = api_v1_correlation_id($payload['correlation_id']);
        $result = api_v1_attendance_execute($payload, casa_url_interna('api/jarvis.php'), get_system_api_token());
        api_v1_log($pdo, 'AI_ATTENDANCE_OK', 'INFO', $client['nome'] ?? null,
            ['session_id'=>$payload['session_id'], 'origem'=>$payload['origem'], 'provedor'=>$result['provedor_ia']], $correlationId);
        api_v1_json_response(200, $result);
    } catch (ApiV1AttendanceException $e) {
        $correlationId = api_v1_correlation_id();
        api_v1_log($pdo, 'AI_ATTENDANCE_ERROR', 'AVISO', $client['nome'] ?? null,
            ['codigo'=>$e->getCode()], $correlationId);
        api_v1_json_response($e->getCode(), ['status'=>'erro','mensagem'=>$e->getMessage(),'correlation_id'=>$correlationId]);
    }
}

$stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave='external_api_key' LIMIT 1");
$stmt->execute();
$master = $stmt->fetchColumn();
if (!$master) {
    $candidate = 'jarvis_sec_v1_' . bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO configuracoes_sistema(chave,valor,descricao)
        VALUES('external_api_key',:v,'Chave mestre interna da API externa')
        ON DUPLICATE KEY UPDATE valor=valor")
        ->execute([':v'=>$candidate]);
    $stmt->execute();
    $master = $stmt->fetchColumn() ?: $candidate;
}
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $master;
unset($_SERVER['HTTP_X_API_KEY']);
unset($_GET['api_key']);
require(__DIR__ . '/index.php');
