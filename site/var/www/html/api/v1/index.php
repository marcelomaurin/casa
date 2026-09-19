<?php
// CASA/JARVIS - API REST v1 - MySQL/MariaDB
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key, X-Device-Token');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../seguranca.php');
require_once(__DIR__ . '/../agente_externo.php');

$pdo = get_db_pdo();

function get_external_api_key($pdo) {
    $stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave = 'external_api_key' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && !empty($row['valor'])) return $row['valor'];

    $chave = 'jarvis_sec_v1_' . bin2hex(random_bytes(24));
    $ins = $pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor, descricao)
        VALUES ('external_api_key', :k, 'Chave mestre da API externa')
        ON DUPLICATE KEY UPDATE valor = valor");
    $ins->execute([':k' => $chave]);

    $stmt->execute();
    $row = $stmt->fetch();
    return ($row && !empty($row['valor'])) ? $row['valor'] : $chave;
}

function api_v1_input() {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : [];
    return is_array($j) ? $j : $_POST;
}

function api_v1_error($code, $message) {
    http_response_code($code);
    echo json_encode(['status'=>'erro','codigo'=>$code,'mensagem'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
}

$master_key = get_external_api_key($pdo);
$token = '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
    $token = trim($m[1]);
} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $token = trim($_SERVER['HTTP_X_API_KEY']);
} elseif (!empty($_SERVER['HTTP_X_DEVICE_TOKEN'])) {
    $token = trim($_SERVER['HTTP_X_DEVICE_TOKEN']);
}

$autenticado = false;
$cliente = 'Anonimo';

if ($token !== '') {
    if (hash_equals($master_key, $token)) {
        $autenticado = true;
        $cliente = 'Master External API';
    } else {
        $stmt = $pdo->prepare("SELECT id, nome FROM dispositivos_cluster WHERE device_token = :t LIMIT 1");
        $stmt->execute([':t'=>$token]);
        $dev = $stmt->fetch();
        if ($dev) {
            $autenticado = true;
            $cliente = 'Dispositivo: ' . $dev['nome'];
        } else {
            $hash = hash('sha256', $token);
            $stmt = $pdo->prepare("SELECT id, nome FROM api_client_tokens WHERE token_hash=:h AND ativo=1 AND (expira_em IS NULL OR expira_em > NOW()) LIMIT 1");
            $stmt->execute([':h'=>$hash]);
            $cli = $stmt->fetch();
            if ($cli) {
                $autenticado = true;
                $cliente = $cli['nome'];
                $pdo->prepare("UPDATE api_client_tokens SET ultimo_uso=NOW(), ultimo_ip=:ip WHERE id=:id")
                    ->execute([':ip'=>get_client_ip(), ':id'=>$cli['id']]);
            }
        }
    }
}

if (!$autenticado) {
    registrar_falha_seguranca('Tentativa não autorizada na API v1', 'CRITICO');
    api_v1_error(401, 'Acesso negado: Bearer token obrigatório.');
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comandos_log WHERE origem='API_V1_EXTERNA' AND data_hora > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
    $stmt->execute();
    if (intval($stmt->fetchColumn()) > 60) {
        registrar_falha_seguranca('Rate limit excedido na API v1: ' . get_client_ip(), 'AVISO');
        api_v1_error(429, 'Limite de requisições excedido.');
    }
} catch (Throwable $e) {}

$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = trim(explode('?', $uri, 2)[0], '/');
$subpath = '';
if (preg_match('#api/v1/(.*)#', $path, $matches)) {
    $subpath = trim($matches[1], '/');
} elseif (isset($_GET['endpoint'])) {
    $subpath = trim($_GET['endpoint'], '/');
}
$input = api_v1_input();

try {
    $pdo->prepare("INSERT INTO comandos_log (comando, origem, resultado) VALUES (:cmd, 'API_V1_EXTERNA', 'Autenticado')")
        ->execute([':cmd'=>"Rota /api/v1/{$subpath} por {$cliente}"]);
} catch (Throwable $e) {}

if ($subpath === '' || $subpath === 'status') {
    $totalDev = intval($pdo->query("SELECT COUNT(*) FROM dispositivos_cluster WHERE status='online'")->fetchColumn());
    $totalNodes = intval($pdo->query("SELECT COUNT(*) FROM arm_nodes WHERE status='online'")->fetchColumn());
    $totalSens = intval($pdo->query("SELECT COUNT(*) FROM iot_leituras")->fetchColumn());
    $bloq = intval($pdo->query("SELECT COUNT(*) FROM seguranca_ips_bloqueados WHERE bloqueado_ate IS NULL OR bloqueado_ate > NOW()")->fetchColumn());

    echo json_encode([
        'status'=>'sucesso',
        'sistema'=>'CASA_JARVIS',
        'versao_api'=>'v1.1.0',
        'dominio'=>'https://maurinsoft.com.br/casa',
        'cliente'=>$cliente,
        'timestamp'=>date('c'),
        'resumo_residencia'=>[
            'dispositivos_online'=>$totalDev,
            'nos_arm_online'=>$totalNodes,
            'leituras_iot'=>$totalSens,
            'firewall_ips_bloqueados'=>$bloq
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($subpath === 'comando') {
    $cmd = trim($input['comando'] ?? '');
    $ia_mode = trim($input['ia_mode'] ?? 'auto');
    if ($cmd === '') api_v1_error(400, 'Parâmetro comando obrigatório.');

    // No Hostinger esta rota delega ao jarvis.php do próprio site.
    $url = 'https://maurinsoft.com.br/casa/api/jarvis.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode([
            'comando'=>$cmd,
            'ia_mode'=>$ia_mode,
            'origem'=>'API_V1'
        ]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Key: ' . get_system_api_token()],
        CURLOPT_TIMEOUT=>30
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false || $http >= 500) api_v1_error(502, 'Falha no núcleo cognitivo do JARVIS.');
    $data = json_decode($res, true);
    echo json_encode([
        'status'=>'sucesso',
        'comando'=>$cmd,
        'resposta'=>$data['resposta'] ?? $res,
        'provedor_ia'=>$data['provedor'] ?? 'JARVIS',
        'acao_executada'=>$data['acao'] ?? null,
        'audio_url'=>$data['audio_url'] ?? null,
        'id_plano'=>$data['id_plano'] ?? null,
        'id_tarefa_raiz'=>$data['id_tarefa_raiz'] ?? null,
        'task_context'=>$data['task_context'] ?? null,
        'task_status'=>$data['task_status'] ?? null,
        'tarefas_execucao'=>$data['tarefas_execucao'] ?? []
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($subpath === 'dispositivos') {
    $sql = "SELECT d.iddevice,d.devname,d.devdesc,d.devcon,d.devstatus,
        COALESCE(JSON_ARRAYAGG(IF(p.idpar IS NULL, NULL, JSON_OBJECT('parametro',p.devparname,'valor',p.devvalue))), JSON_ARRAY()) AS parametros
        FROM devices d
        LEFT JOIN devpar p ON d.iddevice=p.iddevice
        GROUP BY d.iddevice,d.devname,d.devdesc,d.devcon,d.devstatus
        ORDER BY d.iddevice";
    $legacy = $pdo->query($sql)->fetchAll();
    foreach ($legacy as &$row) {
        if (is_string($row['parametros'])) $row['parametros'] = json_decode($row['parametros'], true);
        if ($row['parametros'] === [null]) $row['parametros'] = [];
    }
    unset($row);

    $iot = $pdo->query("SELECT id,device_id,nome,tipo,ip_address,status,reles_status,capabilities,ram_livre,sinal_rssi,ultimo_heartbeat FROM dispositivos_cluster ORDER BY id")->fetchAll();
    foreach ($iot as &$row) {
        foreach (['reles_status','capabilities'] as $f) {
            if (isset($row[$f]) && is_string($row[$f])) $row[$f] = json_decode($row[$f], true);
        }
    }
    unset($row);

    echo json_encode(['status'=>'sucesso','dispositivos_legados'=>$legacy,'cluster_iot'=>$iot], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($subpath === 'dispositivos/acionar') {
    $id = intval($input['iddevice'] ?? 0);
    $par = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['parametro'] ?? 'dev1');
    $val = (string)($input['valor'] ?? '1');
    if ($id <= 0) api_v1_error(400, 'iddevice inválido.');

    $stmt = $pdo->prepare("INSERT INTO devpar (iddevice,devparname,devvalue) VALUES (:id,:p,:v)
        ON DUPLICATE KEY UPDATE devvalue=VALUES(devvalue), atualizado_em=CURRENT_TIMESTAMP");
    $stmt->execute([':id'=>$id, ':p'=>$par, ':v'=>$val]);
    $pdo->prepare("INSERT INTO comandos_log (iddevice,comando,origem,resultado) VALUES (:id,:c,'API_V1_EXTERNA','Executado')")
        ->execute([':id'=>$id, ':c'=>"Acionamento Remoto: {$par} = {$val}"]);
    echo json_encode(['status'=>'sucesso','iddevice'=>$id,'parametro'=>$par,'novo_valor'=>$val], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($subpath === 'sensores') {
    $leituras = $pdo->query("SELECT id,id_dispositivo,tipo_sensor,temperatura_c,umidade_pct,rssi,dados,data_hora FROM iot_leituras ORDER BY id DESC LIMIT 50")->fetchAll();
    foreach ($leituras as &$r) if (is_string($r['dados'])) $r['dados'] = json_decode($r['dados'], true);
    unset($r);
    echo json_encode(['status'=>'sucesso','sensores'=>$leituras], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($subpath === 'clima') {
    echo json_encode(['status'=>'sucesso','dados_climaticos'=>agente_consultar_clima()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($subpath === 'camera/snapshot') {
    $stmt = $pdo->query("SELECT id,arquivo,nome_dispositivo,localizacao,data_hora FROM camera_eventos ORDER BY data_hora DESC LIMIT 1");
    $cam = $stmt->fetch();
    if (!$cam) api_v1_error(404, 'Nenhum snapshot de câmera registrado.');
    echo json_encode(['status'=>'sucesso','snapshot'=>$cam], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

api_v1_error(404, "Endpoint /api/v1/{$subpath} não encontrado.");
?>
