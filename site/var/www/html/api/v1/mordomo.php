<?php
// CASA/JARVIS - API v1 do mordomo.
//   POST ?acao=ciclo   roda um ciclo (detecta, decide, avisa/age). Escopo tasks.worker ou mordomo.ciclo.
//                      Chamado periodicamente pelo daemon casa-tarefas (CASA_MORDOMO_INTERVALO).
//   GET  ?acao=estado  observações abertas e decisões recentes. Escopo home.read, tasks.worker ou mordomo.ciclo.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');
require_once(__DIR__ . '/../mordomo.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
$acao = strtolower((string)($_GET['acao'] ?? 'estado'));

try {
    if ($acao === 'ciclo') {
        api_v1_auth_client_any($pdo, ['tasks.worker', 'mordomo.ciclo']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_v1_json_response(405, ['status' => 'erro', 'mensagem' => 'Use POST']);
        // Um ciclo por vez, mesmo com vários daemons chamando.
        if ((int)$pdo->query("SELECT GET_LOCK('casa_mordomo_ciclo', 0)")->fetchColumn() !== 1) {
            api_v1_json_response(200, ['status' => 'ok', 'ocupado' => true]);
        }
        try { $r = md_ciclo($pdo); }
        finally { $pdo->query("SELECT RELEASE_LOCK('casa_mordomo_ciclo')"); }
        if ($r['novas'] || $r['lembretes']) api_v1_log($pdo, 'MORDOMO_CICLO', 'INFO', null, $r);
        api_v1_json_response(200, ['status' => 'ok'] + $r);
    }
    if ($acao === 'estado') {
        api_v1_auth_client_any($pdo, ['home.read', 'tasks.worker', 'mordomo.ciclo']);
        $obs = $pdo->query("SELECT id,tipo,severidade,titulo,status,primeira_vez,ultima_vez,resolvida_em,avisos FROM jarvis_mordomo_observacoes
            ORDER BY status='ABERTA' DESC, ultima_vez DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $dec = $pdo->query("SELECT id,observacao_id,acao,mensagem,ordem,motivo,origem,executado,resultado,criado_em FROM jarvis_mordomo_decisoes
            ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        api_v1_json_response(200, ['status' => 'ok', 'autonomia' => md_config($pdo)['mordomo_autonomia'], 'observacoes' => $obs, 'decisoes' => $dec]);
    }
    api_v1_json_response(404, ['status' => 'erro', 'mensagem' => 'Ação desconhecida. Use: ciclo, estado']);
} catch (Throwable $e) {
    error_log('api/v1/mordomo: ' . $e->getMessage());
    api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Falha no ciclo do mordomo']);
}
