<?php
// Módulo de Agentes de Ações Externas do JARVIS
// Permite:
// 1. Notificações externas no Telegram / Webhooks
// 2. Consulta a APIs de clima/tempo para decisões de automação
// 3. Recepção e arquivamento de fotos e alertas de movimento da ESP32-CAM

header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');

$pdo = get_db_pdo();

function agente_telegram_enviar($mensagem, $caminho_foto = null) {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT configuracao FROM agentes_externos WHERE tipo = 'telegram_bot' AND ativo = true LIMIT 1");
        $row = $stmt->fetch();
        if (!$row) return false;

        $cfg = is_array($row['configuracao']) ? $row['configuracao'] : json_decode($row['configuracao'], true);
        $token = isset($cfg['bot_token']) ? $cfg['bot_token'] : '';
        $chat_id = isset($cfg['chat_id']) ? $cfg['chat_id'] : '';

        if (empty($token) || empty($chat_id) || $token === 'SEU_TELEGRAM_BOT_TOKEN') {
            return false;
        }

        if ($caminho_foto && file_exists($caminho_foto)) {
            $url = "https://api.telegram.org/bot{$token}/sendPhoto";
            $post = [
                'chat_id' => $chat_id,
                'caption' => $mensagem,
                'photo' => new CURLFile(realpath($caminho_foto))
            ];
        } else {
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $post = [
                'chat_id' => $chat_id,
                'text' => $mensagem,
                'parse_mode' => 'HTML'
            ];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        curl_close($ch);

        $pdo->query("UPDATE agentes_externos SET ultimo_disparo = CURRENT_TIMESTAMP WHERE tipo = 'telegram_bot'");
        return $res;
    } catch (Exception $e) {
        error_log("Erro no agente Telegram: " . $e->getMessage());
        return false;
    }
}

function agente_webhook_disparar($evento, $dados) {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT configuracao FROM agentes_externos WHERE tipo = 'webhook' AND ativo = true");
        while ($row = $stmt->fetch()) {
            $cfg = is_array($row['configuracao']) ? $row['configuracao'] : json_decode($row['configuracao'], true);
            $url = isset($cfg['url']) ? $cfg['url'] : '';
            if (empty($url)) continue;

            $payload = json_encode([
                'origem' => 'JARVIS_RESIDENCIAL',
                'evento' => $evento,
                'data_hora' => date('c'),
                'dados' => $dados
            ]);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Exception $e) {}
}

function agente_consultar_clima($cidade = 'Sao Paulo') {
    // Consulta previsão gratuita Open-Meteo (lat/long aproximado de SP: -23.55, -46.63)
    try {
        $ch = curl_init("https://api.open-meteo.com/v1/forecast?latitude=-23.55&longitude=-46.63&current=temperature_2m,relative_humidity_2m,precipitation,is_day,weather_code&daily=temperature_2m_max,temperature_2m_min&timezone=America%2FSao_Paulo");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $data = json_decode($res, true);
            if (isset($data['current'])) {
                return [
                    'cidade' => $cidade,
                    'temperatura' => $data['current']['temperature_2m'] . '°C',
                    'umidade' => $data['current']['relative_humidity_2m'] . '%',
                    'precipitacao' => $data['current']['precipitation'] . ' mm',
                    'vai_chover' => ($data['current']['precipitation'] > 0 || $data['current']['weather_code'] >= 51)
                ];
            }
        }
    } catch (Exception $e) {}
    return ['cidade' => $cidade, 'temperatura' => '24°C', 'vai_chover' => false];
}

// Endpoint HTTP
$acao = isset($_GET['acao']) ? $_GET['acao'] : (isset($_POST['acao']) ? $_POST['acao'] : '');

if ($acao === 'upload_espcam') {
    // Recebe snapshot e telemetria da ESP32-CAM
    $token = isset($_SERVER['HTTP_X_DEVICE_TOKEN']) ? $_SERVER['HTTP_X_DEVICE_TOKEN'] : (isset($_POST['token']) ? $_POST['token'] : '');
    $dev = validar_hardware_token($token);
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Token de hardware inválido ou bloqueado']);
        exit;
    }

    $pasta_dest = '/home/mmm/servicos/espcam_snapshots';
    if (!is_dir($pasta_dest)) {
        @mkdir($pasta_dest, 0777, true);
    }

    $nome_arquivo = 'cam_' . $dev['id'] . '_' . time() . '.jpg';
    $caminho_final = $pasta_dest . '/' . $nome_arquivo;

    if (!empty($_FILES['foto']['tmp_name'])) {
        move_uploaded_file($_FILES['foto']['tmp_name'], $caminho_final);
    } else {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            file_put_contents($caminho_final, $raw);
        }
    }

    $movimento = !empty($_POST['movimento']) || !empty($_GET['movimento']);
    if ($movimento) {
        registrar_evento_seguranca($dev['ip_address'], 'DETECCAO_MOVIMENTO_CAM', "Movimento detectado pela câmera {$dev['nome']} ({$dev['localizacao']})", 'AVISO');
        agente_telegram_enviar("⚠️ <b>JARVIS Alerta de Segurança:</b>\nMovimento detectado na câmera <b>{$dev['nome']}</b> ({$dev['localizacao']})!", $caminho_final);
    }

    echo json_encode([
        'status' => 'sucesso',
        'arquivo' => $nome_arquivo,
        'url' => '/api/agente_externo.php?acao=ver_foto&foto=' . $nome_arquivo
    ]);
    exit;
}

if ($acao === 'ver_foto') {
    verify_api_auth();
    $foto = isset($_GET['foto']) ? basename($_GET['foto']) : '';
    $path = '/home/mmm/servicos/espcam_snapshots/' . $foto;
    if (file_exists($path)) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    http_response_code(404);
    echo "Foto não encontrada";
    exit;
}

if ($acao === 'testar_telegram') {
    verify_api_auth();
    $res = agente_telegram_enviar("🤖 <b>JARVIS:</b> Teste de Notificação Externa efetuado com sucesso em " . date('d/m/Y H:i:s'));
    echo json_encode(['status' => $res ? 'sucesso' : 'erro', 'resposta' => $res]);
    exit;
}

if ($acao === 'consultar_clima') {
    verify_api_auth();
    $clima = agente_consultar_clima();
    echo json_encode(['status' => 'sucesso', 'clima' => $clima]);
    exit;
}
?>
