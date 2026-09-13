<?php
// Módulo de Agentes de Ações Externas do JARVIS
// Permite:
// 1. Notificações externas no Telegram / Webhooks
// 2. Consulta a APIs de clima/tempo para decisões de automação
// 3. Recepção e arquivamento de fotos e alertas de movimento da ESP32-CAM
// 4. Detecção facial com Google Cloud Vision
// 5. Encaminhamento da imagem para IA multimodal quando houver rosto detectado

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

        if (empty($token) || empty($chat_id) || $token === 'SEU_TELEGRAM_BOT_TOKEN') return false;

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

function espcam_executar_python_json($script, $args = []) {
    $python = getenv('JARVIS_PYTHON_BIN');
    if (!$python) $python = '/usr/bin/python3';

    if (!is_file($script)) {
        return ['sucesso' => false, 'erro' => 'Script não encontrado', 'script' => $script];
    }

    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) $cmd .= ' ' . escapeshellarg((string)$arg);
    $cmd .= ' 2>&1';

    $linhas = [];
    $codigo = 0;
    exec($cmd, $linhas, $codigo);

    for ($i = count($linhas) - 1; $i >= 0; $i--) {
        $dados = json_decode(trim($linhas[$i]), true);
        if (is_array($dados)) {
            $dados['exit_code'] = $codigo;
            return $dados;
        }
    }

    return [
        'sucesso' => false,
        'erro' => 'Worker não retornou JSON válido',
        'exit_code' => $codigo,
        'saida' => implode("\n", $linhas)
    ];
}

function espcam_detectar_faces($caminho_imagem) {
    $script = getenv('JARVIS_ESPCAM_PROCESSOR');
    if (!$script) $script = '/home/mmm/servicos/espcam/processa_imagem.py';
    return espcam_executar_python_json($script, [$caminho_imagem]);
}

function espcam_enviar_para_ia($caminho_imagem, $dev, $analise_faces) {
    $script = getenv('JARVIS_ESPCAM_AI_PROCESSOR');
    if (!$script) $script = '/home/mmm/servicos/espcam/analisa_cena_ia.py';

    $metadados = [
        'id_dispositivo' => isset($dev['id']) ? $dev['id'] : null,
        'camera' => isset($dev['nome']) ? $dev['nome'] : 'ESP32-CAM',
        'localizacao' => isset($dev['localizacao']) ? $dev['localizacao'] : null,
        'ip' => isset($dev['ip_address']) ? $dev['ip_address'] : null,
        'rostos' => isset($analise_faces['rostos']) ? (int)$analise_faces['rostos'] : 0,
        'confianca_max' => isset($analise_faces['confianca_max']) ? $analise_faces['confianca_max'] : null
    ];

    return espcam_executar_python_json($script, [
        $caminho_imagem,
        json_encode($metadados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
}

function espcam_criar_evento($dev, $nome_arquivo, $movimento) {
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO camera_eventos
             (id_dispositivo, nome_dispositivo, localizacao, arquivo, movimento, status_processamento)
             VALUES (:id, :nome, :localizacao, :arquivo, :movimento, 'PROCESSANDO') RETURNING id"
        );
        $stmt->execute([
            ':id' => isset($dev['id']) ? $dev['id'] : null,
            ':nome' => isset($dev['nome']) ? $dev['nome'] : null,
            ':localizacao' => isset($dev['localizacao']) ? $dev['localizacao'] : null,
            ':arquivo' => $nome_arquivo,
            ':movimento' => $movimento
        ]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log('JARVIS ESPCAM: erro ao criar evento: ' . $e->getMessage());
        return null;
    }
}

function espcam_salvar_faces($id_evento, $analise) {
    global $pdo;
    if (!$id_evento) return;

    try {
        $sucesso = !empty($analise['sucesso']);
        $stmt = $pdo->prepare(
            "UPDATE camera_eventos SET
                quantidade_faces = :qtd,
                confianca_max = :conf,
                status_processamento = :status,
                provedor = :provedor,
                dados_analise = CAST(:dados AS jsonb),
                erro = :erro,
                data_processamento = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([
            ':qtd' => isset($analise['rostos']) ? (int)$analise['rostos'] : 0,
            ':conf' => isset($analise['confianca_max']) ? $analise['confianca_max'] : null,
            ':status' => $sucesso ? 'CONCLUIDO' : 'ERRO',
            ':provedor' => isset($analise['provedor']) ? $analise['provedor'] : 'google_cloud_vision',
            ':dados' => json_encode($analise, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':erro' => $sucesso ? null : (isset($analise['erro']) ? $analise['erro'] : 'Falha na detecção facial'),
            ':id' => $id_evento
        ]);
    } catch (Exception $e) {
        error_log('JARVIS ESPCAM: erro ao salvar faces: ' . $e->getMessage());
    }
}

function espcam_salvar_ia($id_evento, $resultado_ia) {
    global $pdo;
    if (!$id_evento) return;

    try {
        $sucesso = !empty($resultado_ia['sucesso']);
        $stmt = $pdo->prepare(
            "UPDATE camera_eventos SET
                status_ia = :status,
                modelo_ia = :modelo,
                resposta_ia = CAST(:resposta AS jsonb),
                erro_ia = :erro,
                data_ia = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([
            ':status' => $sucesso ? 'CONCLUIDO' : 'ERRO',
            ':modelo' => isset($resultado_ia['modelo']) ? $resultado_ia['modelo'] : null,
            ':resposta' => json_encode($resultado_ia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':erro' => $sucesso ? null : (isset($resultado_ia['erro']) ? $resultado_ia['erro'] : 'Falha na IA visual'),
            ':id' => $id_evento
        ]);
    } catch (Exception $e) {
        error_log('JARVIS ESPCAM: erro ao salvar resposta IA: ' . $e->getMessage());
    }
}

function espcam_marcar_ia_nao_necessaria($id_evento) {
    global $pdo;
    if (!$id_evento) return;
    try {
        $stmt = $pdo->prepare("UPDATE camera_eventos SET status_ia = 'SEM_FACE' WHERE id = :id");
        $stmt->execute([':id' => $id_evento]);
    } catch (Exception $e) {}
}

$acao = isset($_GET['acao']) ? $_GET['acao'] : (isset($_POST['acao']) ? $_POST['acao'] : '');

if ($acao === 'upload_espcam') {
    $token = isset($_SERVER['HTTP_X_DEVICE_TOKEN']) ? $_SERVER['HTTP_X_DEVICE_TOKEN'] : (isset($_POST['token']) ? $_POST['token'] : '');
    $dev = validar_hardware_token($token);
    if (!$dev) {
        http_response_code(401);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Token de hardware inválido ou bloqueado']);
        exit;
    }

    $pasta_dest = '/home/mmm/servicos/espcam_snapshots';
    if (!is_dir($pasta_dest)) @mkdir($pasta_dest, 0775, true);

    $nome_arquivo = 'cam_' . $dev['id'] . '_' . time() . '.jpg';
    $caminho_final = $pasta_dest . '/' . $nome_arquivo;
    $gravou = false;

    if (!empty($_FILES['foto']['tmp_name'])) {
        $gravou = move_uploaded_file($_FILES['foto']['tmp_name'], $caminho_final);
    } else {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) $gravou = (file_put_contents($caminho_final, $raw, LOCK_EX) !== false);
    }

    if (!$gravou || !is_file($caminho_final) || filesize($caminho_final) <= 0) {
        @unlink($caminho_final);
        http_response_code(400);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Imagem não recebida ou não pôde ser gravada']);
        exit;
    }

    $infoImagem = @getimagesize($caminho_final);
    if (!$infoImagem || !isset($infoImagem[2]) || $infoImagem[2] !== IMAGETYPE_JPEG) {
        @unlink($caminho_final);
        http_response_code(415);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Payload não é JPEG válido']);
        exit;
    }

    $movimento = !empty($_POST['movimento']) || !empty($_GET['movimento']);
    $id_evento = espcam_criar_evento($dev, $nome_arquivo, $movimento);

    // 1) Google Cloud Vision detecta se existem rostos.
    $analise_faces = espcam_detectar_faces($caminho_final);
    espcam_salvar_faces($id_evento, $analise_faces);

    $qtd_faces = isset($analise_faces['rostos']) ? (int)$analise_faces['rostos'] : 0;
    $resultado_ia = null;

    // 2) Havendo rosto, o JARVIS avisa a IA multimodal e envia a imagem completa.
    if (!empty($analise_faces['sucesso']) && $qtd_faces > 0) {
        $resultado_ia = espcam_enviar_para_ia($caminho_final, $dev, $analise_faces);
        espcam_salvar_ia($id_evento, $resultado_ia);

        agente_webhook_disparar('JARVIS_FACE_DETECTADA_IA', [
            'id_evento' => $id_evento,
            'id_dispositivo' => isset($dev['id']) ? $dev['id'] : null,
            'camera' => isset($dev['nome']) ? $dev['nome'] : null,
            'localizacao' => isset($dev['localizacao']) ? $dev['localizacao'] : null,
            'arquivo' => $nome_arquivo,
            'quantidade_faces' => $qtd_faces,
            'analise_ia' => $resultado_ia
        ]);
    } elseif (!empty($analise_faces['sucesso'])) {
        espcam_marcar_ia_nao_necessaria($id_evento);
    }

    if ($movimento) {
        $descricao = "Movimento detectado pela câmera {$dev['nome']} ({$dev['localizacao']})";
        if (!empty($analise_faces['sucesso'])) $descricao .= " - rostos: {$qtd_faces}";
        else $descricao .= " - detector facial indisponível";
        registrar_evento_seguranca($dev['ip_address'], 'DETECCAO_MOVIMENTO_CAM', $descricao, 'AVISO');

        $msg = "⚠️ <b>JARVIS Alerta de Segurança:</b>\nMovimento na câmera <b>{$dev['nome']}</b> ({$dev['localizacao']}).";
        if ($qtd_faces > 0) $msg .= "\n👤 Rostos detectados: <b>{$qtd_faces}</b>.";
        elseif (!empty($analise_faces['sucesso'])) $msg .= "\nNenhum rosto detectado.";

        if (is_array($resultado_ia) && !empty($resultado_ia['sucesso']) && isset($resultado_ia['analise'])) {
            $analiseTexto = is_array($resultado_ia['analise'])
                ? json_encode($resultado_ia['analise'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)$resultado_ia['analise'];
            if (strlen($analiseTexto) > 700) $analiseTexto = substr($analiseTexto, 0, 700) . '...';
            $msg .= "\n🤖 IA: " . htmlspecialchars($analiseTexto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        agente_telegram_enviar($msg, $caminho_final);
    }

    echo json_encode([
        'status' => 'sucesso',
        'id_evento' => $id_evento,
        'arquivo' => $nome_arquivo,
        'url' => '/api/agente_externo.php?acao=ver_foto&foto=' . $nome_arquivo,
        'faces' => [
            'sucesso' => !empty($analise_faces['sucesso']),
            'quantidade' => $qtd_faces,
            'confianca_max' => isset($analise_faces['confianca_max']) ? $analise_faces['confianca_max'] : null
        ],
        'ia_acionada' => ($qtd_faces > 0),
        'ia_sucesso' => is_array($resultado_ia) ? !empty($resultado_ia['sucesso']) : null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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