<?php
// CASA/JARVIS - API v1 da fila de tarefas (daemons e agentes externos).
//
// Autenticação: chave de cliente da API v1 com escopo "tasks.worker" (ou a chave mestra).
// Todas as ações são POST com JSON; "trabalhador" identifica o daemon/agente (ex.: "pi-sala").
//
//   acao=reservar  {trabalhador, executores:[...], filas:[...], limite, prazo_segundos, versao, host}
//   acao=renovar   {trabalhador, id, prazo_segundos}
//   acao=concluir  {trabalhador, id, resultado}
//   acao=falhar    {trabalhador, id, erro, repetir:true|false, resultado}
//   acao=liberar   {trabalhador, id}
//   acao=dividir   {trabalhador, id, partes:[{titulo, executor, fila, payload, depende_da_parte}]}
//   acao=status    (GET) {id_plano} -> situação das tarefas do plano
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');
require_once(__DIR__ . '/../fila_tarefas.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
$client = api_v1_auth_client_any($pdo, ['tasks.worker']);

$acao = strtolower((string)($_GET['acao'] ?? ''));
$in = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    $in = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($in)) api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'JSON inválido']);
    if ($acao === '') $acao = strtolower((string)($in['acao'] ?? ''));
}

try {
    if ($acao === 'status') {
        $plano = (int)($_GET['id_plano'] ?? ($in['id_plano'] ?? 0));
        if ($plano <= 0) throw new FilaErro('id_plano obrigatório');
        $st = $pdo->prepare("SELECT id,titulo,executor,fila,status,tentativas,max_tentativas,reservado_por,depende_de,tarefa_pai_id,
                executar_em,iniciado_em,concluido_em,erro FROM jarvis_tarefas WHERE id_plano=:p ORDER BY ordem,id");
        $st->execute([':p' => $plano]);
        $p = $pdo->prepare("SELECT id,status,resumo,criado_em,concluido_em FROM jarvis_planos WHERE id=:p");
        $p->execute([':p' => $plano]);
        api_v1_json_response(200, ['status' => 'ok', 'plano' => $p->fetch(PDO::FETCH_ASSOC) ?: null, 'tarefas' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new FilaErro('Use POST', 405);

    $trab = trim((string)($in['trabalhador'] ?? ''));
    if (!ft_nome_valido($trab)) throw new FilaErro('Campo trabalhador obrigatório (letras, números, . _ : -; até 80)');
    $id = (int)($in['id'] ?? 0);
    $exigeId = in_array($acao, ['renovar', 'concluir', 'falhar', 'liberar', 'dividir'], true);
    if ($exigeId && $id <= 0) throw new FilaErro('Campo id obrigatório');

    switch ($acao) {
        case 'reservar':
            $executores = ft_lista_nomes($in['executores'] ?? [], 'executores');
            $filas = ft_lista_nomes($in['filas'] ?? [], 'filas');
            ft_registrar_trabalhador($pdo, $trab, $executores, $filas, $client['nome'] ?? null, [
                'host' => $in['host'] ?? null, 'versao' => $in['versao'] ?? null,
            ]);
            $tarefas = ft_reservar($pdo, $trab, $executores, $filas, (int)($in['limite'] ?? 1), (int)($in['prazo_segundos'] ?? 300));
            if ($tarefas) api_v1_log($pdo, 'FILA_RESERVA', 'INFO', $client['nome'] ?? null, ['trabalhador' => $trab, 'ids' => array_column($tarefas, 'id')]);
            api_v1_json_response(200, ['status' => 'ok', 'tarefas' => $tarefas, 'server_time' => date('c')]);

        case 'renovar':
            $ate = ft_renovar($pdo, $id, $trab, (int)($in['prazo_segundos'] ?? 300));
            api_v1_json_response(200, ['status' => 'ok', 'reservado_ate' => $ate]);

        case 'concluir':
            ft_concluir($pdo, $id, $trab, $in['resultado'] ?? null);
            api_v1_json_response(200, ['status' => 'ok']);

        case 'falhar':
            $novo = ft_falhar($pdo, $id, $trab, (string)($in['erro'] ?? ''), ($in['repetir'] ?? true) !== false, $in['resultado'] ?? null);
            api_v1_json_response(200, ['status' => 'ok', 'tarefa_status' => $novo]);

        case 'liberar':
            ft_liberar($pdo, $id, $trab);
            api_v1_json_response(200, ['status' => 'ok']);

        case 'dividir':
            $ids = ft_dividir($pdo, $id, $trab, is_array($in['partes'] ?? null) ? $in['partes'] : []);
            api_v1_json_response(200, ['status' => 'ok', 'partes' => $ids]);
    }
    throw new FilaErro('Ação desconhecida. Use: reservar, renovar, concluir, falhar, liberar, dividir, status', 404);
} catch (FilaErro $e) {
    api_v1_json_response($e->http, ['status' => 'erro', 'mensagem' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api/v1/fila: ' . $e->getMessage());
    api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Falha interna na fila de tarefas']);
}
