<?php
header('Content-Type: application/json; charset=utf-8');
include_once('../sessao.php');
include_once('../config.php');
include_once('../funcs.php');

$input = json_decode(file_get_contents('php://input'), true);
$pergunta = '';
if (is_array($input) && isset($input['pergunta'])) {
    $pergunta = trim($input['pergunta']);
} elseif (isset($_POST['pergunta'])) {
    $pergunta = trim($_POST['pergunta']);
}

if (empty($pergunta)) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Pergunta vazia']);
    exit;
}

// Contexto do sistema residencial para a IA
$system_prompt = "Você é o Assistente Inteligente da Residência Casa Inteligente de Marcelo Maurin Martins. Você controla sensores, iluminação, piscina e sintetizador de voz. Responda em português de forma clara, educada e prestativa.";

$payload = [
    "model" => "LiquidAI/lfm2.5-1.2b-instruct:q4_k_m",
    "prompt" => "{$system_prompt}\n\nUsuário: {$pergunta}\nAssistente:",
    "stream" => false
];

$ch = curl_init("{$llm_api_url}/api/generate");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($response && $httpcode === 200) {
    $res_data = json_decode($response, true);
    $resposta_ia = isset($res_data['response']) ? trim($res_data['response']) : 'Não foi possível obter resposta do modelo.';
    
    // Salvar no PostgreSQL
    try {
        $pdo = get_db_pdo();
        $stmt = $pdo->prepare("INSERT INTO llm_conversas (user_msg, bot_msg, contexto) VALUES (:u, :b, 'chat_web')");
        $stmt->execute([':u' => $pergunta, ':b' => $resposta_ia]);
    } catch(Exception $e) {
        error_log("Erro ao salvar chat no banco: " . $e->getMessage());
    }

    echo json_encode([
        'status' => 'sucesso',
        'pergunta' => $pergunta,
        'resposta' => $resposta_ia
    ]);
} else {
    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'Falha na comunicação com o servidor llama.cpp: ' . $curl_err
    ]);
}
?>
