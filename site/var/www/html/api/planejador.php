<?php
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/seguranca.php');

verify_api_auth();
$pdo = get_db_pdo();

function planner_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function planner_call_json($demanda) {
    $agora = date('Y-m-d H:i:s');
    $prompt = "Voce e o planejador do JARVIS. Data/hora atual: {$agora}. " .
        "Decomponha a demanda do usuario em tarefas executaveis. Retorne SOMENTE JSON valido, sem markdown. " .
        "Formato obrigatorio: {\"resumo\":\"...\",\"tarefas\":[{" .
        "\"ordem\":1,\"titulo\":\"...\",\"descricao\":\"...\",\"tipo\":\"IMEDIATA|AGENDADA|CONDICIONAL\"," .
        "\"executor\":\"jarvis|web|dispositivo|fala\",\"payload\":{\"comando\":\"...\"}," .
        "\"executar_em\":null,\"recorrencia\":null,\"depende_de_ordem\":null}]} . " .
        "Use AGENDADA quando houver data/hora futura ou recorrencia. Use CONDICIONAL quando depender de evento/condicao. " .
        "Use web quando a tarefa exigir pesquisa atual na internet. Nao invente data ausente. " .
        "Demanda: " . $demanda;

    $token = get_system_api_token();
    $payload = json_encode([
        'comando' => '/cloud ' . $prompt,
        'skip_planner' => true,
        'origem' => 'PLANEJADOR'
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

function planner_executar_imediata($executor, $payload) {
    $texto = planner_executor_payload($executor, $payload);
    if ($executor === 'web') {
        return planner_post('http://127.0.0.1/api/agente_internet.php', [
            'acao'=>'responder','query'=>$texto,'max_results'=>5
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
        $texto = 'Execute a acao de dispositivo: ' . json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    return planner_post('http://127.0.0.1/api/jarvis.php', [
        'comando'=>$texto,'skip_planner'=>true,'origem'=>'PLANEJADOR_EXECUTOR'
    ], 50);
}

$in = planner_input();
$demanda = trim($in['demanda'] ?? ($in['comando'] ?? ($_POST['demanda'] ?? '')));
$executarImediatas = array_key_exists('executar_imediatas', $in) ? (bool)$in['executar_imediatas'] : true;
if ($demanda === '') {
    http_response_code(400);
    echo json_encode(['status'=>'erro','mensagem'=>'Demanda vazia'], JSON_UNESCAPED_UNICODE);
    exit;
}

$gen = planner_call_json($demanda);
if (!$gen['ok']) {
    http_response_code(502);
    echo json_encode(['status'=>'erro','etapa'=>'planejamento','mensagem'=>$gen['erro'],'raw'=>$gen['raw'] ?? null], JSON_UNESCAPED_UNICODE);
    exit;
}
$plano = $gen['plano'];

$stmt = $pdo->prepare("INSERT INTO jarvis_planos (demanda_original,origem,status,resumo,dados_plano) VALUES (:d,:o,'PLANEJADO',:r,CAST(:j AS jsonb)) RETURNING id");
$stmt->execute([
    ':d'=>$demanda,
    ':o'=>trim($in['origem'] ?? 'JARVIS'),
    ':r'=>trim($plano['resumo'] ?? ''),
    ':j'=>json_encode($plano, JSON_UNESCAPED_UNICODE)
]);
$idPlano = intval($stmt->fetchColumn());

$idsPorOrdem = [];
$tarefasSalvas = [];
foreach ($plano['tarefas'] as $idx => $t) {
    $ordem = intval($t['ordem'] ?? ($idx + 1));
    $tipo = strtoupper(trim($t['tipo'] ?? 'IMEDIATA'));
    if (!in_array($tipo, ['IMEDIATA','AGENDADA','CONDICIONAL'], true)) $tipo = 'IMEDIATA';
    $executor = strtolower(trim($t['executor'] ?? 'jarvis'));
    if (!in_array($executor, ['jarvis','web','dispositivo','fala'], true)) $executor = 'jarvis';
    $payload = is_array($t['payload'] ?? null) ? $t['payload'] : ['comando'=>(string)($t['payload'] ?? '')];
    $executarEm = !empty($t['executar_em']) ? date('Y-m-d H:i:s', strtotime($t['executar_em'])) : null;
    $status = $tipo === 'AGENDADA' ? 'AGENDADA' : ($tipo === 'CONDICIONAL' ? 'AGUARDANDO' : 'PENDENTE');

    $st = $pdo->prepare("INSERT INTO jarvis_tarefas (id_plano,ordem,titulo,descricao,tipo,executor,payload,status,executar_em,recorrencia) VALUES (:p,:o,:t,:d,:tp,:e,CAST(:j AS jsonb),:s,:x,:r) RETURNING id");
    $st->execute([
        ':p'=>$idPlano, ':o'=>$ordem, ':t'=>trim($t['titulo'] ?? ('Tarefa '.$ordem)),
        ':d'=>trim($t['descricao'] ?? ''), ':tp'=>$tipo, ':e'=>$executor,
        ':j'=>json_encode($payload, JSON_UNESCAPED_UNICODE), ':s'=>$status,
        ':x'=>$executarEm, ':r'=>$t['recorrencia'] ?? null
    ]);
    $idTarefa = intval($st->fetchColumn());
    $idsPorOrdem[$ordem] = $idTarefa;
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
        $st = $pdo->prepare("INSERT INTO tarefas_agendadas (titulo,descricao,horario,dias_semana,tipo_acao,payload,target_node,ativo,modo_agendamento,executar_em,id_plano,id_tarefa_plano,executar_uma_vez) SELECT titulo,descricao,to_char(:x::timestamp,'HH24:MI'),'*',:a,:pl,'local',TRUE,'UNICA',:x,:p,id,TRUE FROM jarvis_tarefas WHERE id=:id RETURNING id");
        $st->execute([':x'=>$quando, ':a'=>$tipoAcao, ':pl'=>$payloadTxt, ':p'=>$idPlano, ':id'=>$t['id']]);
        $t['id_agendamento'] = intval($st->fetchColumn());
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
        $exec = planner_executar_imediata($t['executor'], $t['payload']);
        $status = $exec['ok'] ? 'CONCLUIDA' : 'ERRO';
        $pdo->prepare("UPDATE jarvis_tarefas SET status=:s, resultado=CAST(:r AS jsonb), erro=:e, concluido_em=CURRENT_TIMESTAMP WHERE id=:id")->execute([
            ':s'=>$status, ':r'=>json_encode($exec, JSON_UNESCAPED_UNICODE), ':e'=>$exec['ok'] ? null : ($exec['erro'] ?? 'erro'), ':id'=>$t['id']
        ]);
        $t['status'] = $status;
        $resultados[] = ['id_tarefa'=>$t['id'],'resultado'=>$exec];
    }
    unset($t);
}

$pdo->prepare("UPDATE jarvis_planos SET status='EM_EXECUCAO' WHERE id=:id")->execute([':id'=>$idPlano]);

echo json_encode([
    'status'=>'ok',
    'id_plano'=>$idPlano,
    'resumo'=>$plano['resumo'] ?? '',
    'tarefas'=>$tarefasSalvas,
    'resultados_imediatos'=>$resultados
], JSON_UNESCAPED_UNICODE);
