<?php
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');
require_once(__DIR__ . '/task_engine.php');

verify_api_auth();
$pdo = get_db_pdo();

function planner_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function planner_call_json($demanda, array $taskContext) {
    $agora = date('Y-m-d H:i:s');
    $prompt = "Voce e o planejador do JARVIS. Data/hora atual: {$agora}. " .
        "Decomponha a demanda do usuario em tarefas executaveis. Retorne SOMENTE JSON valido, sem markdown. " .
        "Formato obrigatorio: {\"resumo\":\"...\",\"tarefas\":[{" .
        "\"ordem\":1,\"titulo\":\"...\",\"descricao\":\"...\",\"tipo\":\"IMEDIATA|AGENDADA|CONDICIONAL\"," .
        "\"executor\":\"jarvis|web|dispositivo|fala\",\"payload\":{\"comando\":\"...\"}," .
        "Para executor dispositivo, payload DEVE ser {\"device_id\":\"id registrado\",\"command\":\"comando\",\"payload\":{},\"required_capability\":\"capability opcional\",\"risk_level\":1}. " .
        "\"executar_em\":null,\"recorrencia\":null,\"depende_de_ordem\":null}]} . " .
        "Use AGENDADA quando houver data/hora futura ou recorrencia. Use CONDICIONAL quando depender de evento/condicao. " .
        "Use web quando a tarefa exigir pesquisa atual na internet. Nao invente data ausente. " .
        "Demanda: " . $demanda;

    $token = get_system_api_token();
    $payload = json_encode([
        'comando' => '/cloud ' . $prompt,
        'skip_planner' => true,
        'origem' => 'PLANEJADOR',
        'task_context' => te_public_context($taskContext),
        'correlation_id' => $taskContext['correlation_id'] ?? null
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('http://127.0.0.1/api/jarvis.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $token]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 50);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$res || $code < 200 || $code >= 300) return ['ok'=>false,'erro'=>$err ?: 'Falha ao consultar IA'];

    $r = json_decode($res, true);
    $txt = trim($r['resposta'] ?? '');
    $txt = preg_replace('/^```(?:json)?\s*/i', '', $txt);
    $txt = preg_replace('/\s*```$/', '', $txt);
    $ini = strpos($txt, '{');
    $fim = strrpos($txt, '}');
    if ($ini !== false && $fim !== false && $fim >= $ini) $txt = substr($txt, $ini, $fim - $ini + 1);
    $plano = json_decode($txt, true);
    if (!is_array($plano) || !isset($plano['tarefas']) || !is_array($plano['tarefas'])) {
        return ['ok'=>false,'erro'=>'IA nao retornou plano JSON valido','raw'=>$txt];
    }
    return ['ok'=>true,'plano'=>$plano];
}

function planner_post($url, $payload, $timeout=50) {
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

function planner_executor_payload($executor, $payload) {
    if (is_string($payload)) return $payload;
    if (!is_array($payload)) return '';
    if ($executor === 'dispositivo' && isset($payload['iddevice'])) {
        return http_build_query([
            'iddevice'=>$payload['iddevice'],
            'devparname'=>$payload['devparname'] ?? 'dev1',
            'valor'=>$payload['valor'] ?? '1'
        ]);
    }
    return trim($payload['comando'] ?? ($payload['texto'] ?? ($payload['query'] ?? '')));
}

function planner_tipo_acao($executor) {
    if ($executor === 'web') return 'pesquisa_web';
    if ($executor === 'fala') return 'aviso_fala';
    if ($executor === 'dispositivo') return 'dispositivo_devpar';
    return 'comando_jarvis';
}

function planner_executar_imediata(PDO $pdo, array $ctx, int $taskId, $executor, $payload) {
    $texto = planner_executor_payload($executor, $payload);
    if ($executor === 'web') {
        return planner_post('http://127.0.0.1/api/agente_internet.php', [
            'acao'=>'responder','query'=>$texto,'max_results'=>5,
            'task_context'=>te_public_context($ctx),
            'correlation_id'=>$ctx['correlation_id'] ?? null
        ], 65);
    }
    if ($executor === 'fala') {
        $ch = curl_init('http://127.0.0.1:8097/falar');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['texto'=>$texto,'speaker'=>'padrao','reproduzir'=>true], JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ($res && $code>=200 && $code<300) ? ['ok'=>true,'dados'=>json_decode($res,true)] : ['ok'=>false,'erro'=>'Falha TTS'];
    }
    if ($executor === 'dispositivo') {
        if (!is_array($payload)) return ['ok'=>false,'erro'=>'Payload de dispositivo invalido'];
        try {
            $queued=te_device_action($pdo,$ctx,$taskId,$payload,!empty($payload['confirm']));
            return ['ok'=>true,'queued'=>true,'dados'=>$queued];
        } catch (Throwable $e) {
            return ['ok'=>false,'erro'=>$e->getMessage()];
        }
    }
    return planner_post('http://127.0.0.1/api/jarvis.php', [
        'comando'=>$texto,'skip_planner'=>true,'origem'=>'PLANEJADOR_EXECUTOR',
        'task_context'=>te_public_context($ctx)
    ], 50);
}

$in = planner_input();
$demanda = trim($in['demanda'] ?? ($in['comando'] ?? ($_POST['demanda'] ?? '')));
$executarImediatas = array_key_exists('executar_imediatas', $in) ? (bool)$in['executar_imediatas'] : true;
$existingTaskContext=te_context_from_input($in['task_context'] ?? null);
if ($demanda === '') {
    http_response_code(400);
    echo json_encode(['status'=>'erro','mensagem'=>'Demanda vazia'], JSON_UNESCAPED_UNICODE);
    exit;
}

$taskContext=te_begin(
    $pdo,
    $demanda,
    trim($in['origem'] ?? 'JARVIS'),
    'planejador',
    $existingTaskContext,
    $in['correlation_id'] ?? null
);
$idPlano=(int)$taskContext['id_plano'];

$taskPlanning=te_add_subtask($pdo,$taskContext,'Gerar plano estruturado','planejador',[
    'demanda'=>$demanda
],null,'EXECUTANDO');

$gen = planner_call_json($demanda,$taskContext);
if (!$gen['ok']) {
    te_fail_with_plan($pdo,$taskContext,$taskPlanning,(string)$gen['erro'],['raw'=>$gen['raw'] ?? null]);
    http_response_code(502);
    echo json_encode(te_attach_context([
        'status'=>'erro','etapa'=>'planejamento','mensagem'=>$gen['erro'],'raw'=>$gen['raw'] ?? null
    ],$taskContext,$pdo), JSON_UNESCAPED_UNICODE);
    exit;
}
$plano = $gen['plano'];
te_complete($pdo,$taskPlanning,['resumo'=>$plano['resumo'] ?? '','tarefas_previstas'=>count($plano['tarefas'] ?? [])]);

$pdo->prepare("UPDATE jarvis_planos SET status='EM_EXECUCAO',resumo=:r,dados_plano=:j WHERE id=:id")
    ->execute([
        ':r'=>trim($plano['resumo'] ?? ''),
        ':j'=>json_encode($plano, JSON_UNESCAPED_UNICODE),
        ':id'=>$idPlano
    ]);

$idsPorOrdem = [];
$tarefasSalvas = [];
$baseOrdem=te_next_order($pdo,$idPlano);
foreach ($plano['tarefas'] as $idx => $t) {
    $ordemDeclarada = intval($t['ordem'] ?? ($idx + 1));
    $ordem = $baseOrdem + $idx;
    $tipo = strtoupper(trim($t['tipo'] ?? 'IMEDIATA'));
    if (!in_array($tipo, ['IMEDIATA','AGENDADA','CONDICIONAL'], true)) $tipo = 'IMEDIATA';
    $executor = strtolower(trim($t['executor'] ?? 'jarvis'));
    if (!in_array($executor, ['jarvis','web','dispositivo','fala'], true)) $executor = 'jarvis';
    $payload = is_array($t['payload'] ?? null) ? $t['payload'] : ['comando'=>(string)($t['payload'] ?? '')];
    $executarEm = !empty($t['executar_em']) ? date('Y-m-d H:i:s', strtotime($t['executar_em'])) : null;
    $status = $tipo === 'AGENDADA' ? 'AGENDADA' : ($tipo === 'CONDICIONAL' ? 'AGUARDANDO' : 'PENDENTE');

    $st = $pdo->prepare("INSERT INTO jarvis_tarefas (id_plano,correlation_id,ordem,titulo,descricao,tipo,executor,payload,status,executar_em,recorrencia,tarefa_pai_id) VALUES (:p,:corr,:o,:t,:d,:tp,:e,:j,:s,:x,:r,:pai)");
    $st->execute([
        ':p'=>$idPlano, ':corr'=>$taskContext['correlation_id'] ?? null, ':o'=>$ordem, ':t'=>trim($t['titulo'] ?? ('Tarefa '.$ordem)),
        ':d'=>trim($t['descricao'] ?? ''), ':tp'=>$tipo, ':e'=>$executor,
        ':j'=>json_encode($payload, JSON_UNESCAPED_UNICODE), ':s'=>$status,
        ':x'=>$executarEm, ':r'=>$t['recorrencia'] ?? null, ':pai'=>$taskContext['id_tarefa_raiz']
    ]);
    $idTarefa = (int)$pdo->lastInsertId();
    $idsPorOrdem[$ordemDeclarada] = $idTarefa;
    $tarefasSalvas[] = ['id'=>$idTarefa,'ordem'=>$ordem,'tipo'=>$tipo,'executor'=>$executor,'payload'=>$payload,'executar_em'=>$executarEm,'depende_de_ordem'=>$t['depende_de_ordem'] ?? null];
}

foreach ($tarefasSalvas as &$t) {
    $depOrd = $t['depende_de_ordem'];
    if ($depOrd !== null && isset($idsPorOrdem[intval($depOrd)])) {
        $depId = $idsPorOrdem[intval($depOrd)];
        $pdo->prepare("UPDATE jarvis_tarefas SET depende_de=:d WHERE id=:id")->execute([':d'=>$depId, ':id'=>$t['id']]);
        $t['depende_de'] = $depId;
    }

    if ($t['tipo'] === 'AGENDADA') {
        $quando = $t['executar_em'];
        if (!$quando) {
            $pdo->prepare("UPDATE jarvis_tarefas SET status='AGUARDANDO', erro='Data/hora de execucao nao definida' WHERE id=:id")->execute([':id'=>$t['id']]);
            $t['status'] = 'AGUARDANDO';
            continue;
        }
        $tipoAcao = planner_tipo_acao($t['executor']);
        $payloadTxt = planner_executor_payload($t['executor'], $t['payload']);
        $st = $pdo->prepare("INSERT INTO tarefas_agendadas (titulo,descricao,horario,dias_semana,tipo_acao,payload,target_node,ativo,modo_agendamento,executar_em,id_plano,id_tarefa_plano,executar_uma_vez)
            SELECT titulo,descricao,DATE_FORMAT(:x,'%H:%i'),'*',:a,:pl,'local',1,'UNICA',:x,:p,id,1 FROM jarvis_tarefas WHERE id=:id");
        $st->execute([':x'=>$quando, ':a'=>$tipoAcao, ':pl'=>$payloadTxt, ':p'=>$idPlano, ':id'=>$t['id']]);
        $t['id_agendamento'] = (int)$pdo->lastInsertId();
        $t['status'] = 'AGENDADA';
    }
}
unset($t);

$resultados = [];
if ($executarImediatas) {
    foreach ($tarefasSalvas as &$t) {
        if ($t['tipo'] !== 'IMEDIATA') continue;
        if (!empty($t['depende_de'])) {
            $q = $pdo->prepare("SELECT status FROM jarvis_tarefas WHERE id=:id");
            $q->execute([':id'=>$t['depende_de']]);
            if ($q->fetchColumn() !== 'CONCLUIDA') {
                $t['status'] = 'AGUARDANDO';
                $pdo->prepare("UPDATE jarvis_tarefas SET status='AGUARDANDO' WHERE id=:id")->execute([':id'=>$t['id']]);
                continue;
            }
        }
        $pdo->prepare("UPDATE jarvis_tarefas SET status='EXECUTANDO', iniciado_em=CURRENT_TIMESTAMP WHERE id=:id")->execute([':id'=>$t['id']]);
        $ctx=[
            'id_plano'=>$idPlano,
            'id_tarefa_raiz'=>(int)$taskContext['id_tarefa_raiz'],
            'current_task_id'=>(int)$t['id'],
            'correlation_id'=>$taskContext['correlation_id'] ?? '',
            'origem'=>'PLANEJADOR',
            'modulo'=>'planejador'
        ];
        $exec = planner_executar_imediata($pdo,$ctx,(int)$t['id'],$t['executor'], $t['payload']);
        $queued=!empty($exec['queued']);
        $status = $exec['ok'] ? ($queued ? 'AGUARDANDO' : 'CONCLUIDA') : 'ERRO';
        if(!$queued){
            $pdo->prepare("UPDATE jarvis_tarefas SET status=:s, resultado=:r, erro=:e, concluido_em=CURRENT_TIMESTAMP WHERE id=:id")->execute([
                ':s'=>$status, ':r'=>json_encode($exec, JSON_UNESCAPED_UNICODE), ':e'=>$exec['ok'] ? null : ($exec['erro'] ?? 'erro'), ':id'=>$t['id']
            ]);
        }
        $t['status'] = $status;
        $resultados[] = ['id_tarefa'=>$t['id'],'resultado'=>$exec];
    }
    unset($t);
}

$pdo->prepare("UPDATE jarvis_planos SET status='EM_EXECUCAO' WHERE id=:id")->execute([':id'=>$idPlano]);

echo json_encode(te_attach_context([
    'status'=>'ok',
    'resumo'=>$plano['resumo'] ?? '',
    'tarefas'=>$tarefasSalvas,
    'resultados_imediatos'=>$resultados
],$taskContext,$pdo), JSON_UNESCAPED_UNICODE);
