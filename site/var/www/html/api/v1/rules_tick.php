<?php
// CASA/JARVIS - Tick deterministico para regras schedule/state.
// Pode ser chamado por cron a cada minuto. Nao usa IA.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once(__DIR__.'/../db.php');
require_once(__DIR__.'/security_v1.php');
require_once(__DIR__.'/rules_engine.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
api_v1_auth_client_any($pdo, ['home.write','devices.write']);

function tick_day_matches(array $cfg, int $weekday): bool {
    $days = $cfg['days'] ?? [];
    if (!is_array($days) || !$days) return true;
    $normalized = array_map('intval', $days);
    return in_array($weekday, $normalized, true);
}

function tick_schedule_due(array $cfg, ?string $lastTriggered): bool {
    $hour = max(0, min(23, (int)($cfg['hour'] ?? -1)));
    $minute = max(0, min(59, (int)($cfg['minute'] ?? -1)));
    if ($hour < 0 || $minute < 0) return false;
    $now = new DateTimeImmutable('now');
    if (!tick_day_matches($cfg, (int)$now->format('N'))) return false;
    if ((int)$now->format('G') !== $hour || (int)$now->format('i') !== $minute) return false;
    if ($lastTriggered) {
        $last = new DateTimeImmutable($lastTriggered);
        if ($last->format('Y-m-d H:i') === $now->format('Y-m-d H:i')) return false;
    }
    return true;
}

function tick_run_rule(PDO $pdo, array $rule, array $context, string $source): array {
    $conditions = rules_json($rule['conditions_json'] ?? null, []);
    if (!rules_conditions_match($conditions, $context)) return ['rule_id'=>(int)$rule['id'],'status'=>'SKIPPED_CONDITION'];
    $cooldown = max(0, (int)($rule['cooldown_seconds'] ?? 0));
    if ($cooldown > 0 && !empty($rule['last_triggered_at'])) {
        $last = strtotime((string)$rule['last_triggered_at']);
        if ($last !== false && time() - $last < $cooldown) return ['rule_id'=>(int)$rule['id'],'status'=>'SKIPPED_COOLDOWN'];
    }

    $ruleId = (int)$rule['id'];
    $corr = 'rule_' . bin2hex(random_bytes(12));
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO automation_rule_runs(rule_id,source_event_id,correlation_id,status,details) VALUES(:r,NULL,:c,'QUEUED',:d)");
        $stmt->execute([':r'=>$ruleId, ':c'=>$corr, ':d'=>json_encode(['source'=>$source], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $runId = (int)$pdo->lastInsertId();
        $a = $pdo->prepare("SELECT id,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds FROM automation_rule_actions WHERE rule_id=:r AND enabled=1 ORDER BY ordem,id");
        $a->execute([':r'=>$ruleId]);
        $queued=[]; $errors=[];
        foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $action) {
            try {
                $commandId = rules_enqueue_action($pdo, $action, 'RULE:' . $rule['slug'], $runId);
                $pdo->prepare("INSERT INTO automation_rule_run_actions(run_id,rule_action_id,command_id,status) VALUES(:r,:a,:c,'QUEUED')")
                    ->execute([':r'=>$runId,':a'=>$action['id'],':c'=>$commandId]);
                $queued[]=$commandId;
            } catch (Throwable $e) {
                $pdo->prepare("INSERT INTO automation_rule_run_actions(run_id,rule_action_id,status,erro) VALUES(:r,:a,'FAILED',:e)")
                    ->execute([':r'=>$runId,':a'=>$action['id'],':e'=>substr($e->getMessage(),0,1000)]);
                $errors[]=['action_id'=>(int)$action['id'],'error'=>$e->getMessage()];
            }
        }
        $status=$errors?($queued?'PARTIAL':'FAILED'):'QUEUED';
        $pdo->prepare("UPDATE automation_rule_runs SET status=:s,details=:d,concluido_em=CASE WHEN :done=1 THEN NOW() ELSE NULL END WHERE id=:id")
            ->execute([':s'=>$status,':d'=>json_encode(['source'=>$source,'commands'=>$queued,'errors'=>$errors],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':done'=>$status==='FAILED'?1:0,':id'=>$runId]);
        $pdo->prepare("UPDATE automation_rules SET last_triggered_at=NOW() WHERE id=:id")->execute([':id'=>$ruleId]);
        $pdo->commit();
        return ['rule_id'=>$ruleId,'run_id'=>$runId,'status'=>$status,'commands'=>$queued,'errors'=>$errors];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['rule_id'=>$ruleId,'status'=>'FAILED','error'=>$e->getMessage()];
    }
}

$stmt = $pdo->query("SELECT id,slug,nome,trigger_type,trigger_config,conditions_json,cooldown_seconds,last_triggered_at FROM automation_rules WHERE enabled=1 AND trigger_type IN('schedule','state') ORDER BY id");
$runs=[];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
    $cfg = rules_json($rule['trigger_config'] ?? null, []);
    if ($rule['trigger_type'] === 'schedule') {
        if (!tick_schedule_due($cfg, $rule['last_triggered_at'] ?? null)) continue;
        $context=['schedule'=>['now'=>date('c'),'hour'=>(int)date('G'),'minute'=>(int)date('i'),'weekday'=>(int)date('N')]];
        $runs[] = tick_run_rule($pdo,$rule,$context,'schedule');
        continue;
    }

    $deviceId = trim((string)($cfg['device_id'] ?? ''));
    if ($deviceId==='') continue;
    $d=$pdo->prepare("SELECT device_id,nome,tipo,status,health,battery_pct,sinal_rssi,ultimo_heartbeat,metadata FROM dispositivos_cluster WHERE device_id=:d LIMIT 1");
    $d->execute([':d'=>$deviceId]);
    $dev=$d->fetch(PDO::FETCH_ASSOC);
    if (!$dev) continue;
    $dev['battery_pct']=$dev['battery_pct']===null?null:(int)$dev['battery_pct'];
    $dev['sinal_rssi']=$dev['sinal_rssi']===null?null:(int)$dev['sinal_rssi'];
    $dev['metadata']=rules_json($dev['metadata']??null,[]);
    $context=['device'=>$dev,'state'=>$dev,'data'=>$dev['metadata']];
    $runs[] = tick_run_rule($pdo,$rule,$context,'state');
}

api_v1_json_response(200,['status'=>'ok','runs'=>$runs,'processed'=>count($runs),'timestamp'=>date('c')]);
