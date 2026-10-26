<?php
// CASA/JARVIS - Estatisticas dos itens do menu do painel.
//
// GET /casa/api/painel_estatisticas.php
// Devolve, em uma unica chamada, um numero principal, uma legenda e (quando
// faz sentido) a serie dos ultimos 7 dias de cada item do menu. O painel usa
// isso para desenhar os cartoes de cada area com dados reais do item.
//
// Somente leitura e somente contagens: nenhum dado pessoal, chave ou senha sai
// daqui. Exige a sessao do painel. Cada metrica e calculada isolada: se uma
// tabela nao existir, aquele item fica sem numero e o resto continua.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__ . '/db.php');

if (empty($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Sessao do painel obrigatoria.'], JSON_UNESCAPED_UNICODE);
    exit;
}
session_write_close();

$pdo = get_db_pdo();

// Valor unico de uma consulta fixa (sem entrada do usuario); null se falhar.
function est_valor(PDO $pdo, string $sql) {
    try {
        $v = $pdo->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    } catch (Throwable $e) {
        return null;
    }
}

// Quantidade de registros por dia nos ultimos 7 dias (do mais antigo para hoje).
function est_serie(PDO $pdo, string $tabela, string $coluna, string $filtro = ''): ?array {
    try {
        $sql = "SELECT DATE(`{$coluna}`) AS d, COUNT(*) AS n FROM `{$tabela}`
                WHERE `{$coluna}` >= CURDATE() - INTERVAL 6 DAY" . ($filtro ? " AND ({$filtro})" : '') . "
                GROUP BY DATE(`{$coluna}`)";
        $porDia = [];
        foreach ($pdo->query($sql) as $r) $porDia[$r['d']] = (int)$r['n'];
        $serie = [];
        for ($i = 6; $i >= 0; $i--) $serie[] = $porDia[date('Y-m-d', strtotime("-{$i} day"))] ?? 0;
        return $serie;
    } catch (Throwable $e) {
        return null;
    }
}

function est_int($v): ?int { return $v === null ? null : (int)$v; }

$reles = "(capabilities LIKE '%relay%' OR capabilities LIKE '%switch%' OR capabilities LIKE '%rele%')";
$cameras = "(tipo IN ('camera','espcam','esp32_cam','ip_camera','camera_ip','cftv','dvr','nvr') OR capabilities LIKE '%camera%')";
$online = "ultimo_heartbeat >= NOW() - INTERVAL 90 SECOND";

$e = [];

// OPERACOES
$total = est_int(est_valor($pdo, "SELECT COUNT(*) FROM dispositivos_cluster WHERE {$reles}"));
$on = est_int(est_valor($pdo, "SELECT COUNT(*) FROM dispositivos_cluster WHERE {$reles} AND {$online}"));
$e['devices'] = ['valor' => $total === null ? null : "{$on} / {$total}", 'legenda' => 'relés online',
    'serie' => est_serie($pdo, 'comandos_log', 'data_hora'), 'serie_legenda' => 'comandos por dia',
    'estado' => ($total && $on < $total) ? 'alerta' : 'ok'];
$cenas = est_int(est_valor($pdo, "SELECT COUNT(*) FROM scenes WHERE ativo = 1"));
$regras = est_int(est_valor($pdo, "SELECT COUNT(*) FROM automation_rules WHERE enabled = 1"));
$e['cenas'] = ['valor' => $cenas, 'legenda' => 'cenas ativas · ' . (int)$regras . ' regra(s)',
    'serie' => est_serie($pdo, 'scene_runs', 'criado_em'), 'serie_legenda' => 'execuções por dia'];
$e['agendamentos'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM tarefas_agendadas WHERE ativo = 1")),
    'legenda' => 'agendamentos ativos',
    'detalhe' => (string)(est_valor($pdo, "SELECT DATE_FORMAT(MIN(proxima_execucao), '%d/%m %H:%i') FROM tarefas_agendadas WHERE ativo = 1 AND proxima_execucao >= NOW()") ?? '')];

// SEGURANCA
$cTot = est_int(est_valor($pdo, "SELECT COUNT(*) FROM dispositivos_cluster WHERE {$cameras}"));
$cOn = est_int(est_valor($pdo, "SELECT COUNT(*) FROM dispositivos_cluster WHERE {$cameras} AND {$online}"));
$e['cameras'] = ['valor' => $cTot === null ? null : "{$cOn} / {$cTot}", 'legenda' => 'câmeras online',
    'serie' => est_serie($pdo, 'camera_eventos', 'data_hora'), 'serie_legenda' => 'eventos de câmera por dia',
    'estado' => ($cTot && $cOn < $cTot) ? 'alerta' : 'ok'];
$bloq = est_int(est_valor($pdo, "SELECT COUNT(*) FROM seguranca_ips_bloqueados WHERE bloqueado_ate IS NULL OR bloqueado_ate > NOW()"));
$e['seguranca'] = ['valor' => $bloq, 'legenda' => 'IP(s) bloqueado(s)',
    'serie' => est_serie($pdo, 'seguranca_logs', 'data_hora'), 'serie_legenda' => 'eventos de segurança por dia',
    'estado' => $bloq ? 'alerta' : 'ok'];

// AMBIENTE
$temp = est_valor($pdo, "SELECT temperature_c FROM device_environment_readings WHERE temperature_c IS NOT NULL ORDER BY observed_at DESC LIMIT 1");
if ($temp === null) $temp = est_valor($pdo, "SELECT temperatura_c FROM iot_leituras WHERE temperatura_c IS NOT NULL ORDER BY data_hora DESC LIMIT 1");
$umid = est_valor($pdo, "SELECT humidity_pct FROM device_environment_readings WHERE humidity_pct IS NOT NULL ORDER BY observed_at DESC LIMIT 1");
$e['sensores'] = ['valor' => $temp === null ? null : number_format((float)$temp, 1, ',', '') . ' °C',
    'legenda' => $umid === null ? 'última temperatura' : 'umidade ' . number_format((float)$umid, 0, ',', '') . '%',
    'serie' => est_serie($pdo, 'sensores_telemetria', 'data_hora'), 'serie_legenda' => 'leituras por dia'];
$e['ambiente'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM device_environment_readings WHERE observed_at >= NOW() - INTERVAL 1 DAY")),
    'legenda' => 'leituras nas últimas 24 h',
    'serie' => est_serie($pdo, 'device_environment_readings', 'observed_at'), 'serie_legenda' => 'leituras por dia'];

// IA & VOZ
$e['jarvis'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM comandos_log WHERE data_hora >= NOW() - INTERVAL 1 DAY")),
    'legenda' => 'comandos nas últimas 24 h',
    'serie' => est_serie($pdo, 'comandos_log', 'data_hora'), 'serie_legenda' => 'comandos por dia'];
$e['frases'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM frases")), 'legenda' => 'frases cadastradas'];
$mAt = est_int(est_valor($pdo, "SELECT COUNT(*) FROM ia_modelos WHERE ativo = 1"));
$mFalha = est_int(est_valor($pdo, "SELECT COUNT(*) FROM ia_modelos WHERE ativo = 1 AND falhas_consecutivas > 0"));
$e['modelos-ia'] = ['valor' => $mAt, 'legenda' => 'modelo(s) ativo(s)' . ($mFalha ? " · {$mFalha} com falha" : ''),
    'estado' => $mFalha ? 'alerta' : 'ok'];
$prov = est_valor($pdo, "SELECT valor FROM configuracoes_sistema WHERE chave = 'ia_provider' LIMIT 1");
$nomes = ['local' => 'LOCAL', 'openai_compatible' => 'COMPATÍVEL OPENAI', 'runpod' => 'RUNPOD'];
$e['config'] = ['valor' => $prov === null ? null : ($nomes[$prov] ?? strtoupper((string)$prov)), 'legenda' => 'provedor principal'];

// FAMILIA
$e['familia'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM family_presence WHERE ultimo_ping >= NOW() - INTERVAL 2 MINUTE")),
    'legenda' => 'pessoa(s) conectada(s) agora',
    'serie' => est_serie($pdo, 'family_messages', 'criado_em'), 'serie_legenda' => 'mensagens por dia'];
$e['celular'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM api_client_tokens WHERE ativo = 1 AND revogado_em IS NULL AND device_id LIKE 'mobile-%'")),
    'legenda' => 'celular(es) pareado(s)',
    'serie' => est_serie($pdo, 'mobile_eventos', 'data_hora'), 'serie_legenda' => 'eventos do celular por dia'];
$e['relogio'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM watch_notificacoes WHERE lida = 0")),
    'legenda' => 'aviso(s) não lido(s) no relógio',
    'serie' => est_serie($pdo, 'watch_notificacoes', 'data_hora'), 'serie_legenda' => 'avisos por dia'];

// SISTEMA
$dTot = est_int(est_valor($pdo, "SELECT COUNT(*) FROM dispositivos_cluster"));
$dOn = est_int(est_valor($pdo, "SELECT COUNT(*) FROM dispositivos_cluster WHERE {$online}"));
$e['iot'] = ['valor' => $dTot === null ? null : "{$dOn} / {$dTot}", 'legenda' => 'equipamentos online',
    'serie' => est_serie($pdo, 'device_heartbeats', 'criado_em'), 'serie_legenda' => 'sinais de vida por dia',
    'estado' => ($dTot && $dOn < $dTot) ? 'alerta' : 'ok'];
$nTot = est_int(est_valor($pdo, "SELECT COUNT(*) FROM arm_nodes"));
$nOn = est_int(est_valor($pdo, "SELECT COUNT(*) FROM arm_nodes WHERE ultimo_ping >= NOW() - INTERVAL 40 SECOND"));
$e['nodes'] = ['valor' => $nTot === null ? null : "{$nOn} / {$nTot}", 'legenda' => 'nós online',
    'estado' => ($nTot && $nOn < $nTot) ? 'alerta' : 'ok'];
$versao = est_valor($pdo, "SELECT valor FROM param WHERE chave = 'VERSAO' LIMIT 1");
$e['health'] = ['valor' => $versao === null ? null : 'v' . $versao, 'legenda' => 'versão do banco',
    'estado' => ($versao !== null && $versao === CASA_SCHEMA_VERSION) ? 'ok' : 'alerta'];
$e['telemetria'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM device_events WHERE criado_em >= NOW() - INTERVAL 1 DAY")),
    'legenda' => 'eventos nas últimas 24 h',
    'serie' => est_serie($pdo, 'device_events', 'criado_em'), 'serie_legenda' => 'eventos por dia'];
$e['atualizacoes'] = ['valor' => $nTot, 'legenda' => 'nó(s) acompanhado(s)'];
$apks = glob(dirname(__DIR__) . '/bin/*/*.apk') ?: [];
$e['binarios'] = ['valor' => count($apks), 'legenda' => 'aplicativo(s) disponível(is)'];

// ACESSOS
$e['usuarios'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM usuarios WHERE ativo = 1")), 'legenda' => 'usuário(s) ativo(s)'];
$kAt = est_int(est_valor($pdo, "SELECT COUNT(*) FROM api_client_tokens WHERE ativo = 1 AND revogado_em IS NULL AND (expira_em IS NULL OR expira_em > NOW())"));
$e['api'] = ['valor' => $kAt, 'legenda' => 'chave(s) de acesso ativa(s)'];
$e['agentes'] = ['valor' => est_int(est_valor($pdo, "SELECT COUNT(*) FROM agentes_externos WHERE ativo = 1")), 'legenda' => 'agente(s) ativo(s)'];

// Numeros puros para os graficos: 'num' (valor atual) e 'total' quando o
// valor e do tipo "online / total".
$pares = ['devices' => [$on, $total], 'cameras' => [$cOn, $cTot], 'iot' => [$dOn, $dTot], 'nodes' => [$nOn, $nTot]];
foreach ($e as $id => &$m) {
    if (isset($pares[$id]) && $pares[$id][1] !== null) { $m['num'] = (int)$pares[$id][0]; $m['total'] = (int)$pares[$id][1]; }
    elseif (is_int($m['valor'] ?? null)) $m['num'] = $m['valor'];
}
unset($m);

// Remove o que nao foi possivel calcular.
foreach ($e as $id => $m) {
    if (($m['valor'] ?? null) === null && empty($m['serie'])) unset($e[$id]);
    elseif (isset($m['serie']) && $m['serie'] === null) unset($e[$id]['serie'], $e[$id]['serie_legenda']);
}

echo json_encode(['status' => 'ok', 'gerado_em' => date('c'), 'itens' => $e], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
