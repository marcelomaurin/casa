<?php
// Controle de servicos (daemons) dos nos do cluster. Usado pelo painel
// (api/arm_nodes.php) e pela API v1 (api/v1/daemons.php).
require_once __DIR__.'/db.php';
require_once __DIR__.'/arm_nodes_registry.php';

function arm_node_find_db_row(PDO $pdo, string $deviceId, ?int $rawNumId, string $ip): ?array {
    if ($deviceId !== '') {
        $st = $pdo->prepare("SELECT * FROM dispositivos_cluster WHERE device_id = :d LIMIT 1");
        $st->execute([':d' => $deviceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }
    if ($rawNumId !== null) {
        $st = $pdo->prepare("SELECT * FROM dispositivos_cluster WHERE id = :id LIMIT 1");
        $st->execute([':id' => $rawNumId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }
    if ($ip !== '') {
        $st = $pdo->prepare("SELECT * FROM dispositivos_cluster WHERE local_ip = :ip1 OR ip_address = :ip2 LIMIT 1");
        $st->execute([':ip1' => $ip, ':ip2' => $ip]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }
    return null;
}

function arm_node_dispatch_service_command(PDO $pdo, array $node, string $endpoint, string $commandName, array $payload): array {
    $targetIp = trim((string)($node['local_ip'] ?? $node['ip_address'] ?? ''));
    $devId = trim((string)($node['device_id'] ?? ''));

    // 1. Tentativa direta no portal local do no (:8080)
    if ($targetIp !== '' && filter_var($targetIp, FILTER_VALIDATE_IP)) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nConnection: close\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout' => 3.5,
                'ignore_errors' => true,
            ]
        ]);
        $resp = @file_get_contents("http://{$targetIp}:8080{$endpoint}", false, $ctx);
        if ($resp !== false) {
            $decoded = json_decode($resp, true);
            if (is_array($decoded) && !empty($decoded['ok'])) {
                return ['ok' => true, 'method' => 'Portal HTTP (:8080)', 'response' => $decoded];
            }
            if (is_array($decoded) && !empty($decoded['error'])) {
                return ['ok' => false, 'method' => 'Portal HTTP (:8080)', 'error' => (string)$decoded['error'], 'response' => $decoded];
            }
        }

        // 2. Tentativa via Agente HTTP (:8098/exec)
        $ctxAgent = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nConnection: close\r\n",
                'content' => json_encode(['comando' => $commandName, 'payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout' => 3.5,
                'ignore_errors' => true,
            ]
        ]);
        $respAgent = @file_get_contents("http://{$targetIp}:8098/exec", false, $ctxAgent);
        if ($respAgent !== false) {
            $decodedAgent = json_decode($respAgent, true);
            if (is_array($decodedAgent) && ($decodedAgent['status'] ?? '') === 'ok') {
                return ['ok' => true, 'method' => 'Agente ARM (:8098)', 'response' => $decodedAgent['resultado'] ?? $decodedAgent];
            }
        }
    }

    // 3. Fallback Cloud-to-Node: Enfileira comando prioritario no Command Bus (device_commands)
    // O casa-node-agent consulta a fila a cada 2.5s e aplica imediatamente no no!
    if ($devId !== '') {
        try {
            // correlation_id e obrigatorio no Command Bus (schema 1.20+); sem ele a
            // insercao falhava e o painel nao conseguia controlar nos fora da LAN.
            $stCmd = $pdo->prepare("INSERT INTO device_commands
                (device_id, comando, payload, prioridade, correlation_id, status, lifecycle_status, max_retries, criado_em, expira_em)
                VALUES (:d, :cmd, :pay, 'critical', :corr, 'pending', 'QUEUED', 3, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
            $stCmd->execute([
                ':d' => $devId,
                ':cmd' => $commandName,
                ':pay' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':corr' => 'svc-' . bin2hex(random_bytes(12)),
            ]);
            $cmdId = (int)$pdo->lastInsertId();
            return [
                'ok' => true,
                'queued' => true,
                'command_id' => $cmdId,
                'method' => 'Fila Cloud Command Bus (Heartbeat/Agente 2.5s)',
                'response' => ['ok' => true, 'queued' => true, 'command_id' => $cmdId],
            ];
        } catch (Throwable $e) {
            try {
                $stCmdLegacy = $pdo->prepare("INSERT INTO device_commands (device_id, comando, payload, status, criado_em) VALUES (:d, :cmd, :pay, 'pending', NOW())");
                $stCmdLegacy->execute([
                    ':d' => $devId,
                    ':cmd' => $commandName,
                    ':pay' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                return ['ok' => true, 'queued' => true, 'method' => 'Fila Cloud Command Bus', 'response' => ['ok' => true]];
            } catch (Throwable $e2) {
                return ['ok' => false, 'error' => 'Nao foi possivel comunicar com o no nem enfileirar comando: ' . $e2->getMessage()];
            }
        }
    }

    return ['ok' => false, 'error' => 'No sem IP acessivel ou device_id cadastrado.'];
}

/**
 * Inicia, para ou reinicia um servico em um no. Retorna ['code'=>http, 'body'=>array].
 * $acao: controlar_servico | service_control | mover_servico.
 */
function arm_node_control_service(PDO $pdo, string $acao, array $input, string $deviceId, ?int $rawNumId, string $ip): array {
    $service = trim((string)($input['service'] ?? $input['servico'] ?? ''));
    $svcAction = strtolower(trim((string)($input['service_action'] ?? $input['action'] ?? 'start')));
    if ($acao === 'mover_servico') {
        $svcAction = 'start';
    }

    if ($service === '') {
        return ['code' => 422, 'body' => ['status' => 'erro', 'mensagem' => 'Nome do servico e obrigatorio.']];
    }
    if (!in_array($svcAction, ['start', 'stop', 'restart', 'activate', 'deactivate', 'enable', 'disable'], true)) {
        return ['code' => 422, 'body' => ['status' => 'erro', 'mensagem' => 'Acao invalida para o servico.']];
    }

    // REGRA CRITICA: Nunca desativar o servico web (:8080)
    if ($service === 'casa-cluster-site' && in_array($svcAction, ['stop', 'deactivate', 'disable'], true)) {
        return ['code' => 403, 'body' => [
            'status' => 'erro',
            'mensagem' => 'Operacao bloqueada: o servico web (casa-cluster-site :8080) e protegido e nao pode ser desativado.'
        ]];
    }

    $targetNode = arm_node_find_db_row($pdo, $deviceId, $rawNumId, $ip);
    if (!$targetNode) {
        return ['code' => 404, 'body' => ['status' => 'erro', 'mensagem' => 'No de destino nao encontrado no cluster.']];
    }

    $catalogMap = [];
    foreach (arm_cluster_service_catalog() as $cItem) {
        $catalogMap[$cItem['id']] = $cItem;
    }
    $isExclusive = !empty($catalogMap[$service]['exclusive']);
    $deactivatedOnNodes = [];

    // Se estamos ativando/movendo um servico exclusivo do cluster para este no,
    // desativa e remove a atribuicao de qualquer OUTRO no para evitar redundancia!
    if ($isExclusive && in_array($svcAction, ['start', 'activate', 'enable'], true)) {
        try {
            $allRows = $pdo->query("SELECT * FROM dispositivos_cluster")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allRows as $other) {
                if ((int)$other['id'] === (int)$targetNode['id']) continue;
                $oMeta = arm_node_json($other['metadata'] ?? null);
                $oIp = (string)($other['local_ip'] ?? $other['ip_address'] ?? '');
                $oDef = arm_node_default_profile($oIp);
                $oAssigned = is_array($oMeta['assigned_services'] ?? null) && !empty($oMeta['assigned_services'])
                    ? array_values(array_unique(array_map('strval', $oMeta['assigned_services'])))
                    : $oDef['assigned_services'];
                $oServices = is_array($oMeta['services'] ?? null) ? $oMeta['services'] : [];

                $wasAssignedOrActive = in_array($service, $oAssigned, true) || (($oServices[$service] ?? '') === 'active');
                if ($wasAssignedOrActive) {
                    $oAssigned = array_values(array_filter($oAssigned, fn($s) => $s !== $service));
                    $oServices[$service] = 'inactive';
                    $oMeta['assigned_services'] = $oAssigned;
                    $oMeta['services'] = $oServices;
                    $stUpOther = $pdo->prepare("UPDATE dispositivos_cluster SET metadata = :m WHERE id = :id");
                    $stUpOther->execute([
                        ':m' => json_encode($oMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':id' => (int)$other['id'],
                    ]);
                    arm_node_dispatch_service_command($pdo, $other, '/api/services/control', 'service.control', [
                        'service' => $service,
                        'action' => 'stop',
                        'force' => true,
                        'update_assignment' => true,
                    ]);
                    $deactivatedOnNodes[] = (string)($other['nome'] ?? $other['device_id'] ?? $oIp);
                }
            }
        } catch (Throwable $e) {
            error_log('Aviso ao desativar servico redundante: ' . $e->getMessage());
        }
    }

    $updateAssignment = !empty($input['update_assignment']) || $acao === 'mover_servico' || ($isExclusive && in_array($svcAction, ['start', 'activate', 'enable'], true));
    $dispatchRes = arm_node_dispatch_service_command($pdo, $targetNode, '/api/services/control', 'service.control', [
        'service' => $service,
        'action' => $svcAction,
        'force' => true,
        'update_assignment' => $updateAssignment,
    ]);

    if (empty($dispatchRes['ok'])) {
        return ['code' => 500, 'body' => [
            'status' => 'erro',
            'mensagem' => $dispatchRes['error'] ?? 'Falha ao controlar servico no no.',
            'detalhes' => $dispatchRes,
        ]];
    }

    // Atualiza metadata persistido do no no banco principal
    $tMeta = arm_node_json($targetNode['metadata'] ?? null);
    $tIp = (string)($targetNode['local_ip'] ?? $targetNode['ip_address'] ?? '');
    $tDef = arm_node_default_profile($tIp);
    $tAssigned = is_array($tMeta['assigned_services'] ?? null) && !empty($tMeta['assigned_services'])
        ? array_values(array_unique(array_map('strval', $tMeta['assigned_services'])))
        : $tDef['assigned_services'];
    $tServices = is_array($tMeta['services'] ?? null) ? $tMeta['services'] : [];

    $newState = in_array($svcAction, ['start', 'activate', 'enable', 'restart'], true) ? 'active' : 'inactive';
    $tServices[$service] = $newState;
    if ($newState === 'active' && !in_array($service, $tAssigned, true)) {
        $tAssigned[] = $service;
    } elseif ($newState === 'inactive' && !empty($input['unassign']) && !in_array($service, ['casa-cluster-site', 'casa-node-agent', 'ssh'], true)) {
        $tAssigned = array_values(array_filter($tAssigned, fn($s) => $s !== $service));
    }
    $tMeta['assigned_services'] = array_values(array_unique($tAssigned));
    $tMeta['services'] = $tServices;

    try {
        $stUp = $pdo->prepare("UPDATE dispositivos_cluster SET metadata = :m WHERE id = :id");
        $stUp->execute([
            ':m' => json_encode($tMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => (int)$targetNode['id'],
        ]);
    } catch (Throwable $e) {}

    $msg = "Servico '{$service}' " . ($newState === 'active' ? 'ativado' : 'desativado') . " em " . ($targetNode['nome'] ?? $targetNode['device_id']) . ".";
    if (!empty($deactivatedOnNodes)) {
        $msg .= " Desativado automaticamente em: " . implode(', ', $deactivatedOnNodes) . " (sem redundancia).";
    }

    return ['code' => 200, 'body' => [
        'status' => 'ok',
        'mensagem' => $msg,
        'service' => $service,
        'action' => $svcAction,
        'state' => $newState,
        'metodo' => $dispatchRes['method'] ?? '',
        'deactivated_on' => $deactivatedOnNodes,
    ]];
}
