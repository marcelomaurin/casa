<?php
// Endpoint de Streaming de Áudios Gerados pelo JARVIS
// Aceita sessão/API normal ou token de hardware de um dispositivo cadastrado.
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');

$autorizado = false;
$deviceToken = isset($_SERVER['HTTP_X_DEVICE_TOKEN']) ? trim($_SERVER['HTTP_X_DEVICE_TOKEN']) : '';

if ($deviceToken !== '') {
    $dev = validar_hardware_token($deviceToken);
    $autorizado = ($dev !== false && $dev !== null);
}

if (!$autorizado) {
    // Mantém compatibilidade com sessão Web, Bearer e X-API-Key.
    verify_api_auth();
}

$file = isset($_GET['file']) ? basename($_GET['file']) : '';
if (empty($file) || !preg_match('/\.wav$/i', $file)) {
    http_response_code(400);
    echo "Arquivo inválido.";
    exit;
}

$path = '/home/mmm/servicos/tts/audios/' . $file;
if (!file_exists($path)) {
    http_response_code(404);
    echo "Áudio não encontrado.";
    exit;
}

header('Content-Type: audio/wav');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
readfile($path);
exit;
?>
