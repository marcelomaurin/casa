<?php
// CASA/JARVIS - API do painel web para Cenas e Regras (sessão do painel).
//
// GET  ?acao=estado                     -> dispositivos, cenas e regras (com ações)
// POST {acao:'cena_salvar', ...}        -> cria/atualiza cena e suas ações
// POST {acao:'cena_excluir', id}
// POST {acao:'cena_ativar', id, ativo}
// POST {acao:'cena_executar', id, confirmar}
// POST {acao:'regra_salvar', ...}       -> cria/atualiza regra (gatilho evento/estado)
// POST {acao:'regra_excluir', id}
// POST {acao:'regra_ativar', id, ativo}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/automation_common.php';
verify_api_auth();
$pdo = get_db_pdo();

function au_out(int $code, array $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function au_slug(PDO $pdo, string $table, string $name, int $ignoreId): string {
    $s = strtolower(trim($name));
    $a = @iconv('UTF-8', 'ASCII//TRANSLIT', $s); if ($a !== false) $s = $a;
    $s = trim(preg_replace('/[^a-z0-9\-]+/', '-', $s), '-') ?: 'item';
    $s = substr($s, 0, 100);
    $base = $s; $n = 2;
    $st = $pdo->prepare("SELECT id FROM $table WHERE slug=:s AND id<>:id LIMIT 1");
    while (true) { $st->execute([':s' => $s, ':id' => $ignoreId]); if (!$st->fetchColumn()) return $s; $s = $base . '-' . $n++; }
}

/** Valida e normaliza a lista de ações vinda do editor. */
function au_actions(PDO $pdo, $list): array {
    if (!is_array($list)) return [];
    $out = []; $order = 10;
    $chk = $pdo->prepare("SELECT device_id FROM dispositivos_cluster WHERE device_id=:d LIMIT 1");
    foreach ($list as $a) {
        if (!is_array($a)) continue;
        $dev = trim((string)($a['device_id'] ?? ''));
        $cmd = substr(trim((string)($a['comando'] ?? $a['command'] ?? '')), 0, 120);
        if ($dev === '' || $cmd === '') continue;
        $chk->execute([':d' => $dev]);
        if (!$chk->fetchColumn()) au_out(400, ['status' => 'erro', 'mensagem' => 'Dispositivo não encontrado: ' . $dev]);
        $payload = $a['payload'] ?? [];
        if (is_string($payload)) { $payload = trim($payload) === '' ? [] : json_decode($payload, true); if (!is_array($payload)) au_out(400, ['status' => 'erro', 'mensagem' => 'Parâmetros (JSON) inválidos no comando ' . $cmd]); }
        if (!is_array($payload)) $payload = [];
        $out[] = ['ordem' => $order, 'device_id' => $dev, 'comando' => $cmd, 'payload' => $payload,
            'risk_level' => max(0, min(4, (int)($a['risk_level'] ?? 1))), 'ttl_seconds' => max(10, min(86400, (int)($a['ttl_seconds'] ?? 300)))];
        $order += 10;
    }
    return $out;
}
function au_json($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$acao = (string)($in['acao'] ?? ($_GET['acao'] ?? 'estado'));

// Escritas a partir do painel exigem o token aleatório da sessão (proteção CSRF).
if ($method === 'POST' && empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['HTTP_X_API_KEY']) && !empty($_SESSION['auth_user'])) {
    $sent = (string)($_SERVER['HTTP_X_CASA_SESSION_TOKEN'] ?? '');
    $tok = (string)($_SESSION['jarvis_session_token'] ?? '');
    if ($sent === '' || $tok === '' || !hash_equals($tok, $sent)) au_out(403, ['status' => 'erro', 'mensagem' => 'Sessão inválida. Recarregue a página.']);
}
$who = 'WEB:' . substr((string)($_SESSION['auth_user'] ?? 'api'), 0, 60);

try {
    if ($method === 'GET' && $acao === 'estado') {
        $devices = automation_action_devices($pdo);
        $scenes = $pdo->query("SELECT id,slug,nome,descricao,ativo,stop_on_error,risk_level,atualizado_em FROM scenes ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
        $lastRun = $pdo->prepare("SELECT id,status,requested_by,criado_em FROM scene_runs WHERE scene_id=:s ORDER BY id DESC LIMIT 1");
        foreach ($scenes as &$s) {
            $s['id'] = (int)$s['id']; $s['ativo'] = (bool)$s['ativo']; $s['stop_on_error'] = (bool)$s['stop_on_error']; $s['risk_level'] = (int)$s['risk_level'];
            $s['actions'] = automation_scene_actions($pdo, $s['id']);
            $lastRun->execute([':s' => $s['id']]); $s['last_run'] = $lastRun->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        unset($s);
        $rules = $pdo->query("SELECT id,slug,nome,descricao,enabled,trigger_type,trigger_config,conditions_json,cooldown_seconds,last_triggered_at FROM automation_rules ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
        $ra = $pdo->prepare("SELECT id,ordem,device_id,comando,payload,risk_level,ttl_seconds,enabled FROM automation_rule_actions WHERE rule_id=:r ORDER BY ordem,id");
        foreach ($rules as &$r) {
            $r['id'] = (int)$r['id']; $r['enabled'] = (bool)$r['enabled']; $r['cooldown_seconds'] = (int)$r['cooldown_seconds'];
            $r['trigger_config'] = automation_json($r['trigger_config'] ?? null, []);
            $r['conditions'] = automation_json($r['conditions_json'] ?? null, []); unset($r['conditions_json']);
            $ra->execute([':r' => $r['id']]);
            $r['actions'] = array_map(function ($a) { $a['payload'] = automation_json($a['payload'] ?? null, []); $a['enabled'] = (bool)$a['enabled']; return $a; }, $ra->fetchAll(PDO::FETCH_ASSOC));
        }
        unset($r);
        $events = [];
        try { $events = $pdo->query("SELECT device_id,tipo,MAX(criado_em) ultimo FROM device_events WHERE criado_em>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY device_id,tipo ORDER BY device_id,tipo LIMIT 300")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        au_out(200, ['status' => 'ok', 'devices' => $devices, 'scenes' => $scenes, 'rules' => $rules, 'events' => $events]);
    }

    if ($method !== 'POST') au_out(405, ['status' => 'erro', 'mensagem' => 'Método não permitido.']);
    $id = (int)($in['id'] ?? 0);

    if ($acao === 'cena_salvar') {
        $nome = trim((string)($in['nome'] ?? ''));
        if ($nome === '') au_out(400, ['status' => 'erro', 'mensagem' => 'Informe o nome da cena.']);
        $actions = au_actions($pdo, $in['actions'] ?? []);
        if (!$actions) au_out(400, ['status' => 'erro', 'mensagem' => 'Adicione pelo menos uma ação.']);
        $desc = trim((string)($in['descricao'] ?? ''));
        $ativo = array_key_exists('ativo', $in) ? (int)(bool)$in['ativo'] : 1;
        $stop = (int)(bool)($in['stop_on_error'] ?? false);
        $risk = max(0, min(4, (int)($in['risk_level'] ?? 1)));
        $pdo->beginTransaction();
        $slug = au_slug($pdo, 'scenes', $nome, $id);
        if ($id > 0) {
            $pdo->prepare('UPDATE scenes SET slug=:s,nome=:n,descricao=:d,ativo=:a,stop_on_error=:e,risk_level=:r WHERE id=:id')->execute([':s' => $slug, ':n' => $nome, ':d' => $desc, ':a' => $ativo, ':e' => $stop, ':r' => $risk, ':id' => $id]);
        } else {
            $pdo->prepare('INSERT INTO scenes(slug,nome,descricao,ativo,stop_on_error,risk_level) VALUES(:s,:n,:d,:a,:e,:r)')->execute([':s' => $slug, ':n' => $nome, ':d' => $desc, ':a' => $ativo, ':e' => $stop, ':r' => $risk]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM scene_actions WHERE scene_id=:id')->execute([':id' => $id]);
        $ins = $pdo->prepare('INSERT INTO scene_actions(scene_id,ordem,device_id,comando,payload,prioridade,risk_level,ttl_seconds,enabled) VALUES(:s,:o,:d,:c,:p,\'normal\',:r,:t,1)');
        foreach ($actions as $a) $ins->execute([':s' => $id, ':o' => $a['ordem'], ':d' => $a['device_id'], ':c' => $a['comando'], ':p' => au_json($a['payload']), ':r' => $a['risk_level'], ':t' => $a['ttl_seconds']]);
        $pdo->commit();
        au_out(200, ['status' => 'ok', 'id' => $id, 'mensagem' => 'Cena salva.']);
    }
    if ($acao === 'cena_excluir') {
        $pdo->prepare('DELETE FROM scenes WHERE id=:id')->execute([':id' => $id]);
        au_out(200, ['status' => 'ok']);
    }
    if ($acao === 'cena_ativar') {
        $pdo->prepare('UPDATE scenes SET ativo=:a WHERE id=:id')->execute([':a' => (int)(bool)($in['ativo'] ?? true), ':id' => $id]);
        au_out(200, ['status' => 'ok']);
    }
    if ($acao === 'cena_executar') {
        $scene = automation_scene_load($pdo, $id);
        if (!$scene) au_out(404, ['status' => 'erro', 'mensagem' => 'Cena não encontrada.']);
        $r = automation_scene_execute($pdo, $scene, $who, !empty($in['confirmar']));
        au_out($r['http'] === 202 ? 200 : $r['http'], $r['body']);
    }

    if ($acao === 'regra_salvar') {
        $nome = trim((string)($in['nome'] ?? ''));
        if ($nome === '') au_out(400, ['status' => 'erro', 'mensagem' => 'Informe o nome da regra.']);
        $type = (string)($in['trigger_type'] ?? 'event');
        if (!in_array($type, ['event', 'state'], true)) au_out(400, ['status' => 'erro', 'mensagem' => 'Para horários, use Operações > Agendamentos.']);
        $src = trim((string)($in['trigger_device_id'] ?? ''));
        if ($src === '') au_out(400, ['status' => 'erro', 'mensagem' => 'Escolha o dispositivo que dispara a regra.']);
        $trigger = ['device_id' => $src];
        $evt = trim((string)($in['trigger_event_type'] ?? ''));
        if ($type === 'event' && $evt !== '') $trigger['event_type'] = substr($evt, 0, 120);
        $ops = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'exists'];
        $conds = [];
        foreach ((array)($in['conditions'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $f = trim((string)($c['field'] ?? '')); if ($f === '') continue;
            if (strpos($f, '.') === false) $f = 'data.' . $f;
            $op = (string)($c['op'] ?? 'eq'); if (!in_array($op, $ops, true)) $op = 'eq';
            $v = $c['value'] ?? null;
            if (is_string($v)) { $t = trim($v); $lv = strtolower($t);
                if ($lv === 'true' || $lv === 'ligado') $v = true; elseif ($lv === 'false' || $lv === 'desligado') $v = false; elseif (is_numeric($t)) $v = $t + 0; else $v = $t; }
            $conds[] = ['field' => $f, 'op' => $op, 'value' => $v];
        }
        if ($type === 'state' && !$conds) au_out(400, ['status' => 'erro', 'mensagem' => 'Regras por estado precisam de pelo menos uma condição.']);
        $actions = au_actions($pdo, $in['actions'] ?? []);
        if (!$actions) au_out(400, ['status' => 'erro', 'mensagem' => 'Adicione pelo menos uma ação.']);
        foreach ($actions as $a) if ($a['risk_level'] >= 3) au_out(400, ['status' => 'erro', 'mensagem' => 'Regras automáticas não podem ter ações de risco 3 ou maior.']);
        $desc = trim((string)($in['descricao'] ?? ''));
        $enabled = array_key_exists('enabled', $in) ? (int)(bool)$in['enabled'] : 1;
        $cool = max(0, min(86400, (int)($in['cooldown_seconds'] ?? 60)));
        $pdo->beginTransaction();
        $slug = au_slug($pdo, 'automation_rules', $nome, $id);
        $p = [':s' => $slug, ':n' => $nome, ':d' => $desc, ':e' => $enabled, ':t' => $type, ':tc' => au_json($trigger), ':c' => au_json($conds), ':cd' => $cool];
        if ($id > 0) { $p[':id'] = $id; $pdo->prepare('UPDATE automation_rules SET slug=:s,nome=:n,descricao=:d,enabled=:e,trigger_type=:t,trigger_config=:tc,conditions_json=:c,cooldown_seconds=:cd WHERE id=:id')->execute($p); }
        else { $pdo->prepare('INSERT INTO automation_rules(slug,nome,descricao,enabled,trigger_type,trigger_config,conditions_json,cooldown_seconds) VALUES(:s,:n,:d,:e,:t,:tc,:c,:cd)')->execute($p); $id = (int)$pdo->lastInsertId(); }
        $pdo->prepare('DELETE FROM automation_rule_actions WHERE rule_id=:id')->execute([':id' => $id]);
        $ins = $pdo->prepare('INSERT INTO automation_rule_actions(rule_id,ordem,device_id,comando,payload,prioridade,risk_level,ttl_seconds,enabled) VALUES(:r,:o,:d,:c,:p,\'normal\',:k,:t,1)');
        foreach ($actions as $a) $ins->execute([':r' => $id, ':o' => $a['ordem'], ':d' => $a['device_id'], ':c' => $a['comando'], ':p' => au_json($a['payload']), ':k' => $a['risk_level'], ':t' => $a['ttl_seconds']]);
        $pdo->commit();
        au_out(200, ['status' => 'ok', 'id' => $id, 'mensagem' => 'Regra salva.']);
    }
    if ($acao === 'regra_excluir') {
        $pdo->prepare('DELETE FROM automation_rules WHERE id=:id')->execute([':id' => $id]);
        au_out(200, ['status' => 'ok']);
    }
    if ($acao === 'regra_ativar') {
        $pdo->prepare('UPDATE automation_rules SET enabled=:e WHERE id=:id')->execute([':e' => (int)(bool)($in['ativo'] ?? true), ':id' => $id]);
        au_out(200, ['status' => 'ok']);
    }
    au_out(400, ['status' => 'erro', 'mensagem' => 'Ação desconhecida.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('automacao.php: ' . $e->getMessage());
    au_out(500, ['status' => 'erro', 'mensagem' => 'Falha ao processar a automação.']);
}
