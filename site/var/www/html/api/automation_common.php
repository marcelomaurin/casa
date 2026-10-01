<?php
// CASA/JARVIS - Funções compartilhadas de automação (cenas, regras e agendamentos).
//
// Ponto central: comandos de liga/desliga enviados a dispositivos com capacidade
// "relay"/"switch" são convertidos em estado desejado (device_desired_state), que
// é o que os firmwares de relé (ex.: ESP01 Relay) consultam. O comando continua
// registrado em device_commands para histórico, já marcado como concluído, para
// que nenhum outro consumidor o reexecute.

if (!function_exists('automation_json')) {

function automation_json($value, $fallback = []) {
    if (is_array($value)) return $value;
    if (!is_string($value) || $value === '') return $fallback;
    $d = json_decode($value, true);
    return is_array($d) ? $d : $fallback;
}

/** Comandos reconhecidos para relés. Retorna true/false, 'toggle' ou null (não é comando de relé). */
function automation_relay_intent(string $command, array $payload) {
    $c = strtolower(trim($command));
    if (in_array($c, ['power_on','on','turn_on','relay_on','switch_on','ligar'], true)) return true;
    if (in_array($c, ['power_off','off','turn_off','relay_off','switch_off','desligar'], true)) return false;
    if (in_array($c, ['toggle','relay_toggle','alternar'], true)) return 'toggle';
    if (in_array($c, ['set_relay','relay','set_state','set_power'], true)) {
        foreach (['relay_on','on','state','power'] as $k) {
            if (array_key_exists($k, $payload)) {
                $v = $payload[$k];
                if (is_bool($v)) return $v;
                if (is_numeric($v)) return ((int)$v) === 1;
                $s = strtolower((string)$v);
                if (in_array($s, ['on','true','ligado','ligar','1'], true)) return true;
                if (in_array($s, ['off','false','desligado','desligar','0'], true)) return false;
            }
        }
    }
    return null;
}

function automation_device_row(PDO $pdo, string $deviceId): ?array {
    $st = $pdo->prepare("SELECT d.device_id,d.nome,d.capabilities,d.metadata,s.desired_state FROM dispositivos_cluster d LEFT JOIN device_desired_state s ON s.device_id=d.device_id WHERE d.device_id=:d LIMIT 1");
    $st->execute([':d' => $deviceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function automation_caps_is_relay(array $caps): bool {
    return in_array('relay', $caps, true) || in_array('switch', $caps, true);
}

/**
 * Se o dispositivo é um relé e o comando é de liga/desliga, grava o estado desejado.
 * Retorna o estado aplicado (bool) ou null quando não se aplica.
 */
function automation_apply_relay(PDO $pdo, string $deviceId, string $command, array $payload): ?bool {
    $intent = automation_relay_intent($command, $payload);
    if ($intent === null) return null;
    $row = automation_device_row($pdo, $deviceId);
    if (!$row || !automation_caps_is_relay(automation_json($row['capabilities'] ?? null, []))) return null;
    $desired = automation_json($row['desired_state'] ?? null, []);
    if ($intent === 'toggle') {
        $meta = automation_json($row['metadata'] ?? null, []);
        $current = array_key_exists('relay_on', $meta) ? (bool)$meta['relay_on'] : (bool)($desired['relay_on'] ?? false);
        $intent = !$current;
    }
    $desired['relay_on'] = (bool)$intent;
    $json = json_encode($desired, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO device_desired_state(device_id,desired_state,updated_at) VALUES(:d,:s,NOW()) ON DUPLICATE KEY UPDATE desired_state=VALUES(desired_state),updated_at=NOW()")
        ->execute([':d' => $deviceId, ':s' => $json]);
    return (bool)$intent;
}

/** Marca um comando de device_commands como concluído pelo caminho de estado desejado. */
function automation_complete_command(PDO $pdo, int $commandId, bool $state): void {
    if ($commandId <= 0) return;
    $res = json_encode(['via' => 'desired_state', 'relay_on' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE device_commands SET status='success',lifecycle_status='DONE',concluido_em=NOW(),resultado=:r WHERE id=:id")
        ->execute([':r' => $res, ':id' => $commandId]);
}

/** Insere o comando e, se for relé, aplica o estado desejado. */
function automation_enqueue_command(PDO $pdo, array $a, string $requestedBy, string $corr, string $idem): array {
    $priority = strtolower((string)($a['prioridade'] ?? $a['priority'] ?? 'normal'));
    if (!in_array($priority, ['low','normal','high','critical'], true)) $priority = 'normal';
    $payload = automation_json($a['payload'] ?? null, []);
    $cmd = $pdo->prepare("INSERT INTO device_commands(device_id,comando,payload,prioridade,correlation_id,idempotency_key,status,lifecycle_status,max_retries,requested_by,risk_level,expira_em) VALUES(:d,:c,:p,:pr,:x,:i,'pending','QUEUED',3,:rb,:risk,DATE_ADD(NOW(),INTERVAL :ttl SECOND))");
    $cmd->bindValue(':d', (string)$a['device_id']);
    $cmd->bindValue(':c', (string)$a['comando']);
    $cmd->bindValue(':p', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd->bindValue(':pr', $priority);
    $cmd->bindValue(':x', $corr);
    $cmd->bindValue(':i', $idem);
    $cmd->bindValue(':rb', substr($requestedBy, 0, 160));
    $cmd->bindValue(':risk', max(0, min(4, (int)($a['risk_level'] ?? 1))), PDO::PARAM_INT);
    $cmd->bindValue(':ttl', max(10, min(86400, (int)($a['ttl_seconds'] ?? 300))), PDO::PARAM_INT);
    $cmd->execute();
    $commandId = (int)$pdo->lastInsertId();
    $state = automation_apply_relay($pdo, (string)$a['device_id'], (string)$a['comando'], $payload);
    if ($state !== null) automation_complete_command($pdo, $commandId, $state);
    return ['command_id' => $commandId, 'relay_on' => $state];
}

function automation_scene_load(PDO $pdo, int $id, string $slug = ''): ?array {
    if ($id > 0) { $st = $pdo->prepare('SELECT * FROM scenes WHERE id=:id LIMIT 1'); $st->execute([':id' => $id]); }
    else { $st = $pdo->prepare('SELECT * FROM scenes WHERE slug=:s LIMIT 1'); $st->execute([':s' => $slug]); }
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function automation_scene_actions(PDO $pdo, int $sceneId): array {
    $st = $pdo->prepare("SELECT id,ordem,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds,enabled FROM scene_actions WHERE scene_id=:s ORDER BY ordem,id");
    $st->execute([':s' => $sceneId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['payload'] = automation_json($r['payload'] ?? null, []);
        $r['enabled'] = (bool)$r['enabled'];
        $r['risk_level'] = (int)$r['risk_level'];
        $r['ttl_seconds'] = (int)$r['ttl_seconds'];
    }
    return $rows;
}

/**
 * Executa uma cena. Retorna ['http'=>código, 'body'=>array] para que cada API
 * formate a resposta do seu jeito.
 */
function automation_scene_execute(PDO $pdo, array $scene, string $requestedBy, bool $confirm = false): array {
    if (!(bool)$scene['ativo']) return ['http' => 409, 'body' => ['status' => 'erro', 'mensagem' => 'Cena desabilitada']];
    $actions = array_values(array_filter(automation_scene_actions($pdo, (int)$scene['id']), fn($a) => $a['enabled']));
    if (!$actions) return ['http' => 409, 'body' => ['status' => 'erro', 'mensagem' => 'Cena sem ações habilitadas']];
    $maxRisk = (int)$scene['risk_level'];
    foreach ($actions as $a) $maxRisk = max($maxRisk, (int)$a['risk_level']);
    if ($maxRisk >= 3 && !$confirm) return ['http' => 409, 'body' => ['status' => 'confirmacao_necessaria', 'risk_level' => $maxRisk, 'mensagem' => 'Cena contém ação sensível; confirme a execução.']];

    $requestedBy = substr($requestedBy ?: 'api-client', 0, 160);
    $runCorr = 'scene_' . bin2hex(random_bytes(12));
    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO scene_runs(scene_id,requested_by,status,correlation_id,iniciado_em) VALUES(:s,:r,'QUEUED',:c,NOW())")
            ->execute([':s' => $scene['id'], ':r' => $requestedBy, ':c' => $runCorr]);
        $runId = (int)$pdo->lastInsertId();
        $queued = []; $errors = []; $applied = 0;
        $devStmt = $pdo->prepare("SELECT device_id FROM dispositivos_cluster WHERE device_id=:d AND credential_revoked_at IS NULL LIMIT 1");
        foreach ($actions as $a) {
            $devStmt->execute([':d' => $a['device_id']]);
            if (!$devStmt->fetchColumn()) {
                $errors[] = ['action_id' => $a['id'], 'device_id' => $a['device_id'], 'error' => 'device_not_found'];
                $pdo->prepare("INSERT INTO scene_run_actions(run_id,scene_action_id,device_id,comando,status,erro) VALUES(:r,:a,:d,:c,'FAILED','Device nao encontrado ou revogado')")
                    ->execute([':r' => $runId, ':a' => $a['id'], ':d' => $a['device_id'], ':c' => $a['comando']]);
                if ((bool)$scene['stop_on_error']) break; else continue;
            }
            if (!empty($a['required_capability'])) {
                $capStmt = $pdo->prepare("SELECT enabled FROM device_capabilities WHERE device_id=:d AND capability=:c LIMIT 1");
                $capStmt->execute([':d' => $a['device_id'], ':c' => $a['required_capability']]);
                $cap = $capStmt->fetch(PDO::FETCH_ASSOC);
                if ($cap && !(bool)$cap['enabled']) {
                    $errors[] = ['action_id' => $a['id'], 'device_id' => $a['device_id'], 'error' => 'capability_disabled'];
                    $pdo->prepare("INSERT INTO scene_run_actions(run_id,scene_action_id,device_id,comando,status,erro) VALUES(:r,:a,:d,:c,'FAILED','Capability desabilitada')")
                        ->execute([':r' => $runId, ':a' => $a['id'], ':d' => $a['device_id'], ':c' => $a['comando']]);
                    if ((bool)$scene['stop_on_error']) break; else continue;
                }
            }
            $corr = 'cmd_' . bin2hex(random_bytes(12));
            $res = automation_enqueue_command($pdo, $a, $requestedBy, $corr, 'scene_' . $runId . '_action_' . $a['id']);
            $st = $res['relay_on'] !== null ? 'DONE' : 'QUEUED';
            if ($res['relay_on'] !== null) $applied++;
            $pdo->prepare("INSERT INTO scene_run_actions(run_id,scene_action_id,command_id,device_id,comando,status) VALUES(:r,:a,:cmd,:d,:c,:st)")
                ->execute([':r' => $runId, ':a' => $a['id'], ':cmd' => $res['command_id'], ':d' => $a['device_id'], ':c' => $a['comando'], ':st' => $st]);
            $queued[] = ['action_id' => (int)$a['id'], 'command_id' => $res['command_id'], 'device_id' => $a['device_id'], 'correlation_id' => $corr, 'relay_on' => $res['relay_on']];
        }
        $status = $errors ? ($queued ? 'PARTIAL' : 'FAILED') : 'QUEUED';
        $summary = ['queued' => $queued, 'errors' => $errors, 'risk_level' => $maxRisk, 'relays_applied' => $applied];
        $pdo->prepare("UPDATE scene_runs SET status=:s,resumo=:j,concluido_em=CASE WHEN :terminal=1 THEN NOW() ELSE NULL END WHERE id=:id")
            ->execute([':s' => $status, ':j' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':terminal' => $status === 'FAILED' ? 1 : 0, ':id' => $runId]);
        if ($started) $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        error_log('automation_scene_execute: ' . $e->getMessage());
        return ['http' => 500, 'body' => ['status' => 'erro', 'mensagem' => 'Falha ao executar a cena']];
    }
    return ['http' => 202, 'body' => [
        'status' => 'ok',
        'scene' => ['id' => (int)$scene['id'], 'slug' => $scene['slug'], 'name' => $scene['nome']],
        'run_id' => $runId, 'correlation_id' => $runCorr, 'run_status' => $status,
        'queued' => $queued, 'errors' => $errors, 'relays_applied' => $applied,
    ]];
}

/** Dispositivos que podem receber ações (exclui nós Linux/ARM e revogados). */
function automation_action_devices(PDO $pdo): array {
    $rows = $pdo->query("SELECT d.device_id,d.nome,d.tipo,d.model,d.localizacao,d.status,d.capabilities,d.metadata,s.desired_state FROM dispositivos_cluster d LEFT JOIN device_desired_state s ON s.device_id=d.device_id WHERE d.device_id IS NOT NULL AND d.device_id<>'' AND d.credential_revoked_at IS NULL ORDER BY COALESCE(d.nome,d.model,d.device_id),d.device_id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $caps = automation_json($r['capabilities'] ?? null, []);
        $tipo = strtolower((string)($r['tipo'] ?? ''));
        if (in_array($tipo, ['linux-arm','cluster','server'], true) || in_array('arm-agent', $caps, true)) continue;
        $meta = automation_json($r['metadata'] ?? null, []);
        $desired = automation_json($r['desired_state'] ?? null, []);
        $out[] = [
            'device_id' => $r['device_id'], 'nome' => $r['nome'] ?: ($r['model'] ?: $r['device_id']),
            'tipo' => $r['tipo'], 'localizacao' => $r['localizacao'], 'status' => $r['status'],
            'capabilities' => $caps, 'is_relay' => automation_caps_is_relay($caps),
            'relay_actual' => array_key_exists('relay_on', $meta) ? (bool)$meta['relay_on'] : null,
            'relay_desired' => array_key_exists('relay_on', $desired) ? (bool)$desired['relay_on'] : null,
            'data_fields' => array_values(array_filter(array_keys($meta), fn($k) => is_string($k) && $k !== '' && $k[0] !== '_')),
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Agendamentos (tarefas_agendadas) que acionam cenas ou relés.
// Rodam no próprio servidor, disparados pelo tick de automação (heartbeats dos
// dispositivos e /api/v1/rules_tick.php), sem depender do scheduler local.
// ---------------------------------------------------------------------------

function automation_cron_field(string $expr, int $value, int $min, int $max): bool {
    foreach (explode(',', $expr) as $part) {
        $part = trim($part); if ($part === '') continue;
        $step = 1;
        if (strpos($part, '/') !== false) { [$part, $st] = explode('/', $part, 2); $step = max(1, (int)$st); }
        if ($part === '*') { $lo = $min; $hi = $max; }
        elseif (strpos($part, '-') !== false) { [$lo, $hi] = array_map('intval', explode('-', $part, 2)); }
        else { $lo = $hi = (int)$part; if ($step > 1) $hi = $max; }
        if ($value >= $lo && $value <= $hi && (($value - $lo) % $step) === 0) return true;
    }
    return false;
}

function automation_cron_matches(string $cron, DateTimeInterface $t): bool {
    $p = preg_split('/\s+/', trim($cron));
    if (count($p) !== 5) return false;
    [$mi, $ho, $dom, $mon, $dow] = $p;
    if (!automation_cron_field($mi, (int)$t->format('i'), 0, 59)) return false;
    if (!automation_cron_field($ho, (int)$t->format('G'), 0, 23)) return false;
    if (!automation_cron_field($mon, (int)$t->format('n'), 1, 12)) return false;
    $w = (int)$t->format('w');
    $domOk = automation_cron_field($dom, (int)$t->format('j'), 1, 31);
    $dowOk = automation_cron_field($dow, $w, 0, 7) || ($w === 0 && automation_cron_field($dow, 7, 0, 7));
    if ($dom !== '*' && $dow !== '*') return $domOk || $dowOk;
    return $domOk && $dowOk;
}

/** Tipos de agendamento executados pelo servidor web. */
function automation_schedule_server_types(): array { return ['executar_cena', 'dispositivo_rele']; }

/** Executa um agendamento de cena/relé. Retorna ['ok'=>bool,'dados'=>...,'erro'=>...]. */
function automation_schedule_execute(PDO $pdo, array $t, string $origin): array {
    parse_str((string)($t['payload'] ?? ''), $p);
    $who = substr($origin . ':' . ($t['titulo'] ?? ('#' . $t['id'])), 0, 160);
    if (($t['tipo_acao'] ?? '') === 'executar_cena') {
        $scene = automation_scene_load($pdo, (int)($p['scene_id'] ?? 0));
        if (!$scene) return ['ok' => false, 'erro' => 'Cena do agendamento não encontrada'];
        $r = automation_scene_execute($pdo, $scene, $who, true);
        if ($r['http'] !== 202) return ['ok' => false, 'erro' => $r['body']['mensagem'] ?? 'Falha ao executar a cena'];
        return ['ok' => true, 'dados' => ['cena' => $scene['nome'], 'run_id' => $r['body']['run_id'], 'reles' => $r['body']['relays_applied'], 'erros' => $r['body']['errors']]];
    }
    if (($t['tipo_acao'] ?? '') === 'dispositivo_rele') {
        $dev = trim((string)($p['device_id'] ?? ''));
        $on = ((string)($p['relay_on'] ?? '1')) === '1';
        if ($dev === '') return ['ok' => false, 'erro' => 'Relé do agendamento não informado'];
        $res = automation_enqueue_command($pdo, ['device_id' => $dev, 'comando' => $on ? 'power_on' : 'power_off', 'payload' => [], 'risk_level' => 1, 'ttl_seconds' => 300],
            $who, 'sched_' . bin2hex(random_bytes(12)), 'sched_' . (int)$t['id'] . '_' . date('YmdHi') . '_' . bin2hex(random_bytes(3)));
        if ($res['relay_on'] === null) return ['ok' => false, 'erro' => 'O dispositivo não é um relé'];
        return ['ok' => true, 'dados' => ['device_id' => $dev, 'relay_on' => $res['relay_on']]];
    }
    return ['ok' => false, 'erro' => 'Tipo de ação não executado pelo servidor'];
}

/** Dispara os agendamentos de cena/relé que vencem no minuto atual (ou no anterior, se o tick atrasou). */
function automation_schedules_tick(PDO $pdo): array {
    $types = automation_schedule_server_types();
    $in = "'" . implode("','", $types) . "'";
    $rows = $pdo->query("SELECT id,titulo,cron_expr,tipo_acao,payload,ultima_execucao FROM tarefas_agendadas WHERE ativo=1 AND cron_expr IS NOT NULL AND cron_expr<>'' AND tipo_acao IN($in)")->fetchAll(PDO::FETCH_ASSOC);
    $now = new DateTimeImmutable('now');
    $cur = $now->setTime((int)$now->format('G'), (int)$now->format('i'), 0);
    $runs = [];
    foreach ($rows as $t) {
        $last = !empty($t['ultima_execucao']) ? new DateTimeImmutable((string)$t['ultima_execucao']) : null;
        $due = null;
        foreach ([$cur, $cur->modify('-1 minute')] as $slot) {
            if ($last && $last >= $slot) continue;
            if (automation_cron_matches((string)$t['cron_expr'], $slot)) { $due = $slot; break; }
        }
        if (!$due) continue;
        // Reserva a execução (evita disparo duplo entre processos concorrentes).
        $claim = $pdo->prepare("UPDATE tarefas_agendadas SET ultima_execucao=:agora WHERE id=:id AND (ultima_execucao IS NULL OR ultima_execucao<:slot)");
        // Horário do PHP em ambos os lados, para não depender do fuso do MySQL.
        $claim->execute([':agora' => $now->format('Y-m-d H:i:s'), ':id' => $t['id'], ':slot' => $due->format('Y-m-d H:i:s')]);
        if ($claim->rowCount() === 0) continue;
        $r = automation_schedule_execute($pdo, $t, 'AGENDAMENTO');
        try { $pdo->prepare("INSERT INTO comandos_log(comando,origem,resultado) VALUES(:c,'SCHEDULER_WEB',:r)")->execute([':c' => 'Agendamento: ' . $t['titulo'], ':r' => substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 2000)]); } catch (Throwable $e) {}
        $runs[] = ['tarefa_id' => (int)$t['id'], 'ok' => $r['ok'], 'erro' => $r['erro'] ?? null];
    }
    return $runs;
}

}
