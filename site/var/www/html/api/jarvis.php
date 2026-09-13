<?php
// Gateway Unificado da Inteligencia JARVIS Residencial
// Suporta:
// 1. Provedor Local (llama.cpp / Ollama)
// 2. Nuvem GPU Serverless (RunPod.io vLLM / OpenAI Compatible)
// 3. Fallback automatico
// 4. Extracao de acoes de automacao (ligar luz, irrigacao, consulta de sensores)
// 5. Sintese de resposta em voz neural/clonada

header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');

// Verificação de Segurança (Sessão Web ou Token de API)
verify_api_auth();

$input = json_decode(file_get_contents('php://input'), true);
$comando = '';
if (is_array($input)) {
    if (!empty($input['comando'])) $comando = trim($input['comando']);
    elseif (!empty($input['prompt'])) $comando = trim($input['prompt']);
    elseif (!empty($input['msg'])) $comando = trim($input['msg']);
}
if (empty($comando)) {
    if (!empty($_POST['comando'])) $comando = trim($_POST['comando']);
    elseif (!empty($_POST['prompt'])) $comando = trim($_POST['prompt']);
    elseif (!empty($_POST['msg'])) $comando = trim($_POST['msg']);
}

if (empty($comando)) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Nenhum comando fornecido']);
    exit;
}

$pdo = get_db_pdo();

// 1. Obter configuracoes atuais do sistema
$stmt = $pdo->query("SELECT chave, valor FROM configuracoes_sistema");
$configs = [];
while ($row = $stmt->fetch()) {
    $configs[$row['chave']] = $row['valor'];
}

$routing_mode = isset($configs['ia_routing_mode']) ? $configs['ia_routing_mode'] : 'auto';
if (is_array($input) && !empty($input['ia_mode'])) {
    $routing_mode = trim($input['ia_mode']);
} elseif (!empty($_POST['ia_mode'])) {
    $routing_mode = trim($_POST['ia_mode']);
}

$runpod_api_key = isset($configs['runpod_api_key']) ? $configs['runpod_api_key'] : '';
$runpod_endpoint_id = isset($configs['runpod_endpoint_id']) ? $configs['runpod_endpoint_id'] : '';
$runpod_model = isset($configs['runpod_model']) && !empty($configs['runpod_model']) ? $configs['runpod_model'] : 'meta-llama/Meta-Llama-3-8B-Instruct';
$local_model = isset($configs['local_model']) ? $configs['local_model'] : 'jarvis-local:latest';
$jarvis_voice = isset($configs['jarvis_voice']) ? $configs['jarvis_voice'] : 'padrao';

// 2. Classificador de Intencao e Atribuicao de Especialidade Multi-IA
$target_ia = 'local';
$tipo_tarefa = 'automacao_residencial';

if (strpos($comando, '/cloud') === 0 || strpos($comando, '/runpod') === 0) {
    $target_ia = 'runpod';
    $tipo_tarefa = 'analise_forcada_nuvem';
    $comando = trim(preg_replace('/^\/(cloud|runpod)\s*/i', '', $comando));
} elseif (strpos($comando, '/local') === 0) {
    $target_ia = 'local';
    $tipo_tarefa = 'comando_forcado_local';
    $comando = trim(preg_replace('/^\/local\s*/i', '', $comando));
} elseif ($routing_mode === 'local_only') {
    $target_ia = 'local';
    $tipo_tarefa = 'modo_apenas_local';
} elseif ($routing_mode === 'cloud_only') {
    $target_ia = 'runpod';
    $tipo_tarefa = 'modo_apenas_nuvem';
} else {
    // MODO AUTO: Hibrido Inteligente por Especialidade
    $cmd_lower = strtolower($comando);
    $eh_comando_fisico = preg_match('/(lig|deslig|acend|apag|irriga|bomba|piscina|status|temperatura|rele|porta|janela|alarme|sensor)/i', $cmd_lower);
    
    $cloud_keywords = isset($configs['ia_cloud_keywords']) ? $configs['ia_cloud_keywords'] : 'analise,pesquisa,programe,codigo,explique,calcule,redija,artigo,relatorio,python,sql';
    $keywords_arr = array_filter(array_map('trim', explode(',', $cloud_keywords)));
    $eh_pesquisa_complexa = false;
    foreach ($keywords_arr as $kw) {
        if (!empty($kw) && strpos($cmd_lower, $kw) !== false) {
            $eh_pesquisa_complexa = true;
            break;
        }
    }

    if ($eh_pesquisa_complexa || (strlen($comando) > 90 && !$eh_comando_fisico)) {
        $target_ia = 'runpod';
        $tipo_tarefa = 'pesquisa_analise_programacao';
    } else {
        $target_ia = 'local';
        $tipo_tarefa = 'automacao_perguntas_simples';
    }
}

// 3. Prompts Especializados
$system_prompt_local = "Voce e o JARVIS, IA residencial de Marcelo Maurin.
Especialidade: automação de relés, irrigação, iluminação, sensores e respostas concisas e imediatas.
Ao acionar dispositivos, adicione a tag:
[[CMD:LIGAR_LUZ_SALA]] ou [[CMD:DESLIGAR_LUZ_SALA]]
[[CMD:LIGAR_IRRIGACAO]] ou [[CMD:DESLIGAR_IRRIGACAO]]
Responda em portugues de forma ultra concisa (1 ou 2 frases).";

$system_prompt_cloud = "Voce e o JARVIS em modo de Alta Capacidade Cognitiva (Nuvem GPU).
Especialidade: Analise profunda, pesquisa detalhada, arquitetura de software, programação e raciocínio avançado.
Responda em portugues com clareza, riqueza técnica e excelência para o usuário Marcelo Maurin.";

function chamar_llm_runpod($apiKey, $endpointId, $model, $system_prompt, $user_msg) {
    if (empty($apiKey) || empty($endpointId)) {
        return false;
    }
    $url = "https://api.runpod.ai/v2/{$endpointId}/openai/v1/chat/completions";
    $payload = [
        "model" => $model,
        "messages" => [
            ["role" => "system", "content" => $system_prompt],
            ["role" => "user", "content" => $user_msg]
        ],
        "temperature" => 0.4,
        "max_tokens" => 350
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer {$apiKey}"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && $httpcode === 200) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
    }
    return false;
}

function chamar_llm_local($model, $system_prompt, $user_msg) {
    $url = "http://127.0.0.1:11434/api/generate";
    $payload = [
        "model" => $model,
        "prompt" => "{$system_prompt}\n\nUsuario: {$user_msg}\nJARVIS:",
        "stream" => false,
        "options" => [
            "num_predict" => 90,
            "temperature" => 0.3
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && $httpcode === 200) {
        $data = json_decode($response, true);
        return isset($data['response']) ? trim($data['response']) : false;
    }
    return false;
}

// 4. Execucao de Acordo com a Atribuicao Selecionada
$resposta_jarvis = false;
$provedor_usado = "";

if ($target_ia === 'runpod') {
    if (!empty($runpod_api_key) && !empty($runpod_endpoint_id)) {
        $resposta_jarvis = chamar_llm_runpod($runpod_api_key, $runpod_endpoint_id, $runpod_model, $system_prompt_cloud, $comando);
        if ($resposta_jarvis) {
            $provedor_usado = "IA Externa: RunPod GPU [Pesquisa & Análise]";
        }
    }
    // Fallback caso a nuvem falhe ou ainda não tenha endpoint configurado
    if (!$resposta_jarvis) {
        $resposta_jarvis = chamar_llm_local($local_model, $system_prompt_local, $comando);
        $provedor_usado = "IA Local: llama.cpp [Fallback Automático]";
    }
} else {
    // IA Local (Padrão para automação e tarefas locais)
    $resposta_jarvis = chamar_llm_local($local_model, $system_prompt_local, $comando);
    $provedor_usado = "IA Local: llama.cpp [Automação Residencial]";
}

if (!$resposta_jarvis) {
    $resposta_jarvis = "Senhor, detectei seu comando, mas no momento o núcleo cognitivo local e nuvem não responderam a tempo.";
}

// 4. Processamento de Comandos Fisicos (via tag da LLM ou deteccao direta de intencao)
$cmd_lower = strtolower($comando);
$acao_executada = null;

if (strpos($resposta_jarvis, '[[CMD:LIGAR_LUZ_SALA]]') !== false || (preg_match('/(lig|acend).*(luz|sala)/i', $cmd_lower) && !preg_match('/deslig|apag/i', $cmd_lower))) {
    qryExec("UPDATE devpar SET devvalue = '1' WHERE iddevice = 1 AND devparname = 'dev1'");
    qryExec("INSERT INTO comandos_log (iddevice, comando, origem, resultado) VALUES (1, 'Ligar Luz Sala', 'JARVIS_AI', 'Executado')");
    $acao_executada = "Luz da Sala Ligada";
} elseif (strpos($resposta_jarvis, '[[CMD:DESLIGAR_LUZ_SALA]]') !== false || preg_match('/(deslig|apag).*(luz|sala)/i', $cmd_lower)) {
    qryExec("UPDATE devpar SET devvalue = '0' WHERE iddevice = 1 AND devparname = 'dev1'");
    qryExec("INSERT INTO comandos_log (iddevice, comando, origem, resultado) VALUES (1, 'Desligar Luz Sala', 'JARVIS_AI', 'Executado')");
    $acao_executada = "Luz da Sala Desligada";
} elseif (strpos($resposta_jarvis, '[[CMD:LIGAR_IRRIGACAO]]') !== false || (preg_match('/(lig|inici|acion).*(irriga|bomba|piscina)/i', $cmd_lower) && !preg_match('/deslig|par/i', $cmd_lower))) {
    qryExec("UPDATE devpar SET devvalue = '1' WHERE iddevice = 2 AND devparname = 'dev1'");
    qryExec("INSERT INTO comandos_log (iddevice, comando, origem, resultado) VALUES (2, 'Ligar Irrigacao', 'JARVIS_AI', 'Executado')");
    $acao_executada = "Irrigação da Piscina Ligada";
} elseif (strpos($resposta_jarvis, '[[CMD:DESLIGAR_IRRIGACAO]]') !== false || preg_match('/(deslig|par|cess).*(irriga|bomba|piscina)/i', $cmd_lower)) {
    qryExec("UPDATE devpar SET devvalue = '0' WHERE iddevice = 2 AND devparname = 'dev1'");
    qryExec("INSERT INTO comandos_log (iddevice, comando, origem, resultado) VALUES (2, 'Desligar Irrigacao', 'JARVIS_AI', 'Executado')");
    $acao_executada = "Irrigação da Piscina Desligada";
}

// Limpar tags da fala final
$resposta_limpa = trim(preg_replace('/\[\[CMD:.*?\]\]/', '', $resposta_jarvis));

// 5. Salvar historico no PostgreSQL
try {
    $stmt = $pdo->prepare("INSERT INTO llm_conversas (user_msg, bot_msg, contexto) VALUES (:u, :b, :c)");
    $stmt->execute([
        ':u' => $comando,
        ':b' => $resposta_limpa,
        ':c' => $provedor_usado
    ]);
} catch (Exception $e) {
    error_log("Erro ao salvar conversa: " . $e->getMessage());
}

// 6. Sintetizar a resposta em audio neural/clone
$audio_url = null;
try {
    $fala_payload = json_encode([
        'texto' => $resposta_limpa,
        'speaker' => $jarvis_voice,
        'reproduzir' => true
    ]);
    $ch = curl_init("http://127.0.0.1:8097/falar");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fala_payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $tts_res = curl_exec($ch);
    curl_close($ch);
    if ($tts_res) {
        $tts_data = json_decode($tts_res, true);
        if (isset($tts_data['audio_url'])) {
            $audio_url = "/api/audio.php?file=" . basename($tts_data['audio_url']);
        }
    }
} catch (Exception $e) {
    error_log("Erro na sintese de voz do JARVIS: " . $e->getMessage());
}

echo json_encode([
    'status' => 'sucesso',
    'comando' => $comando,
    'resposta' => $resposta_limpa,
    'provedor' => $provedor_usado,
    'target_ia' => $target_ia,
    'tipo_tarefa' => $tipo_tarefa,
    'modo_roteamento' => $routing_mode,
    'acao' => $acao_executada,
    'audio_url' => $audio_url,
    'speaker' => $jarvis_voice
], JSON_UNESCAPED_UNICODE);
?>
