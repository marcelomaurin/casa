<?php
// CASA/JARVIS - endpoint exclusivo do site: IA somente via RunPod.
// O site NAO chama o endpoint OpenAI-compatible do RunPod diretamente.
// Ele chama a API intermediaria da Maurinsoft em /api/chat, usando resposta sincrona.
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');
verify_api_auth();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$comando = trim($input['comando'] ?? ($input['prompt'] ?? ($input['msg'] ?? '')));
if ($comando === '') {
    http_response_code(400);
    echo json_encode(['status'=>'erro','mensagem'=>'Nenhum comando fornecido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db_pdo();
$configs = [];
try {
    $stmt = $pdo->query("SELECT chave, valor FROM configuracoes_sistema");
    while ($row = $stmt->fetch()) {
        $configs[$row['chave']] = $row['valor'];
    }
} catch (Exception $e) {}

// Valores padrao do site: somente RunPod.
$runpodApiKey = trim($configs['runpod_api_key'] ?? '');
$runpodEndpointId = trim($configs['runpod_endpoint_id'] ?? '');
$runpodModel = trim($configs['runpod_model'] ?? 'meta-llama/Meta-Llama-3-8B-Instruct');
$jarvisVoice = trim($configs['jarvis_voice'] ?? 'padrao');
$chatUrl = trim($configs['maurinsoft_chat_url'] ?? 'https://maurinsoft.com.br/api/chat');

if ($runpodApiKey === '' || $runpodEndpointId === '') {
    http_response_code(503);
    echo json_encode([
        'status'=>'erro',
        'mensagem'=>'RunPod não configurado. Informe a API Key e o Endpoint ID nas configurações.',
        'provedor'=>'RunPod',
        'target_ia'=>'runpod',
        'modo_roteamento'=>'cloud_only'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$systemPrompt = "Voce e o JARVIS, assistente do sistema CASA. Responda em portugues com clareza, objetividade e seguranca. " .
    "Quando o usuario solicitar automacao residencial, interprete o pedido de forma direta. " .
    "Nao invente fatos atuais e nao afirme que executou algo se nao houver confirmacao do sistema.";

// A API Maurinsoft recebe um unico campo prompt. Enviamos o contexto do sistema junto.
$prompt = $systemPrompt . "\n\nUsuario: " . $comando . "\nJARVIS:";

$payload = [
    'runpod_api_key' => $runpodApiKey,
    'runpod_endpoint_id' => $runpodEndpointId,
    'model' => $runpodModel,
    'prompt' => $prompt
];

$ch = curl_init($chatUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_TIMEOUT, 90);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($res === false || $res === '' || $httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    echo json_encode([
        'status'=>'erro',
        'mensagem'=>'Falha ao consultar a API Maurinsoft/RunPod' . ($curlError ? ': ' . $curlError : ' (HTTP ' . $httpCode . ')'),
        'provedor'=>'RunPod',
        'target_ia'=>'runpod',
        'modo_roteamento'=>'cloud_only'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($res, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode([
        'status'=>'erro',
        'mensagem'=>'A API Maurinsoft/RunPod retornou JSON inválido.',
        'provedor'=>'RunPod',
        'target_ia'=>'runpod',
        'modo_roteamento'=>'cloud_only'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($data['success'])) {
    http_response_code(502);
    echo json_encode([
        'status'=>'erro',
        'mensagem'=>trim((string)($data['error'] ?? $data['message'] ?? 'A API Maurinsoft/RunPod retornou success=false.')),
        'provedor'=>'RunPod',
        'target_ia'=>'runpod',
        'modo_roteamento'=>'cloud_only'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$resposta = trim((string)($data['output'] ?? ''));
if ($resposta === '') {
    http_response_code(502);
    echo json_encode([
        'status'=>'erro',
        'mensagem'=>'A API Maurinsoft/RunPod respondeu sem conteúdo em output.',
        'provedor'=>'RunPod',
        'target_ia'=>'runpod',
        'modo_roteamento'=>'cloud_only'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO llm_conversas (user_msg, bot_msg, contexto) VALUES (:u, :b, :c)");
    $stmt->execute([
        ':u'=>$comando,
        ':b'=>$resposta,
        ':c'=>'IA Externa: RunPod GPU via Maurinsoft'
    ]);
} catch (Exception $e) {}

// Mantem o formato esperado pelo JavaScript atual do site.
echo json_encode([
    'status'=>'sucesso',
    'comando'=>$comando,
    'resposta'=>$resposta,
    'provedor'=>'IA Externa: RunPod GPU',
    'target_ia'=>'runpod',
    'tipo_tarefa'=>'runpod_only',
    'modo_roteamento'=>'cloud_only',
    'acao'=>null,
    'audio_url'=>null,
    'speaker'=>$jarvisVoice
], JSON_UNESCAPED_UNICODE);
