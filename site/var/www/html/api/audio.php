<?php
// Endpoint de Streaming de Áudios Gerados pelo JARVIS
require_once(__DIR__ . '/db.php');
verify_api_auth();

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
header('Cache-Control: public, max-age=86400');
readfile($path);
exit;
?>
