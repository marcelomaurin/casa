<?php
// JARVIS RESIDENCIAL - API EXTERNA v1 PARA APP ANDROID
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);

function mobile_v1_json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$acao = $_GET['acao'] ?? 'status';
$scope = in_array($acao, ['notificacoes','status'], true) ? 'mobile.read' : 'mobile.write';
$client = api_v1_auth_client($pdo, [$scope]);

$deviceId = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM dispositivos_cluster WHERE nome = :n LIMIT 1");
    $stmt->execute([':n' => $client['nome']]);
    $deviceId = $stmt->fetchColumn();
} catch (Throwable $e) {}

if ($acao === 'status') {
    echo json_encode([
        'status' => 'ok',
        'cliente' => $client['nome'],
        'device_id' => $deviceId ? intval($deviceId) : null,
        'server_time' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'network_event') {
    $in = mobile_v1_json_input();
    $tipo = substr(trim($in['tipo'] ?? 'NETWORK'), 0, 80);
    $descricao = substr(trim($in['descricao'] ?? 'Evento de rede Android'), 0, 500);
    $dados = $in['dados'] ?? [];

    try {
        $stmt = $pdo->prepare("INSERT INTO mobile_eventos (id_dispositivo, tipo, descricao, dados)
            VALUES (:d, :t, :x, CAST(:j AS jsonb))");
        $stmt->execute([
            ':d' => $deviceId ?: null,
            ':t' => $tipo,
            ':x' => $descricao,
            ':j' => json_encode($dados, JSON_UNESCAPED_UNICODE)
        ]);
        api_v1_log($pdo, 'MOBILE_NETWORK_EVENT', 'INFO', $client['nome'], ['tipo'=>$tipo]);
        echo json_encode(['status' => 'ok']);
    } catch (Throwable $e) {
        api_v1_json_response(500, ['status'=>'erro','mensagem'=>'Falha ao registrar evento móvel']);
    }
    exit;
}

if ($acao === 'notificacoes') {
    try {
        if ($deviceId) {
            $stmt = $pdo->prepare("SELECT id, titulo, mensagem, audio_url, prioridade, data_hora
                FROM mobile_notificacoes
                WHERE (id_dispositivo IS NULL OR id_dispositivo = :id)
                  AND entregue = FALSE
                ORDER BY CASE prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END, data_hora ASC
                LIMIT 10");
            $stmt->execute([':id' => intval($deviceId)]);
        } else {
            $stmt = $pdo->query("SELECT id, titulo, mensagem, audio_url, prioridade, data_hora
                FROM mobile_notificacoes
                WHERE id_dispositivo IS NULL AND entregue = FALSE
                ORDER BY CASE prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END, data_hora ASC
                LIMIT 10");
        }
        echo json_encode(['status'=>'ok','notificacoes'=>$stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        api_v1_json_response(500, ['status'=>'erro','mensagem'=>'Falha ao consultar notificações']);
    }
    exit;
}

if ($acao === 'ack') {
    $in = mobile_v1_json_input();
    $id = intval($in['id'] ?? 0);
    if ($id <= 0) api_v1_json_response(400, ['status'=>'erro','mensagem'=>'ID de notificação inválido']);

    try {
        if ($deviceId) {
            $stmt = $pdo->prepare("UPDATE mobile_notificacoes
                SET entregue=TRUE, lida=TRUE, data_entrega=CURRENT_TIMESTAMP, data_leitura=CURRENT_TIMESTAMP
                WHERE id=:nid AND (id_dispositivo IS NULL OR id_dispositivo=:did)");
            $stmt->execute([':nid'=>$id, ':did'=>intval($deviceId)]);
        } else {
            $stmt = $pdo->prepare("UPDATE mobile_notificacoes
                SET entregue=TRUE, lida=TRUE, data_entrega=CURRENT_TIMESTAMP, data_leitura=CURRENT_TIMESTAMP
                WHERE id=:nid AND id_dispositivo IS NULL");
            $stmt->execute([':nid'=>$id]);
        }
        echo json_encode(['status'=>'ok']);
    } catch (Throwable $e) {
        api_v1_json_response(500, ['status'=>'erro','mensagem'=>'Falha ao confirmar notificação']);
    }
    exit;
}

api_v1_json_response(404, [
    'status'=>'erro',
    'mensagem'=>'Ação mobile v1 desconhecida',
    'acoes'=>['status','network_event','notificacoes','ack']
]);
