<?php
// JARVIS RESIDENCIAL - Gateway unificado de IA, planejamento, web, automacao e voz
header('Content-Type: application/json; charset=utf-8');
// Nunca deixe uma falha PHP virar HTTP 500 sem diagnostico para o COMPUTER.
set_exception_handler(function(Throwable $e): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'status'=>'erro',
        'mensagem'=>'Falha interna do JARVIS: '.$e->getMessage(),
        'etapa'=>'bootstrap_or_pipeline',
        'arquivo'=>basename($e->getFile()),
        'linha'=>$e->getLine()
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
});
register_shutdown_function(function(): void {
    $e=error_get_last();
    if(!$e || !in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)) return;
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'status'=>'erro',
        'mensagem'=>'Falha fatal do JARVIS: '.($e['message']??'erro PHP'),
        'etapa'=>'fatal_php',
        'arquivo'=>basename((string)($e['file']??'')),
        'linha'=>(int)($e['line']??0)
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');
require_once(__DIR__ . '/agente_externo.php');
require_once(__DIR__ . '/task_engine.php');
require_once(__DIR__ . '/rag.php');
require_once(__DIR__ . '/contexto_casa.php');

// A interface web usa cookie de sessao + token aleatorio da propria sessao.
// Integracoes externas continuam autenticando por Bearer/X-API-Key em verify_api_auth().
$hasExternalAuth = !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_API_KEY']);
$painelWeb = false;
if (!$hasExternalAuth && !empty($_SESSION['auth_user'])) {
    $sentToken = (string)($_SERVER['HTTP_X_CASA_SESSION_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['jarvis_session_token'] ?? '');
    if ($sentToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
        http_response_code(403);
        echo json_encode(['status'=>'erro','mensagem'=>'Token da sessao JARVIS ausente ou invalido. Recarregue a pagina.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $painelWeb = true;
} else {
    verify_api_auth();
    // Dispositivos (ESP32, câmeras, nós) não falam direto com o JARVIS: eles passam pelo
    // /api/v1/gateway, que confere o escopo jarvis.command. Assim um nó comprometido
    // não consegue acionar relés, cenas ou mensagens por aqui.
    if (($GLOBALS['casa_auth_tipo'] ?? null) === 'dispositivo') {
        http_response_code(403);
        echo json_encode(['status'=>'erro','mensagem'=>'Token de dispositivo não autoriza comandos ao JARVIS. Use /api/v1/gateway/comando.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
$usuarioConversa = $painelWeb ? substr((string)$_SESSION['auth_user'], 0, 120) : null;
// O painel fala pelo navegador; o alto-falante da casa só toca para as outras origens,
// ou quando o painel pede explicitamente (reproduzir_casa=true).

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$comando = trim($input['comando'] ?? ($input['prompt'] ?? ($input['msg'] ?? ($_POST['comando'] ?? ($_POST['prompt'] ?? ($_POST['msg'] ?? ''))))));
$origem = trim($input['origem'] ?? 'JARVIS');
$skipPlanner = !empty($input['skip_planner']);
$forcarPlanejamento = !empty($input['planejar']);

// Historico da conversa atual.
// "historico" e o contrato textual longo; "historico_mensagens" preserva papeis para APIs de chat.
$historicoTexto='';
$historicoRaw=$input['historico'] ?? '';
if(is_string($historicoRaw)) $historicoTexto=trim($historicoRaw);

$historicoConversa=[];
$historicoInput=$input['historico_mensagens'] ?? (is_array($historicoRaw)?$historicoRaw:[]);
if(is_array($historicoInput)){
    foreach($historicoInput as $msg){
        if(!is_array($msg)) continue;
        $role=strtolower(trim((string)($msg['role']??'')));
        $content=trim((string)($msg['content']??''));
        if(!in_array($role,['user','assistant'],true)||$content==='') continue;
        $historicoConversa[]=['role'=>$role,'content'=>$content];
    }
}
// Chamadas externas podem enviar somente a string longa. Nesse caso ela ainda entra no contexto.
if(empty($historicoConversa) && $historicoTexto!==''){
    $historicoConversa[]=['role'=>'user','content'=>"HISTORICO DA CONVERSA:\n".$historicoTexto];
}
// Compatibilidade: se vier apenas o array antigo, tambem produzimos a representacao textual.
if($historicoTexto==='' && !empty($historicoConversa)){
    $linhas=[];
    foreach($historicoConversa as $h) $linhas[]=strtoupper($h['role']).': '.$h['content'];
    $historicoTexto=implode("\n\n",$linhas);
}

// Limita o contexto enviado à IA: últimas mensagens, cada uma e o total com teto de caracteres.
const JARVIS_HIST_MAX_MSGS = 12;
const JARVIS_HIST_MAX_CHARS_MSG = 1500;
const JARVIS_HIST_MAX_CHARS_TOTAL = 6000;
$historicoConversa = array_slice($historicoConversa, -JARVIS_HIST_MAX_MSGS);
foreach ($historicoConversa as &$h) {
    if (mb_strlen($h['content'], 'UTF-8') > JARVIS_HIST_MAX_CHARS_MSG) {
        $h['content'] = mb_substr($h['content'], 0, JARVIS_HIST_MAX_CHARS_MSG, 'UTF-8') . '…';
    }
}
unset($h);
$totalHist = 0;
for ($i = count($historicoConversa) - 1; $i >= 0; $i--) {
    $totalHist += mb_strlen($historicoConversa[$i]['content'], 'UTF-8');
    if ($totalHist > JARVIS_HIST_MAX_CHARS_TOTAL) { $historicoConversa = array_slice($historicoConversa, $i + 1); break; }
}
$historicoConversa = array_values($historicoConversa);
// O texto longo é refeito a partir da lista já cortada.
$historicoTexto = '';
if (!empty($historicoConversa)) {
    $linhas = [];
    foreach ($historicoConversa as $h) $linhas[] = strtoupper($h['role']) . ': ' . $h['content'];
    $historicoTexto = implode("\n\n", $linhas);
}

if (mb_strlen($comando, 'UTF-8') > 4000) {
    http_response_code(413);
    echo json_encode(['status'=>'erro','mensagem'=>'Comando muito longo (limite de 4000 caracteres).'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($comando === '') {
    http_response_code(400);
    echo json_encode(['status'=>'erro','mensagem'=>'Nenhum comando fornecido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db_pdo();

$existingTaskContext=te_context_from_input($input['task_context'] ?? null);
$taskContext=te_begin($pdo,$comando,$origem,'computer',$existingTaskContext,$input['correlation_id'] ?? null);
$taskInterpret=0;
if($taskContext['root_created']){
    $taskInterpret=te_add_subtask($pdo,$taskContext,'Interpretar e planejar solicitação','computer',[
        'pergunta'=>$comando,'origem'=>$origem
    ],null,'EXECUTANDO');
}

function computer_client_ip(): ?string {
    $cf=$_SERVER['HTTP_CF_CONNECTING_IP']??'';
    if($cf!=='' && filter_var($cf,FILTER_VALIDATE_IP)) return $cf;
    $xff=$_SERVER['HTTP_X_FORWARDED_FOR']??'';
    if($xff!==''){
        $first=trim(explode(',',$xff)[0]);
        if(filter_var($first,FILTER_VALIDATE_IP)) return $first;
    }
    $ip=$_SERVER['REMOTE_ADDR']??'';
    return filter_var($ip,FILTER_VALIDATE_IP)?$ip:null;
}
function computer_telemetry_start(PDO $pdo,string $origem,string $comando,string $correlationId): int {
    try{
        $canal='web';
        if(stripos($origem,'GOOGLE_HOME')!==false)$canal='google_home';
        elseif(stripos($origem,'SCHEDULER')!==false)$canal='scheduler';
        elseif(!empty($_SERVER['HTTP_X_DEVICE_TOKEN']))$canal='hardware';
        elseif(!empty($_SERVER['HTTP_AUTHORIZATION'])||!empty($_SERVER['HTTP_X_API_KEY']))$canal='api';
        $st=$pdo->prepare("INSERT INTO telemetria_operacional(correlation_id,origem,canal,ip_cliente,operacao,solicitacao,status,detalhes) VALUES(:x,:o,:c,:ip,'COMPUTER_COMMAND',:s,'PROCESSANDO',:d)");
        $st->execute([
            ':x'=>$correlationId,
            ':o'=>$origem?:'COMPUTER',
            ':c'=>$canal,
            ':ip'=>computer_client_ip(),
            ':s'=>$comando,
            ':d'=>json_encode(['metodo'=>$_SERVER['REQUEST_METHOD']??'POST','rota'=>parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ]);
        return (int)$pdo->lastInsertId();
    }catch(Throwable $e){return 0;}
}
function computer_telemetry_finish(PDO $pdo,int $id,string $status,?string $resposta=null,?string $acao=null,?string $modelo=null,int $inicioNs=0): void {
    if($id<=0)return;
    try{
        $ms=$inicioNs>0?(int)round((hrtime(true)-$inicioNs)/1000000):null;
        $st=$pdo->prepare("UPDATE telemetria_operacional SET finalizado_em=NOW(),status=:s,resposta_ia=:r,acao_executada=:a,modelo=:m,duracao_ms=:d WHERE id=:id");
        $st->execute([':s'=>$status,':r'=>$resposta,':a'=>$acao,':m'=>$modelo,':d'=>$ms,':id'=>$id]);
    }catch(Throwable $e){}
}
// Chamadas internas (planejador, partes de um plano executadas por daemon) não falam:
// antes, o pedido do planejador à IA fazia a casa ler o plano em JSON em voz alta.
$chamadaInterna = $existingTaskContext !== null
    || in_array(strtoupper($origem), ['PLANEJADOR','PLANEJADOR_EXECUTOR','DAEMON_TAREFAS','MORDOMO'], true);
// Opções para chamadas internas autenticadas por chave (o painel não usa):
//   somente_direto: executa apenas uma ordem direta reconhecida (sem IA); senão recusa.
//   sem_acoes: a IA responde, mas nenhuma marca [[CMD:...]] é executada nem a conversa é gravada.
$somenteDireto = !$painelWeb && !empty($input['somente_direto']);
$semAcoes = !$painelWeb && !empty($input['sem_acoes']);
// Google Home: o agente sintetiza e toca a resposta no próprio aparelho; tocar aqui também
// fazia a casa ouvir a resposta duas vezes.
$origemTocaAudio = stripos($origem, 'GOOGLE_HOME') === 0;
$reproduzirNaCasa = ((!$painelWeb && !$chamadaInterna && !$origemTocaAudio) || !empty($input['reproduzir_casa']));
$chamadaInterna = $chamadaInterna || $origemTocaAudio; // também não gera áudio desnecessário
$telemetryStarted=hrtime(true);
$telemetryId=computer_telemetry_start($pdo,$origem,$comando,(string)$taskContext['correlation_id']);

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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$res || $code < 200 || $code >= 300) return null;
    $j = json_decode($res, true);
    return !empty($j['audio_url']) ? casa_base_path() . '/api/audio.php?file=' . rawurlencode(basename($j['audio_url'])) : null;
}

function jarvis_deve_planejar($cmd) {
    if (cti_pergunta_sobre_tarefa((string)$cmd)) return false; // Consulta ao historico nao cria novo plano.
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

// Historico de datas: responder com dados do banco, sem inferencia do modelo.
// Esta consulta nao inicia tarefas nem planeja novas solicitacoes.
if (!$forcarPlanejamento && !$somenteDireto && cti_pergunta_datas_tarefas($comando)) {
    $datas = cti_responder_datas_tarefas($pdo, $comando);
    $respostaDatas = (string)$datas['resposta'];
    if ($taskInterpret > 0) {
        if ($datas['ok']) te_complete($pdo, $taskInterpret, ['roteamento'=>'consulta_datas_tarefas']);
        else te_fail($pdo, $taskInterpret, 'Consulta de datas indisponivel');
    }
    if ($datas['ok']) te_finish($pdo, $taskContext, $respostaDatas, ['consulta'=>'datas_tarefas']);
    else te_finish_error($pdo, $taskContext, $respostaDatas);
    $audio = ($chamadaInterna && !$reproduzirNaCasa)
        ? null : jarvis_tts($respostaDatas, $configs['jarvis_voice'] ?? 'padrao', $reproduzirNaCasa);
    computer_telemetry_finish($pdo, $telemetryId, $datas['ok']?'SUCESSO':'FALHA',
        $respostaDatas, 'CONSULTA_DATAS_TAREFAS', 'BANCO_CASA', $telemetryStarted);
    echo json_encode(te_attach_context([
        'status'=>$datas['ok']?'sucesso':'erro',
        'comando'=>$comando,
        'resposta'=>$respostaDatas,
        'tipo_tarefa'=>'consulta_datas_tarefas',
        'tarefas_datas'=>$datas['tarefas'],
        'provedor'=>'Banco de dados Casa',
        'target_ia'=>'consulta_deterministica',
        'audio_url'=>$audio,
        'tarefas_execucao'=>te_list_tasks($pdo,$taskContext)
    ], $taskContext, $pdo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 0. Planejamento antes da execucao quando a demanda pede varias etapas, futuro ou condicao.
if (!$skipPlanner && ($forcarPlanejamento || jarvis_deve_planejar($comando))) {
    $demanda = preg_replace('/^\s*\/planejar\s*/iu', '', $comando);
    if($taskInterpret>0) te_complete($pdo,$taskInterpret,['roteamento'=>'planejador']);
    $taskPlanner=te_add_subtask($pdo,$taskContext,'Planejar e decompor solicitação','planejador',[
        'demanda'=>$demanda
    ],$taskInterpret>0?$taskInterpret:null,'EXECUTANDO');
    $p = jarvis_post_local(casa_url_interna('api/planejador.php'), [
        'demanda'=>$demanda,
        'origem'=>$origem,
        'executar_imediatas'=>true,
        'task_context'=>te_public_context($taskContext)
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
        $nFila = (int)($dados['fila']['enfileiradas'] ?? 0);
        if ($nFila > 0) {
            $resp .= ", {$nFila} enviada(s) para a fila de execução";
            $semTrab = $dados['fila']['sem_trabalhador'] ?? [];
            if ($semTrab) $resp .= ' (aguardando um daemon que execute: ' . implode(', ', $semTrab) . ')';
        }
        $resp .= '. ' . trim($dados['resumo'] ?? '');
        te_complete($pdo,$taskPlanner,['plano'=>$dados]);
        $taskFinal=te_add_subtask($pdo,$taskContext,'Consolidar resposta final','computer',[
            'resposta'=>$resp
        ],$taskPlanner,'EXECUTANDO');
        te_complete($pdo,$taskFinal,['resposta'=>$resp]);
        if ($nFila > 0) {
            // O plano continua aberto: a fila o fecha quando a última tarefa terminar.
            te_complete($pdo,(int)$taskContext['id_tarefa_raiz'],['resposta'=>$resp,'plano'=>$dados]);
        } else {
            te_finish($pdo,$taskContext,$resp,['plano'=>$dados]);
        }
        $audio = ($chamadaInterna && !$reproduzirNaCasa) ? null : jarvis_tts($resp, $configs['jarvis_voice'] ?? 'padrao', $reproduzirNaCasa);
        computer_telemetry_finish($pdo,$telemetryId,'SUCESSO',$resp,'PLANO/AGENDAMENTO REGISTRADO','PLANEJADOR',$telemetryStarted);
        echo json_encode(te_attach_context([
            'status'=>'sucesso','resposta'=>$resp,'tipo_tarefa'=>'plano_de_tarefas',
            'tarefas_execucao'=>te_list_tasks($pdo,$taskContext),
            'plano'=>$dados,'audio_url'=>$audio
        ],$taskContext,$pdo), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 1. Pesquisa web direta para demandas atuais.
if (!$skipPlanner && jarvis_pedido_web($comando)) {
    $query = preg_replace('/^\s*\/web\s*/iu', '', $comando);
    if($taskInterpret>0) te_complete($pdo,$taskInterpret,['roteamento'=>'web']);
    $taskWeb=te_add_subtask($pdo,$taskContext,'Pesquisar e analisar fontes externas','web',[
        'query'=>$query
    ],$taskInterpret>0?$taskInterpret:null,'EXECUTANDO');
    $w = jarvis_post_local(casa_url_interna('api/agente_internet.php'), [
        'acao'=>'responder','query'=>$query,'max_results'=>5,
        'task_context'=>te_public_context($taskContext)
    ], 75);
    if ($w['ok'] && (($w['dados']['status'] ?? '') === 'ok')) {
        $d = $w['dados'];
        te_complete($pdo,$taskWeb,['fontes'=>$d['fontes'] ?? [],'resposta'=>$d['resposta'] ?? '']);
        $taskFinal=te_add_subtask($pdo,$taskContext,'Consolidar resposta final','computer',[
            'resposta'=>$d['resposta'] ?? ''
        ],$taskWeb,'EXECUTANDO');
        te_complete($pdo,$taskFinal,['resposta'=>$d['resposta'] ?? '']);
        te_finish($pdo,$taskContext,$d['resposta'] ?? '',['fontes'=>$d['fontes'] ?? []]);
        computer_telemetry_finish($pdo,$telemetryId,'SUCESSO',$d['resposta'] ?? '','PESQUISA WEB','AGENTE_WEB',$telemetryStarted);
        echo json_encode(te_attach_context([
            'status'=>'sucesso','comando'=>$comando,'resposta'=>$d['resposta'] ?? '',
            'provedor'=>'Agente Web','target_ia'=>'web','tipo_tarefa'=>'pesquisa_internet',
            'fontes'=>$d['fontes'] ?? [],'audio_url'=>$d['audio_url'] ?? null,
            'tarefas_execucao'=>te_list_tasks($pdo,$taskContext)
        ],$taskContext,$pdo), JSON_UNESCAPED_UNICODE);
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

function jarvis_chamar_modelo(array $m, string $systemPrompt, string $userMsg, array $historico=[]): array {
    $provedor = strtolower((string)($m['provedor'] ?? 'openai_compatible'));
    $timeout = max(5, (int)($m['timeout_segundos'] ?? 45));
    $maxTokens = max(32, (int)($m['max_tokens'] ?? 600));
    $temperature = (float)($m['temperatura'] ?? 0.35);
    $baseUrl = rtrim(trim((string)($m['base_url'] ?? '')), '/');
    $apiKey = trim((string)($m['api_key'] ?? ''));
    $model = trim((string)($m['modelo'] ?? ''));

    if ($provedor === 'ollama' || $provedor === 'local') {
        $contexto='';
        foreach($historico as $h) $contexto.=($h['role']==='assistant'?'JARVIS':'Usuario').': '.$h['content']."\n";
        return chamar_llm_local($baseUrl, $model, $systemPrompt, $contexto.$userMsg);
    }
    if ($provedor === 'runpod_native') {
        $contexto='';
        foreach($historico as $h) $contexto.=($h['role']==='assistant'?'JARVIS':'Usuario').': '.$h['content']."\n";
        return chamar_llm_runpod_native($apiKey, $m['endpoint_id'] ?? '', $model, $systemPrompt, $contexto.$userMsg, $timeout, $maxTokens, $temperature);
    }
    if ($model === '') return ['ok'=>false,'erro'=>'Modelo ausente','http'=>0];

    if ($provedor === 'gemini') {
        if ($baseUrl === '') $baseUrl='https://generativelanguage.googleapis.com/v1beta';
        if ($apiKey === '') return ['ok'=>false,'erro'=>'API key Gemini ausente','http'=>0];
        $url=$baseUrl.'/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($apiKey);
        $payload=[
            'system_instruction'=>['parts'=>[['text'=>$systemPrompt]]],
            'contents'=>array_merge(array_map(function($h){return ['role'=>$h['role']==='assistant'?'model':'user','parts'=>[['text'=>$h['content']]]];},$historico),[['role'=>'user','parts'=>[['text'=>$userMsg]]]]),
            'generationConfig'=>['temperature'=>$temperature,'maxOutputTokens'=>$maxTokens]
        ];
        $headers=['Content-Type: application/json'];
    } elseif ($provedor === 'anthropic' || $provedor === 'claude') {
        if ($baseUrl === '') $baseUrl='https://api.anthropic.com/v1';
        if ($apiKey === '') return ['ok'=>false,'erro'=>'API key Anthropic ausente','http'=>0];
        $url=$baseUrl.'/messages';
        $messages=array_values($historico); $messages[]=['role'=>'user','content'=>$userMsg];
        $payload=['model'=>$model,'system'=>$systemPrompt,'messages'=>$messages,'temperature'=>$temperature,'max_tokens'=>$maxTokens];
        $headers=['Content-Type: application/json','x-api-key: '.$apiKey,'anthropic-version: 2023-06-01'];
    } else {
        if ($baseUrl === '') return ['ok'=>false,'erro'=>'URL do provedor ausente','http'=>0];
        $url = preg_match('#/chat/completions$#i', $baseUrl) ? $baseUrl : $baseUrl . '/chat/completions';
        $messages=[['role'=>'system','content'=>$systemPrompt]];
        foreach($historico as $h)$messages[]=$h;
        $messages[]=['role'=>'user','content'=>$userMsg];
        $payload=['model'=>$model,'messages'=>$messages,'temperature'=>$temperature,'max_tokens'=>$maxTokens,'stream'=>false];
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

// ---------------------------------------------------------------------------
// Escolha do destino da IA.
// O prefixo /cloud, /runpod ou /local é lido ANTES de ser removido do comando,
// e a preferência resultante é que ordena (e, quando forçada, filtra) os modelos.
// ---------------------------------------------------------------------------
$tipo_tarefa = 'automacao_perguntas_simples';
$destinoForcado = null; // 'cloud' | 'local'
if (preg_match('/^\s*\/(cloud|runpod)\b/i', $comando)) {
    $destinoForcado = 'cloud';
    $tipo_tarefa = 'analise_forcada_nuvem';
    $comando = trim(preg_replace('/^\s*\/(cloud|runpod)\b\s*/i', '', $comando));
} elseif (preg_match('/^\s*\/local\b/i', $comando)) {
    $destinoForcado = 'local';
    $tipo_tarefa = 'comando_forcado_local';
    $comando = trim(preg_replace('/^\s*\/local\b\s*/i', '', $comando));
}
if ($comando === '') {
    http_response_code(400);
    echo json_encode(['status'=>'erro','mensagem'=>'Comando vazio após o prefixo /cloud ou /local.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$preferenciaModelos = 'auto';
if ($destinoForcado !== null) {
    $preferenciaModelos = $destinoForcado;
} elseif ($routing_mode === 'cloud_only') {
    $preferenciaModelos = 'cloud';
} elseif ($routing_mode === 'local_only') {
    $preferenciaModelos = 'local';
} elseif ($routing_mode === 'cloud_preferido') {
    // Usado pelo planejador: tenta a nuvem primeiro, mas aceita o modelo local.
    $preferenciaModelos = 'cloud';
} else {
    $c = mb_strtolower($comando, 'UTF-8');
    $fisico = preg_match('/(lig|deslig|acend|apag|irriga|bomba|piscina|status|temperatura|rele|porta|janela|alarme|sensor)/u', $c);
    $cloudKeywords = $configs['ia_cloud_keywords'] ?? 'analise,pesquisa,programe,codigo,explique,calcule,redija,artigo,relatorio,python,sql';
    $complexa = false;
    foreach (array_filter(array_map('trim', explode(',', $cloudKeywords))) as $kw) {
        if ($kw !== '' && mb_strpos($c, mb_strtolower($kw, 'UTF-8')) !== false) { $complexa = true; break; }
    }
    if ($complexa || (mb_strlen($comando) > 120 && !$fisico)) {
        $preferenciaModelos = 'cloud';
        $tipo_tarefa = 'pesquisa_analise_programacao';
    }
}
// Forçado pelo usuário ou modo *_only: usa só modelos daquele lado. No modo automático é só preferência.
$destinoEstrito = $destinoForcado !== null || in_array($routing_mode, ['cloud_only','local_only'], true);
$target_ia = $preferenciaModelos === 'cloud' ? 'nuvem' : 'local';

$instrucoesAcoes = " Para automação e controle de hardware, use as marcas abaixo SOMENTE quando o usuário pedir a ação de forma explícita; nunca em perguntas, explicações ou hipóteses: " .
    "[[CMD:LIGAR_LUZ_SALA]], [[CMD:DESLIGAR_LUZ_SALA]], [[CMD:LIGAR_IRRIGACAO]], [[CMD:DESLIGAR_IRRIGACAO]], " .
    "[[CMD:RELE:NUMERO:ESTADO]] (ex: [[CMD:RELE:1:1]] liga, [[CMD:RELE:2:0]] desliga), " .
    "[[CMD:CENA:SLUG]] (ex: [[CMD:CENA:boa-noite]]), " .
    "[[CMD:STATUS_SISTEMA]].";

$systemLocal = "Você é o Computador de Bordo do sistema residencial CASA/JARVIS, operando estritamente nos padrões de inteligência e interface LCARS da Frota Estelar (Star Trek: The Next Generation). " .
    "Seja formal, objetivo, técnico e conciso como a voz clássica do Computador da Enterprise. " .
    "Use confirmações padronizadas quando pertinente: 'Afirmativo.', 'Trabalhando...', 'Comando executado.', 'Impossível atender à solicitação: [motivo]'." .
    $instrucoesAcoes;

$systemCloud = "Você é o Computador Central de Bordo do sistema CASA/JARVIS operando em alta capacidade (Núcleo LCARS Star Trek). " .
    "Responda em português com precisão técnica absoluta nos padrões do computador de Jornada nas Estrelas: A Nova Geração. " .
    "Gerencie subsistemas de bordo, rede de sensores de telemetria, relés e regras determinísticas com máxima eficiência e clareza." .
    $instrucoesAcoes;

$resposta = false;
$provedor = '';
$ia_diagnostico = [];
$modelo_usado = null;

function jarvis_modelo_eh_local(array $m): bool {
    return in_array(strtolower((string)($m['provedor'] ?? '')), ['ollama','local'], true);
}

// Modelo remoto montado a partir da tela de configuração (se houver dados suficientes).
$modeloRemoto = null;
$p = $ia_remote_provider ?: 'runpod';
$base = $ia_remote_base_url;
$key = $ia_remote_api_key;
$model = $ia_remote_model;
if ($p === 'runpod') {
    $key = $runpod_api_key;
    $model = $runpod_model;
    if ($runpod_protocol === 'native') {
        $base = '';
        $execProvider = 'runpod_native';
    } else {
        $base = $runpod_endpoint_id !== '' ? "https://api.runpod.ai/v2/{$runpod_endpoint_id}/openai/v1" : '';
        $execProvider = 'openai_compatible';
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
$remotoUtilizavel = $execProvider === 'runpod_native'
    ? (trim((string)$key) !== '' && trim((string)$runpod_endpoint_id) !== '')
    : (trim((string)$model) !== '' && trim((string)$base) !== '');
if ($remotoUtilizavel) {
    $modeloRemoto = [
        'id'=>0,'nome'=>'Remoto / '.strtoupper($p),'provedor'=>$execProvider,
        'base_url'=>$base,'api_key'=>$key,'modelo'=>$model,'endpoint_id'=>$runpod_endpoint_id,
        'classe_hardware'=>'GPU_HIGH','nivel_capacidade'=>'PROFISSIONAL',
        'timeout_segundos'=>38,'max_tokens'=>600,'temperatura'=>0.35,'padrao'=>1,'prioridade'=>10
    ];
}
$modeloLocal = (trim($local_ollama_url) !== '' && trim((string)$local_model) !== '') ? [
    'id'=>0,'nome'=>'Local','provedor'=>'ollama',
    'base_url'=>$local_ollama_url,'api_key'=>'','modelo'=>$local_model,
    'classe_hardware'=>'CPU','nivel_capacidade'=>'ESTUDANTE',
    'timeout_segundos'=>25,'max_tokens'=>220,'temperatura'=>0.3,'padrao'=>1,'prioridade'=>20
] : null;

$modelosFallback = jarvis_lista_modelos_ia($pdo, $preferenciaModelos);
$cadastradosLocais = array_values(array_filter($modelosFallback, 'jarvis_modelo_eh_local'));
$cadastradosRemotos = array_values(array_filter($modelosFallback, function($m){ return !jarvis_modelo_eh_local($m); }));

$candidatos = [];
if ($preferenciaModelos === 'cloud') {
    if ($modeloRemoto) $candidatos[] = $modeloRemoto;
    $candidatos = array_merge($candidatos, $cadastradosRemotos);
    if (!$destinoEstrito) {
        if ($modeloLocal) $candidatos[] = $modeloLocal;
        $candidatos = array_merge($candidatos, $cadastradosLocais);
    }
} elseif ($preferenciaModelos === 'local') {
    if ($modeloLocal) $candidatos[] = $modeloLocal;
    $candidatos = array_merge($candidatos, $cadastradosLocais);
    if (!$destinoEstrito) {
        if ($modeloRemoto) $candidatos[] = $modeloRemoto;
        $candidatos = array_merge($candidatos, $cadastradosRemotos);
    }
} else {
    // Automático: a seleção da tela IA vem primeiro; a tabela ia_modelos fica como contingência.
    $principal = ($ia_provider === 'remote') ? $modeloRemoto : $modeloLocal;
    if ($principal) $candidatos[] = $principal;
    $candidatos = array_merge($candidatos, $modelosFallback);
}

// Remove repetidos (mesmo provedor, modelo e endereço).
$modelosIA = [];
foreach ($candidatos as $cand) {
    $dup = false;
    foreach ($modelosIA as $atual) {
        if (strtolower((string)($atual['provedor']??'')) === strtolower((string)($cand['provedor']??''))
            && trim((string)($atual['modelo']??'')) === trim((string)($cand['modelo']??''))
            && rtrim(trim((string)($atual['base_url']??'')),'/') === rtrim(trim((string)($cand['base_url']??'')),'/')) { $dup = true; break; }
    }
    if (!$dup) $modelosIA[] = $cand;
}

$rag=rag_prompt($comando);
$ragContexto=$rag['prompt']??'';
$ragFontes=$rag['sources']??[];

// Estado AO VIVO da casa (dispositivos, sensores, alertas...) em todo pedido à IA.
// configuracoes_sistema: jarvis_contexto_casa = sempre|nunca; jarvis_contexto_max_chars (padrão 3500).
$contextoCasa=['prompt'=>'','secoes'=>[],'chars'=>0];
if(strtolower(trim((string)($configs['jarvis_contexto_casa'] ?? 'sempre')))!=='nunca'){
    $maxCtx=max(800,min(12000,(int)($configs['jarvis_contexto_max_chars'] ?? 3500)));
    try{ $contextoCasa=casa_contexto_prompt($pdo,$maxCtx,$comando); }catch(Throwable $e){ error_log('contexto_casa: '.$e->getMessage()); }
}

if($taskInterpret>0) te_complete($pdo,$taskInterpret,['roteamento'=>'ia']);
$taskIA=te_add_subtask($pdo,$taskContext,'Consultar modelo de IA','ia',[
    'preferencia'=>$preferenciaModelos
],$taskInterpret>0?$taskInterpret:null,'EXECUTANDO');

// ---------------------------------------------------------------------------
// Comandos diretos (sem IA) e execução de ações.
// Ações físicas NUNCA são deduzidas por palavras soltas no texto: só por
//  (a) uma ordem completa no imperativo/infinitivo, reconhecida abaixo, ou
//  (b) uma marca [[CMD:...]] devolvida pela IA.
// ---------------------------------------------------------------------------
function jarvis_normalizar(string $t): string {
    $t = mb_strtolower(trim($t), 'UTF-8');
    $t = strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
    $t = preg_replace('/\s+/u', ' ', $t);
    $t = preg_replace('/^(computador|computer|jarvis)\s*[,:!.]?\s*/u', '', $t);
    $t = preg_replace('/^(por favor|pfv|pf)\s*[,:]?\s*/u', '', $t);
    // Retira cortesia e pontuação final, mas mantém '?' (pergunta não vira ordem).
    $t = preg_replace('/(\s*,?\s*por favor)?[\s!.,;]*$/u', '', $t);
    return trim($t);
}

function jarvis_comando_direto(string $comando): ?string {
    $t = jarvis_normalizar($comando);
    if ($t === '') return null;
    $fim = '(\s+(agora|ja|imediatamente))?$';
    $lig = '(ligar|ligue|liga|acender|acenda|acende)';
    $des = '(desligar|desligue|desliga|apagar|apague|apaga)';
    $ini = '(ligar|ligue|liga|iniciar|inicie|inicia|acionar|acione|aciona)';
    $par = '(desligar|desligue|desliga|parar|pare|para|interromper|interrompa|cessar)';
    $irr = '(a\s+|o\s+)?(irrigacao|bomba(\s+da\s+piscina)?)';

    if (preg_match('/^'.$lig.'\s+(a\s+|as\s+)?(luz|luzes|lampada)\s+da\s+sala'.$fim.'/u', $t)) return '[[CMD:LIGAR_LUZ_SALA]]';
    if (preg_match('/^'.$des.'\s+(a\s+|as\s+)?(luz|luzes|lampada)\s+da\s+sala'.$fim.'/u', $t)) return '[[CMD:DESLIGAR_LUZ_SALA]]';
    if (preg_match('/^'.$par.'\s+'.$irr.$fim.'/u', $t)) return '[[CMD:DESLIGAR_IRRIGACAO]]';
    if (preg_match('/^'.$ini.'\s+'.$irr.$fim.'/u', $t)) return '[[CMD:LIGAR_IRRIGACAO]]';
    if (preg_match('/^(desligar|desligue|desliga)\s+(o\s+)?rele\s+(\d{1,3})'.$fim.'/u', $t, $m)) return '[[CMD:RELE:'.(int)$m[3].':0]]';
    if (preg_match('/^(ligar|ligue|liga|acionar|acione|aciona)\s+(o\s+)?rele\s+(\d{1,3})'.$fim.'/u', $t, $m)) return '[[CMD:RELE:'.(int)$m[3].':1]]';
    if (preg_match('/^(ativar|ative|ativa|entrar em|entre em|iniciar|inicie|inicia)\s+(o\s+)?modo\s+boa[\s\-]noite$/u', $t)) return '[[CMD:CENA:boa-noite]]';
    if (preg_match('/^(ativar|ative|ativa|executar|execute|executa|iniciar|inicie|inicia)\s+(a\s+)?cena\s+([a-z0-9][a-z0-9 _\-]{0,60})$/u', $t, $m)) {
        return '[[CMD:CENA:'.preg_replace('/\s+/', '-', trim($m[3])).']]';
    }
    if (preg_match('/^(status|relatorio( de status)?|diagnostico)\s+do\s+sistema\??$/u', $t)) return '[[CMD:STATUS_SISTEMA]]';
    if (preg_match('/^(como (esta|vai|ta) o (clima|tempo)( hoje)?|previsao do tempo( para hoje| de hoje| hoje)?|vai chover( hoje)?)\??$/u', $t)) return '[[CMD:CLIMA]]';
    if (preg_match('/^(envie|enviar|mande|mandar|dispare|disparar)\s+(um\s+|uma\s+)?(alerta|aviso|mensagem|notificacao)\s+(no|pelo|para o|via)\s+(telegram|celular)\b/u', $t)) return '[[CMD:TELEGRAM]]';
    return null;
}

// Executa uma marca. Retorna null (marca desconhecida) ou
// ['acao'=>texto|null, 'resposta'=>texto, 'substitui'=>bool, 'erro'=>bool].
function jarvis_executar_acao(PDO $pdo, string $nome, string $arg, string $comando, array $taskContext): ?array {
    $erro = function(string $motivo){ return ['acao'=>null,'resposta'=>'Impossível atender à solicitação: '.$motivo.'.','substitui'=>false,'erro'=>true]; };
    try {
        switch ($nome) {
        case 'STATUS_SISTEMA':
            // Resumo falável a partir do retrato ao vivo (antes: só contava nós online).
            $disp = cc_secao(fn() => cc_dispositivos($pdo, 200));
            $partes = [];
            if ($disp) {
                $partes[] = rtrim(preg_replace('/^DISPOSITIVOS: /', '', $disp[0]), '.');
                $fora = [];
                foreach (array_slice($disp, 1) as $ln) if (strpos($ln, 'FORA DO AR') !== false) $fora[] = trim(explode(' (', substr($ln, 2))[0]);
                if ($fora) $partes[] = 'fora do ar: ' . implode(', ', array_slice($fora, 0, 6)) . (count($fora) > 6 ? ' e outros' : '');
            }
            $alertas = cc_secao(fn() => cc_alertas($pdo));
            $nAlertas = max(0, count($alertas) - 1);
            $partes[] = $nAlertas ? ($nAlertas . ' alerta(s) ou evento(s) recente(s)') : 'nenhum alerta pendente';
            $sens = cc_secao(fn() => cc_sensores($pdo));
            $nSens = max(0, count($sens) - 1);
            if ($nSens) $partes[] = $nSens . ' sensor(es) com leitura nas últimas 24 horas';
            return ['acao'=>'Relatório de status da casa','resposta'=>'Afirmativo. Varredura concluída: ' . implode('; ', $partes) . '.','substitui'=>true,'erro'=>false];

        case 'RELE':
            if (!preg_match('/^(\d{1,3}):([01])$/', $arg, $m)) return $erro('marca de relé inválida');
            $num = (int)$m[1]; $estado = (int)$m[2];
            $dev = $pdo->query("SELECT device_id FROM dispositivos_cluster WHERE (capabilities LIKE '%hardware-gateway%' OR capabilities LIKE '%relay%') AND credential_revoked_at IS NULL
                ORDER BY (status='online') DESC, ultimo_heartbeat DESC, id ASC LIMIT 1")->fetchColumn(); // prefere nó online e recente
            if (!$dev) return $erro('nenhum nó de relés cadastrado');
            // Mesmo caminho da automação: status pending/QUEUED, que é o que o nó busca em /api/v1/device.php.
            require_once(__DIR__ . '/automation_common.php');
            automation_enqueue_command($pdo, [
                'device_id'=>$dev, 'comando'=>'relay.set',
                'payload'=>['relay'=>$num, 'state'=>$estado],
                'prioridade'=>'normal', 'risk_level'=>1, 'ttl_seconds'=>300
            ], 'COMPUTER', (string)$taskContext['correlation_id'], 'jarvis_'.bin2hex(random_bytes(8)));
            $txt = $estado ? 'LIGADO' : 'DESLIGADO';
            return ['acao'=>"Relé {$num} definido como {$txt} no nó {$dev}",'resposta'=>"Afirmativo. Comando para ".($estado ? 'ligar' : 'desligar')." o relé {$num} enviado ao nó {$dev}.",'substitui'=>false,'erro'=>false];

        case 'CENA':
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $arg));
            if ($slug === '') return $erro('cena não informada');
            require_once(__DIR__ . '/automation_common.php');
            $cena = automation_scene_load($pdo, 0, $slug);
            if (!$cena) {
                // Busca aproximada só vale se houver exatamente uma cena parecida.
                $st = $pdo->prepare("SELECT * FROM scenes WHERE slug LIKE :s LIMIT 2");
                $st->execute([':s'=>'%'.$slug.'%']);
                $achadas = $st->fetchAll(PDO::FETCH_ASSOC);
                if (count($achadas) === 1) $cena = $achadas[0];
                elseif (count($achadas) > 1) return $erro("mais de uma cena corresponde a '{$slug}'; informe o nome exato");
            }
            if (!$cena) return $erro("cena '{$slug}' não encontrada");
            // Sem confirmação: cenas com ação sensível (risco >= 3) ficam para o painel.
            $exec = automation_scene_execute($pdo, $cena, 'COMPUTER', false);
            $corpo = $exec['body'] ?? [];
            if (($corpo['status'] ?? '') === 'confirmacao_necessaria') {
                return $erro("a cena '".$cena['nome']."' contém ação sensível e precisa ser confirmada no painel");
            }
            if ((int)($exec['http'] ?? 500) >= 300) {
                return $erro("cena '".$cena['nome']."': ".mb_strtolower((string)($corpo['mensagem'] ?? 'falha na execução'), 'UTF-8'));
            }
            return ['acao'=>'Cena executada: '.$cena['nome'],'resposta'=>"Afirmativo. Cena '".$cena['nome']."' iniciada.",'substitui'=>false,'erro'=>false];

        case 'LIGAR_LUZ_SALA':
        case 'DESLIGAR_LUZ_SALA':
        case 'LIGAR_IRRIGACAO':
        case 'DESLIGAR_IRRIGACAO':
            $mapa = [
                'LIGAR_LUZ_SALA'=>[1,'1','Ligar Luz Sala','Luz da sala ligada'],
                'DESLIGAR_LUZ_SALA'=>[1,'0','Desligar Luz Sala','Luz da sala desligada'],
                'LIGAR_IRRIGACAO'=>[2,'1','Ligar Irrigacao','Irrigação ligada'],
                'DESLIGAR_IRRIGACAO'=>[2,'0','Desligar Irrigacao','Irrigação desligada'],
            ][$nome];
            [$dev, $valor, $logCmd, $txt] = $mapa;
            $up = $pdo->prepare("UPDATE devpar SET devvalue=:v, atualizado_em=CURRENT_TIMESTAMP WHERE iddevice=:d AND devparname='dev1'");
            $up->execute([':v'=>$valor, ':d'=>$dev]);
            $lg = $pdo->prepare("INSERT INTO comandos_log (iddevice,comando,origem,resultado) VALUES (:d,:c,'JARVIS_AI','Executado')");
            $lg->execute([':d'=>$dev, ':c'=>$logCmd]);
            return ['acao'=>$txt,'resposta'=>'Afirmativo. '.$txt.'.','substitui'=>false,'erro'=>false];

        case 'CLIMA':
            $clima = agente_consultar_clima();
            $r = "Temperatura atual {$clima['temperatura']}" . (!empty($clima['umidade']) ? ", umidade {$clima['umidade']}" : '') . '. ' . (!empty($clima['vai_chover']) ? 'Há indicação de chuva.' : 'Sem indicação imediata de chuva.');
            return ['acao'=>'Consulta meteorológica','resposta'=>$r,'substitui'=>true,'erro'=>false];

        case 'TELEGRAM':
            agente_telegram_enviar('JARVIS: ' . $comando);
            return ['acao'=>'Notificação Telegram enviada','resposta'=>'Afirmativo. Aviso enviado pelo Telegram.','substitui'=>false,'erro'=>false];
        }
    } catch (Throwable $e) {
        error_log('JARVIS acao '.$nome.': '.$e->getMessage());
        return $erro('falha ao executar '.strtolower(str_replace('_',' ',$nome)));
    }
    return null;
}

$tagDireta = $semAcoes ? null : jarvis_comando_direto($comando);
if ($somenteDireto && $tagDireta === null) {
    if($taskInterpret>0) te_fail($pdo,$taskInterpret,'Ordem direta não reconhecida');
    computer_telemetry_finish($pdo,$telemetryId,'RECUSADO',null,null,null,$telemetryStarted);
    http_response_code(422);
    echo json_encode(['status'=>'erro','mensagem'=>'Não é uma ordem direta reconhecida: '.$comando], JSON_UNESCAPED_UNICODE);
    exit;
}

foreach (($tagDireta === null ? $modelosIA : []) as $modeloIA) {
    $promptSistema = (($modeloIA['nivel_capacidade'] ?? '') === 'PROFESSOR' || ($modeloIA['nivel_capacidade'] ?? '') === 'PROFISSIONAL')
        ? $systemCloud : $systemLocal;
    $promptSistema.=$contextoCasa['prompt'];
    if($ragContexto!=='') $promptSistema.=$ragContexto;
    $r = jarvis_chamar_modelo($modeloIA, $promptSistema, $comando, $historicoConversa);

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

$iaFalhou = false;
if ($tagDireta !== null) {
    $resposta = $tagDireta;
    $provedor = 'Comando direto';
    $target_ia = 'comando_direto';
    $tipo_tarefa = 'comando_direto';
} elseif (!$resposta) {
    $iaFalhou = true;
    $provedor = 'indisponivel';
    $resposta = 'Nenhum modelo de IA configurado respondeu. Consulte o diagnóstico da integração.';
}

// Executa somente as marcas presentes na resposta (nunca por palavras do pedido).
// CLIMA e TELEGRAM não constam nas instruções da IA: só valem vindas de um comando direto.
$acoes = [];
$confirmacoes = [];
$erros = [];
$substituta = null;
if (!$iaFalhou && !$semAcoes && preg_match_all('/\[\[CMD:([A-Z_]+)(?::([^\]]*))?\]\]/', $resposta, $marcas, PREG_SET_ORDER)) {
    $vistas = [];
    foreach ($marcas as $mc) {
        $nomeAcao = $mc[1]; $argAcao = $mc[2] ?? '';
        if (isset($vistas[$nomeAcao.':'.$argAcao])) continue;
        $vistas[$nomeAcao.':'.$argAcao] = true;
        if ($tagDireta === null && in_array($nomeAcao, ['CLIMA','TELEGRAM'], true)) continue;
        $r = jarvis_executar_acao($pdo, $nomeAcao, $argAcao, $comando, $taskContext);
        if ($r === null) continue;
        if (!empty($r['acao'])) $acoes[] = $r['acao'];
        if (!empty($r['erro'])) $erros[] = $r['resposta'];
        elseif (!empty($r['substitui'])) $substituta = $r['resposta'];
        else $confirmacoes[] = $r['resposta'];
    }
}
$acao = $acoes ? implode('; ', $acoes) : null;

$respostaLimpa = $semAcoes ? trim((string)$resposta) : trim(preg_replace('/\[\[CMD:.*?\]\]/', '', $resposta));
if ($substituta !== null) {
    $respostaLimpa = $substituta;
} elseif ($respostaLimpa === '') {
    $respostaLimpa = $confirmacoes ? implode(' ', $confirmacoes) : ($erros ? '' : 'Comando executado.');
}
if ($erros) $respostaLimpa = trim($respostaLimpa . ' ' . implode(' ', $erros));

if (!$semAcoes) try {
    $stmt = $pdo->prepare("INSERT INTO llm_conversas (user_msg,bot_msg,contexto,usuario) VALUES (:u,:b,:c,:us)");
    $stmt->execute([':u'=>$comando, ':b'=>$respostaLimpa, ':c'=>mb_substr((string)$provedor,0,80,'UTF-8'), ':us'=>$usuarioConversa]);
} catch (Throwable $e) {
    // Banco ainda sem a migração 1.33.
    try {
        $stmt = $pdo->prepare("INSERT INTO llm_conversas (user_msg,bot_msg,contexto) VALUES (:u,:b,:c)");
        $stmt->execute([':u'=>$comando, ':b'=>$respostaLimpa, ':c'=>mb_substr((string)$provedor,0,80,'UTF-8')]);
    } catch (Throwable $e2) {}
}

te_complete($pdo,$taskIA,['resposta'=>$respostaLimpa,'modelo'=>$modelo_usado]);
$taskFinal=te_add_subtask($pdo,$taskContext,'Consolidar resposta final','computer',[
    'resposta'=>$respostaLimpa
],$taskIA,'EXECUTANDO');
te_complete($pdo,$taskFinal,['resposta'=>$respostaLimpa]);
te_finish($pdo,$taskContext,$respostaLimpa,['acao'=>$acao,'modelo'=>$modelo_usado]);
// Chamada interna não gera áudio (ninguém vai ouvir).
$audioUrl = ($chamadaInterna && !$reproduzirNaCasa) ? null : jarvis_tts($respostaLimpa, $jarvis_voice, $reproduzirNaCasa);
computer_telemetry_finish(
    $pdo,
    $telemetryId,
    $iaFalhou ? 'FALHA' : 'SUCESSO',
    $respostaLimpa,
    $acao,
    is_array($modelo_usado)?($modelo_usado['modelo']??null):null,
    $telemetryStarted
);
echo json_encode(te_attach_context([
    'status'=>'sucesso','comando'=>$comando,'resposta'=>$respostaLimpa,'data_hora'=>date('Y-m-d H:i:s'),
    'historico_chars'=>strlen($historicoTexto),'historico_mensagens'=>count($historicoConversa),
    'rag_fontes'=>$ragFontes,'rag_matches'=>count($ragFontes),
    'contexto_casa'=>['secoes'=>$contextoCasa['secoes'],'chars'=>$contextoCasa['chars']],
    'provedor'=>$provedor,'target_ia'=>$target_ia,'tipo_tarefa'=>$tipo_tarefa,
    'modo_roteamento'=>$routing_mode,'acao'=>$acao,'audio_url'=>$audioUrl,
    'speaker'=>$jarvis_voice,'modelo_usado'=>$modelo_usado,'ia_diagnostico'=>$ia_diagnostico,
    'tarefas_execucao'=>te_list_tasks($pdo,$taskContext)
],$taskContext,$pdo), JSON_UNESCAPED_UNICODE);
