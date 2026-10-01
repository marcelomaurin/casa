<?php
// CASA/JARVIS - Painel Segurança › Defesa & anti-intrusão (sessão do painel).
// GET  ?acao=resumo                      -> contadores e eventos recentes
// GET  ?acao=bloqueados                  -> IPs bloqueados (ativos) e em observação
// GET  ?acao=eventos&limite&offset&sev&fonte&q -> lista de eventos (painel/hardware + API v1)
// POST {acao:'desbloquear', ip}          -> remove o bloqueio e registra o evento
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/seguranca.php';
verify_api_auth();
$pdo = get_db_pdo();

function sp_out(int $code, array $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$acao = (string)($in['acao'] ?? ($_GET['acao'] ?? 'resumo'));

if ($method === 'POST' && empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['HTTP_X_API_KEY']) && !empty($_SESSION['auth_user'])) {
    $sent = (string)($_SERVER['HTTP_X_CASA_SESSION_TOKEN'] ?? '');
    $tok = (string)($_SESSION['jarvis_session_token'] ?? '');
    if ($sent === '' || $tok === '' || !hash_equals($tok, $sent)) sp_out(403, ['status' => 'erro', 'mensagem' => 'Sessão inválida. Recarregue a página.']);
}
$usuario = is_string($_SESSION['auth_user'] ?? null) ? $_SESSION['auth_user'] : 'api';

/** Eventos de seguranca_logs + eventos relevantes (não-INFO) da API v1, numa só lista. */
function sp_eventos(PDO $pdo, array $f, int $limite, int $offset): array {
    $sevOk = ['INFO', 'AVISO', 'ALTO', 'CRITICO'];
    $partes = []; $params = [];
    if (($f['fonte'] ?? 'todos') !== 'api_v1') {
        $w = [];
        if (in_array($f['sev'] ?? '', $sevOk, true)) { $w[] = 'severidade = :s1'; $params[':s1'] = $f['sev']; }
        if (($f['q'] ?? '') !== '') { $w[] = '(origem_ip LIKE :q1 OR evento LIKE :q1b OR detalhes LIKE :q1c OR usuario LIKE :q1d)'; $params[':q1'] = $params[':q1b'] = $params[':q1c'] = $params[':q1d'] = '%' . $f['q'] . '%'; }
        $partes[] = "SELECT data_hora, origem_ip AS ip, evento, severidade, detalhes, COALESCE(origem,'painel') AS origem, usuario, bloqueado, 'casa' AS fonte FROM seguranca_logs" . ($w ? ' WHERE ' . implode(' AND ', $w) : '');
    }
    if (($f['fonte'] ?? 'todos') !== 'casa') {
        $w = ["severidade <> 'INFO'"];
        if (in_array($f['sev'] ?? '', $sevOk, true)) { $w[] = 'severidade = :s2'; $params[':s2'] = $f['sev']; }
        if (($f['q'] ?? '') !== '') { $w[] = '(ip LIKE :q2 OR evento LIKE :q2b OR cliente LIKE :q2c OR rota LIKE :q2d)'; $params[':q2'] = $params[':q2b'] = $params[':q2c'] = $params[':q2d'] = '%' . $f['q'] . '%'; }
        $partes[] = "SELECT data_hora, ip, evento, severidade, CONCAT_WS(' · ', NULLIF(CONCAT(COALESCE(metodo,''),' ',COALESCE(rota,'')),' '), CAST(detalhes AS CHAR)) AS detalhes, 'api_v1' AS origem, cliente AS usuario, 0 AS bloqueado, 'api_v1' AS fonte FROM api_v1_security_log WHERE " . implode(' AND ', $w);
    }
    $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $partes) . ') e ORDER BY data_hora DESC LIMIT ' . $limite . ' OFFSET ' . $offset;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function sp_count(PDO $pdo, string $sql): int { try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } }

try {
    if ($method === 'GET' && $acao === 'resumo') {
        sp_out(200, ['status' => 'ok',
            'bloqueados_ativos' => sp_count($pdo, "SELECT COUNT(*) FROM seguranca_ips_bloqueados WHERE bloqueado_ate > NOW()"),
            'em_observacao' => sp_count($pdo, "SELECT COUNT(*) FROM seguranca_ips_bloqueados WHERE (bloqueado_ate IS NULL OR bloqueado_ate <= NOW()) AND ultima_falha >= DATE_SUB(NOW(), INTERVAL " . SEG_JANELA_MIN . " MINUTE)"),
            'eventos_24h' => sp_count($pdo, "SELECT COUNT(*) FROM seguranca_logs WHERE data_hora >= DATE_SUB(NOW(), INTERVAL 1 DAY)")
                + sp_count($pdo, "SELECT COUNT(*) FROM api_v1_security_log WHERE severidade <> 'INFO' AND data_hora >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
            'criticos_24h' => sp_count($pdo, "SELECT COUNT(*) FROM seguranca_logs WHERE severidade IN ('CRITICO','ALTO') AND data_hora >= DATE_SUB(NOW(), INTERVAL 1 DAY)")
                + sp_count($pdo, "SELECT COUNT(*) FROM api_v1_security_log WHERE severidade IN ('CRITICO','ALTO') AND data_hora >= DATE_SUB(NOW(), INTERVAL 1 DAY)"),
            'recentes' => sp_eventos($pdo, [], 6, 0),
            'regra' => ['falhas' => SEG_MAX_FALHAS, 'janela_min' => SEG_JANELA_MIN, 'bloqueio_min' => SEG_BLOQUEIO_MIN],
        ]);
    }
    if ($method === 'GET' && $acao === 'bloqueados') {
        $ativos = $pdo->query("SELECT ip_address AS ip, motivo, tentativas_falhas AS tentativas, COALESCE(bloqueado_em, DATE_SUB(bloqueado_ate, INTERVAL " . SEG_BLOQUEIO_MIN . " MINUTE)) AS bloqueado_em, bloqueado_ate, origem, GREATEST(0, TIMESTAMPDIFF(MINUTE, NOW(), bloqueado_ate)) AS restante_min FROM seguranca_ips_bloqueados WHERE bloqueado_ate > NOW() ORDER BY bloqueado_em DESC")->fetchAll(PDO::FETCH_ASSOC);
        $obs = $pdo->query("SELECT ip_address AS ip, motivo, tentativas_falhas AS tentativas, COALESCE(ultima_falha, atualizado_em) AS ultima_falha, origem FROM seguranca_ips_bloqueados WHERE (bloqueado_ate IS NULL OR bloqueado_ate <= NOW()) ORDER BY COALESCE(ultima_falha, atualizado_em) DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $hist = $pdo->query("SELECT data_hora, origem_ip AS ip, detalhes FROM seguranca_logs WHERE evento = 'BLOQUEIO_IP_AUTOMATICO' ORDER BY data_hora DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        sp_out(200, ['status' => 'ok', 'ativos' => $ativos, 'observacao' => $obs, 'historico' => $hist,
            'regra' => ['falhas' => SEG_MAX_FALHAS, 'janela_min' => SEG_JANELA_MIN, 'bloqueio_min' => SEG_BLOQUEIO_MIN]]);
    }
    if ($method === 'GET' && $acao === 'eventos') {
        $limite = max(1, min(200, (int)($_GET['limite'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $f = ['sev' => strtoupper(trim((string)($_GET['sev'] ?? ''))), 'fonte' => (string)($_GET['fonte'] ?? 'todos'), 'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80)];
        $rows = sp_eventos($pdo, $f, $limite + 1, $offset);
        $mais = count($rows) > $limite;
        sp_out(200, ['status' => 'ok', 'eventos' => array_slice($rows, 0, $limite), 'mais' => $mais, 'offset' => $offset, 'limite' => $limite]);
    }
    if ($method === 'POST' && $acao === 'desbloquear') {
        $ip = trim((string)($in['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) sp_out(400, ['status' => 'erro', 'mensagem' => 'IP inválido.']);
        $st = $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address = :ip");
        $st->execute([':ip' => $ip]);
        registrar_evento_seguranca($ip, 'DESBLOQUEIO_MANUAL', 'IP desbloqueado pelo administrador no painel.', 'INFO', false, 'painel', $usuario);
        sp_out(200, ['status' => 'ok', 'removido' => $st->rowCount() > 0]);
    }
    sp_out(400, ['status' => 'erro', 'mensagem' => 'Ação desconhecida.']);
} catch (Throwable $e) {
    error_log('seguranca_painel.php: ' . $e->getMessage());
    sp_out(500, ['status' => 'erro', 'mensagem' => 'Falha ao consultar a segurança.']);
}
