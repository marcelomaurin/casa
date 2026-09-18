<?php
// JARVIS RESIDENCIAL - Gateway unificado de IA, planejamento, web, automacao e voz
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');
require_once(__DIR__ . '/agente_externo.php');
verify_api_auth();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$comando = trim($input['comando'] ?? ($input['prompt'] ?? ($input['msg'] ?? ($_POST['comando'] ?? ($_POST['prompt'] ?? ($_POST['msg'] ?? ''))))));
$origem = trim($input['origem'] ?? 'JARVIS');
$skipPlanner = !empty($input['skip_planner']);
$forcarPlanejamento = !empty($input['planejar']);

if ($comando === '') {
    http_response_code(400);
    echo json_encode(['status'=>'erro','mensagem'=>'Nenhum comando fornecido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db_pdo();
$configs = [];
try {
    $stmt = $pdo->query("SELECT chave, valor FROM configuracoes_sistema");
    while ($row = $stmt->fetch()) $configs[$row['chave']] = $row['valor'];
} catch (Exception $e) {}

function jarvis_post_local($url, $payload, $timeout=50) {
    $token = get_system_api_token();
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $token]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$res || $code < 200 || $code >= 300) return ['ok'=>false,'erro'=>$err ?: ('HTTP '.$code)];
    $j = json_decode($res, true);
    return is_array($j) ? ['ok'=>true,'dados'=>$j] : ['ok'=>false,'erro'=>'JSON invalido'];
}

function jarvis_tts($texto, $speaker='padrao', $reproduzir=true) {
    if (trim($texto) === '') return null;
    $ch = curl_init('http://127.0.0.1:8097/falar');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'texto'=>$texto,'speaker'=>$speaker,'reproduzir'=>$reproduzir
    ], JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$res || $code < 200 || $code >= 300) return null;
    $j = json_decode($res, true);
    return !empty($j['audio_url']) ? '/api/audio.php?file=' . rawurlencode(basename($j['audio_url'])) : null;
}

function jarvis_deve_planejar($cmd) {
    $c = mb_strtolower($cmd, 'UTF-8');
    if (preg_match('/^\s*\/planejar\b/u', $c)) return true;
    if (preg_match('/\b(amanh[aã]|depois de amanh[aã]|daqui a|[àa]s \d{1,2}(:\d{2})?|todo dia|toda semana|todo m[eê]s|semanalmente|diariamente)\b/u', $c)) return true;
    if (preg_match('/\b(e depois|depois disso|em seguida|logo depois|ap[oó]s isso)\b/u', $c)) return true;
    if (preg_match('/\b(quando|assim que|se acontecer|caso aconte[cç]a|quando detectar|quando chegar)\b/u', $c)) return true;
    if (substr_count($cmd, ';') >= 1) return true;
    return false;
}

function jarvis_pedido_web($cmd) {
    $c = mb_strtolower($cmd, 'UTF-8');
    return preg_match('/(^\s*\/web\b|pesquis(e|ar).*internet|procure.*internet|busque.*internet|busca.*internet|[uú]ltimas not[ií]cias|not[ií]cias de hoje|na internet)/u', $c);
}

// 0. Planejamento antes da execucao quando a demanda pede varias etapas, futuro ou condicao.
if (!$skipPlanner && ($forcarPlanejamento || jarvis_deve_planejar($comando))) {
    $demanda = preg_replace('/^\s*\/planejar\s*/iu', '', $comando);
    $p = jarvis_post_local('http://127.0.0.1/api/planejador.php', [
        'demanda'=>$demanda,
        'origem'=>$origem,
        'executar_imediatas'=>true
    ], 90);
    if ($p['ok'] && (($p['dados']['status'] ?? '') === 'ok')) {
        $dados = $p['dados'];
        $tarefas = $dados['tarefas'] ?? [];
        $nAg = 0; $nIm = 0; $nCond = 0;
        foreach ($tarefas as $t) {
            if (($t['tipo'] ?? '') === 'AGENDADA') $nAg++;
            elseif (($t['tipo'] ?? '') === 'CONDICIONAL') $nCond++;
            else $nIm++;
        }
        $resp = 'Organizei a demanda em ' . count($tarefas) . ' tarefa(s)';
        if ($nIm) $resp .= ", {$nIm} imediata(s)";
        if ($nAg) $resp .= ", {$nAg} agendada(s)";
        if ($nCond) $resp .= ", {$nCond} condicional(is)";
        $resp .= '. ' . trim($dados['resumo'] ?? '');
        $audio = jarvis_tts($resp, $configs['jarvis_voice'] ?? 'padrao', true);
        echo json_encode([
            'status'=>'sucesso','resposta'=>$resp,'tipo_tarefa'=>'plano_de_tarefas',
            'id_plano'=>$dados['id_plano'] ?? null,'plano'=>$dados,'audio_url'=>$audio
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 1. Pesquisa web direta para demandas atuais.
if (!$skipPlanner && jarvis_pedido_web($comando)) {
    $query = preg_replace('/^\s*\/web\s*/iu', '', $comando);
    $w = jarvis_post_local('http://127.0.0.1/api/agente_internet.php', [
        'acao'=>'responder','query'=>$query,'max_results'=>5
    ], 75);
    if ($w['ok'] && (($w['dados']['status'] ?? '') === 'ok')) {
        $d = $w['dados'];
        echo json_encode([
            'status'=>'sucesso','comando'=>$comando,'resposta'=>$d['resposta'] ?? '',
            'provedor'=>'Agente Web','target_ia'=>'web','tipo_tarefa'=>'pesquisa_internet',
            'fontes'=>$d['fontes'] ?? [],'audio_url'=>$d['audio_url'] ?? null
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$routing_mode = $configs['ia_routing_mode'] ?? 'auto';
if (!empty($input['ia_mode'])) $routing_mode = trim($input['ia_mode']);
elseif (!empty($_POST['ia_mode'])) $routing_mode = trim($_POST['ia_mode']);

$ia_provider = strtolower(trim($configs['ia_provider'] ?? 'local')); // local | remote
$ia_remote_provider = strtolower(trim($configs['ia_remote_provider'] ?? 'runpod'));
$ia_remote_model = trim($configs['ia_remote_model'] ?? '');
$ia_remote_api_key = trim($configs['ia_remote_api_key'] ?? '');
$ia_remote_base_url = trim($configs['ia_remote_base_url'] ?? '');
// Compatibilidade com configurações anteriores.
if (!in_array($ia_provider, ['local','remote'], true)) {
    $ia_remote_provider = $ia_provider === 'openai_compatible' ? 'custom' : $ia_provider;
    $ia_provider = ($ia_remote_provider === 'local') ? 'local' : 'remote';
}
$runpod_api_key = $configs['runpod_api_key'] ?? $ia_remote_api_key;
$runpod_endpoint_id = $configs['runpod_endpoint_id'] ?? '';
$runpod_protocol = strtolower(trim($configs['runpod_protocol'] ?? 'openai')); // native | openai
if (!in_array($runpod_protocol, ['native','openai'], true)) $runpod_protocol = 'openai';
$runpod_model = !empty($configs['runpod_model']) ? $configs['runpod_model'] : ($ia_remote_model ?: 'meta-llama/Meta-Llama-3-8B-Instruct');
$local_model = $configs['local_model'] ?? 'jarvis-local:latest';
$local_ollama_url = trim($configs['local_ollama_url'] ?? 'http://127.0.0.1:11434');
$openai_base_url = trim($configs['openai_base_url'] ?? $ia_remote_base_url);
$openai_api_key = trim($configs['openai_api_key'] ?? $ia_remote_api_key);
$openai_model = trim($configs['openai_model'] ?? $ia_remote_model);
$jarvis_voice = $configs['jarvis_voice'] ?? 'padrao';

function chamar_llm_runpod($apiKey, $endpointId, $model, $systemPrompt, $userMsg) {
    if ($apiKey === '' || $endpointId === '') return false;
    $url = "https://api.runpod.ai/v2/{$endpointId}/openai/v1/chat/completions";
    $payload = [
        'model'=>$model,
        'messages'=>[
            ['role'=>'system','content'=>$systemPrompt],
            ['role'=>'user','content'=>$userMsg]
        ],
        'temperature'=>0.35,'max_tokens'=>600
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','Authorization: Bearer '.$apiKey]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res && $code === 200) {
        $j = json_decode($res, true);
        return $j['choices'][0]['message']['content'] ?? false;
    }
    return false;
}

function chamar_llm_openai_compatible($baseUrl, $apiKey, $model, $systemPrompt, $userMsg) {
    $baseUrl = rtrim(trim((string)$baseUrl), '/');
    $model = trim((string)$model);
    if ($baseUrl === '' || $model === '') return ['ok'=>false,'erro'=>'URL/modelo OpenAI-compatible não configurados','http'=>0];

    if (preg_match('#/chat/completions$#i', $baseUrl)) $url = $baseUrl;
    else $url = $baseUrl . '/chat/completions';

    $payload = [
        'model'=>$model,
        'messages'=>[
            ['role'=>'system','content'=>$systemPrompt],
            ['role'=>'user','content'=>$userMsg]
        ],
        'temperature'=>0.35,
        'max_tokens'=>600,
        'stream'=>false
    ];
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>45
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res !== false && $code >= 200 && $code < 300) {
        $j = json_decode($res, true);
        $txt = $j['choices'][0]['message']['content'] ?? null;
        if (is_string($txt) && trim($txt) !== '') return ['ok'=>true,'texto'=>trim($txt),'http'=>$code];
        return ['ok'=>false,'erro'=>'Resposta OpenAI-compatible sem choices[0].message.content','http'=>$code];
    }
    $detail = '';
    if (is_string($res) && $res !== '') {
        $j = json_decode($res, true);
        $detail = is_array($j) ? ($j['error']['message'] ?? $j['message'] ?? '') : '';
    }
    return ['ok'=>false,'erro'=>trim($err ?: ($detail ?: ('HTTP ' . $code))),'http'=>$code];
}

function chamar_llm_local($baseUrl, $model, $systemPrompt, $userMsg) {
    $baseUrl = rtrim(trim((string)$baseUrl), '/');
    if ($baseUrl === '') return ['ok'=>false,'erro'=>'URL do Ollama local não configurada','http'=>0];
    $payload = [
        'model'=>$model,
        'prompt'=>$systemPrompt."\n\nUsuario: ".$userMsg."\nJARVIS:",
        'stream'=>false,
        'options'=>['num_predict'=>220,'temperature'=>0.3]
    ];
    $ch = curl_init($baseUrl . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT=>3,
        CURLOPT_TIMEOUT=>25
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res !== false && $code >= 200 && $code < 300) {
        $j = json_decode($res, true);
        if (isset($j['response']) && trim((string)$j['response']) !== '') return ['ok'=>true,'texto'=>trim($j['response']),'http'=>$code];
        return ['ok'=>false,'erro'=>'Resposta Ollama sem campo response','http'=>$code];
    }
    return ['ok'=>false,'erro'=>$err ?: ('HTTP '.$code),'http'=>$code];
}

function jarvis_lista_modelos_ia(PDO $pdo, string $preferencia='auto'): array {
    $order = "padrao DESC, prioridade ASC, id ASC";
    if ($preferencia === 'local') {
        $order = "padrao DESC, CASE classe_hardware WHEN 'CPU' THEN 0 WHEN 'GPU_LOW' THEN 1 ELSE 2 END, prioridade ASC, id ASC";
    } elseif ($preferencia === 'cloud') {
        $order = "padrao DESC, CASE classe_hardware WHEN 'GPU_HIGH' THEN 0 WHEN 'GPU_LOW' THEN 1 ELSE 2 END, prioridade ASC, id ASC";
    }
    try {
        return $pdo->query("SELECT * FROM ia_modelos WHERE ativo=1 ORDER BY {$order}")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function chamar_llm_runpod_native($apiKey, $endpointId, $model, $systemPrompt, $userMsg, $timeout=45, $maxTokens=600, $temperature=0.35) {
    $apiKey=trim((string)$apiKey);
    $endpointId=trim((string)$endpointId);
    if ($apiKey==='' || $endpointId==='') return ['ok'=>false,'erro'=>'RunPod API key/Endpoint ID ausentes','http'=>0];

    $url="https://api.runpod.ai/v2/{$endpointId}/runsync";
    $prompt=trim($systemPrompt . "\n\n" . $userMsg);
    $payload=['input'=>['prompt'=>$prompt]];

    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>max(5,(int)$timeout)
    ]);
    $res=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);

    if ($res!==false && $http>=200 && $http<300) {
        $j=json_decode((string)$res,true);
        $status=strtoupper((string)($j['status']??''));
        if ($status==='FAILED') return ['ok'=>false,'erro'=>(string)($j['error']??'RunPod job failed'),'http'=>$http];

        $out=$j['output']??null;
        $txt='';
        if (is_string($out)) $txt=$out;
        elseif (is_array($out)) {
            $txt=$out['choices'][0]['message']['content']
                ?? $out['text']
                ?? $out['response']
                ?? $out['output']
                ?? '';
            if ($txt==='' && isset($out[0]) && is_string($out[0])) $txt=$out[0];
        }
        if (is_array($txt)) $txt=json_encode($txt,JSON_UNESCAPED_UNICODE);
        if (trim((string)$txt)!=='') return ['ok'=>true,'texto'=>trim((string)$txt),'http'=>$http];
        return ['ok'=>false,'erro'=>'RunPod respondeu sem texto reconhecível','http'=>$http];
    }

    $detail='';
    if ($res!==false) {
        $j=json_decode((string)$res,true);
        if (is_array($j)) $detail=$j['error']??$j['message']??'';
        if (is_array($detail)) $detail=json_encode($detail,JSON_UNESCAPED_UNICODE);
    }
    return ['ok'=>false,'erro'=>$err ?: ($detail ?: ('HTTP '.$http)),'http'=>$http];
}

function jarvis_chamar_modelo(array $m, string $systemPrompt, string $userMsg): array {
    $provedor = strtolower((string)($m['provedor'] ?? 'openai_compatible'));
    $timeout = max(5, (int)($m['timeout_segundos'] ?? 45));
    $maxTokens = max(32, (int)($m['max_tokens'] ?? 600));
    $temperature = (float)($m['temperatura'] ?? 0.35);
    $baseUrl = rtrim(trim((string)($m['base_url'] ?? '')), '/');
    $apiKey = trim((string)($m['api_key'] ?? ''));
    $model = trim((string)($m['modelo'] ?? ''));

    if ($provedor === 'ollama' || $provedor === 'local') {
        return chamar_llm_local($baseUrl, $model, $systemPrompt, $userMsg);
    }
    if ($provedor === 'runpod_native') {
        return chamar_llm_runpod_native($apiKey, $m['endpoint_id'] ?? '', $model, $systemPrompt, $userMsg, $timeout, $maxTokens, $temperature);
    }
    if ($model === '') return ['ok'=>false,'erro'=>'Modelo ausente','http'=>0];

    if ($provedor === 'gemini') {
        if ($baseUrl === '') $baseUrl='https://generativelanguage.googleapis.com/v1beta';
        if ($apiKey === '') return ['ok'=>false,'erro'=>'API key Gemini ausente','http'=>0];
        $url=$baseUrl.'/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($apiKey);
        $payload=[
            'system_instruction'=>['parts'=>[['text'=>$systemPrompt]]],
            'contents'=>[['role'=>'user','parts'=>[['text'=>$userMsg]]]],
            'generationConfig'=>['temperature'=>$temperature,'maxOutputTokens'=>$maxTokens]
        ];
        $headers=['Content-Type: application/json'];
    } elseif ($provedor === 'anthropic' || $provedor === 'claude') {
        if ($baseUrl === '') $baseUrl='https://api.anthropic.com/v1';
        if ($apiKey === '') return ['ok'=>false,'erro'=>'API key Anthropic ausente','http'=>0];
        $url=$baseUrl.'/messages';
        $payload=['model'=>$model,'system'=>$systemPrompt,'messages'=>[['role'=>'user','content'=>$userMsg]],'temperature'=>$temperature,'max_tokens'=>$maxTokens];
        $headers=['Content-Type: application/json','x-api-key: '.$apiKey,'anthropic-version: 2023-06-01'];
    } else {
        if ($baseUrl === '') return ['ok'=>false,'erro'=>'URL do provedor ausente','http'=>0];
        $url = preg_match('#/chat/completions$#i', $baseUrl) ? $baseUrl : $baseUrl . '/chat/completions';
        $payload=['model'=>$model,'messages'=>[['role'=>'system','content'=>$systemPrompt],['role'=>'user','content'=>$userMsg]],'temperature'=>$temperature,'max_tokens'=>$maxTokens,'stream'=>false];
        $headers=['Content-Type: application/json'];
        if ($apiKey !== '') $headers[]='Authorization: Bearer '.$apiKey;
    }

    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$timeout]);
    $res=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);

    if ($res !== false && $http >= 200 && $http < 300) {
        $j=json_decode((string)$res,true);
        if ($provedor === 'gemini') $txt=$j['candidates'][0]['content']['parts'][0]['text'] ?? '';
        elseif ($provedor === 'anthropic' || $provedor === 'claude') $txt=$j['content'][0]['text'] ?? '';
        else $txt=$j['choices'][0]['message']['content'] ?? '';
        if (trim((string)$txt) !== '') return ['ok'=>true,'texto'=>trim((string)$txt),'http'=>$http];
        return ['ok'=>false,'erro'=>'Resposta do provedor sem texto reconhecível','http'=>$http];
    }
    $detail='';
    if ($res !== false) {
        $j=json_decode((string)$res,true);
        $detail=is_array($j)?($j['error']['message']??$j['message']??$j['error']??''):'';
        if (is_array($detail)) $detail=json_encode($detail,JSON_UNESCAPED_UNICODE);
    }
    return ['ok'=>false,'erro'=>$err ?: ($detail ?: ('HTTP '.$http)),'http'=>$http];
}

function jarvis_registrar_saude_modelo(PDO $pdo, int $id, array $r): void {
    try {
        $stmt=$pdo->prepare("UPDATE ia_modelos
            SET ultima_tentativa=NOW(),
                ultimo_sucesso=IF(:ok1=1,NOW(),ultimo_sucesso),
                ultimo_erro=:erro,
                falhas_consecutivas=IF(:ok2=1,0,falhas_consecutivas+1)
            WHERE id=:id");
        $stmt->execute([
            ':ok1'=>!empty($r['ok'])?1:0,
            ':ok2'=>!empty($r['ok'])?1:0,
            ':erro'=>!empty($r['ok'])?null:($r['erro']??'Falha desconhecida'),
            ':id'=>$id
        ]);
    } catch(Throwable $e) {}
}

$target_ia = ($ia_provider === 'remote') ? $ia_remote_provider : 'local';
$tipo_tarefa = 'automacao_perguntas_simples';
if (strpos($comando, '/cloud') === 0 || strpos($comando, '/runpod') === 0) {
    $target_ia = 'runpod';
    $tipo_tarefa = 'analise_forcada_nuvem';
    $comando = trim(preg_replace('/^\/(cloud|runpod)\s*/i', '', $comando));
} elseif (strpos($comando, '/local') === 0) {
    $target_ia = 'local';
    $tipo_tarefa = 'comando_forcado_local';
    $comando = trim(preg_replace('/^\/local\s*/i', '', $comando));
} elseif ($routing_mode === 'cloud_only') {
    $target_ia = 'runpod';
} elseif ($routing_mode !== 'local_only') {
    $c = mb_strtolower($comando, 'UTF-8');
    $fisico = preg_match('/(lig|deslig|acend|apag|irriga|bomba|piscina|status|temperatura|rele|porta|janela|alarme|sensor)/u', $c);
    $cloudKeywords = $configs['ia_cloud_keywords'] ?? 'analise,pesquisa,programe,codigo,explique,calcule,redija,artigo,relatorio,python,sql';
    $complexa = false;
    foreach (array_filter(array_map('trim', explode(',', $cloudKeywords))) as $kw) {
        if ($kw !== '' && mb_strpos($c, mb_strtolower($kw, 'UTF-8')) !== false) { $complexa = true; break; }
    }
    if ($complexa || (mb_strlen($comando) > 120 && !$fisico)) {
        $target_ia = 'runpod';
        $tipo_tarefa = 'pesquisa_analise_programacao';
    }
}

$systemLocal = "Voce e o JARVIS, IA residencial. Seja conciso e seguro. " .
    "Para automacao, use apenas estas tags quando realmente solicitado: " .
    "[[CMD:LIGAR_LUZ_SALA]], [[CMD:DESLIGAR_LUZ_SALA]], [[CMD:LIGAR_IRRIGACAO]], [[CMD:DESLIGAR_IRRIGACAO]].";
$systemCloud = "Voce e o JARVIS em modo de alta capacidade. Responda em portugues com clareza tecnica. " .
    "Nao invente fatos atuais; quando contexto de fontes for fornecido, baseie-se nele.";

$resposta = false;
$provedor = '';
$ia_diagnostico = [];
$modelo_usado = null;

$preferenciaModelos = 'auto';
if (strpos($comando, '/cloud') === 0 || strpos($comando, '/runpod') === 0) $preferenciaModelos = 'cloud';
elseif (strpos($comando, '/local') === 0) $preferenciaModelos = 'local';

$modelosFallback = jarvis_lista_modelos_ia($pdo, $preferenciaModelos);
$modelosIA = [];

// A configuração selecionada na tela IA tem prioridade. A tabela ia_modelos fica como fallback.
if ($ia_provider === 'remote') {
        $p=$ia_remote_provider ?: 'runpod';
        $base=$ia_remote_base_url;
        $key=$ia_remote_api_key;
        $model=$ia_remote_model;
        if ($p === 'runpod') {
            $key = $runpod_api_key;
            $model = $runpod_model;
            if ($runpod_protocol === 'native') {
                $base = '';
                $execProvider='runpod_native';
            } else {
                $base = $runpod_endpoint_id !== '' ? "https://api.runpod.ai/v2/{$runpod_endpoint_id}/openai/v1" : '';
                $execProvider='openai_compatible';
            }
        } elseif ($p === 'openai') {
            if ($base==='') $base='https://api.openai.com/v1';
            $execProvider='openai_compatible';
        } elseif ($p === 'openrouter') {
            if ($base==='') $base='https://openrouter.ai/api/v1';
            $execProvider='openai_compatible';
        } elseif ($p === 'cerebras') {
            if ($base==='') $base='https://api.cerebras.ai/v1';
            $execProvider='openai_compatible';
        } elseif ($p === 'deepseek') {
            if ($base==='') $base='https://api.deepseek.com/v1';
            $execProvider='openai_compatible';
        } elseif ($p === 'gemini') {
            if ($base==='') $base='https://generativelanguage.googleapis.com/v1beta';
            $execProvider='gemini';
        } elseif ($p === 'anthropic') {
            if ($base==='') $base='https://api.anthropic.com/v1';
            $execProvider='anthropic';
        } else {
            $execProvider='openai_compatible';
        }
        $modelosIA[]=[
            'id'=>0,'nome'=>'Remoto / '.strtoupper($p),'provedor'=>$execProvider,
            'base_url'=>$base,'api_key'=>$key,'modelo'=>$model,'endpoint_id'=>$runpod_endpoint_id,
            'classe_hardware'=>'GPU_HIGH','nivel_capacidade'=>'PROFISSIONAL',
            'timeout_segundos'=>38,'max_tokens'=>600,'temperatura'=>0.35,'padrao'=>1,'prioridade'=>10
        ];
} else {
    $modelosIA[]=[
        'id'=>0,'nome'=>'Local','provedor'=>'ollama',
        'base_url'=>$local_ollama_url,'api_key'=>'','modelo'=>$local_model,
        'classe_hardware'=>'CPU','nivel_capacidade'=>'ESTUDANTE',
        'timeout_segundos'=>25,'max_tokens'=>220,'temperatura'=>0.3,'padrao'=>1,'prioridade'=>20
    ];
}

// Mantém os modelos cadastrados como fallback, sem substituir a seleção atual.
foreach ($modelosFallback as $fallback) {
    $duplicado=false;
    foreach ($modelosIA as $atual) {
        if (
            strtolower((string)($atual['provedor']??'')) === strtolower((string)($fallback['provedor']??'')) &&
            trim((string)($atual['modelo']??'')) === trim((string)($fallback['modelo']??'')) &&
            rtrim(trim((string)($atual['base_url']??'')),'/') === rtrim(trim((string)($fallback['base_url']??'')),'/')
        ) {
            $duplicado=true;
            break;
        }
    }
    if(!$duplicado) $modelosIA[]=$fallback;
}

foreach ($modelosIA as $modeloIA) {
    $promptSistema = (($modeloIA['nivel_capacidade'] ?? '') === 'PROFESSOR' || ($modeloIA['nivel_capacidade'] ?? '') === 'PROFISSIONAL')
        ? $systemCloud : $systemLocal;
    $r = jarvis_chamar_modelo($modeloIA, $promptSistema, $comando);

    if ((int)($modeloIA['id'] ?? 0) > 0) {
        jarvis_registrar_saude_modelo($pdo, (int)$modeloIA['id'], $r);
    }

    $ia_diagnostico[] = [
        'id'=>$modeloIA['id'] ?? null,
        'nome'=>$modeloIA['nome'] ?? 'modelo',
        'provedor'=>$modeloIA['provedor'] ?? null,
        'modelo'=>$modeloIA['modelo'] ?? null,
        'classe_hardware'=>$modeloIA['classe_hardware'] ?? null,
        'nivel_capacidade'=>$modeloIA['nivel_capacidade'] ?? null,
        'padrao'=>!empty($modeloIA['padrao']),
        'prioridade'=>(int)($modeloIA['prioridade'] ?? 100),
        'ok'=>!empty($r['ok']),
        'http'=>$r['http'] ?? 0,
        'erro'=>$r['erro'] ?? null
    ];

    if (!empty($r['ok'])) {
        $resposta = $r['texto'];
        $provedor = ($modeloIA['nome'] ?? 'IA') . ' / ' . ($modeloIA['modelo'] ?? '');
        $target_ia = $modeloIA['provedor'] ?? 'openai_compatible';
        $modelo_usado = [
            'id'=>$modeloIA['id'] ?? null,
            'nome'=>$modeloIA['nome'] ?? null,
            'modelo'=>$modeloIA['modelo'] ?? null,
            'classe_hardware'=>$modeloIA['classe_hardware'] ?? null,
            'nivel_capacidade'=>$modeloIA['nivel_capacidade'] ?? null
        ];
        break;
    }
}

if (!$resposta) {
    $provedor = 'indisponivel';
    $resposta = 'Nenhum modelo de IA configurado respondeu. Consulte o diagnóstico da integração.';
}

$cmdLower = mb_strtolower($comando, 'UTF-8');
$acao = null;
if (strpos($resposta, '[[CMD:LIGAR_LUZ_SALA]]') !== false || (preg_match('/(lig|acend).*(luz|sala)/u', $cmdLower) && !preg_match('/deslig|apag/u', $cmdLower))) {
    qryExec("UPDATE devpar SET devvalue='1', atualizado_em=CURRENT_TIMESTAMP WHERE iddevice=1 AND devparname='dev1'");
    qryExec("INSERT INTO comandos_log (iddevice,comando,origem,resultado) VALUES (1,'Ligar Luz Sala','JARVIS_AI','Executado')");
    $acao = 'Luz da Sala Ligada';
} elseif (strpos($resposta, '[[CMD:DESLIGAR_LUZ_SALA]]') !== false || preg_match('/(deslig|apag).*(luz|sala)/u', $cmdLower)) {
    qryExec("UPDATE devpar SET devvalue='0', atualizado_em=CURRENT_TIMESTAMP WHERE iddevice=1 AND devparname='dev1'");
    qryExec("INSERT INTO comandos_log (iddevice,comando,origem,resultado) VALUES (1,'Desligar Luz Sala','JARVIS_AI','Executado')");
    $acao = 'Luz da Sala Desligada';
} elseif (strpos($resposta, '[[CMD:LIGAR_IRRIGACAO]]') !== false || (preg_match('/(lig|inici|acion).*(irriga|bomba|piscina)/u', $cmdLower) && !preg_match('/deslig|par/u', $cmdLower))) {
    qryExec("UPDATE devpar SET devvalue='1', atualizado_em=CURRENT_TIMESTAMP WHERE iddevice=2 AND devparname='dev1'");
    qryExec("INSERT INTO comandos_log (iddevice,comando,origem,resultado) VALUES (2,'Ligar Irrigacao','JARVIS_AI','Executado')");
    $acao = 'Irrigacao Ligada';
} elseif (strpos($resposta, '[[CMD:DESLIGAR_IRRIGACAO]]') !== false || preg_match('/(deslig|par|cess).*(irriga|bomba|piscina)/u', $cmdLower)) {
    qryExec("UPDATE devpar SET devvalue='0', atualizado_em=CURRENT_TIMESTAMP WHERE iddevice=2 AND devparname='dev1'");
    qryExec("INSERT INTO comandos_log (iddevice,comando,origem,resultado) VALUES (2,'Desligar Irrigacao','JARVIS_AI','Executado')");
    $acao = 'Irrigacao Desligada';
} elseif (preg_match('/(telegram|alerta.*celular|notifiq.*celular)/u', $cmdLower)) {
    agente_telegram_enviar('JARVIS: ' . $comando);
    $acao = 'Notificacao Telegram enviada';
} elseif (preg_match('/(clima|tempo|vai chover|previs.*tempo)/u', $cmdLower)) {
    $clima = agente_consultar_clima();
    $resposta = "Temperatura atual {$clima['temperatura']}" . (!empty($clima['umidade']) ? ", umidade {$clima['umidade']}" : '') . '. ' . (!empty($clima['vai_chover']) ? 'Ha indicacao de chuva.' : 'Sem indicacao imediata de chuva.');
    $acao = 'Consulta Meteorologica';
}

$respostaLimpa = trim(preg_replace('/\[\[CMD:.*?\]\]/', '', $resposta));
try {
    $stmt = $pdo->prepare("INSERT INTO llm_conversas (user_msg,bot_msg,contexto) VALUES (:u,:b,:c)");
    $stmt->execute([':u'=>$comando, ':b'=>$respostaLimpa, ':c'=>$provedor]);
} catch (Exception $e) {}

$audioUrl = jarvis_tts($respostaLimpa, $jarvis_voice, true);
echo json_encode([
    'status'=>'sucesso','comando'=>$comando,'resposta'=>$respostaLimpa,
    'provedor'=>$provedor,'target_ia'=>$target_ia,'tipo_tarefa'=>$tipo_tarefa,
    'modo_roteamento'=>$routing_mode,'acao'=>$acao,'audio_url'=>$audioUrl,
    'speaker'=>$jarvis_voice,'modelo_usado'=>$modelo_usado,'ia_diagnostico'=>$ia_diagnostico
], JSON_UNESCAPED_UNICODE);
