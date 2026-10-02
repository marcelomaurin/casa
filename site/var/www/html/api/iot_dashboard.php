<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/db.php';
if (empty($_SESSION['auth_user'])) {
    verify_api_auth();
}
$pdo = get_db_pdo();

function out($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function caps_array($v) {
    $a = json_decode((string)$v, true);
    return is_array($a) ? $a : [];
}
function json_array($v) {
    $a = json_decode((string)$v, true);
    return is_array($a) ? $a : [];
}

function ensure_iot_dashboard_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS device_desired_state (
            device_id VARCHAR(120) NOT NULL PRIMARY KEY,
            desired_state JSON NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS device_environment_readings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(120) NOT NULL,
            temperature_c DECIMAL(5,2) NULL,
            humidity_pct DECIMAL(5,2) NULL,
            sensor_type VARCHAR(40) NULL,
            observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dev_env_obs (device_id, observed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    $done = true;
}

ensure_iot_dashboard_tables($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $rows = [];
        try {
            $sql = "SELECT d.device_id, d.nome, d.tipo, d.model, d.localizacao, d.status, d.ip_address, d.mac_address, d.sinal_rssi, d.ultimo_heartbeat, d.capabilities, d.metadata, s.desired_state, s.updated_at AS desired_updated
                    FROM dispositivos_cluster d
                    LEFT JOIN device_desired_state s ON s.device_id=d.device_id
                    WHERE d.tipo NOT IN ('linux-arm', 'cluster', 'server')
                      AND d.device_id NOT LIKE 'raspberry%'
                      AND d.device_id NOT LIKE 'cubie%'
                    ORDER BY COALESCE(d.nome, d.model, d.device_id), d.device_id";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Fallback caso a tabela device_desired_state ainda não tenha sido ligada
            $sql = "SELECT d.device_id, d.nome, d.tipo, d.model, d.localizacao, d.status, d.ip_address, d.mac_address, d.sinal_rssi, d.ultimo_heartbeat, d.capabilities, d.metadata, NULL AS desired_state, NULL AS desired_updated
                    FROM dispositivos_cluster d
                    WHERE d.tipo NOT IN ('linux-arm', 'cluster', 'server')
                      AND d.device_id NOT LIKE 'raspberry%'
                      AND d.device_id NOT LIKE 'cubie%'
                    ORDER BY COALESCE(d.nome, d.model, d.device_id), d.device_id";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }

        $latest = [];
        try {
            $q = $pdo->query("SELECT r.device_id, r.temperature_c, r.humidity_pct, r.sensor_type, r.observed_at
                              FROM device_environment_readings r
                              INNER JOIN (SELECT device_id, MAX(id) id FROM device_environment_readings GROUP BY device_id) x ON x.id=r.id");
            if ($q) {
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $latest[$r['device_id']] = $r;
                }
            }
        } catch (Throwable $e) {}

        $devices = [];
        $relaysCount = 0;
        $relaysOn = 0;
        $sensorsCount = 0;
        $onlineDevices = 0;
        $bestTemp = null;
        $bestHum = null;

        foreach ($rows as $r) {
            $caps = caps_array($r['capabilities'] ?? '[]');
            if (in_array('arm-agent', $caps, true) || in_array('linux-arm', $caps, true) || $r['tipo'] === 'linux-arm') continue;

            $meta = json_array($r['metadata'] ?? '{}');
            $desired = json_array($r['desired_state'] ?? '{}');
            $env = $latest[$r['device_id']] ?? null;

            $isRelay = in_array('relay', $caps, true) || in_array('switch', $caps, true);
            $isEnv = ($env !== null) || in_array('temperature', $caps, true) || in_array('humidity', $caps, true);

            $relayActual = array_key_exists('relay_on', $meta) ? (bool)$meta['relay_on'] : null;
            $relayDesired = array_key_exists('relay_on', $desired) ? (bool)$desired['relay_on'] : null;

            if ($isRelay) {
                $relaysCount++;
                if ($relayActual === true || $relayDesired === true) $relaysOn++;
            }
            if ($isEnv) {
                $sensorsCount++;
                if ($env && $env['temperature_c'] !== null) {
                    $bestTemp = (float)$env['temperature_c'];
                    $bestHum = $env['humidity_pct'] !== null ? (float)$env['humidity_pct'] : null;
                }
            }
            if (strtolower((string)$r['status']) === 'online') {
                $onlineDevices++;
            }

            $devices[] = [
                'device_id' => $r['device_id'],
                'nome' => $r['nome'],
                'tipo' => $r['tipo'],
                'model' => $r['model'],
                'localizacao' => $r['localizacao'],
                'status' => $r['status'],
                'ip_address' => $r['ip_address'],
                'sinal_rssi' => $r['sinal_rssi'],
                'ultimo_heartbeat' => $r['ultimo_heartbeat'],
                'capabilities' => $caps,
                'is_relay' => $isRelay,
                'is_environment' => $isEnv,
                'relay_actual' => $relayActual,
                'relay_desired' => $relayDesired,
                'desired_updated' => $r['desired_updated'],
                'environment' => $env
            ];
        }

        // Informações de presença familiar (celulares e watch)
        $mobilesOnline = 0;
        $watchOnline = 0;
        try {
            $stmt = $pdo->query("SELECT plataforma, COUNT(*) as qtd FROM family_presence WHERE ultimo_ping >= DATE_SUB(NOW(), INTERVAL 90 SECOND) GROUP BY plataforma");
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rowP) {
                    $p = strtolower($rowP['plataforma'] ?? '');
                    if ($p === 'watch') $watchOnline += (int)$rowP['qtd'];
                    else $mobilesOnline += (int)$rowP['qtd'];
                }
            }
        } catch (Throwable $e) {}

        $summary = [
            'total_devices' => count($devices),
            'online_devices' => $onlineDevices,
            'relays_count' => $relaysCount,
            'relays_on' => $relaysOn,
            'sensors_count' => $sensorsCount,
            'temperature_c' => $bestTemp,
            'humidity_pct' => $bestHum,
            'mobiles_online' => $mobilesOnline,
            'watch_online' => $watchOnline,
            'system_status' => 'ONLINE'
        ];

        out(200, ['status' => 'ok', 'summary' => $summary, 'devices' => $devices]);
    } catch (Throwable $e) {
        error_log('iot_dashboard GET err: ' . $e->getMessage());
        out(200, [
            'status' => 'ok',
            'summary' => [
                'total_devices' => 0, 'online_devices' => 0, 'relays_count' => 0,
                'relays_on' => 0, 'sensors_count' => 0, 'temperature_c' => null,
                'humidity_pct' => null, 'mobiles_online' => 0, 'watch_online' => 0,
                'system_status' => 'ONLINE'
            ],
            'devices' => []
        ]);
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    if (($input['acao'] ?? '') !== 'relay_state') out(400, ['status' => 'erro', 'mensagem' => 'Ação inválida.']);
    $deviceId = trim((string)($input['device_id'] ?? ''));
    $relay = $input['relay_on'] ?? null;
    if ($deviceId === '' || !is_bool($relay)) out(400, ['status' => 'erro', 'mensagem' => 'device_id e relay_on booleano são obrigatórios.']);
    try {
        $st = $pdo->prepare("SELECT capabilities FROM dispositivos_cluster WHERE device_id=:d LIMIT 1");
        $st->execute([':d' => $deviceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) out(404, ['status' => 'erro', 'mensagem' => 'Dispositivo não encontrado.']);
        $caps = caps_array($row['capabilities'] ?? '[]');
        if (!in_array('relay', $caps, true) && !in_array('switch', $caps, true)) {
            out(400, ['status' => 'erro', 'mensagem' => 'Dispositivo não anuncia capacidade de relé.']);
        }
        $json = json_encode(['relay_on' => $relay], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare("INSERT INTO device_desired_state(device_id, desired_state, updated_at) VALUES(:d, :s, NOW()) ON DUPLICATE KEY UPDATE desired_state=VALUES(desired_state), updated_at=NOW()")
            ->execute([':d' => $deviceId, ':s' => $json]);
        out(200, ['status' => 'ok', 'device_id' => $deviceId, 'relay_on' => $relay, 'mensagem' => 'Estado desejado atualizado.']);
    } catch (Throwable $e) {
        error_log('iot_dashboard POST err: ' . $e->getMessage());
        out(500, ['status' => 'erro', 'mensagem' => 'Falha ao atualizar o relé.']);
    }
}

out(405, ['status' => 'erro', 'mensagem' => 'Método não permitido.']);
