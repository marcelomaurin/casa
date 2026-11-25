<?php
require_once __DIR__ . '/../../site/var/www/html/api/v1/ai_attendance.php';
$base = $argv[1] ?? '';
if (!preg_match('#\Ahttp://127\.0\.0\.1:[0-9]+\z#', $base)) throw new RuntimeException('Informe a URL local da fixture');
$payload = api_v1_attendance_payload(json_encode([
    'mensagem'=>'E na cozinha?', 'session_id'=>'http-fixture-call',
    'historico_mensagens'=>[['role'=>'assistant','content'=>'Na sala, vinte graus.']],
]), 'sip/atender', 'http-fixture-request');
$response = api_v1_attendance_execute($payload, $base.'/reply', 'fixture-internal-token');
if ($response['resposta'] !== 'Na cozinha, vinte e dois graus.' || $response['audio_url'] !== null) {
    throw new RuntimeException('Falha na resposta HTTP para SIP');
}
foreach (['/unavailable'=>503, '/invalid'=>502, '/redirect'=>502, '/too-large'=>502] as $path=>$status) {
    $failed = false;
    try { api_v1_attendance_execute($payload, $base.$path, 'fixture-internal-token'); }
    catch (ApiV1AttendanceException $e) { $failed = $e->getCode() === $status; }
    if (!$failed) throw new RuntimeException('Falha HTTP nao reconhecida: '.$path);
}
echo "PASS: transporte HTTP real, corpo/contexto, chave interna, limites e falhas de upstream\n";
