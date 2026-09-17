<?php
// CASA/JARVIS - readiness probe. Verifica dependencias essenciais sem expor segredos.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once(__DIR__ . '/../db.php');

$checks = [
    'database' => false,
    'control_plane' => false,
    'schema_version' => false
];
$error = null;
$schemaVersion = null;

try {
    $pdo = get_db_pdo();
    $pdo->query('SELECT 1')->fetchColumn();
    $checks['database'] = true;

    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('dispositivos_cluster','device_commands','device_events')");
    $checks['control_plane'] = ((int)$stmt->fetchColumn() >= 3);

    $schemaVersion = casa_schema_version($pdo);
    $checks['schema_version'] = ($schemaVersion === CASA_SCHEMA_VERSION);
} catch (Throwable $e) {
    $error = 'Dependencia essencial indisponivel';
}

$ready = !in_array(false, $checks, true);
http_response_code($ready ? 200 : 503);
echo json_encode([
    'status' => $ready ? 'ready' : 'not_ready',
    'service' => 'casa-api-v1',
    'check' => 'ready',
    'schema_version' => $schemaVersion,
    'expected_schema_version' => CASA_SCHEMA_VERSION,
    'checks' => $checks,
    'message' => $error,
    'timestamp' => date('c')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
