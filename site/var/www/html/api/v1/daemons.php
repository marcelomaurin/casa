<?php
// CASA/JARVIS - API v1: acompanhamento e gestao dos daemons dos nos do cluster.
//
// GET  ?acao=listar     estado dos nos e dos servicos (daemons.read ou mobile.read)
// POST ?acao=controlar  inicia, para ou reinicia um servico (daemons.control ou celular
//                       pareado por administrador). Exige {"confirmar": true}.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../seguranca.php');
require_once(__DIR__ . '/security_v1.php');
require_once(__DIR__ . '/../arm_nodes_control.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$acao = (string)($_GET['acao'] ?? ($metodo === 'POST' ? 'controlar' : 'listar'));

/** Escopos explicitos, chave total ou celular pareado por um administrador ativo. */
function daemons_pode_controlar(PDO $pdo, array $client): bool {
    $scopes = is_array($client['scopes'] ?? null) ? $client['scopes'] : [];
    if (in_array('*', $scopes, true) || in_array('daemons.control', $scopes, true)) return true;
    $deviceId = trim((string)($client['device_id'] ?? ''));
    if ($deviceId === '' || !in_array('mobile.write', $scopes, true)) return false;
    try {
        $st = $pdo->prepare("SELECT metadata FROM dispositivos_cluster WHERE device_id=:d AND tipo='mobile' LIMIT 1");
        $st->execute([':d' => $deviceId]);
        $meta = json_decode((string)$st->fetchColumn(), true);
        $pareadoPor = is_array($meta) ? trim((string)($meta['paired_by'] ?? '')) : '';
        if ($pareadoPor === '') return false;
        $u = $pdo->prepare("SELECT perfil FROM usuarios WHERE LOWER(login)=LOWER(:l) AND ativo=1 LIMIT 1");
        $u->execute([':l' => $pareadoPor]);
        return in_array(strtolower((string)$u->fetchColumn()), ['admin', 'administrador'], true);
    } catch (Throwable $e) {
        return false;
    }
}

function daemons_resumo_no(array $n): array {
    $servicos = [];
    foreach ((array)($n['services_detail'] ?? []) as $s) {
        $servicos[] = [
            'id' => (string)$s['id'],
            'nome' => (string)$s['label'],
            'estado' => (string)$s['state'],
            'ativo' => (bool)$s['active'],
            'protegido' => (bool)$s['protected'],
            'exclusivo' => (bool)$s['exclusive'],
            'porta' => $s['port'] ?? null,
            'finalidade' => (string)($s['purpose'] ?? ''),
            'impacto_ao_parar' => (string)($s['impact_when_stopped'] ?? ''),
        ];
    }
    return [
        'id' => (string)$n['id'],
        'device_id' => (string)$n['device_id'],
        'nome' => (string)$n['nome'],
        'ip' => (string)$n['ip_address'],
        'papel' => (string)$n['papel'],
        'online' => (bool)$n['online'],
        'status' => (string)$n['status'],
        'ultimo_sinal' => $n['last_seen'],
        'segundos_sem_sinal' => $n['age_seconds'],
        'cpu' => (string)$n['cpu'],
        'ram' => (string)$n['ram'],
        'versao' => (string)$n['version'],
        'atualizado' => $n['up_to_date'],
        'servicos' => $servicos,
    ];
}

if ($acao === 'listar') {
    if ($metodo !== 'GET') api_v1_json_response(405, ['status' => 'erro', 'mensagem' => 'Use GET para listar']);
    $client = api_v1_auth_client_any($pdo, ['daemons.read', 'mobile.read', 'status.read']);
    try {
        $nos = array_map('daemons_resumo_no', arm_nodes_list($pdo));
    } catch (Throwable $e) {
        error_log('API v1 daemons: ' . $e->getMessage());
        api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Nao foi possivel carregar os nos do cluster']);
    }
    $ativos = $parados = 0;
    foreach ($nos as $n) foreach ($n['servicos'] as $s) { if ($s['ativo']) $ativos++; else $parados++; }
    api_v1_json_response(200, [
        'status' => 'ok',
        'gerado_em' => date('c'),
        'pode_controlar' => daemons_pode_controlar($pdo, $client),
        'resumo' => [
            'nos' => count($nos),
            'nos_online' => count(array_filter($nos, fn($n) => $n['online'])),
            'servicos_ativos' => $ativos,
            'servicos_parados' => $parados,
        ],
        'nos' => $nos,
    ]);
}

if ($acao === 'controlar') {
    if ($metodo !== 'POST') {
        header('Allow: POST');
        api_v1_json_response(405, ['status' => 'erro', 'mensagem' => 'Use POST para controlar um servico']);
    }
    $client = api_v1_auth_client_any($pdo, ['daemons.control', 'mobile.write']);
    if (!daemons_pode_controlar($pdo, $client)) {
        api_v1_log($pdo, 'DAEMON_CONTROL_DENIED', 'ALTO', $client['nome'] ?? null);
        api_v1_json_response(403, ['status' => 'erro', 'mensagem' => 'Este aparelho nao tem permissao para controlar daemons']);
    }
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
    if (!is_array($in)) api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'JSON invalido']);

    $noId = trim((string)($in['no'] ?? $in['node_id'] ?? ''));
    $servico = trim((string)($in['servico'] ?? $in['service'] ?? ''));
    $operacao = strtolower(trim((string)($in['operacao'] ?? $in['action'] ?? '')));
    if (!preg_match('/^(device|legacy):\d+$/', $noId) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/', $servico)) {
        api_v1_json_response(422, ['status' => 'erro', 'mensagem' => 'No ou servico invalido']);
    }
    if (!in_array($operacao, ['start', 'stop', 'restart'], true)) {
        api_v1_json_response(422, ['status' => 'erro', 'mensagem' => 'Operacao deve ser start, stop ou restart']);
    }
    if (($in['confirmar'] ?? false) !== true) {
        api_v1_json_response(428, ['status' => 'erro', 'mensagem' => 'Confirme a operacao enviando "confirmar": true']);
    }
    $catalogo = array_column(arm_cluster_service_catalog(), null, 'id');
    if (!isset($catalogo[$servico])) {
        api_v1_json_response(404, ['status' => 'erro', 'mensagem' => 'Servico desconhecido']);
    }
    if (!empty($catalogo[$servico]['protected']) && $operacao === 'stop') {
        api_v1_json_response(403, ['status' => 'erro', 'mensagem' => 'Servico protegido nao pode ser parado']);
    }

    preg_match('/^(?:device|legacy):(\d+)$/', $noId, $m);
    $resultado = arm_node_control_service($pdo, 'controlar_servico',
        ['service' => $servico, 'service_action' => $operacao], '', (int)$m[1], '');

    $ok = $resultado['code'] === 200;
    api_v1_log($pdo, $ok ? 'DAEMON_CONTROL_OK' : 'DAEMON_CONTROL_FAIL', $ok ? 'AVISO' : 'ALTO', $client['nome'] ?? null,
        ['no' => $noId, 'servico' => $servico, 'operacao' => $operacao]);
    if (function_exists('registrar_evento_seguranca')) {
        registrar_evento_seguranca(get_client_ip(), 'DAEMON_' . strtoupper($operacao),
            "Servico {$servico} no no {$noId} ({$operacao}) por " . ($client['nome'] ?? 'cliente') . ($ok ? '' : ' - falhou'),
            $ok ? 'INFO' : 'AVISO');
    }
    api_v1_json_response($resultado['code'], $resultado['body']);
}

api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'Acao desconhecida']);
