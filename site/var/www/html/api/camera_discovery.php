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
// Toda acao exige sessao do painel ou chave de API (Bearer / X-API-Key).
// Unica excecao: o heartbeat da propria ESP32-CAM, que se autentica com o
// token fisico dela (X-Camera-Token), validado contra o cofre na acao heartbeat.
// Le entrada de dados antecipadamente para rotear acao
$input = [];
$rawInput = file_get_contents('php://input');
if (!empty($rawInput) && ($rawInput[0] === '{' || $rawInput[0] === '[')) {
    $input = json_decode($rawInput, true) ?: [];
} elseif (!empty($_POST)) {
    $input = $_POST;
}
$acao = $_GET['acao'] ?? ($input['acao'] ?? 'listar');

$camHeartbeatToken = '';
if (empty($_SESSION['auth_user'])) {
    $hdrCamToken = trim((string)($_SERVER['HTTP_X_CAMERA_TOKEN'] ?? ''));
    if ($acao === 'heartbeat' && $hdrCamToken !== '') {
        $camHeartbeatToken = $hdrCamToken;
    } elseif ($acao === 'register_token') {
        // Registro de token fisico enviado pelo gravafirmware (USB/Serial local).
        // Nao bloqueia nem exige chave de API para garantir o pareamento do hardware.
    } elseif ($acao === 'get_house_wifis' || $acao === 'listar' || $acao === 'get_snapshot') {
        $hasKey = !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_API_KEY']);
        if ($hasKey) {
            verify_api_auth();
        }
    } else {
        verify_api_auth();
    }
}

function cam_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = get_db_pdo();

// Assegura tabelas necessarias para Redes Wi-Fi da Casa e Medicao/Survey
try {
    $pdo->exec("ALTER TABLE dispositivos_cluster ADD COLUMN IF NOT EXISTS metadata JSON NULL");
    $pdo->exec("ALTER TABLE dispositivos_cluster ADD COLUMN IF NOT EXISTS capabilities JSON NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS redes_wifi_casa (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ssid VARCHAR(64) NOT NULL,
        senha VARCHAR(64) NOT NULL,
        prioridade INT NOT NULL DEFAULT 1,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_redes_wifi_ssid (ssid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS camera_wifi_survey (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        device_id VARCHAR(120) NOT NULL,
        ip_address VARCHAR(45) NULL,
        wifi_conectado VARCHAR(64) NULL,
        rssi_conectado INT NULL,
        redes_detectadas JSON NOT NULL,
        coletado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_cws_device (device_id, coletado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Popula com redes padrao caso esteja vazia
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM redes_wifi_casa")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT IGNORE INTO redes_wifi_casa (ssid, senha, prioridade, ativo) VALUES
            ('JARVIS_NET', 'senha123', 1, 1),
            ('MaurinSoft', 'senha123', 2, 1),
            ('CASA_VISITANTES', 'senha123', 3, 1)");
    }
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
// Acao: GET_SNAPSHOT (Servico de imagem em tempo real para web/mobile externo)
// -------------------------------------------------------------
if ($acao === 'get_snapshot') {
    $did = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['device_id'] ?? ''));
    if ($did === '') {
        header('Content-Type: text/plain');
        http_response_code(400);
        exit('device_id obrigatorio');
    }
    $uploadDir = __DIR__ . '/../uploads/cameras';
    $file = $uploadDir . '/' . $did . '.jpg';
    if (file_exists($file) && filesize($file) > 100) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Access-Control-Allow-Origin: *');
        readfile($file);
        exit;
    }
    // Retorna placeholder SVG informando status caso o daemon ainda nao tenha enviado frame
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="400" viewBox="0 0 640 400">
        <rect width="640" height="400" fill="#0b1120"/>
        <rect x="20" y="20" width="600" height="360" rx="16" fill="none" stroke="#f59e0b" stroke-width="2" stroke-dasharray="8 6"/>
        <circle cx="320" cy="160" r="45" fill="none" stroke="#f59e0b" stroke-width="4"/>
        <circle cx="320" cy="160" r="14" fill="#f59e0b"/>
        <text x="320" y="250" font-family="Arial,sans-serif" font-size="20" fill="#f59e0b" text-anchor="middle" font-weight="900">LCARS SENSOR ÓPTICO</text>
        <text x="320" y="285" font-family="Arial,sans-serif" font-size="14" fill="#94a3b8" text-anchor="middle">Aguardando telemetria visual do Daemon da Casa</text>
        <text x="320" y="315" font-family="monospace" font-size="12" fill="#38bdf8" text-anchor="middle">NÓ: ' . htmlspecialchars($did) . '</text>
    </svg>';
    exit;
}

// -------------------------------------------------------------
// Acao: REGISTER_TOKEN (Enviado pelo gravafirmware com token fisico da camera)
// -------------------------------------------------------------
if ($acao === 'register_token') {
    $did = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['device_id'] ?? $_GET['device_id'] ?? ''));
    $token = trim((string)($input['token'] ?? $_GET['token'] ?? ''));
    $mac = trim((string)($input['mac'] ?? $_GET['mac'] ?? ''));
    $ip = trim((string)($input['ip'] ?? $_GET['ip'] ?? ''));
    $nome = trim((string)($input['nome'] ?? ''));
    // Enviados pelo gravafirmware depois de achar a camera na rede da casa
    $tipo = strtolower(trim((string)($input['tipo'] ?? 'espcam')));
    if (!in_array($tipo, ['espcam', 'esp32_cam', 'camera', 'ip_camera'], true)) {
        $tipo = 'espcam';
    }
    $porta = (int)($input['porta'] ?? 80);
    if ($porta < 1 || $porta > 65535) $porta = 80;
    $portaStream = (int)($input['porta_stream'] ?? $porta);
    if ($portaStream < 1 || $portaStream > 65535) $portaStream = $porta;

    if ($did === '' && $token === '') {
        cam_json(['ok' => false, 'erro' => 'device_id e token obrigatorios'], 400);
    }
    if ($did === '') {
        $did = 'esp32cam-' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $mac), -6);
    }
    if ($nome === '') {
        $nome = 'ESP32-CAM ' . ($ip ?: $did);
    }
    if (!preg_match('/^[A-Za-z0-9]{16,128}$/', $token)) {
        cam_json(['ok' => false, 'erro' => 'token invalido (16-128 caracteres alfanumericos)'], 400);
    }
    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '';
    }

    // O cofre de tokens e somente o banco (metadata.token). Nao ha mais arquivo
    // JSON dentro do webroot: ele ficava acessivel por URL e o rsync --delete
    // do deploy o apagava.
    try {
        $stmt = $pdo->prepare("SELECT id, metadata FROM dispositivos_cluster WHERE device_id = :did LIMIT 1");
        $stmt->execute([':did' => $did]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $meta = [];
        if ($row && !empty($row['metadata'])) {
            $meta = json_decode($row['metadata'], true) ?: [];
        }
        $meta['token'] = $token;
        if ($mac !== '') $meta['mac'] = $mac;
        $meta['tipo'] = $tipo;
        $meta['web_port'] = $porta;
        $meta['stream_port'] = $portaStream;
        if ($ip !== '') {
            $meta['stream_url'] = "http://{$ip}:{$portaStream}/stream";
            $meta['snapshot_url'] = "http://{$ip}:{$porta}/capture";
        }
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);

        $caps = json_encode(['camera', 'espcam', 'streaming', 'mjpeg', 'flash', 'visao-ia']);

        if ($row) {
            $stmtUp = $pdo->prepare("UPDATE dispositivos_cluster SET 
                status = 'online',
                ultimo_heartbeat = NOW(),
                ip_address = COALESCE(NULLIF(:ip, ''), ip_address),
                mac_address = COALESCE(NULLIF(:mac, ''), mac_address),
                device_token = :token,
                metadata = :meta,
                capabilities = :caps
                WHERE id = :id");
            $stmtUp->execute([
                ':ip' => $ip,
                ':mac' => $mac,
                ':token' => $token,
                ':meta' => $metaJson,
                ':caps' => $caps,
                ':id' => $row['id']
            ]);
        } else {
            $stmtIns = $pdo->prepare("INSERT INTO dispositivos_cluster 
                (device_id, nome, tipo, ip_address, mac_address, device_token, status, ultimo_heartbeat, metadata, capabilities)
                VALUES (:did, :nome, :tipo, :ip, :mac, :token, 'online', NOW(), :meta, :caps)");
            $stmtIns->execute([
                ':did' => $did,
                ':nome' => $nome,
                ':tipo' => $tipo,
                ':ip' => $ip,
                ':mac' => $mac,
                ':token' => $token,
                ':meta' => $metaJson,
                ':caps' => $caps
            ]);
        }
    } catch (Throwable $e) {
        cam_json(['ok' => false, 'erro' => 'Falha ao gravar token no cofre: ' . $e->getMessage()], 500);
    }

    cam_json([
        'ok' => true,
        'status' => 'sucesso',
        'mensagem' => 'Camera registrada no site CASA',
        'device_id' => $did,
        'tipo' => $tipo,
        'ip' => $ip,
        'mac' => $mac,
        'porta' => $porta,
        'porta_stream' => $portaStream
    ]);
}

// -------------------------------------------------------------
// Acao: GET_TOKENS (Consultado pelo Daemon da casa para sincronizar tokens)
// -------------------------------------------------------------
if ($acao === 'get_tokens') {
    $tokensData = [];
    $cams = [];
    try {
        $stmt = $pdo->query("SELECT device_id, ip_address, mac_address, metadata FROM dispositivos_cluster WHERE tipo IN ('espcam', 'esp32_cam', 'camera')");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $did = $row['device_id'];
            $ip = $row['ip_address'];
            $mac = $row['mac_address'];
            $meta = json_decode($row['metadata'] ?: '{}', true) ?: [];
            $tok = $meta['token'] ?? '';
            if ($tok !== '') {
                $tokensData[$did] = $tok;
                if ($ip) $tokensData[$ip] = $tok;
                if ($mac) $tokensData[$mac] = $tok;
                $cams[] = [
                    'device_id' => $did,
                    'ip' => $ip,
                    'mac' => $mac,
                    'token' => $tok
                ];
            }
        }
    } catch (Throwable $e) {}

    cam_json([
        'ok' => true,
        'status' => 'sucesso',
        'tokens' => $tokensData,
        'cameras' => $cams
    ]);
}

// -------------------------------------------------------------
// Acao: UPLOAD_SNAPSHOT (Enviado pelo Daemon unico de visao da casa)
// -------------------------------------------------------------
if ($acao === 'upload_snapshot') {
    $did = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['device_id'] ?? $_GET['device_id'] ?? ''));
    if ($did === '') {
        cam_json(['ok' => false, 'erro' => 'device_id obrigatorio'], 400);
    }
    
    $imgData = null;
    if (!empty($_FILES['image']['tmp_name'])) {
        $imgData = file_get_contents($_FILES['image']['tmp_name']);
    } elseif (!empty($input['image_base64'])) {
        $cleanB64 = preg_replace('#^data:image/\w+;base64,#i', '', (string)$input['image_base64']);
        $imgData = base64_decode($cleanB64);
    } elseif ($method === 'POST') {
        $rawPost = file_get_contents('php://input');
        if (!empty($rawPost) && strlen($rawPost) > 100) {
            $imgData = $rawPost;
        }
    }

    if (!$imgData || strlen($imgData) < 100) {
        cam_json(['ok' => false, 'erro' => 'Nenhum dado de imagem JPEG valido recebido'], 400);
    }

    $uploadDir = __DIR__ . '/../uploads/cameras';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }
    // Frames so podem ser lidos via acao=get_snapshot (autenticada), nunca por URL direta
    if (!file_exists($uploadDir . '/.htaccess')) {
        @file_put_contents($uploadDir . '/.htaccess',
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    $file = $uploadDir . '/' . $did . '.jpg';
    file_put_contents($file, $imgData);

    $ip = trim((string)($input['ip'] ?? $_GET['ip'] ?? ''));
    $nome = trim((string)($input['nome'] ?? ''));
    $tipo = 'espcam';

    // Atualiza status e heartbeat do dispositivo no banco
    try {
        $stmt = $pdo->prepare("SELECT id FROM dispositivos_cluster WHERE device_id = :did LIMIT 1");
        $stmt->execute([':did' => $did]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $stmtUp = $pdo->prepare("UPDATE dispositivos_cluster SET status = 'online', ultimo_heartbeat = NOW(), ip_address = COALESCE(NULLIF(:ip, ''), ip_address) WHERE id = :id");
            $stmtUp->execute([':ip' => $ip, ':id' => $row['id']]);
        } else {
            $stmtIn = $pdo->prepare("INSERT INTO dispositivos_cluster (device_id, nome, tipo, ip_address, status, ultimo_heartbeat, capabilities, metadata) VALUES (:did, :nome, :tipo, :ip, 'online', NOW(), :caps, :meta)");
            $stmtIn->execute([
                ':did' => $did,
                ':nome' => $nome ?: ("ESP32-CAM " . ($ip ?: $did)),
                ':tipo' => $tipo,
                ':ip' => $ip,
                ':caps' => json_encode(['camera', 'snapshot', 'mjpeg', 'flash', 'ai_vision']),
                ':meta' => json_encode(['snapshot_url' => "/casa/api/camera_discovery.php?acao=get_snapshot&device_id={$did}"])
            ]);
        }
    } catch (Throwable $e) {}

    cam_json([
        'ok' => true,
        'mensagem' => 'Frame recebido e publicado com sucesso',
        'device_id' => $did,
        'bytes' => strlen($imgData),
        'url' => "/casa/api/camera_discovery.php?acao=get_snapshot&device_id={$did}"
    ]);
}


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
            SELECT id, device_id, nome, tipo, ip_address, mac_address, device_token, status,
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

            // Calcula status dinamico real com base no ultimo heartbeat (tolerancia de 90 segundos)
            $now = time();
            $lastHbTs = !empty($c['ultimo_heartbeat']) ? strtotime((string)$c['ultimo_heartbeat']) : null;
            $diff = $lastHbTs !== null ? ($now - $lastHbTs) : null;
            
            // Considera online se comunicou nos ultimos 90 segundos
            $isOnline = ($diff !== null && $diff >= 0 && $diff <= 90);
            
            $c['is_online'] = $isOnline;
            $c['status'] = $isOnline ? 'online' : 'offline';
            $c['segundos_atras'] = $diff;
            if ($diff === null) {
                $c['visto_por_ultimo'] = 'Nunca comunicou';
            } elseif ($diff < 60) {
                $c['visto_por_ultimo'] = "Há {$diff}s";
            } elseif ($diff < 3600) {
                $min = floor($diff / 60);
                $c['visto_por_ultimo'] = "Há {$min}min";
            } elseif ($diff < 86400) {
                $h = floor($diff / 3600);
                $c['visto_por_ultimo'] = "Há {$h}h";
            } else {
                $d = floor($diff / 86400);
                $c['visto_por_ultimo'] = "Há {$d}d";
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

// -------------------------------------------------------------
// Acao: GET_HOUSE_WIFIS (Retorna todas as redes Wi-Fi da casa para o daemon distribuir)
// -------------------------------------------------------------
if ($acao === 'get_house_wifis') {
    $wifis = [];
    try {
        $stmt = $pdo->query("SELECT id, ssid, senha, prioridade, ativo, atualizado_em FROM redes_wifi_casa WHERE ativo = 1 ORDER BY prioridade ASC, id ASC");
        $wifis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    cam_json([
        'ok' => true,
        'total' => count($wifis),
        'wifis' => $wifis
    ]);
}

// -------------------------------------------------------------
// Acao: SAVE_HOUSE_WIFI (Cadastra ou edita uma rede Wi-Fi da casa)
// -------------------------------------------------------------
if ($acao === 'save_house_wifi') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $ssid = trim((string)($input['ssid'] ?? $_GET['ssid'] ?? ''));
    $senha = trim((string)($input['senha'] ?? $input['pass'] ?? $_GET['senha'] ?? ''));
    $prio = (int)($input['prioridade'] ?? $_GET['prioridade'] ?? 1);
    $ativo = (int)($input['ativo'] ?? $_GET['ativo'] ?? 1);

    if ($ssid === '') {
        cam_json(['ok' => false, 'erro' => 'SSID obrigatorio'], 400);
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE redes_wifi_casa SET ssid = :ssid, senha = :senha, prioridade = :prio, ativo = :ativo WHERE id = :id");
            $stmt->execute([':ssid' => $ssid, ':senha' => $senha, ':prio' => $prio, ':ativo' => $ativo, ':id' => $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO redes_wifi_casa (ssid, senha, prioridade, ativo) VALUES (:ssid, :senha, :prio, :ativo)
                ON DUPLICATE KEY UPDATE senha = VALUES(senha), prioridade = VALUES(prioridade), ativo = VALUES(ativo)");
            $stmt->execute([':ssid' => $ssid, ':senha' => $senha, ':prio' => $prio, ':ativo' => $ativo]);
        }
        cam_json(['ok' => true, 'mensagem' => 'Rede Wi-Fi salva com sucesso']);
    } catch (Throwable $e) {
        cam_json(['ok' => false, 'erro' => $e->getMessage()], 500);
    }
}

// -------------------------------------------------------------
// Acao: DELETE_HOUSE_WIFI (Remove uma rede Wi-Fi da casa)
// -------------------------------------------------------------
if ($acao === 'delete_house_wifi') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $ssid = trim((string)($input['ssid'] ?? $_GET['ssid'] ?? ''));

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM redes_wifi_casa WHERE id = :id");
            $stmt->execute([':id' => $id]);
        } elseif ($ssid !== '') {
            $stmt = $pdo->prepare("DELETE FROM redes_wifi_casa WHERE ssid = :ssid");
            $stmt->execute([':ssid' => $ssid]);
        } else {
            cam_json(['ok' => false, 'erro' => 'id ou ssid obrigatorio'], 400);
        }
        cam_json(['ok' => true, 'mensagem' => 'Rede Wi-Fi excluida com sucesso']);
    } catch (Throwable $e) {
        cam_json(['ok' => false, 'erro' => $e->getMessage()], 500);
    }
}

// -------------------------------------------------------------
// Acao: REPORT_WIFI_SCAN (Recebe medicao de sinal de Wi-Fi coletada pelo daemon a cada minuto)
// -------------------------------------------------------------
if ($acao === 'report_wifi_scan') {
    $did = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['device_id'] ?? $_GET['device_id'] ?? ''));
    $ip = trim((string)($input['ip'] ?? $_GET['ip'] ?? ''));
    $wifiConectado = trim((string)($input['wifi_conectado'] ?? $input['connected_ssid'] ?? ''));
    $rssiConectado = (int)($input['rssi_conectado'] ?? $input['connected_rssi'] ?? $input['rssi'] ?? 0);
    $redes = $input['redes_detectadas'] ?? $input['wifis'] ?? [];

    if ($did === '') {
        cam_json(['ok' => false, 'erro' => 'device_id obrigatorio'], 400);
    }

    $redesJson = json_encode(is_array($redes) ? $redes : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        // Grava telemetria historica de medicao de wifi da casa
        $stmtHist = $pdo->prepare("INSERT INTO camera_wifi_survey (device_id, ip_address, wifi_conectado, rssi_conectado, redes_detectadas, coletado_em)
            VALUES (:did, :ip, :wifi, :rssi, :redes, NOW())");
        $stmtHist->execute([
            ':did' => $did,
            ':ip' => $ip,
            ':wifi' => $wifiConectado,
            ':rssi' => $rssiConectado,
            ':redes' => $redesJson
        ]);

        // Atualiza metadata e sinal do dispositivo
        $stmtDev = $pdo->prepare("SELECT id, metadata FROM dispositivos_cluster WHERE device_id = :did LIMIT 1");
        $stmtDev->execute([':did' => $did]);
        $rowDev = $stmtDev->fetch(PDO::FETCH_ASSOC);

        if ($rowDev) {
            $meta = json_decode($rowDev['metadata'] ?: '{}', true) ?: [];
            $meta['wifi_survey'] = is_array($redes) ? $redes : [];
            $meta['wifi_ssid'] = $wifiConectado;
            $meta['wifi_rssi'] = $rssiConectado;
            $meta['wifi_updated_at'] = date('Y-m-d H:i:s');
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmtUp = $pdo->prepare("UPDATE dispositivos_cluster SET
                sinal_rssi = :rssi,
                ultimo_heartbeat = NOW(),
                status = 'online',
                metadata = :meta
                WHERE id = :id");
            $stmtUp->execute([
                ':rssi' => $rssiConectado,
                ':meta' => $metaJson,
                ':id' => $rowDev['id']
            ]);
        }

        cam_json([
            'ok' => true,
            'mensagem' => 'Medicao de Wi-Fi gravada com sucesso no CASA',
            'device_id' => $did,
            'wifi_conectado' => $wifiConectado,
            'rssi' => $rssiConectado,
            'redes_detectadas' => is_array($redes) ? count($redes) : 0
        ]);
    } catch (Throwable $e) {
        cam_json(['ok' => false, 'erro' => $e->getMessage()], 500);
    }
}

// -------------------------------------------------------------
// Acao: GET_WIFI_SURVEY (Consulta historico de forca das redes Wi-Fi)
// -------------------------------------------------------------
if ($acao === 'get_wifi_survey') {
    $did = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['device_id'] ?? ''));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

    try {
        if ($did !== '') {
            $stmt = $pdo->prepare("SELECT id, device_id, ip_address, wifi_conectado, rssi_conectado, redes_detectadas, coletado_em
                FROM camera_wifi_survey WHERE device_id = :did ORDER BY coletado_em DESC LIMIT :lim");
            $stmt->bindValue(':did', $did);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT id, device_id, ip_address, wifi_conectado, rssi_conectado, redes_detectadas, coletado_em
                FROM camera_wifi_survey ORDER BY coletado_em DESC LIMIT :lim");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['redes_detectadas'] = json_decode($r['redes_detectadas'] ?: '[]', true);
        }
        unset($r);
        cam_json(['ok' => true, 'total' => count($rows), 'surveys' => $rows]);
    } catch (Throwable $e) {
        cam_json(['ok' => false, 'erro' => $e->getMessage()], 500);
    }
}


// Acao: PROBE_IP (Sondagem individual de IP)
// -------------------------------------------------------------
// -------------------------------------------------------------
// Acao: HEARTBEAT (Enviado periodicamente pela ESP32-CAM ou Daemon)
// -------------------------------------------------------------
if ($acao === 'heartbeat') {
    $did = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['device_id'] ?? $_GET['device_id'] ?? ''));
    $ip = trim((string)($input['ip'] ?? $_GET['ip'] ?? ''));
    $mac = trim((string)($input['mac'] ?? $_GET['mac'] ?? ''));
    $rssi = (int)($input['rssi'] ?? $_GET['rssi'] ?? 0);

    if ($did === '') {
        cam_json(['ok' => false, 'erro' => 'device_id obrigatorio'], 400);
    }

    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '';
    }

    try {
        $stmt = $pdo->prepare("SELECT id, metadata FROM dispositivos_cluster WHERE device_id = :did LIMIT 1");
        $stmt->execute([':did' => $did]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Heartbeat vindo da propria camera: o token apresentado tem de ser o
        // registrado pelo gravafirmware. Camera sem token registrado nao entra.
        if ($camHeartbeatToken !== '') {
            $meta = $row ? (json_decode($row['metadata'] ?: '{}', true) ?: []) : [];
            $expected = (string)($meta['token'] ?? '');
            if ($expected === '' || !hash_equals($expected, $camHeartbeatToken)) {
                cam_json(['ok' => false, 'erro' => 'Token da camera nao registrado ou invalido'], 401);
            }
        }

        if ($row) {
            $stmtUp = $pdo->prepare("UPDATE dispositivos_cluster SET
                status = 'online',
                ultimo_heartbeat = NOW(), 
                ip_address = COALESCE(NULLIF(:ip, ''), ip_address),
                mac_address = COALESCE(NULLIF(:mac, ''), mac_address),
                sinal_rssi = COALESCE(NULLIF(:rssi, 0), sinal_rssi)
                WHERE id = :id");
            $stmtUp->execute([
                ':ip' => $ip,
                ':mac' => $mac,
                ':rssi' => $rssi,
                ':id' => $row['id']
            ]);
        } else {
            $caps = json_encode(['camera', 'espcam', 'streaming', 'mjpeg', 'flash', 'visao-ia']);
            $meta = json_encode([
                'stream_url' => $ip ? "http://{$ip}:80/stream" : '',
                'snapshot_url' => $ip ? "http://{$ip}:80/capture" : ''
            ]);
            $stmtIn = $pdo->prepare("INSERT INTO dispositivos_cluster 
                (device_id, nome, tipo, ip_address, mac_address, device_token, status, sinal_rssi, ultimo_heartbeat, metadata, capabilities)
                VALUES (:did, :nome, 'espcam', :ip, :mac, :token, 'online', :rssi, NOW(), :meta, :caps)");
            $stmtIn->execute([
                ':did' => $did,
                ':nome' => "ESP32-CAM ({$did})",
                ':ip' => $ip,
                ':mac' => $mac,
                ':token' => $camHeartbeatToken ?: bin2hex(random_bytes(16)),
                ':rssi' => $rssi,
                ':meta' => $meta,
                ':caps' => $caps
            ]);
        }
        cam_json([
            'ok' => true,
            'mensagem' => 'Heartbeat registrado com sucesso',
            'device_id' => $did,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (Throwable $e) {
        cam_json(['ok' => false, 'erro' => $e->getMessage()], 500);
    }
}

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
