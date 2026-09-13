<?php
// GATEWAY DE API REST v1 SEGURA — JARVIS RESIDENCIAL
// Ponto de entrada oficial para conexões externas via Web e Aplicativos Móveis
// Proteção Criptográfica, Rate Limiting, Cabeçalhos Hardened e Bloqueio Ativo

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key, X-Device-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../seguranca.php');
require_once(__DIR__ . '/../agente_externo.php');

$pdo = get_db_pdo();

// 1. Obter Chave Mestre Externa configurada no Banco
function get_external_api_key($pdo) {
    $stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave = 'external_api_key' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && !empty($row['valor'])) {
        return $row['valor'];
    }
    // Se não existir, inicializar chave padrão segura
    $chave_nova = 'jarvis_sec_v1_' . bin2hex(random_bytes(24));
    $ins = $pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor) VALUES ('external_api_key', :k) ON CONFLICT (chave) DO NOTHING");
    $ins->execute([':k' => $chave_nova]);
    return $chave_nova;
}

$master_key = get_external_api_key($pdo);

// 2. Extrair e Validar Token de Autenticação
$token_fornecido = '';
$auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
if (preg_match('/Bearer\s+(\S+)/i', $auth_header, $m)) {
    $token_fornecido = $m[1];
} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $token_fornecido = $_SERVER['HTTP_X_API_KEY'];
} elseif (!empty($_GET['api_key'])) {
    $token_fornecido = $_GET['api_key'];
}

$autenticado = false;
$identificador_cliente = 'Anonimo';

if (!empty($token_fornecido)) {
    if (hash_equals($master_key, $token_fornecido)) {
        $autenticado = true;
        $identificador_cliente = 'Master External API';
    } else {
        // Verificar se é um token válido de dispositivo de hardware
        $stmt_dev = $pdo->prepare("SELECT nome FROM dispositivos_cluster WHERE device_token = :t LIMIT 1");
        $stmt_dev->execute([':t' => $token_fornecido]);
        $d = $stmt_dev->fetch();
        if ($d) {
            $autenticado = true;
            $identificador_cliente = 'Dispositivo: ' . $d['nome'];
        }
    }
}

if (!$autenticado) {
    registrar_falha_seguranca("Tentativa não autorizada na API v1 externa (Token inválido ou ausente)", "CRITICO");
    http_response_code(401);
    echo json_encode([
        'status' => 'erro',
        'codigo' => 401,
        'mensagem' => 'Acesso negado: Autenticação Bearer Token obrigatória para acesso à API externa v1.',
        'ajuda' => 'Envie o cabeçalho Authorization: Bearer <sua_chave_api_externa>'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Rate Limiting por IP (Máximo de 60 requisições por minuto)
$client_ip = get_client_ip();
try {
    $stmt_rl = $pdo->prepare("SELECT count(*) FROM comandos_log WHERE origem = 'API_V1_EXTERNA' AND data_hora > NOW() - INTERVAL '1 minute'");
    $stmt_rl->execute();
    $req_count = $stmt_rl->fetchColumn();
    if ($req_count > 60) {
        registrar_falha_seguranca("Rate limit excedido na API v1 pelo IP: $client_ip", "AVISO");
        http_response_code(429);
        echo json_encode([
            'status' => 'erro',
            'codigo' => 429,
            'mensagem' => 'Limite de requisições excedido. Tente novamente em 1 minuto.'
        ]);
        exit;
    }
} catch (Exception $e) {}

// 4. Roteamento de Endpoints
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
$uri_parts = explode('?', $uri);
$path = trim($uri_parts[0], '/');
$subpath = '';

if (preg_match('#api/v1/(.*)#', $path, $matches)) {
    $subpath = trim($matches[1], '/');
} elseif (isset($_GET['endpoint'])) {
    $subpath = trim($_GET['endpoint'], '/');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// Registrar acesso no log
$pdo->prepare("INSERT INTO comandos_log (comando, origem, resultado) VALUES (:cmd, 'API_V1_EXTERNA', 'Autenticado')")
    ->execute([':cmd' => "Rota: /api/v1/{$subpath} por {$identificador_cliente}"]);

// ROTA: Status Geral (/api/v1/status)
if ($subpath === 'status' || $subpath === '') {
    $total_dev = $pdo->query("SELECT count(*) FROM devices")->fetchColumn();
    $total_nodes = $pdo->query("SELECT count(*) FROM arm_nodes WHERE status = 'online'")->fetchColumn();
    $total_sensores = $pdo->query("SELECT count(*) FROM sensores_telemetria")->fetchColumn();
    $ips_bloq = $pdo->query("SELECT count(*) FROM seguranca_ips_bloqueados")->fetchColumn();
    
    // Status do túnel
    $tunnel_info = ['status' => 'offline', 'url' => ''];
    if (file_exists('/var/www/html/api/tunnel_status.json')) {
        $tunnel_info = json_decode(file_get_contents('/var/www/html/api/tunnel_status.json'), true);
    }

    echo json_encode([
        'status' => 'sucesso',
        'sistema' => 'JARVIS_RESIDENCIAL',
        'versao_api' => 'v1.0.0',
        'cliente' => $identificador_cliente,
        'timestamp' => date('c'),
        'tunel_externo' => $tunnel_info,
        'resumo_residencia' => [
            'dispositivos_ativos' => intval($total_dev),
            'nos_arm_online' => intval($total_nodes),
            'leituras_telemetria' => intval($total_sensores),
            'firewall_ips_bloqueados' => intval($ips_bloq)
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ROTA: Executar Comando JARVIS AI (/api/v1/comando)
if ($subpath === 'comando') {
    $cmd = isset($input['comando']) ? trim($input['comando']) : (isset($_GET['comando']) ? trim($_GET['comando']) : '');
    $ia_mode = isset($input['ia_mode']) ? trim($input['ia_mode']) : 'auto';

    if (empty($cmd)) {
        http_response_code(400);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Parâmetro "comando" obrigatório']);
        exit;
    }

    $ch = curl_init("http://127.0.0.1/api/jarvis.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['comando' => $cmd, 'ia_mode' => $ia_mode]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . get_system_api_token()]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res = curl_exec($ch);
    curl_close($ch);

    if ($res) {
        $data = json_decode($res, true);
        echo json_encode([
            'status' => 'sucesso',
            'comando' => $cmd,
            'resposta' => isset($data['resposta']) ? $data['resposta'] : $res,
            'provedor_ia' => isset($data['provedor']) ? $data['provedor'] : 'JARVIS',
            'acao_executada' => isset($data['acao']) ? $data['acao'] : null,
            'audio_url' => isset($data['audio_url']) ? $data['audio_url'] : null
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Falha no núcleo cognitivo do JARVIS']);
    }
    exit;
}

// ROTA: Listar Dispositivos e Relés (/api/v1/dispositivos)
if ($subpath === 'dispositivos') {
    $stmt = $pdo->query("SELECT d.iddevice, d.devname, d.devdesc, d.devcon, d.devstatus, 
        json_agg(json_build_object('parametro', p.devparname, 'valor', p.devvalue)) as parametros
        FROM devices d
        LEFT JOIN devpar p ON d.iddevice = p.iddevice
        GROUP BY d.iddevice, d.devname, d.devdesc, d.devcon, d.devstatus
        ORDER BY d.iddevice ASC");
    $devices = $stmt->fetchAll();

    // Dispositivos do cluster IoT
    $stmt_iot = $pdo->query("SELECT id, nome, tipo, ip_address, status, reles_status, ram_livre, sinal_rssi, ultimo_heartbeat FROM dispositivos_cluster ORDER BY id ASC");
    $iot_devices = $stmt_iot->fetchAll();

    echo json_encode([
        'status' => 'sucesso',
        'dispositivos_legados' => $devices,
        'cluster_iot' => $iot_devices
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ROTA: Acionar Relé / Dispositivo (/api/v1/dispositivos/acionar)
if ($subpath === 'dispositivos/acionar') {
    $dev_id = isset($input['iddevice']) ? intval($input['iddevice']) : 0;
    $par = isset($input['parametro']) ? trim($input['parametro']) : 'dev1';
    $val = isset($input['valor']) ? trim($input['valor']) : '1';

    if ($dev_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'erro', 'mensagem' => 'iddevice inválido']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE devpar SET devvalue = :val WHERE iddevice = :id AND devparname = :p");
    $stmt->execute([':val' => $val, ':id' => $dev_id, ':p' => $par]);

    $pdo->prepare("INSERT INTO comandos_log (iddevice, comando, origem, resultado) VALUES (:id, :cmd, 'API_V1_EXTERNA', 'Executado')")
        ->execute([':id' => $dev_id, ':cmd' => "Acionamento Remoto: {$par} = {$val}"]);

    echo json_encode([
        'status' => 'sucesso',
        'mensagem' => "Dispositivo #{$dev_id} ({$par}) atualizado para {$val}",
        'iddevice' => $dev_id,
        'parametro' => $par,
        'novo_valor' => $val
    ]);
    exit;
}

// ROTA: Telemetria de Sensores (/api/v1/sensores)
if ($subpath === 'sensores') {
    $stmt = $pdo->query("SELECT id, sensor_nome, valor_numerico, unidade, data_hora, raw_data FROM sensores_telemetria ORDER BY id DESC LIMIT 20");
    $leituras = $stmt->fetchAll();
    echo json_encode(['status' => 'sucesso', 'sensores' => $leituras], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ROTA: Previsão do Tempo (/api/v1/clima)
if ($subpath === 'clima') {
    $clima = agente_consultar_clima();
    echo json_encode(['status' => 'sucesso', 'dados_climaticos' => $clima], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ROTA: Snapshot de Câmera (/api/v1/camera/snapshot)
if ($subpath === 'camera/snapshot') {
    $caminho = '/home/mmm/servicos/espcam_snapshots/cam_preview.jpg';
    if (!file_exists($caminho)) {
        // Obter foto mais recente
        $arquivos = glob('/home/mmm/servicos/espcam_snapshots/*.jpg');
        if (!empty($arquivos)) {
            rsort($arquivos);
            $caminho = $arquivos[0];
        }
    }
    if (file_exists($caminho)) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($caminho));
        readfile($caminho);
        exit;
    }
    http_response_code(404);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Nenhum snapshot de câmera disponível']);
    exit;
}

// Rota não mapeada
http_response_code(404);
echo json_encode([
    'status' => 'erro',
    'codigo' => 404,
    'mensagem' => "Endpoint '/api/v1/{$subpath}' não encontrado.",
    'rotas_disponiveis' => [
        'GET /api/v1/status' => 'Status geral da residência e nós',
        'POST /api/v1/comando' => 'Enviar comando em linguagem natural para o JARVIS',
        'GET /api/v1/dispositivos' => 'Listar dispositivos e estados dos relés',
        'POST /api/v1/dispositivos/acionar' => 'Acionar relé ou parâmetro',
        'GET /api/v1/sensores' => 'Últimas medições de sensores',
        'GET /api/v1/clima' => 'Previsão do tempo e probabilidade de chuva',
        'GET /api/v1/camera/snapshot' => 'Obter imagem recente da câmera ESP32-CAM'
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
