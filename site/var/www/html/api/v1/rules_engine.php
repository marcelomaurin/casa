<?php
require_once(__DIR__.'/device_registry.php');
require_once(__DIR__.'/../automation_common.php');
// CASA/JARVIS - Motor deterministico de regras. Nao usa LLM/IA.
// Modelo: device de entrada autenticado -> regra/condicoes -> um ou varios devices de saida.

function rules_json($value, $fallback = []) {
    if (is_array($value)) return $value;
    if (!is_string($value) || $value === '') return $fallback;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function rules_get_path(array $source, string $path) {
    $current = $source;
    foreach (explode('.', $path) as $segment) {
        if ($segment === '') continue;
        if (!is_array($current) || !array_key_exists($segment, $current)) return null;
        $current = $current[$segment];
    }
    return $current;
}

function rules_compare($actual, string $op, $expected): bool {
    switch (strtolower($op)) {
        case 'eq': case '=': case '==': return $actual == $expected;
        case 'neq': case '!=': return $actual != $expected;
        case 'gt': case '>': return is_numeric($actual) && is_numeric($expected) && $actual > $expected;
        case 'gte': case '>=': return is_numeric($actual) && is_numeric($expected) && $actual >= $expected;
        case 'lt': case '<': return is_numeric($actual) && is_numeric($expected) && $actual < $expected;
        case 'lte': case '<=': return is_numeric($actual) && is_numeric($expected) && $actual <= $expected;
        case 'contains':
            if (is_array($actual)) return in_array($expected, $actual, true);
            return is_string($actual) && str_contains($actual, (string)$expected);
        case 'in':
            return is_array($expected) && in_array($actual, $expected, true);
        case 'exists': return $actual !== null;
        default: return false;
    }
}

function rules_conditions_match(array $conditions, array $context): bool {
    foreach ($conditions as $condition) {
        if (!is_array($condition)) continue;
        $field = trim((string)($condition['field'] ?? ''));
        if ($field === '') continue;
        $actual = rules_get_path($context, $field);
        if (!rules_compare($actual, (string)($condition['op'] ?? 'eq'), $condition['value'] ?? null)) return false;
    }
    return true;
}

function rules_trigger_match(array $config, array $event): bool {
    $type = (string)($config['event_type'] ?? '');
    $device = (string)($config['device_id'] ?? '');
    if ($type !== '' && $type !== (string)($event['type'] ?? '')) return false;
    if ($device !== '' && $device !== (string)($event['device_id'] ?? '')) return false;
    return true;
}

function rules_enqueue_action(PDO $pdo, array $action, string $requestedBy, int $runId, string $correlationId): int {
    $priority = strtolower((string)($action['prioridade'] ?? 'normal'));
    if (!in_array($priority, ['low','normal','high','critical'], true)) $priority = 'normal';
    $ttl = max(10, min(86400, (int)($action['ttl_seconds'] ?? 300)));
    $risk = max(0, min(4, (int)($action['risk_level'] ?? 1)));
    if ($risk >= 3) throw new RuntimeException('automatic_high_risk_blocked');

    // O destino da acao e sempre um device cadastrado no registry.
    $deviceId = trim((string)($action['device_id'] ?? ''));
    if ($deviceId === '') throw new RuntimeException('target_device_required');
    registry_require($pdo,$deviceId);

    $required = trim((string)($action['required_capability'] ?? ''));
    if ($required !== '') {
        $resolved=registry_require_capability($pdo,$deviceId,$required);
        if ((int)$resolved['risk_level'] >= 3) throw new RuntimeException('automatic_high_risk_blocked');
    }

    $idem = 'rule_' . $runId . '_action_' . (int)$action['id'];
    $action['device_id'] = $deviceId;
    $action['prioridade'] = $priority;
    $action['risk_level'] = $risk;
    $action['ttl_seconds'] = $ttl;
    // Reles recebem o estado desejado diretamente (ver api/automation_common.php).
    $res = automation_enqueue_command($pdo, $action, $requestedBy, $correlationId, $idem);
    return (int)$res['command_id'];
}

function rules_engine_process_event(PDO $pdo, array $event): array {
    $eventId = isset($event['id']) ? (int)$event['id'] : null;
    $eventType = (string)($event['type'] ?? '');
    $sourceDeviceId = trim((string)($event['device_id'] ?? ''));
    if ($sourceDeviceId === '') throw new RuntimeException('source_device_required');

    // Defesa em profundidade: device.php ja autentica a origem; o motor tambem exige
    // que a origem exista no registry antes de rotear qualquer evento.
    $sourceDevice = registry_require($pdo,$sourceDeviceId);
    $eventData = is_array($event['data'] ?? null) ? $event['data'] : [];
    $eventCorrelation=trim((string)($event['correlation_id'] ?? ''));
    if($eventCorrelation==='')$eventCorrelation='evt_'.bin2hex(random_bytes(16));

    $context = [
        'event'=>[
            'id'=>$eventId,
            'type'=>$eventType,
            'device_id'=>$sourceDeviceId,
            'source_device_id'=>$sourceDeviceId,
            'correlation_id'=>$eventCorrelation
        ],
        'source_device'=>[
            'device_id'=>$sourceDeviceId,
            'name'=>$sourceDevice['nome'] ?? ($sourceDevice['name'] ?? null),
            'type'=>$sourceDevice['tipo'] ?? ($sourceDevice['type'] ?? null),
            'location'=>$sourceDevice['localizacao'] ?? ($sourceDevice['location'] ?? null)
        ],
        'data'=>$eventData
    ];

    $originRule = (int)($eventData['_automation_rule_id'] ?? 0);
    $stmt = $pdo->query("SELECT id,slug,nome,trigger_config,conditions_json,cooldown_seconds,last_triggered_at FROM automation_rules WHERE enabled=1 AND trigger_type IN('event','state') ORDER BY id");
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $runs = [];

    foreach ($rules as $rule) {
        $ruleId = (int)$rule['id'];
        if ($originRule === $ruleId) continue;
        $config = rules_json($rule['trigger_config'] ?? null, []);
        if (!rules_trigger_match($config, ['type'=>$eventType,'device_id'=>$sourceDeviceId])) continue;
        $conditions = rules_json($rule['conditions_json'] ?? null, []);
        if (!rules_conditions_match($conditions, $context)) continue;

        $cooldown = max(0, (int)$rule['cooldown_seconds']);
        if ($cooldown > 0 && !empty($rule['last_triggered_at'])) {
            $last = strtotime((string)$rule['last_triggered_at']);
            if ($last !== false && time() - $last < $cooldown) continue;
        }

        $corr = $eventCorrelation;
        $pdo->beginTransaction();
        try {
            $stmtRun = $pdo->prepare("INSERT INTO automation_rule_runs(rule_id,source_event_id,correlation_id,status) VALUES(:r,:e,:c,'QUEUED')");
            $stmtRun->execute([':r'=>$ruleId, ':e'=>$eventId, ':c'=>$corr]);
            $runId = (int)$pdo->lastInsertId();
            $stmtActions = $pdo->prepare("SELECT id,device_id,comando,payload,prioridade,required_capability,risk_level,ttl_seconds FROM automation_rule_actions WHERE rule_id=:r AND enabled=1 ORDER BY ordem,id");
            $stmtActions->execute([':r'=>$ruleId]);
            $actions = $stmtActions->fetchAll(PDO::FETCH_ASSOC);
            $queued = []; $errors = []; $routes = [];
            foreach ($actions as $action) {
                $targetDeviceId = trim((string)($action['device_id'] ?? ''));
                try {
                    $commandId = rules_enqueue_action($pdo, $action, 'RULE:' . $rule['slug'], $runId, $corr);
                    $pdo->prepare("INSERT INTO automation_rule_run_actions(run_id,rule_action_id,command_id,status) VALUES(:r,:a,:c,'QUEUED')")->execute([':r'=>$runId,':a'=>$action['id'],':c'=>$commandId]);
                    $queued[] = $commandId;
                    $routes[] = ['source_device_id'=>$sourceDeviceId,'target_device_id'=>$targetDeviceId,'action_id'=>(int)$action['id'],'command_id'=>$commandId,'status'=>'QUEUED'];
                } catch (Throwable $e) {
                    $pdo->prepare("INSERT INTO automation_rule_run_actions(run_id,rule_action_id,status,erro) VALUES(:r,:a,'FAILED',:e)")->execute([':r'=>$runId,':a'=>$action['id'],':e'=>substr($e->getMessage(),0,1000)]);
                    $errors[] = ['action_id'=>(int)$action['id'],'target_device_id'=>$targetDeviceId,'error'=>$e->getMessage()];
                    $routes[] = ['source_device_id'=>$sourceDeviceId,'target_device_id'=>$targetDeviceId,'action_id'=>(int)$action['id'],'command_id'=>null,'status'=>'FAILED'];
                }
            }
            $status = $errors ? ($queued ? 'PARTIAL' : 'FAILED') : 'QUEUED';
            $details = [
                'event_type'=>$eventType,
                'source_device_id'=>$sourceDeviceId,
                'routes'=>$routes,
                'commands'=>$queued,
                'errors'=>$errors
            ];
            $pdo->prepare("UPDATE automation_rule_runs SET status=:s,details=:d,concluido_em=CASE WHEN :done=1 THEN NOW() ELSE NULL END WHERE id=:id")->execute([':s'=>$status,':d'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':done'=>$status==='FAILED'?1:0,':id'=>$runId]);
            $pdo->prepare("UPDATE automation_rules SET last_triggered_at=NOW() WHERE id=:id")->execute([':id'=>$ruleId]);
            $pdo->commit();
            $runs[] = ['rule_id'=>$ruleId,'run_id'=>$runId,'status'=>$status,'source_device_id'=>$sourceDeviceId,'routes'=>$routes,'commands'=>$queued,'errors'=>$errors];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    return $runs;
}
