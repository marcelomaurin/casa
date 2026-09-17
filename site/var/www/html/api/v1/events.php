<?php
// CASA/JARVIS - stream SSE autenticado de eventos do Control Plane.
// Mantem conexoes curtas (ate 25 s) para funcionar tambem em hospedagens PHP compartilhadas.
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Last-Event-ID');

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
$client = api_v1_auth_client_any($pdo, ['devices.read','mobile.read','home.read']);

$after = max(0, (int)($_GET['after'] ?? ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0)));
$deviceFilter = substr(trim((string)($_GET['device_id'] ?? '')), 0, 120);
$deadline = microtime(true) + 25.0;

ignore_user_abort(true);
@set_time_limit(30);

echo "retry: 3000\n\n";
@ob_flush(); @flush();

while (microtime(true) < $deadline) {
    if (connection_aborted()) break;

    if ($deviceFilter !== '') {
        $stmt = $pdo->prepare("SELECT id,device_id,tipo,prioridade,correlation_id,dados,criado_em FROM device_events WHERE id>:a AND device_id=:d ORDER BY id ASC LIMIT 50");
        $stmt->execute([':a'=>$after, ':d'=>$deviceFilter]);
    } else {
        $stmt = $pdo->prepare("SELECT id,device_id,tipo,prioridade,correlation_id,dados,criado_em FROM device_events WHERE id>:a ORDER BY id ASC LIMIT 50");
        $stmt->execute([':a'=>$after]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        foreach ($rows as $row) {
            $after = max($after, (int)$row['id']);
            $payload = [
                'id'=>(int)$row['id'],
                'device_id'=>$row['device_id'],
                'type'=>$row['tipo'],
                'priority'=>$row['prioridade'],
                'correlation_id'=>$row['correlation_id'],
                'data'=>is_string($row['dados']) ? (json_decode($row['dados'], true) ?: []) : $row['dados'],
                'created_at'=>$row['criado_em']
            ];
            echo 'id: '.$after."\n";
            echo 'event: '.preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)$row['tipo'])."\n";
            echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
        }
        @ob_flush(); @flush();
        continue;
    }

    echo ": keepalive ".date('c')."\n\n";
    @ob_flush(); @flush();
    sleep(2);
}

api_v1_log($pdo, 'SSE_SESSION', 'INFO', $client['nome'] ?? null, ['last_event_id'=>$after,'device_id'=>$deviceFilter ?: null]);
