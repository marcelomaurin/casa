<?php
// CASA/JARVIS - endpoint exclusivo do site: IA somente via RunPod.
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

$runpodApiKey = trim($configs['runpod_api_key'] ?? '');
$runpodEndpointId = trim($configs['runpod_endpoint_id'] ?? '');
$runpodModel = trim($configs['runpod_model'] ?? 'meta-llama/Meta-Llama-3-8B-Instruct');
$jarvisVoice = trim($configs['jarvis_voice'] ?? 'padrao');

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

$url = "https://api.runpod.ai/v2/" . rawurlencode($runpodEndpointId) . "/openai/v1/chat/completions";
$payload = [
    'model' => $runpodModel,
    'messages' => [
        ['role'=>'system', 'content'=>$systemPrompt],
        ['role'=>'user', 'content'=>$comando]
    ],
    'temperature' => 0.35,
    'max_tokens' => 700
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $runpodApiKey
]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if (!$res || $httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    echo json_encode([
        'status'=>'erro',
        'mensagem'=>'Falha ao consultar o RunPod' . ($curlError ? ': ' . $curlError : ' (HTTP ' . $httpCode . ')'),
        'provedor'=>'RunPod',
        'target_ia'=>'runpod',
        'modo_roteamento'=>'cloud_only'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($res, true);
$resposta = trim($data['choices'][0]['message']['content'] ?? '');
if ($resposta === '') {
    http_response_code(502);
    echo json_encode([
        'status'=>'erro',
        'mensagem'=>'RunPod respondeu sem conteúdo utilizável.',
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
        ':c'=>'IA Externa: RunPod GPU'
    ]);
} catch (Exception $e) {}

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
