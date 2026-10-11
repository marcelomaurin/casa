<?php
// CASA/JARVIS - Painel Seguranca - Defesa & anti-intrusao (sessao do painel / token).
// GET  ?acao=resumo                      -> contadores e eventos recentes
// GET  ?acao=bloqueados                  -> IPs bloqueados (ativos) e em observacao
// GET  ?acao=eventos&limite&offset&sev&fonte&q -> lista de eventos (painel/hardware + API v1)
// POST {acao:'desbloquear', ip}          -> remove o bloqueio e registra o evento
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/seguranca.php';
verify_api_auth();
$pdo = get_db_pdo();

function sp_out(int $code, array $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Garante que a estrutura das tabelas de seguranca esteja completa e compativel,
 * aplicando automaticamente as colunas da migration 1.32 caso ainda nao existam.
 */
function sp_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // 1. seguranca_ips_bloqueados
    try {
        $colsBloq = $pdo->query("SHOW COLUMNS FROM seguranca_ips_bloqueados")->fetchAll(PDO::FETCH_COLUMN);
        $colSet = array_flip($colsBloq ?: []);
        if (!isset($colSet['bloqueado_em'])) {
            $pdo->exec("ALTER TABLE seguranca_ips_bloqueados ADD COLUMN bloqueado_em DATETIME NULL AFTER bloqueado_ate");
            $pdo->exec("UPDATE seguranca_ips_bloqueados SET bloqueado_em = DATE_SUB(bloqueado_ate, INTERVAL 1 HOUR) WHERE bloqueado_ate IS NOT NULL AND bloqueado_em IS NULL");
        }
        if (!isset($colSet['ultima_falha'])) {
            $pdo->exec("ALTER TABLE seguranca_ips_bloqueados ADD COLUMN ultima_falha DATETIME NULL AFTER bloqueado_em");
            $pdo->exec("UPDATE seguranca_ips_bloqueados SET ultima_falha = atualizado_em WHERE ultima_falha IS NULL");
        }
        if (!isset($colSet['origem'])) {
            $pdo->exec("ALTER TABLE seguranca_ips_bloqueados ADD COLUMN origem VARCHAR(40) NULL AFTER ultima_falha");
        }
    } catch (Throwable $e) {
        error_log('sp_ensure_schema seguranca_ips_bloqueados: ' . $e->getMessage());
    }

    // 2. seguranca_logs
    try {
        $colsLogs = $pdo->query("SHOW COLUMNS FROM seguranca_logs")->fetchAll(PDO::FETCH_COLUMN);
        $colSetLogs = array_flip($colsLogs ?: []);
        if (!isset($colSetLogs['origem'])) {
            $pdo->exec("ALTER TABLE seguranca_logs ADD COLUMN origem VARCHAR(40) NULL AFTER bloqueado");
        }
        if (!isset($colSetLogs['usuario'])) {
            $pdo->exec("ALTER TABLE seguranca_logs ADD COLUMN usuario VARCHAR(120) NULL AFTER origem");
        }
    } catch (Throwable $e) {
        error_log('sp_ensure_schema seguranca_logs: ' . $e->getMessage());
    }

    // 3. api_v1_security_log
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_v1_security_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            correlation_id VARCHAR(64) NULL,
            ip VARCHAR(45) NULL,
            cliente VARCHAR(160) NULL,
            rota VARCHAR(220) NULL,
            metodo VARCHAR(12) NULL,
            evento VARCHAR(80) NOT NULL,
            severidade VARCHAR(20) NOT NULL DEFAULT 'INFO',
            detalhes JSON NULL,
            PRIMARY KEY (id),
            KEY idx_api_security_data (data_hora)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('sp_ensure_schema api_v1_security_log: ' . $e->getMessage());
    }
}

sp_ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$acao = (string)($in['acao'] ?? ($_GET['acao'] ?? 'resumo'));

if ($method === 'POST' && empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['HTTP_X_API_KEY']) && !empty($_SESSION['auth_user'])) {
    $sent = (string)($_SERVER['HTTP_X_CASA_SESSION_TOKEN'] ?? '');
    $tok = (string)($_SESSION['jarvis_session_token'] ?? '');
    if ($sent === '' || $tok === '' || !hash_equals($tok, $sent)) {
        sp_out(403, ['status' => 'erro', 'mensagem' => 'Sessao invalida. Recarregue a pagina.']);
    }
}
$usuario = is_string($_SESSION['auth_user'] ?? null) ? $_SESSION['auth_user'] : 'api';

/** Eventos de seguranca_logs + eventos relevantes (nao-INFO) da API v1, numa so lista. */
function sp_eventos(PDO $pdo, array $f, int $limite, int $offset): array {
    $sevOk = ['INFO', 'AVISO', 'ALTO', 'CRITICO'];
    $eventos = [];

    // Detecta colunas disponiveis em seguranca_logs
    $hasOrigem = false;
    $hasUsuario = false;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM seguranca_logs")->fetchAll(PDO::FETCH_COLUMN);
        $colSet = array_flip($cols ?: []);
        $hasOrigem = isset($colSet['origem']);
        $hasUsuario = isset($colSet['usuario']);
    } catch (Throwable $e) {}

    $colOrigem = $hasOrigem ? "COALESCE(origem,'painel') AS origem" : "'painel' AS origem";
    $colUsuario = $hasUsuario ? "usuario" : "NULL AS usuario";

    // 1. Consulta seguranca_logs
    if (($f['fonte'] ?? 'todos') !== 'api_v1') {
        try {
            $w = []; $params = [];
            if (in_array($f['sev'] ?? '', $sevOk, true)) {
                $w[] = 'severidade = :s1';
                $params[':s1'] = $f['sev'];
            }
            if (($f['q'] ?? '') !== '') {
                $qSearch = '%' . $f['q'] . '%';
                if ($hasUsuario) {
                    $w[] = '(origem_ip LIKE :q1 OR evento LIKE :q1b OR detalhes LIKE :q1c OR usuario LIKE :q1d)';
                    $params[':q1'] = $params[':q1b'] = $params[':q1c'] = $params[':q1d'] = $qSearch;
                } else {
                    $w[] = '(origem_ip LIKE :q1 OR evento LIKE :q1b OR detalhes LIKE :q1c)';
                    $params[':q1'] = $params[':q1b'] = $params[':q1c'] = $qSearch;
                }
            }
            $sqlLogs = "SELECT data_hora, origem_ip AS ip, evento, severidade, detalhes, {$colOrigem}, {$colUsuario}, bloqueado, 'casa' AS fonte FROM seguranca_logs" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY data_hora DESC LIMIT " . ($limite + $offset);
            $st = $pdo->prepare($sqlLogs);
            $st->execute($params);
            $eventos = array_merge($eventos, $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('sp_eventos seguranca_logs: ' . $e->getMessage());
        }
    }

    // 2. Consulta api_v1_security_log (se existir e for solicitado)
    if (($f['fonte'] ?? 'todos') !== 'casa') {
        try {
            $w = ["severidade <> 'INFO'"]; $params = [];
            if (in_array($f['sev'] ?? '', $sevOk, true)) {
                $w[] = 'severidade = :s2';
                $params[':s2'] = $f['sev'];
            }
            if (($f['q'] ?? '') !== '') {
                $qSearch = '%' . $f['q'] . '%';
                $w[] = '(ip LIKE :q2 OR evento LIKE :q2b OR cliente LIKE :q2c OR rota LIKE :q2d)';
                $params[':q2'] = $params[':q2b'] = $params[':q2c'] = $params[':q2d'] = $qSearch;
            }
            $sqlApi = "SELECT data_hora, ip, evento, severidade, CONCAT_WS(' • ', NULLIF(CONCAT(COALESCE(metodo,''),' ',COALESCE(rota,'')),' '), CAST(detalhes AS CHAR)) AS detalhes, 'api_v1' AS origem, cliente AS usuario, 0 AS bloqueado, 'api_v1' AS fonte FROM api_v1_security_log WHERE " . implode(' AND ', $w) . " ORDER BY data_hora DESC LIMIT " . ($limite + $offset);
            $st = $pdo->prepare($sqlApi);
            $st->execute($params);
            $eventos = array_merge($eventos, $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('sp_eventos api_v1_security_log: ' . $e->getMessage());
        }
    }

    // Ordena uniao em memoria por data_hora DESC
    usort($eventos, function($a, $b) {
        return strcmp($b['data_hora'] ?? '', $a['data_hora'] ?? '');
    });

    return array_slice($eventos, $offset, $limite);
}

function sp_count(PDO $pdo, string $sql): int {
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

try {
    if ($method === 'GET' && $acao === 'resumo') {
        $bloqAtivos = sp_count($pdo, "SELECT COUNT(*) FROM seguranca_ips_bloqueados WHERE bloqueado_ate > NOW()");
        $emObs = sp_count($pdo, "SELECT COUNT(*) FROM seguranca_ips_bloqueados WHERE (bloqueado_ate IS NULL OR bloqueado_ate <= NOW()) AND (ultima_falha >= DATE_SUB(NOW(), INTERVAL " . SEG_JANELA_MIN . " MINUTE) OR atualizado_em >= DATE_SUB(NOW(), INTERVAL " . SEG_JANELA_MIN . " MINUTE))");
        $ev24h = sp_count($pdo, "SELECT COUNT(*) FROM seguranca_logs WHERE data_hora >= DATE_SUB(NOW(), INTERVAL 1 DAY)")
            + sp_count($pdo, "SELECT COUNT(*) FROM api_v1_security_log WHERE severidade <> 'INFO' AND data_hora >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $crit24h = sp_count($pdo, "SELECT COUNT(*) FROM seguranca_logs WHERE severidade IN ('CRITICO','ALTO') AND data_hora >= DATE_SUB(NOW(), INTERVAL 1 DAY)")
            + sp_count($pdo, "SELECT COUNT(*) FROM api_v1_security_log WHERE severidade IN ('CRITICO','ALTO') AND data_hora >= DATE_SUB(NOW(), INTERVAL 1 DAY)");

        $recentes = sp_eventos($pdo, [], 6, 0);

        sp_out(200, [
            'status' => 'ok',
            'bloqueados_ativos' => $bloqAtivos,
            'em_observacao' => $emObs,
            'eventos_24h' => $ev24h,
            'criticos_24h' => $crit24h,
            'recentes' => $recentes,
            'regra' => [
                'falhas' => SEG_MAX_FALHAS,
                'janela_min' => SEG_JANELA_MIN,
                'bloqueio_min' => SEG_BLOQUEIO_MIN
            ],
        ]);
    }

    if ($method === 'GET' && $acao === 'bloqueados') {
        $ativos = [];
        $obs = [];
        $hist = [];

        // Ativos
        try {
            $ativos = $pdo->query("SELECT ip_address AS ip, motivo, tentativas_falhas AS tentativas, COALESCE(bloqueado_em, DATE_SUB(bloqueado_ate, INTERVAL " . SEG_BLOQUEIO_MIN . " MINUTE)) AS bloqueado_em, bloqueado_ate, COALESCE(origem, 'painel') AS origem, GREATEST(0, TIMESTAMPDIFF(MINUTE, NOW(), bloqueado_ate)) AS restante_min FROM seguranca_ips_bloqueados WHERE bloqueado_ate > NOW() ORDER BY bloqueado_ate DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            try {
                $ativos = $pdo->query("SELECT ip_address AS ip, motivo, tentativas_falhas AS tentativas, DATE_SUB(bloqueado_ate, INTERVAL " . SEG_BLOQUEIO_MIN . " MINUTE) AS bloqueado_em, bloqueado_ate, 'painel' AS origem, GREATEST(0, TIMESTAMPDIFF(MINUTE, NOW(), bloqueado_ate)) AS restante_min FROM seguranca_ips_bloqueados WHERE bloqueado_ate > NOW() ORDER BY bloqueado_ate DESC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e2) {}
        }

        // Em observacao
        try {
            $obs = $pdo->query("SELECT ip_address AS ip, motivo, tentativas_falhas AS tentativas, COALESCE(ultima_falha, atualizado_em) AS ultima_falha, COALESCE(origem, 'painel') AS origem FROM seguranca_ips_bloqueados WHERE (bloqueado_ate IS NULL OR bloqueado_ate <= NOW()) ORDER BY COALESCE(ultima_falha, atualizado_em) DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            try {
                $obs = $pdo->query("SELECT ip_address AS ip, motivo, tentativas_falhas AS tentativas, atualizado_em AS ultima_falha, 'painel' AS origem FROM seguranca_ips_bloqueados WHERE (bloqueado_ate IS NULL OR bloqueado_ate <= NOW()) ORDER BY atualizado_em DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e2) {}
        }

        // Historico de bloqueios
        try {
            $hist = $pdo->query("SELECT data_hora, origem_ip AS ip, detalhes FROM seguranca_logs WHERE evento = 'BLOQUEIO_IP_AUTOMATICO' ORDER BY data_hora DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        sp_out(200, [
            'status' => 'ok',
            'ativos' => $ativos ?: [],
            'observacao' => $obs ?: [],
            'historico' => $hist ?: [],
            'regra' => [
                'falhas' => SEG_MAX_FALHAS,
                'janela_min' => SEG_JANELA_MIN,
                'bloqueio_min' => SEG_BLOQUEIO_MIN
            ]
        ]);
    }

    if ($method === 'GET' && $acao === 'eventos') {
        $limite = max(1, min(200, (int)($_GET['limite'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $f = [
            'sev' => strtoupper(trim((string)($_GET['sev'] ?? ''))),
            'fonte' => (string)($_GET['fonte'] ?? 'todos'),
            'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80)
        ];
        $rows = sp_eventos($pdo, $f, $limite + 1, $offset);
        $mais = count($rows) > $limite;
        sp_out(200, [
            'status' => 'ok',
            'eventos' => array_slice($rows, 0, $limite),
            'mais' => $mais,
            'offset' => $offset,
            'limite' => $limite
        ]);
    }

    if ($method === 'POST' && $acao === 'desbloquear') {
        $ip = trim((string)($in['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            sp_out(400, ['status' => 'erro', 'mensagem' => 'IP invalido.']);
        }
        $st = $pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address = :ip");
        $st->execute([':ip' => $ip]);
        registrar_evento_seguranca($ip, 'DESBLOQUEIO_MANUAL', 'IP desbloqueado pelo administrador no painel.', 'INFO', false, 'painel', $usuario);
        sp_out(200, ['status' => 'ok', 'removido' => $st->rowCount() > 0]);
    }

    sp_out(400, ['status' => 'erro', 'mensagem' => 'Acao desconhecida.']);
} catch (Throwable $e) {
    error_log('seguranca_painel.php: ' . $e->getMessage());
    sp_out(500, ['status' => 'erro', 'mensagem' => 'Falha ao consultar a seguranca: ' . $e->getMessage()]);
}
