<?php
// CASA/JARVIS - API REST v1 - ESP32-CAM
// Heartbeat da camera (firmware jarvis_espcam >= 2.0.1).
//
// GET /api/v1/camera.php?acao=heartbeat&device_id=...&ip=...&mac=...&rssi=...
// Cabecalho obrigatorio: X-Camera-Token (o token registrado pelo gravafirmware).
//
// E um wrapper fino do api/camera_discovery.php: aplica as protecoes basicas da
// v1 (metodo, payload, token fora da URL, rate limit) e libera SOMENTE a acao
// heartbeat. As demais acoes de cameras continuam no endpoint do painel, com
// sessao ou chave de API. Cameras com firmware 2.0.0 seguem usando o caminho
// antigo (camera_discovery.php?acao=heartbeat), que continua aceito.

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);

$acao = (string)($_GET['acao'] ?? 'heartbeat');
if ($acao !== 'heartbeat') {
    api_v1_json_response(404, ['ok' => false, 'erro' => 'Acao de camera desconhecida na API v1']);
}

$camToken = trim((string)($_SERVER['HTTP_X_CAMERA_TOKEN'] ?? ''));
if ($camToken === '') {
    api_v1_json_response(401, ['ok' => false, 'erro' => 'Cabecalho X-Camera-Token obrigatorio']);
}

// A camera manda um heartbeat a cada 30 s; 10 por minuto sobra com folga.
api_v1_rate_limit($pdo, 'camera|' . $camToken, 10, 60);

// Repassa ao endpoint de cameras com a acao fixada. O token e validado la,
// contra o registrado em dispositivos_cluster.metadata.token.
$_GET['acao'] = 'heartbeat';
unset($_POST['acao']);
require __DIR__ . '/../camera_discovery.php';
