<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__.'/db.php';
require_once __DIR__.'/arm_nodes_registry.php';

// Permite autenticacao via sessao web ou Bearer / API token
if (empty($_SESSION['auth_user'])) {
    verify_api_auth();
}

require_once __DIR__.'/arm_nodes_control.php';

// Alterar nos e controlar daemons e exclusivo do administrador (sessao admin
// ou chave do sistema). Tokens de clientes e dispositivos so consultam (GET).
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'DELETE'], true)) {
    $armPerfil = strtolower((string)($_SESSION['auth_perfil'] ?? ''));
    $armAdmin = (!empty($_SESSION['auth_user']) && in_array($armPerfil, ['admin', 'administrador'], true))
        || (($GLOBALS['casa_auth_tipo'] ?? '') === 'sistema');
    if (!$armAdmin) {
        http_response_code(403);
        echo json_encode(['status' => 'erro', 'mensagem' => 'Acesso restrito ao administrador'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $pdo = get_db_pdo();
        $nodes = arm_nodes_list($pdo);
        $catalog = arm_cluster_service_catalog();
        $orchestration = arm_cluster_orchestration_summary($nodes);
        echo json_encode([
            'status' => 'ok',
            'nodes' => $nodes,
            'service_catalog' => $catalog,
            'orchestration' => $orchestration,
            'total' => count($nodes),
            'online' => count(array_filter($nodes, fn($n) => $n['online'])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('ARM nodes list failed: '.$e->getMessage());
        http_response_code(500);
        echo json_encode(['status'=>'erro', 'mensagem'=>'Nao foi possivel carregar os agentes ARM. Tente atualizar a lista.']);
    }
    exit;
}

if ($method === 'POST' || $method === 'DELETE') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;

    $acao = trim((string)($input['acao'] ?? ''));
    if ($method === 'DELETE') {
        $acao = 'excluir';
    }

    $deviceId = trim((string)($input['device_id'] ?? ''));
    $nodeId = trim((string)($input['id'] ?? ''));
    $nome = trim((string)($input['nome'] ?? ''));
    $ip = trim((string)($input['ip_address'] ?? $input['ip'] ?? ''));
    $hostname = trim((string)($input['hostname'] ?? ''));
    $papel = trim((string)($input['papel'] ?? ''));

    $pdo = get_db_pdo();

    $rawNumId = null;
    if (preg_match('/^(?:legacy|device):(\d+)$/', $nodeId, $m)) {
        $rawNumId = (int)$m[1];
    } elseif (is_numeric($nodeId)) {
        $rawNumId = (int)$nodeId;
    }

    // ==========================================
    // 0A. CONTROLE DE SERVICO E REALOCACAO SEM REDUNDANCIA
    // ==========================================
    if (in_array($acao, ['controlar_servico', 'service_control', 'mover_servico'], true)) {
        $resultado = arm_node_control_service($pdo, $acao, $input, $deviceId, $rawNumId, $ip);
        http_response_code($resultado['code']);
        echo json_encode($resultado['body'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ==========================================
    // 0B. ATRIBUICAO DE SERVICOS E PAPEL DO NO
    // ==========================================
    if (in_array($acao, ['atribuir_servicos', 'assign_services'], true)) {
        $targetNode = arm_node_find_db_row($pdo, $deviceId, $rawNumId, $ip);
        if (!$targetNode) {
            http_response_code(404);
            echo json_encode(['status' => 'erro', 'mensagem' => 'No nao encontrado no cluster.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $rawAssigned = is_array($input['assigned_services'] ?? null) ? $input['assigned_services'] : [];
        $assigned = array_values(array_unique(array_filter(array_map(fn($s) => trim((string)$s), $rawAssigned))));
        foreach (['casa-cluster-site', 'casa-node-agent', 'ssh'] as $baseSvc) {
            if (!in_array($baseSvc, $assigned, true)) {
                array_unshift($assigned, $baseSvc);
            }
        }

        $catalogMap = [];
        foreach (arm_cluster_service_catalog() as $cItem) {
            $catalogMap[$cItem['id']] = $cItem;
        }

        // Remove servicos exclusivos atribuidos a este no de qualquer outro no
        $exclusiveAssigned = array_filter($assigned, fn($sid) => !empty($catalogMap[$sid]['exclusive']));
        $reassignedFrom = [];
        if (!empty($exclusiveAssigned)) {
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

                    $changedOther = false;
                    foreach ($exclusiveAssigned as $exSid) {
                        if (in_array($exSid, $oAssigned, true) || (($oServices[$exSid] ?? '') === 'active')) {
                            $oAssigned = array_values(array_filter($oAssigned, fn($s) => $s !== $exSid));
                            $oServices[$exSid] = 'inactive';
                            $changedOther = true;
                            arm_node_dispatch_service_command($pdo, $other, '/api/services/control', 'service.control', [
                                'service' => $exSid,
                                'action' => 'stop',
                                'force' => true,
                                'update_assignment' => true,
                            ]);
                        }
                    }
                    if ($changedOther) {
                        $oMeta['assigned_services'] = $oAssigned;
                        $oMeta['services'] = $oServices;
                        $stUpOther = $pdo->prepare("UPDATE dispositivos_cluster SET metadata = :m WHERE id = :id");
                        $stUpOther->execute([
                            ':m' => json_encode($oMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ':id' => (int)$other['id'],
                        ]);
                        $reassignedFrom[] = (string)($other['nome'] ?? $other['device_id'] ?? $oIp);
                    }
                }
            } catch (Throwable $e) {
                error_log('Aviso ao realocar servicos exclusivos: ' . $e->getMessage());
            }
        }

        $newRole = $papel !== '' ? $papel : (string)($targetNode['model'] ?? 'No do Cluster ARM');
        $dispatchRes = arm_node_dispatch_service_command($pdo, $targetNode, '/api/services/assign', 'service.assign', [
            'role' => $newRole,
            'assigned_services' => $assigned,
            'auto_apply' => true,
        ]);

        // Atualiza banco de dados principal
        $tMeta = arm_node_json($targetNode['metadata'] ?? null);
        $tServices = is_array($tMeta['services'] ?? null) ? $tMeta['services'] : [];
        foreach ($catalogMap as $sid => $cItem) {
            if (!empty($cItem['exclusive'])) {
                $tServices[$sid] = in_array($sid, $assigned, true) ? 'active' : 'inactive';
            }
        }
        $tServices['casa-cluster-site'] = 'active';
        $tMeta['assigned_services'] = $assigned;
        $tMeta['services'] = $tServices;
        $tMeta['node_role'] = $newRole;

        try {
            $stUp = $pdo->prepare("UPDATE dispositivos_cluster SET model = :r, metadata = :m WHERE id = :id");
            $stUp->execute([
                ':r' => $newRole,
                ':m' => json_encode($tMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => (int)$targetNode['id'],
            ]);
            if (!empty($targetNode['device_id'])) {
                $stArm = $pdo->prepare("UPDATE arm_nodes SET papel = :r WHERE device_id = :d");
                $stArm->execute([':r' => $newRole, ':d' => $targetNode['device_id']]);
            }
        } catch (Throwable $e) {}

        echo json_encode([
            'status' => 'ok',
            'mensagem' => 'Distribuicao de servicos atualizada para ' . ($targetNode['nome'] ?? $targetNode['device_id']) . '!',
            'assigned_services' => $assigned,
            'papel' => $newRole,
            'metodo' => $dispatchRes['method'] ?? '',
            'reassigned_from' => $reassignedFrom,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ==========================================
    // 0. ATUALIZACAO DO NO VIA GIT (SOCKET/HTTP OU SSH)
    // ==========================================
    if ($acao === 'atualizar' || $acao === 'update') {
        if ($deviceId === '' && $rawNumId === null && $ip === '') {
            http_response_code(422);
            echo json_encode(['status'=>'erro', 'mensagem'=>'Identificador ou IP do no e obrigatorio para atualizacao.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Obtem informacoes atuais do no no banco
        $node = null;
        if ($deviceId !== '') {
            $st = $pdo->prepare("SELECT * FROM dispositivos_cluster WHERE device_id = :d LIMIT 1");
            $st->execute([':d' => $deviceId]);
            $node = $st->fetch(PDO::FETCH_ASSOC);
        }
        if (!$node && $rawNumId !== null) {
            $st = $pdo->prepare("SELECT * FROM dispositivos_cluster WHERE id = :id LIMIT 1");
            $st->execute([':id' => $rawNumId]);
            $node = $st->fetch(PDO::FETCH_ASSOC);
        }

        $targetIp = $ip ?: ($node['local_ip'] ?? $node['ip_address'] ?? '');
        if (!$targetIp && !empty($node['device_id'])) {
            $stArm = $pdo->prepare("SELECT ip_address FROM arm_nodes WHERE device_id = :d LIMIT 1");
            $stArm->execute([':d' => $node['device_id']]);
            $targetIp = (string)$stArm->fetchColumn();
        }

        // Verifica status online do equipamento
        $now = (int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        $lastHb = !empty($node['ultimo_heartbeat']) ? strtotime($node['ultimo_heartbeat']) : null;
        $isOnline = ($lastHb !== null && ($now - $lastHb) <= 40);

        if (!$isOnline && $targetIp !== '127.0.0.1' && $targetIp !== 'localhost') {
            http_response_code(400);
            echo json_encode([
                'status' => 'erro',
                'mensagem' => 'O equipamento esta offline. A atualizacao so pode ser executada quando o no estiver conectado.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $updateOutput = '';
        $updateSuccess = false;
        $metodoUsado = '';

        // Metodo 1: Comunicacao direta via HTTP/socket no agente do no (porta 8098)
        if ($targetIp !== '') {
            $agentUrl = "http://{$targetIp}:8098/update";
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nConnection: close\r\n",
                    'content' => json_encode(['origem' => 'painel-lcars', 'forcar' => true]),
                    'timeout' => 60,
                    'ignore_errors' => true,
                ]
            ]);
            $agentResp = @file_get_contents($agentUrl, false, $ctx);
            if ($agentResp !== false) {
                $decoded = json_decode($agentResp, true);
                if (is_array($decoded) && ($decoded['status'] ?? '') === 'ok') {
                    $updateSuccess = true;
                    $metodoUsado = 'Agente HTTP/Socket (:8098)';
                    $updateOutput = $decoded['resultado']['stdout'] ?? json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        // Metodo 2: Execucao via SSH direto no servidor
        if (!$updateSuccess && $targetIp !== '') {
            $sshPass = '226468';
            $sshUser = 'mmm';
            if ($targetIp === '127.0.0.1' || $targetIp === 'localhost' || $targetIp === '192.168.2.13') {
                $cmd = "echo {$sshPass} | sudo -S /usr/local/bin/casa-cluster-update --force 2>&1";
            } else {
                $cmd = "sshpass -p '{$sshPass}' ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -p 22 {$sshUser}@{$targetIp} 'echo {$sshPass} | sudo -S /usr/local/bin/casa-cluster-update --force' 2>&1";
            }

            $output = [];
            $retval = 0;
            @exec($cmd, $output, $retval);
            $fullOut = implode("\n", $output);

            if ($retval === 0 || stripos($fullOut, 'up-to-date') !== false || stripos($fullOut, 'updated') !== false || stripos($fullOut, 'unchanged') !== false) {
                $updateSuccess = true;
                $metodoUsado = 'SSH direto (casa-cluster-update --force)';
                $updateOutput = $fullOut;
            } else {
                $updateOutput = "Falha no SSH: " . $fullOut;
            }
        }

        if ($updateSuccess) {
            echo json_encode([
                'status' => 'ok',
                'mensagem' => 'No atualizado com sucesso diretamente pelo Git!',
                'metodo' => $metodoUsado,
                'detalhes' => $updateOutput
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'status' => 'erro',
                'mensagem' => 'Falha ao executar a atualizacao no no. Verifique os logs e conectividade.',
                'detalhes' => $updateOutput
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ==========================================
    // 1. EXCLUSAO DE NO DO CLUSTER
    // ==========================================
    if ($acao === 'excluir' || $acao === 'remover' || $acao === 'delete') {
        if ($deviceId === '' && $rawNumId === null) {
            http_response_code(422);
            echo json_encode(['status'=>'erro', 'mensagem'=>'Identificador do no e obrigatorio para exclusao.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            if ($deviceId !== '') {
                try {
                    $stmt = $pdo->prepare("DELETE FROM dispositivos_cluster WHERE device_id = :d");
                    $stmt->execute([':d' => $deviceId]);
                } catch (Throwable $e) {}

                try {
                    $stmt = $pdo->prepare("DELETE FROM arm_nodes WHERE device_id = :d");
                    $stmt->execute([':d' => $deviceId]);
                } catch (Throwable $e) {}

                try {
                    $stmt = $pdo->prepare("DELETE FROM devices WHERE device_id = :d");
                    $stmt->execute([':d' => $deviceId]);
                } catch (Throwable $e) {}

                try {
                    $stmt = $pdo->prepare("DELETE FROM device_capabilities WHERE device_id = :d");
                    $stmt->execute([':d' => $deviceId]);
                } catch (Throwable $e) {}

                try {
                    $stmt = $pdo->prepare("DELETE FROM device_commands WHERE device_id = :d");
                    $stmt->execute([':d' => $deviceId]);
                } catch (Throwable $e) {}
            }

            if ($rawNumId !== null) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM dispositivos_cluster WHERE id = :id");
                    $stmt->execute([':id' => $rawNumId]);
                } catch (Throwable $e) {}

                try {
                    $stmt = $pdo->prepare("DELETE FROM arm_nodes WHERE id = :id");
                    $stmt->execute([':id' => $rawNumId]);
                } catch (Throwable $e) {}
            }

            echo json_encode([
                'status' => 'ok',
                'mensagem' => 'No excluido com sucesso do cluster!'
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('Falha ao excluir no ARM: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status'=>'erro', 'mensagem'=>'Erro ao excluir no: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ==========================================
    // 2. CADASTRO / INCLUSAO DE NO NOVO
    // ==========================================
    if ($acao === 'criar' || $acao === 'cadastrar' || $acao === 'incluir' || $acao === 'novo') {
        if ($nome === '') {
            http_response_code(422);
            echo json_encode(['status'=>'erro', 'mensagem'=>'O nome do no e obrigatorio.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            http_response_code(422);
            echo json_encode(['status'=>'erro', 'mensagem'=>'Endereco IP invalido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Se nao fornecido, gera um identificador seguro
        if ($deviceId === '') {
            $baseSeed = $hostname !== '' ? $hostname : $nome;
            $slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '-', $baseSeed));
            $slug = trim(preg_replace('/-+/', '-', $slug), '-');
            if ($slug === '') {
                $slug = 'node';
            }
            $deviceId = $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
        }

        // Verifica se device_id ja existe
        try {
            $chk = $pdo->prepare("SELECT id FROM dispositivos_cluster WHERE device_id = :d LIMIT 1");
            $chk->execute([':d' => $deviceId]);
            if ($chk->fetch()) {
                http_response_code(409);
                echo json_encode(['status'=>'erro', 'mensagem'=>'Ja existe um no cadastrado com o identificador "'.$deviceId.'". Escolha outro identificador.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (Throwable $e) {}

        $ipVal = $ip !== '' ? $ip : null;
        $deviceToken = 'NODE_' . bin2hex(random_bytes(16));
        $deviceTokenHash = hash('sha256', $deviceToken);
        $metadata = [
            'platform' => 'linux-arm',
            'hostname' => $hostname,
            'papel' => $papel !== '' ? $papel : 'Agente Linux ARM',
            'created_by' => $_SESSION['auth_user'] ?? 'admin',
            'created_at' => date('c')
        ];
        $capabilities = ['arm-agent', 'linux-arm'];

        try {
            $newId = null;
            try {
                $stmtIns = $pdo->prepare("INSERT INTO dispositivos_cluster 
                    (device_id, nome, tipo, model, local_ip, ip_address, device_token, device_token_hash, status, capabilities, metadata, localizacao, ultimo_heartbeat) 
                    VALUES (:dev_id, :nome, 'arm-agent', :model, :local_ip, :ip_addr, :token, :hash, 'offline', :caps, :meta, 'Cluster', NULL)");
                $stmtIns->execute([
                    ':dev_id' => $deviceId,
                    ':nome' => $nome,
                    ':model' => $papel !== '' ? $papel : 'Agente Linux ARM',
                    ':local_ip' => $ipVal,
                    ':ip_addr' => $ipVal,
                    ':token' => $deviceToken,
                    ':hash' => $deviceTokenHash,
                    ':caps' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
                    ':meta' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ]);
                $newId = (int)$pdo->lastInsertId();
            } catch (PDOException $exClust) {
                // Caso schema legado sem local_ip ou device_token_hash
                $stmtIns = $pdo->prepare("INSERT INTO dispositivos_cluster 
                    (device_id, nome, tipo, model, ip_address, device_token, status, capabilities, metadata, localizacao, ultimo_heartbeat) 
                    VALUES (:dev_id, :nome, 'arm-agent', :model, :ip_addr, :token, 'offline', :caps, :meta, 'Cluster', NULL)");
                $stmtIns->execute([
                    ':dev_id' => $deviceId,
                    ':nome' => $nome,
                    ':model' => $papel !== '' ? $papel : 'Agente Linux ARM',
                    ':ip_addr' => $ipVal,
                    ':token' => $deviceToken,
                    ':caps' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
                    ':meta' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ]);
                $newId = (int)$pdo->lastInsertId();
            }

            // Insere tambem na tabela arm_nodes para compatibilidade
            try {
                $stmtArm = $pdo->prepare("INSERT INTO arm_nodes (device_id, hostname, ip_address, papel, status, capabilities) 
                    VALUES (:dev_id, :host, :ip_addr, :papel, 'offline', :caps)
                    ON DUPLICATE KEY UPDATE hostname = :host2, ip_address = :ip_addr2, papel = :papel2");
                $stmtArm->execute([
                    ':dev_id' => $deviceId,
                    ':host' => $hostname,
                    ':ip_addr' => $ipVal,
                    ':papel' => $papel !== '' ? $papel : 'Agente Linux ARM',
                    ':caps' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
                    ':host2' => $hostname,
                    ':ip_addr2' => $ipVal,
                    ':papel2' => $papel !== '' ? $papel : 'Agente Linux ARM'
                ]);
            } catch (Throwable $eArm) {}

            echo json_encode([
                'status' => 'ok',
                'mensagem' => 'No cadastrado com sucesso!',
                'node' => [
                    'id' => 'device:' . $newId,
                    'device_id' => $deviceId,
                    'nome' => $nome,
                    'ip_address' => $ip,
                    'hostname' => $hostname,
                    'papel' => $papel,
                    'online' => false,
                    'status' => 'registered'
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('Falha ao cadastrar no ARM: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status'=>'erro', 'mensagem'=>'Erro ao cadastrar no: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ==========================================
    // 3. ATUALIZACAO / EDICAO DE NO EXISTENTE
    // ==========================================
    if ($nome === '') {
        http_response_code(422);
        echo json_encode(['status'=>'erro', 'mensagem'=>'O nome do no e obrigatorio.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
        http_response_code(422);
        echo json_encode(['status'=>'erro', 'mensagem'=>'Endereco IP invalido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $ipVal = $ip !== '' ? $ip : null;

        // 1. Atualiza na tabela dispositivos_cluster
        if ($deviceId !== '' || $rawNumId !== null) {
            try {
                $fields = ['nome = :nome', 'ip_address = :ip_addr', 'local_ip = :local_ip'];
                $params = [':nome' => $nome, ':ip_addr' => $ipVal, ':local_ip' => $ipVal];
                if ($papel !== '') {
                    $fields[] = 'model = :papel';
                    $params[':papel'] = $papel;
                }
                $where = ($deviceId !== '') ? 'device_id = :dev_id' : 'id = :num_id';
                $params[($deviceId !== '') ? ':dev_id' : ':num_id'] = ($deviceId !== '') ? $deviceId : $rawNumId;

                $sqlClust = "UPDATE dispositivos_cluster SET " . implode(', ', $fields) . " WHERE " . $where;
                $stmt = $pdo->prepare($sqlClust);
                $stmt->execute($params);
            } catch (PDOException $ex) {
                // Se a coluna local_ip nao existir na versao do schema, tenta sem ela
                if (stripos($ex->getMessage(), 'local_ip') !== false) {
                    $fields = ['nome = :nome', 'ip_address = :ip_addr'];
                    $params = [':nome' => $nome, ':ip_addr' => $ipVal];
                    if ($papel !== '') {
                        $fields[] = 'model = :papel';
                        $params[':papel'] = $papel;
                    }
                    $where = ($deviceId !== '') ? 'device_id = :dev_id' : 'id = :num_id';
                    $params[($deviceId !== '') ? ':dev_id' : ':num_id'] = ($deviceId !== '') ? $deviceId : $rawNumId;

                    $sqlClust = "UPDATE dispositivos_cluster SET " . implode(', ', $fields) . " WHERE " . $where;
                    $stmt = $pdo->prepare($sqlClust);
                    $stmt->execute($params);
                } else {
                    throw $ex;
                }
            }
        }

        // 2. Atualiza na tabela arm_nodes
        if ($deviceId !== '' || $rawNumId !== null) {
            $fieldsArm = ['ip_address = :arm_ip'];
            $paramsArm = [':arm_ip' => $ipVal];

            if ($hostname !== '') {
                $fieldsArm[] = 'hostname = :arm_host';
                $paramsArm[':arm_host'] = $hostname;
            }
            if ($papel !== '') {
                $fieldsArm[] = 'papel = :arm_papel';
                $paramsArm[':arm_papel'] = $papel;
            }

            $whereArm = ($deviceId !== '') ? 'device_id = :arm_dev_id' : 'id = :arm_num_id';
            $paramsArm[($deviceId !== '') ? ':arm_dev_id' : ':arm_num_id'] = ($deviceId !== '') ? $deviceId : $rawNumId;

            $sqlArm = "UPDATE arm_nodes SET " . implode(', ', $fieldsArm) . " WHERE " . $whereArm;
            $stmtArm = $pdo->prepare($sqlArm);
            $stmtArm->execute($paramsArm);
        }

        // 3. Atualiza na tabela devices se existir
        if ($deviceId !== '') {
            try {
                $stmtDev = $pdo->prepare("UPDATE devices SET nome = :dev_nome, ip_address = :dev_ip WHERE device_id = :dev_dev_id");
                $stmtDev->execute([
                    ':dev_nome' => $nome,
                    ':dev_ip' => $ipVal,
                    ':dev_dev_id' => $deviceId
                ]);
            } catch (Throwable $e) {}
        }

        echo json_encode([
            'status' => 'ok',
            'mensagem' => 'Informacoes do no atualizadas com sucesso!',
            'node' => [
                'device_id' => $deviceId,
                'nome' => $nome,
                'ip_address' => $ip,
                'hostname' => $hostname,
                'papel' => $papel
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('Falha ao atualizar no ARM: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status'=>'erro', 'mensagem'=>'Erro ao salvar informacoes do no: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

http_response_code(405);
header('Allow: GET, POST, DELETE');
echo json_encode(['status'=>'erro', 'mensagem'=>'Metodo nao permitido.']);
