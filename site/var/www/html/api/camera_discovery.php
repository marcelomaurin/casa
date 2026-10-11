<?php
/**
 * CASA / JARVIS RESIDENCIAL
 * API de Descoberta e Gestao de Cameras (ESP32-CAM, Cameras IP, CFTV/DVR/NVR)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once(__DIR__ . '/db.php');
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (empty(['auth_user'])) {
    verify_api_auth();
}

function cam_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = get_db_pdo();

// Assegura colunas necessarias em dispositivos_cluster
try {
    $pdo->exec("ALTER TABLE dispositivos_cluster ADD COLUMN IF NOT EXISTS metadata JSON NULL");
    $pdo->exec("ALTER TABLE dispositivos_cluster ADD COLUMN IF NOT EXISTS capabilities JSON NULL");
} catch (Throwable $ignored) {}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '', true);
    if (!is_array($input)) {
        $input = $_POST;
    }
}

$acao = $_GET['acao'] ?? ($input['acao'] ?? 'listar');

// -------------------------------------------------------------
// Helper: Sondagem rapida de portas TCP
// -------------------------------------------------------------
function probe_port(string $ip, int $port, float $timeout = 0.35): bool
{
    $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

// -------------------------------------------------------------
// Helper: Classificacao do tipo de camera por portas e assinaturas
// -------------------------------------------------------------
function classify_camera(string $ip, array $open_ports, string $user = 'admin', string $pass = ''): array
{
    $has_rtsp = in_array(554, $open_ports, true);
    $has_esp = in_array(81, $open_ports, true);
    $has_http = in_array(80, $open_ports, true) || in_array(8080, $open_ports, true);
    $has_onvif = in_array(8899, $open_ports, true) || in_array(5000, $open_ports, true);
    $has_dvr_dahua = in_array(37777, $open_ports, true);
    $has_dvr_hik = in_array(8000, $open_ports, true);
    $has_dvr_xmeye = in_array(34567, $open_ports, true);

    $tipo = 'ip_camera';
    $tipo_label = 'Câmera IP';
    $modelo = 'Câmera IP (RTSP/ONVIF)';
    $fabricante = 'Genérico';
    $stream_url = '';
    $snapshot_url = '';
    $canal = 1;

    $cred_str = ($user !== '' ? $user . ($pass !== '' ? ':' . $pass : '') . '@' : '');

    if ($has_esp) {
        $tipo = 'espcam';
        $tipo_label = 'ESP32-CAM';
        $modelo = 'ESP32-CAM (MJPEG Stream)';
        $fabricante = 'Espressif';
        $stream_url = "http://{$ip}:81/stream";
        $snapshot_url = "http://{$ip}/capture";
    } elseif ($has_dvr_dahua) {
        $tipo = 'cftv';
        $tipo_label = 'CFTV / DVR (Intelbras/Dahua)';
        $modelo = 'DVR / NVR Multi-Canal (Dahua/Intelbras)';
        $fabricante = 'Intelbras / Dahua';
        $stream_url = "rtsp://{$cred_str}{$ip}:554/cam/realmonitor?channel=1&subtype=0";
        $snapshot_url = "http://{$ip}/cgi-bin/snapshot.cgi?channel=1";
    } elseif ($has_dvr_hik) {
        $tipo = 'cftv';
        $tipo_label = 'CFTV / DVR (Hikvision)';
        $modelo = 'DVR / NVR Multi-Canal (Hikvision)';
        $fabricante = 'Hikvision';
        $stream_url = "rtsp://{$cred_str}{$ip}:554/Streaming/Channels/101";
        $snapshot_url = "http://{$ip}/ISAPI/Streaming/channels/101/picture";
    } elseif ($has_dvr_xmeye) {
        $tipo = 'cftv';
        $tipo_label = 'CFTV / DVR (XMeye/H.264)';
        $modelo = 'DVR / NVR Multi-Canal (XMeye)';
        $fabricante = 'XMeye / Xiongmai';
        $stream_url = "rtsp://{$cred_str}{$ip}:554/user={$user}&password={$pass}&channel=1&stream=0.sdp";
        $snapshot_url = "http://{$ip}/snapshot.cgi?channel=1";
    } elseif ($has_rtsp) {
        $tipo = 'ip_camera';
        $tipo_label = 'Câmera IP (RTSP)';
        $modelo = 'Câmera IP RTSP H.264/H.265';
        $fabricante = 'ONVIF / RTSP Padrão';
        $stream_url = "rtsp://{$cred_str}{$ip}:554/stream1";
        $snapshot_url = "http://{$ip}/snapshot.jpg";
    } elseif ($has_onvif) {
        $tipo = 'ip_camera';
        $tipo_label = 'Câmera IP (ONVIF)';
        $modelo = 'Câmera IP ONVIF';
        $fabricante = 'ONVIF';
        $stream_url = "rtsp://{$cred_str}{$ip}:554/onvif1";
        $snapshot_url = "http://{$ip}/onvif/snapshot";
    } elseif ($has_http) {
        // Testa se responde captura de ESP32-CAM na porta 80
        $tipo = 'espcam';
        $tipo_label = 'ESP32-CAM';
        $modelo = 'ESP32-CAM / Módulo HTTP';
        $fabricante = 'Espressif / WebCam';
        $stream_url = "http://{$ip}/stream";
        $snapshot_url = "http://{$ip}/capture";
    }

    return [
        'ip' => $ip,
        'tipo' => $tipo,
        'tipo_label' => $tipo_label,
        'modelo' => $modelo,
        'fabricante' => $fabricante,
        'portas_abertas' => $open_ports,
        'stream_url' => $stream_url,
        'snapshot_url' => $snapshot_url,
        'canal' => $canal,
        'user' => $user,
        'pass' => $pass,
        'web_port' => in_array(80, $open_ports, true) ? 80 : (in_array(8080, $open_ports, true) ? 8080 : 0)
    ];
}

// -------------------------------------------------------------
// Acao: LISTAR
// -------------------------------------------------------------
if ($acao === 'listar') {
    try {
        $stmt = $pdo->query("
            SELECT id, device_id, nome, tipo, ip_address, mac_address, status,
                   capabilities, metadata, localizacao, ultimo_heartbeat, criado_em
              FROM dispositivos_cluster
             WHERE tipo IN ('camera', 'espcam', 'esp32_cam', 'ip_camera', 'camera_ip', 'cftv', 'dvr', 'nvr')
                OR capabilities LIKE '%camera%'
                OR capabilities LIKE '%streaming%'
                OR capabilities LIKE '%rtsp%'
                OR capabilities LIKE '%cftv%'
             ORDER BY id DESC
        ");
        $cams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Processa metadata e normaliza tipo
        foreach ($cams as &$c) {
            if (!empty($c['capabilities']) && is_string($c['capabilities'])) {
                $c['capabilities'] = json_decode($c['capabilities'], true) ?: [$c['capabilities']];
            }
            if (!empty($c['metadata']) && is_string($c['metadata'])) {
                $c['metadata'] = json_decode($c['metadata'], true) ?: [];
            } else {
                $c['metadata'] = is_array($c['metadata'] ?? null) ? $c['metadata'] : [];
            }

            // Normaliza tipo
            $t = strtolower((string)$c['tipo']);
            if ($t === 'cftv' || $t === 'dvr' || $t === 'nvr') {
                $c['tipo_normalizado'] = 'cftv';
                $c['tipo_label'] = 'CFTV / DVR';
            } elseif ($t === 'ip_camera' || $t === 'camera_ip') {
                $c['tipo_normalizado'] = 'ip_camera';
                $c['tipo_label'] = 'Câmera IP';
            } else {
                $c['tipo_normalizado'] = 'espcam';
                $c['tipo_label'] = 'ESP32-CAM';
            }
        }
        unset($c);

        cam_json([
            'ok' => true,
            'total' => count($cams),
            'cameras' => $cams
        ]);
    } catch (Throwable $e) {
        cam_json(['ok' => false, 'erro' => $e->getMessage()], 500);
    }
}

// -------------------------------------------------------------
// Acao: PROBE_IP (Sondagem individual de IP)
// -------------------------------------------------------------
if ($acao === 'probe_ip') {
    $ip = trim((string)($input['ip'] ?? $_GET['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        cam_json(['ok' => false, 'erro' => 'IP inválido fornecido'], 400);
    }

    $user = trim((string)($input['user'] ?? $_GET['user'] ?? 'admin'));
    $pass = (string)($input['pass'] ?? $_GET['pass'] ?? '');

    $test_ports = [80, 81, 554, 8000, 37777, 8899, 8080, 5000, 34567];
    $open_ports = [];

    foreach ($test_ports as $p) {
        if (probe_port($ip, $p, 0.4)) {
            $open_ports[] = $p;
        }
    }

    if (empty($open_ports)) {
        cam_json([
            'ok' => true,
            'is_camera' => false,
            'mensagem' => 'Nenhuma porta comum de câmera (80, 81, 554, 8000, 37777, 8899) aberta no IP especificado.'
        ]);
    }

    $detalhes = classify_camera($ip, $open_ports, $user, $pass);

    cam_json([
        'ok' => true,
        'is_camera' => true,
        'camera' => $detalhes
    ]);
}

// -------------------------------------------------------------
// Acao: SCAN (Varredura de faixa de IPs na rede)
// -------------------------------------------------------------
if ($acao === 'scan') {
    $subnet = trim((string)($input['subnet'] ?? '192.168.2.'));
    if (!preg_match('/^192\.168\.\d+\.$/', $subnet) && !preg_match('/^10\.\d+\.\d+\.$/', $subnet)) {
        $subnet = '192.168.2.';
    }

    $start = max(1, min(254, intval($input['start'] ?? 1)));
    $end = max($start, min(254, intval($input['end'] ?? 30)));
    $user = trim((string)($input['user'] ?? 'admin'));
    $pass = (string)($input['pass'] ?? '');

    // Portas de checagem rapida
    $check_ports = [554, 81, 80, 8000, 37777, 8899];
    $cameras_encontradas = [];

    for ($i = $start; $i <= $end; $i++) {
        $ip = $subnet . $i;
        $open = [];
        foreach ($check_ports as $p) {
            if (probe_port($ip, $p, 0.25)) {
                $open[] = $p;
            }
        }
        if (!empty($open)) {
            // Se tem 554, 81, 8000, 37777 ou 8899, eh com certeza camera/DVR
            // Se apenas porta 80, verifica se tem cara de camera
            $is_cam = in_array(554, $open, true) || in_array(81, $open, true) ||
                      in_array(8000, $open, true) || in_array(37777, $open, true) ||
                      in_array(8899, $open, true);

            if ($is_cam) {
                $cameras_encontradas[] = classify_camera($ip, $open, $user, $pass);
            }
        }
    }

    cam_json([
        'ok' => true,
        'subnet' => $subnet,
        'range' => "{$start}-{$end}",
        'total_encontradas' => count($cameras_encontradas),
        'cameras' => $cameras_encontradas
    ]);
}

// -------------------------------------------------------------
// Acao: SALVAR_CAMERAS (Criar ou Atualizar em Lote ou Individual)
// -------------------------------------------------------------
if ($acao === 'salvar_cameras' || $acao === 'salvar') {
    $cams = $input['cameras'] ?? [$input];
    if (!is_array($cams) || empty($cams)) {
        cam_json(['ok' => false, 'erro' => 'Nenhuma câmera fornecida para salvar'], 400);
    }

    $salvas = 0;
    try {
        $pdo->beginTransaction();

        foreach ($cams as $c) {
            $ip = trim((string)($c['ip_address'] ?? $c['ip'] ?? ''));
            if ($ip === '') continue;

            $tipo = strtolower(trim((string)($c['tipo'] ?? 'ip_camera')));
            if (!in_array($tipo, ['espcam', 'esp32_cam', 'ip_camera', 'cftv', 'dvr', 'nvr', 'camera'], true)) {
                $tipo = 'ip_camera';
            }

            $nome = trim((string)($c['nome'] ?? 'Câmera ' . $ip));
            $loc = trim((string)($c['localizacao'] ?? 'Residência'));
            $status = (string)($c['status'] ?? 'online');
            $device_id = trim((string)($c['device_id'] ?? 'cam-' . str_replace('.', '-', $ip)));
            if (!empty($c['canal']) && (int)$c['canal'] > 1) {
                $device_id .= '-ch' . (int)$c['canal'];
            }

            $meta = [
                'stream_url' => (string)($c['stream_url'] ?? ''),
                'snapshot_url' => (string)($c['snapshot_url'] ?? ''),
                'canal' => (int)($c['canal'] ?? 1),
                'modelo' => (string)($c['modelo'] ?? ''),
                'fabricante' => (string)($c['fabricante'] ?? ''),
                'user' => (string)($c['user'] ?? ''),
                'pass' => (string)($c['pass'] ?? ''),
                'web_port' => (int)($c['web_port'] ?? 80),
                'rtsp_port' => (int)($c['rtsp_port'] ?? 554)
            ];

            $caps = ['camera', 'streaming', 'visao-ia'];
            if ($tipo === 'espcam' || $tipo === 'esp32_cam') {
                $caps[] = 'espcam';
                $caps[] = 'mjpeg';
                $caps[] = 'flash';
            } elseif ($tipo === 'cftv' || $tipo === 'dvr' || $tipo === 'nvr') {
                $caps[] = 'cftv';
                $caps[] = 'rtsp';
                $caps[] = 'multicanal';
            } else {
                $caps[] = 'ip_camera';
                $caps[] = 'rtsp';
                $caps[] = 'onvif';
            }

            $meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $caps_json = json_encode($caps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Verifica se ja existe por device_id ou ip_address + tipo
            $stmtCheck = $pdo->prepare("SELECT id FROM dispositivos_cluster WHERE device_id = :did OR (ip_address = :ip AND tipo = :tipo) LIMIT 1");
            $stmtCheck->execute([':did' => $device_id, ':ip' => $ip, ':tipo' => $tipo]);
            $existId = $stmtCheck->fetchColumn();

            if ($existId) {
                $stmtUp = $pdo->prepare("
                    UPDATE dispositivos_cluster
                       SET nome = :nome,
                           tipo = :tipo,
                           ip_address = :ip,
                           status = :status,
                           localizacao = :loc,
                           capabilities = :caps,
                           metadata = :meta,
                           ultimo_heartbeat = NOW()
                     WHERE id = :id
                ");
                $stmtUp->execute([
                    ':nome' => $nome,
                    ':tipo' => $tipo,
                    ':ip' => $ip,
                    ':status' => $status,
                    ':loc' => $loc,
                    ':caps' => $caps_json,
                    ':meta' => $meta_json,
                    ':id' => $existId
                ]);
            } else {
                $token = bin2hex(random_bytes(16));
                $stmtIn = $pdo->prepare("
                    INSERT INTO dispositivos_cluster (
                        device_id, nome, tipo, ip_address, device_token, status,
                        localizacao, capabilities, metadata, ultimo_heartbeat
                    ) VALUES (
                        :did, :nome, :tipo, :ip, :token, :status,
                        :loc, :caps, :meta, NOW()
                    )
                ");
                $stmtIn->execute([
                    ':did' => $device_id,
                    ':nome' => $nome,
                    ':tipo' => $tipo,
                    ':ip' => $ip,
                    ':token' => $token,
                    ':status' => $status,
                    ':loc' => $loc,
                    ':caps' => $caps_json,
                    ':meta' => $meta_json
                ]);
            }
            $salvas++;
        }

        $pdo->commit();

        cam_json([
            'ok' => true,
            'mensagem' => "{$salvas} câmera(s) salva(s) com sucesso no cluster.",
            'total_salvas' => $salvas
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cam_json(['ok' => false, 'erro' => 'Falha ao salvar câmeras: ' . $e->getMessage()], 500);
    }
}

// -------------------------------------------------------------
// Acao: EXCLUIR
// -------------------------------------------------------------
if ($acao === 'excluir') {
    $id = intval($input['id'] ?? $_GET['id'] ?? 0);
    $device_id = trim((string)($input['device_id'] ?? $_GET['device_id'] ?? ''));

    if ($id <= 0 && $device_id === '') {
        cam_json(['ok' => false, 'erro' => 'ID ou device_id é obrigatório para exclusão'], 400);
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM dispositivos_cluster WHERE id = :id");
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM dispositivos_cluster WHERE device_id = :did");
            $stmt->execute([':did' => $device_id]);
        }

        cam_json([
            'ok' => true,
            'mensagem' => 'Câmera removida com sucesso do sistema.'
        ]);
    } catch (Throwable $e) {
        cam_json(['ok' => false, 'erro' => 'Erro ao excluir câmera: ' . $e->getMessage()], 500);
    }
}

cam_json(['ok' => false, 'erro' => 'Ação não reconhecida: ' . $acao], 400);
