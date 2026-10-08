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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $nodes = arm_nodes_list(get_db_pdo());
        echo json_encode(['status'=>'ok', 'nodes'=>$nodes, 'total'=>count($nodes), 'online'=>count(array_filter($nodes, fn($n)=>$n['online']))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('ARM nodes list failed: '.$e->getMessage());
        http_response_code(500);
        echo json_encode(['status'=>'erro', 'mensagem'=>'Nao foi possivel carregar os agentes ARM. Tente atualizar a lista.']);
    }
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;

    $deviceId = trim((string)($input['device_id'] ?? ''));
    $nodeId = trim((string)($input['id'] ?? ''));
    $nome = trim((string)($input['nome'] ?? ''));
    $ip = trim((string)($input['ip_address'] ?? $input['ip'] ?? ''));
    $hostname = trim((string)($input['hostname'] ?? ''));
    $papel = trim((string)($input['papel'] ?? ''));

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
        $pdo = get_db_pdo();

        $rawNumId = null;
        if (preg_match('/^(?:legacy|device):(\\d+)$/', $nodeId, $m)) {
            $rawNumId = (int)$m[1];
        } elseif (is_numeric($nodeId)) {
            $rawNumId = (int)$nodeId;
        }

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
header('Allow: GET, POST');
echo json_encode(['status'=>'erro', 'mensagem'=>'Metodo nao permitido.']);
