<?php
// JARVIS RESIDENCIAL - API APP ANDROID
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');

$pdo = get_db_pdo();

function mobile_json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function mobile_device_auth() {
    $token = isset($_SERVER['HTTP_X_DEVICE_TOKEN']) ? trim($_SERVER['HTTP_X_DEVICE_TOKEN']) : '';
    if ($token === '') return false;
    return validar_hardware_token($token);
}

function mobile_tts($texto) {
    if (trim($texto) === '') return null;
    $payload = json_encode(['texto' => $texto, 'speaker' => 'padrao', 'reproduzir' => false], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('http://127.0.0.1:8097/falar');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $res = curl_exec($ch);
    curl_close($ch);
    if (!$res) return null;
    $j = json_decode($res, true);
    return (!empty($j['audio_url'])) ? '/api/audio.php?file=' . rawurlencode(basename($j['audio_url'])) : null;
}

$acao = $_GET['acao'] ?? ($_POST['acao'] ?? 'status');

if ($acao === 'status') {
    $dev = mobile_device_auth();
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Token inválido']);
        exit;
    }
    echo json_encode(['status' => 'ok', 'device_id' => $dev['id'], 'server_time' => date('c')]);
    exit;
}

if ($acao === 'network_event') {
    $dev = mobile_device_auth();
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro']);
        exit;
    }
    $in = mobile_json_input();
    $tipo = trim($in['tipo'] ?? 'NETWORK');
    $descricao = trim($in['descricao'] ?? 'Evento de rede Android');
    $dados = $in['dados'] ?? [];

    $stmt = $pdo->prepare("INSERT INTO mobile_eventos (id_dispositivo, tipo, descricao, dados)
        VALUES (:d, :t, :x, CAST(:j AS jsonb))");
    $stmt->execute([
        ':d' => $dev['id'],
        ':t' => $tipo,
        ':x' => $descricao,
        ':j' => json_encode($dados, JSON_UNESCAPED_UNICODE)
    ]);

    echo json_encode(['status' => 'ok']);
    exit;
}

if ($acao === 'notificacoes') {
    $dev = mobile_device_auth();
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, titulo, mensagem, audio_url, prioridade, data_hora
        FROM mobile_notificacoes
        WHERE (id_dispositivo IS NULL OR id_dispositivo = :id)
          AND entregue = FALSE
        ORDER BY CASE prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END, data_hora ASC
        LIMIT 10");
    $stmt->execute([':id' => $dev['id']]);
    echo json_encode(['status' => 'ok', 'notificacoes' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'ack') {
    $dev = mobile_device_auth();
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro']);
        exit;
    }
    $in = mobile_json_input();
    $id = intval($in['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'erro']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE mobile_notificacoes
        SET entregue = TRUE, lida = TRUE, data_entrega = CURRENT_TIMESTAMP, data_leitura = CURRENT_TIMESTAMP
        WHERE id = :nid AND (id_dispositivo IS NULL OR id_dispositivo = :did)");
    $stmt->execute([':nid' => $id, ':did' => $dev['id']]);
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($acao === 'notificar') {
    verify_api_auth();
    $in = mobile_json_input();
    $titulo = trim($in['titulo'] ?? 'JARVIS');
    $mensagem = trim($in['mensagem'] ?? '');
    $prioridade = strtolower(trim($in['prioridade'] ?? 'normal'));
    $idDisp = isset($in['id_dispositivo']) ? intval($in['id_dispositivo']) : null;
    $falar = array_key_exists('falar', $in) ? (bool)$in['falar'] : true;

    if ($mensagem === '') {
        http_response_code(400);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Mensagem vazia']);
        exit;
    }
    if (!in_array($prioridade, ['normal','alta','critica'], true)) $prioridade = 'normal';
    $audioUrl = $falar ? mobile_tts($mensagem) : null;

    $stmt = $pdo->prepare("INSERT INTO mobile_notificacoes
        (id_dispositivo, titulo, mensagem, audio_url, prioridade)
        VALUES (:d,:t,:m,:a,:p) RETURNING id");
    $stmt->execute([
        ':d' => $idDisp ?: null,
        ':t' => $titulo ?: 'JARVIS',
        ':m' => $mensagem,
        ':a' => $audioUrl,
        ':p' => $prioridade
    ]);
    echo json_encode(['status' => 'ok', 'id' => intval($stmt->fetchColumn()), 'audio_url' => $audioUrl], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'erro', 'mensagem' => 'Ação desconhecida']);
