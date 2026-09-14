<?php
// JARVIS RESIDENCIAL - API EXTERNA v1 PARA APP ANDROID
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../seguranca.php');

$pdo = get_db_pdo();

function mobile_v1_json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function mobile_v1_extract_token() {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return trim($m[1]);
    }
    if (!empty($_SERVER['HTTP_X_DEVICE_TOKEN'])) {
        return trim($_SERVER['HTTP_X_DEVICE_TOKEN']);
    }
    return '';
}

function mobile_v1_auth() {
    $token = mobile_v1_extract_token();
    if ($token === '') return false;
    return validar_hardware_token($token);
}

$dev = mobile_v1_auth();
if (!$dev) {
    registrar_falha_seguranca('Tentativa não autorizada na API mobile v1', 'AVISO');
    http_response_code(401);
    echo json_encode([
        'status' => 'erro',
        'codigo' => 401,
        'mensagem' => 'Token de dispositivo Android inválido ou ausente'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$acao = $_GET['acao'] ?? 'status';

if ($acao === 'status') {
    echo json_encode([
        'status' => 'ok',
        'device_id' => intval($dev['id']),
        'device_name' => $dev['nome'] ?? 'Android',
        'server_time' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'network_event') {
    $in = mobile_v1_json_input();
    $tipo = trim($in['tipo'] ?? 'NETWORK');
    $descricao = trim($in['descricao'] ?? 'Evento de rede Android');
    $dados = $in['dados'] ?? [];

    $stmt = $pdo->prepare("INSERT INTO mobile_eventos (id_dispositivo, tipo, descricao, dados)
        VALUES (:d, :t, :x, CAST(:j AS jsonb))");
    $stmt->execute([
        ':d' => intval($dev['id']),
        ':t' => $tipo,
        ':x' => $descricao,
        ':j' => json_encode($dados, JSON_UNESCAPED_UNICODE)
    ]);

    echo json_encode(['status' => 'ok']);
    exit;
}

if ($acao === 'notificacoes') {
    $stmt = $pdo->prepare("SELECT id, titulo, mensagem, audio_url, prioridade, data_hora
        FROM mobile_notificacoes
        WHERE (id_dispositivo IS NULL OR id_dispositivo = :id)
          AND entregue = FALSE
        ORDER BY CASE prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END, data_hora ASC
        LIMIT 10");
    $stmt->execute([':id' => intval($dev['id'])]);

    echo json_encode([
        'status' => 'ok',
        'notificacoes' => $stmt->fetchAll()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'ack') {
    $in = mobile_v1_json_input();
    $id = intval($in['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'erro', 'mensagem' => 'ID de notificação inválido']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE mobile_notificacoes
        SET entregue = TRUE,
            lida = TRUE,
            data_entrega = CURRENT_TIMESTAMP,
            data_leitura = CURRENT_TIMESTAMP
        WHERE id = :nid
          AND (id_dispositivo IS NULL OR id_dispositivo = :did)");
    $stmt->execute([
        ':nid' => $id,
        ':did' => intval($dev['id'])
    ]);

    echo json_encode(['status' => 'ok']);
    exit;
}

http_response_code(404);
echo json_encode([
    'status' => 'erro',
    'mensagem' => 'Ação mobile v1 desconhecida',
    'acoes' => ['status', 'network_event', 'notificacoes', 'ack']
], JSON_UNESCAPED_UNICODE);
