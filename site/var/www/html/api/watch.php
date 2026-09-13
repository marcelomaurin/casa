<?php
// JARVIS RESIDENCIAL - API LILYGO WATCH
// Recursos:
//  - heartbeat/status do relogio
//  - fila de notificacoes
//  - notificacao falada via TTS
//  - conversa por voz: audio -> STT -> JARVIS -> TTS -> audio_url

header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');

$pdo = get_db_pdo();

function watch_config($chave, $padrao = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT valor FROM configuracoes_sistema WHERE chave = :c LIMIT 1');
        $stmt->execute([':c' => $chave]);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null) ? $v : $padrao;
    } catch (Exception $e) {
        return $padrao;
    }
}

function watch_json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function watch_tts($texto, $speaker = 'padrao') {
    if (trim($texto) === '') return null;
    $payload = json_encode([
        'texto' => $texto,
        'speaker' => $speaker,
        'reproduzir' => false
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('http://127.0.0.1:8097/falar');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$res || $code < 200 || $code >= 300) return null;
    $j = json_decode($res, true);
    if (!is_array($j) || empty($j['audio_url'])) return null;
    return '/api/audio.php?file=' . rawurlencode(basename($j['audio_url']));
}

function watch_transcrever($arquivo) {
    $url = watch_config('watch_stt_url', 'http://127.0.0.1:8098/v1/audio/transcriptions');
    $model = watch_config('watch_stt_model', 'whisper-1');
    $apiKey = watch_config('watch_stt_api_key', '');

    if (!$url || !file_exists($arquivo)) {
        return ['ok' => false, 'erro' => 'STT não configurado ou arquivo inexistente'];
    }

    $post = [
        'model' => $model,
        'language' => 'pt',
        'file' => new CURLFile(realpath($arquivo), 'audio/wav', basename($arquivo))
    ];

    $headers = [];
    if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$res || $code < 200 || $code >= 300) {
        return ['ok' => false, 'erro' => $err ?: ('HTTP STT ' . $code)];
    }

    $j = json_decode($res, true);
    if (!is_array($j)) return ['ok' => false, 'erro' => 'Resposta STT inválida'];
    $texto = isset($j['text']) ? trim($j['text']) : (isset($j['texto']) ? trim($j['texto']) : '');
    if ($texto === '') return ['ok' => false, 'erro' => 'STT não retornou texto'];
    return ['ok' => true, 'texto' => $texto];
}

function watch_chamar_jarvis($texto) {
    $token = get_system_api_token();
    $payload = json_encode([
        'comando' => $texto,
        'origem' => 'LILYGO_WATCH'
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('http://127.0.0.1/api/jarvis.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$res || $code < 200 || $code >= 300) {
        return ['ok' => false, 'erro' => $err ?: ('HTTP JARVIS ' . $code)];
    }
    $j = json_decode($res, true);
    if (!is_array($j)) return ['ok' => false, 'erro' => 'Resposta do JARVIS inválida'];
    return ['ok' => true, 'dados' => $j];
}

function watch_dev_auth() {
    $token = isset($_SERVER['HTTP_X_DEVICE_TOKEN']) ? trim($_SERVER['HTTP_X_DEVICE_TOKEN']) : '';
    if ($token === '') return false;
    return validar_hardware_token($token);
}

$acao = isset($_GET['acao']) ? $_GET['acao'] : (isset($_POST['acao']) ? $_POST['acao'] : 'status');

if ($acao === 'status') {
    $dev = watch_dev_auth();
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Token de dispositivo inválido']);
        exit;
    }
    echo json_encode([
        'status' => 'ok',
        'jarvis' => 'online',
        'device_id' => $dev['id'],
        'server_time' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'heartbeat') {
    $dev = watch_dev_auth();
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro']);
        exit;
    }
    $in = watch_json_input();
    try {
        $stmt = $pdo->prepare("UPDATE dispositivos_cluster
            SET ultimo_heartbeat = CURRENT_TIMESTAMP,
                ip_address = :ip,
                sinal_rssi = COALESCE(:rssi, sinal_rssi),
                ram_livre = COALESCE(:heap, ram_livre),
                status = 'online'
            WHERE id = :id");
        $stmt->execute([
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':rssi' => isset($in['rssi']) ? intval($in['rssi']) : null,
            ':heap' => isset($in['free_heap']) ? intval($in['free_heap']) : null,
            ':id' => $dev['id']
        ]);
    } catch (Exception $e) {}
    echo json_encode(['status' => 'ok', 'server_time' => date('c')]);
    exit;
}

if ($acao === 'notificacoes') {
    $dev = watch_dev_auth();
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, titulo, mensagem, audio_url, prioridade, data_hora
        FROM watch_notificacoes
        WHERE (id_dispositivo IS NULL OR id_dispositivo = :id)
          AND entregue = FALSE
        ORDER BY CASE prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 ELSE 3 END, data_hora ASC
        LIMIT 5");
    $stmt->execute([':id' => $dev['id']]);
    $rows = $stmt->fetchAll();

    echo json_encode(['status' => 'ok', 'notificacoes' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'ack') {
    $dev = watch_dev_auth();
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro']);
        exit;
    }
    $in = watch_json_input();
    $id = isset($in['id']) ? intval($in['id']) : intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'erro', 'mensagem' => 'ID inválido']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE watch_notificacoes
        SET entregue = TRUE, lida = TRUE, data_entrega = CURRENT_TIMESTAMP, data_leitura = CURRENT_TIMESTAMP
        WHERE id = :nid AND (id_dispositivo IS NULL OR id_dispositivo = :did)");
    $stmt->execute([':nid' => $id, ':did' => $dev['id']]);
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($acao === 'voz') {
    $dev = watch_dev_auth();
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Token de dispositivo inválido']);
        exit;
    }

    $tmp = null;
    if (!empty($_FILES['audio']['tmp_name'])) {
        $tmp = $_FILES['audio']['tmp_name'];
    } else {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $tmp = tempnam(sys_get_temp_dir(), 'jarvis_watch_') . '.wav';
            file_put_contents($tmp, $raw);
        }
    }
    if (!$tmp || !file_exists($tmp)) {
        http_response_code(400);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Áudio não recebido']);
        exit;
    }

    $stt = watch_transcrever($tmp);
    if (strpos($tmp, sys_get_temp_dir()) === 0) @unlink($tmp);
    if (!$stt['ok']) {
        http_response_code(502);
        echo json_encode(['status' => 'erro', 'etapa' => 'stt', 'mensagem' => $stt['erro']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jarvis = watch_chamar_jarvis($stt['texto']);
    if (!$jarvis['ok']) {
        http_response_code(502);
        echo json_encode(['status' => 'erro', 'etapa' => 'jarvis', 'mensagem' => $jarvis['erro']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dados = $jarvis['dados'];
    echo json_encode([
        'status' => 'ok',
        'texto_usuario' => $stt['texto'],
        'resposta' => $dados['resposta'] ?? '',
        'audio_url' => $dados['audio_url'] ?? null,
        'acao' => $dados['acao'] ?? null,
        'provedor' => $dados['provedor'] ?? null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'notificar') {
    verify_api_auth();
    $in = watch_json_input();
    $titulo = trim($in['titulo'] ?? ($_POST['titulo'] ?? 'JARVIS'));
    $mensagem = trim($in['mensagem'] ?? ($_POST['mensagem'] ?? ''));
    $prioridade = strtolower(trim($in['prioridade'] ?? ($_POST['prioridade'] ?? 'normal')));
    $idDispositivo = isset($in['id_dispositivo']) ? intval($in['id_dispositivo']) : (isset($_POST['id_dispositivo']) ? intval($_POST['id_dispositivo']) : null);
    $falar = isset($in['falar']) ? (bool)$in['falar'] : true;

    if ($mensagem === '') {
        http_response_code(400);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Mensagem vazia']);
        exit;
    }
    if (!in_array($prioridade, ['normal', 'alta', 'critica'], true)) $prioridade = 'normal';

    $audioUrl = $falar ? watch_tts($mensagem, 'padrao') : null;
    $stmt = $pdo->prepare("INSERT INTO watch_notificacoes
        (id_dispositivo, titulo, mensagem, audio_url, prioridade)
        VALUES (:d, :t, :m, :a, :p) RETURNING id");
    $stmt->execute([
        ':d' => $idDispositivo ?: null,
        ':t' => $titulo ?: 'JARVIS',
        ':m' => $mensagem,
        ':a' => $audioUrl,
        ':p' => $prioridade
    ]);
    $id = $stmt->fetchColumn();

    echo json_encode([
        'status' => 'ok',
        'id' => intval($id),
        'audio_url' => $audioUrl
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'erro', 'mensagem' => 'Ação desconhecida']);
