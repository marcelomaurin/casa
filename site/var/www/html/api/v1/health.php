<?php
// CASA/JARVIS - liveness probe publico e minimo.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'service' => 'casa-api-v1',
    'check' => 'health',
    'timestamp' => date('c')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
