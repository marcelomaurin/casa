<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');
require_once(__DIR__ . '/device_registry.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);

function provision_input(): array {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : [];
    return is_array($j) ? $j : [];
}

function provision_scopes(string $type): array {
    $base = ['device.read', 'device.write', 'commands.read', 'events.write', 'telemetry.write'];
    if ($type === 'watch') return array_merge($base, ['watch.read', 'watch.write', 'family.read', 'family.write']);
    if ($type === 'tv') return ['device.read', 'device.write', 'commands.read', 'events.write', 'home.read', 'alerts.read', 'camera.read', 'family.read'];
    if ($type === 'esp32cam') return array_merge($base, ['camera.write']);
    return $base;
}

$action = $_GET['acao'] ?? 'list';

// =========================================================================
// FLUXO DO DISPOSITIVO (SEM TOKEN): Solicitação e Consulta de Pareamento
// =========================================================================

if ($action === 'solicitar_pareamento') {
    // Rate limit por IP para prevenir abuso na solicitacao publica
    api_v1_rate_limit($pdo, 'pairing_request_' . (api_v1_client_ip() ?? 'unknown'), 30, 60);
    $in = provision_input();

    $mac = strtoupper(preg_replace('/[^0-9A-Fa-f:]/', '', (string)($in['mac'] ?? '')));
    if ($mac === '' || strlen($mac) < 11) {
        api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'MAC address valido e obrigatorio']);
    }

    $type = strtolower(trim((string)($in['type'] ?? 'device')));
    $allowed = ['esp32cam', 'watch', 'esp32', 'esp8266', 'sensor', 'gateway', 'tv', 'mobile', 'device'];
    if (!in_array($type, $allowed, true)) {
        $type = 'device';
    }

    $model = mb_substr(trim((string)($in['model'] ?? $type)), 0, 80);
    $firmware = mb_substr(trim((string)($in['firmware_version'] ?? '1.0.0')), 0, 30);
    $caps = is_array($in['capabilities'] ?? null) ? $in['capabilities'] : [];

    // Pairing code: fornecido pelo device ou gerado aleatoriamente (6 digitos)
    $pairingCode = trim((string)($in['pairing_code'] ?? ''));
    if ($pairingCode === '' || strlen($pairingCode) < 4) {
        $pairingCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    } else {
        $pairingCode = mb_substr($pairingCode, 0, 12);
    }

    $requestId = 'req_' . bin2hex(random_bytes(16));

    try {
        // Cancelar solicitacoes pendentes anteriores do mesmo MAC
        $stmtCancel = $pdo->prepare("UPDATE device_pairing_requests SET status = 'cancelled' WHERE mac_address = :m AND status = 'pending'");
        $stmtCancel->execute([':m' => $mac]);

        $stmt = $pdo->prepare("INSERT INTO device_pairing_requests 
            (request_id, mac_address, device_type, model, firmware_version, capabilities, pairing_code, status, solicitado_ip, expira_em)
            VALUES (:rid, :mac, :dt, :md, :fw, :cap, :code, 'pending', :ip, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
        $stmt->execute([
            ':rid' => $requestId,
            ':mac' => $mac,
            ':dt' => $type,
            ':md' => $model,
            ':fw' => $firmware,
            ':cap' => json_encode($caps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':code' => $pairingCode,
            ':ip' => api_v1_client_ip()
        ]);

        api_v1_log($pdo, 'DEVICE_PAIRING_REQUESTED', 'INFO', null, [
            'request_id' => $requestId,
            'mac' => $mac,
            'type' => $type,
            'code' => $pairingCode
        ]);

        api_v1_json_response(200, [
            'status' => 'ok',
            'status_pareamento' => 'pending',
            'request_id' => $requestId,
            'mac' => $mac,
            'pairing_code' => $pairingCode,
            'expira_em_minutos' => 15,
            'mensagem' => 'Solicitacao de pareamento registrada. Autorize este dispositivo no aplicativo JARVIS Mobile no seu celular.'
        ]);
    } catch (Throwable $e) {
        api_v1_log($pdo, 'DEVICE_PAIRING_REQ_ERROR', 'ALTO', null, ['error' => $e->getMessage()]);
        api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Falha ao registrar solicitacao de pareamento: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'consultar_pareamento') {
    // Device faz polling para saber se o celular ja o autorizou
    api_v1_rate_limit($pdo, 'pairing_poll_' . (api_v1_client_ip() ?? 'unknown'), 120, 60);
    $in = provision_input();

    $requestId = trim((string)($in['request_id'] ?? $_GET['request_id'] ?? ''));
    $mac = strtoupper(preg_replace('/[^0-9A-Fa-f:]/', '', (string)($in['mac'] ?? $_GET['mac'] ?? '')));
    $code = trim((string)($in['pairing_code'] ?? $_GET['pairing_code'] ?? ''));

    if ($requestId === '') {
        api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'request_id obrigatorio']);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM device_pairing_requests WHERE request_id = :rid LIMIT 1");
        $stmt->execute([':rid' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            api_v1_json_response(404, ['status' => 'erro', 'mensagem' => 'Solicitacao nao encontrada']);
        }

        if ($code !== '' && $row['pairing_code'] !== $code) {
            api_v1_json_response(403, ['status' => 'erro', 'mensagem' => 'Codigo de pareamento invalido']);
        }

        $now = time();
        if ($row['status'] === 'pending' && strtotime($row['expira_em']) < $now) {
            $pdo->prepare("UPDATE device_pairing_requests SET status = 'expired' WHERE id = :id")->execute([':id' => $row['id']]);
            api_v1_json_response(200, ['status' => 'expired', 'mensagem' => 'A solicitacao expirou sem autorizacao do celular']);
        }

        if ($row['status'] === 'pending') {
            api_v1_json_response(200, [
                'status' => 'pending',
                'mensagem' => 'Aguardando autorizacao do celular no aplicativo JARVIS Mobile',
                'request_id' => $requestId
            ]);
        }

        if ($row['status'] === 'rejected' || $row['status'] === 'cancelled') {
            api_v1_json_response(200, [
                'status' => 'rejected',
                'mensagem' => 'Solicitacao de pareamento foi rejeitada pelo administrador no celular'
            ]);
        }

        if ($row['status'] === 'authorized') {
            $issuedToken = $row['issued_token'];
            $issuedDeviceId = $row['issued_device_id'];

            // Uma vez entregue ao device, limpa a copia em texto claro por seguranca
            $pdo->prepare("UPDATE device_pairing_requests SET status = 'completed', consumido_em = NOW(), issued_token = NULL WHERE id = :id")
                ->execute([':id' => $row['id']]);

            api_v1_log($pdo, 'DEVICE_PAIRING_COMPLETED', 'INFO', $row['autorizado_por'], [
                'request_id' => $requestId,
                'device_id' => $issuedDeviceId
            ]);

            api_v1_json_response(200, [
                'status' => 'ok',
                'status_pareamento' => 'authorized',
                'device_id' => $issuedDeviceId,
                'device_token' => $issuedToken,
                'base_url' => 'https://maurinsoft.com.br/casa',
                'api_endpoint' => 'https://maurinsoft.com.br/casa/api/v1',
                'mensagem' => 'Pareamento concluido com sucesso! Guarde seu device_token na memoria NVS/EEPROM.'
            ]);
        }

        if ($row['status'] === 'completed') {
            api_v1_json_response(200, [
                'status' => 'completed',
                'mensagem' => 'Credenciais ja foram entregues e consumidas pelo dispositivo.'
            ]);
        }

        api_v1_json_response(200, ['status' => $row['status']]);
    } catch (Throwable $e) {
        api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Erro ao consultar status: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// FLUXO DO CELULAR (AUTENTICADO): Autorização, Listagem e Revogação
// =========================================================================

$client = api_v1_auth_client_any($pdo, ['devices.provision', 'mobile.write']);

if ($action === 'solicitacoes_pendentes') {
    // Celular consulta os dispositivos fisicos que estao pedindo entrada na casa
    try {
        $stmt = $pdo->query("SELECT id, request_id, mac_address, device_type, model, firmware_version, capabilities, pairing_code, solicitado_ip, criado_em, expira_em 
            FROM device_pairing_requests 
            WHERE status = 'pending' AND expira_em > NOW() 
            ORDER BY criado_em DESC");
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pending as &$p) {
            $p['capabilities'] = json_decode($p['capabilities'] ?? '[]', true);
        }
        api_v1_json_response(200, ['status' => 'ok', 'solicitacoes' => $pending]);
    } catch (Throwable $e) {
        api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Falha ao buscar solicitacoes: ' . $e->getMessage()]);
    }
}

if ($action === 'autorizar_pareamento') {
    // Celular autoriza o device, gerando a chave criptografica individual vinculada
    $in = provision_input();
    $requestId = trim((string)($in['request_id'] ?? ''));
    if ($requestId === '') {
        api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'request_id obrigatorio']);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM device_pairing_requests WHERE request_id = :rid AND status = 'pending' AND expira_em > NOW() LIMIT 1");
        $stmt->execute([':rid' => $requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            api_v1_json_response(404, ['status' => 'erro', 'mensagem' => 'Solicitacao pendente nao encontrada ou expirada']);
        }

        // Se o celular enviou pairing_code para checagem de proximidade fisica
        $sentCode = trim((string)($in['pairing_code'] ?? ''));
        if ($sentCode !== '' && $sentCode !== $req['pairing_code']) {
            api_v1_log($pdo, 'DEVICE_PAIRING_CODE_MISMATCH', 'ALTO', $client['nome'], ['request_id' => $requestId]);
            api_v1_json_response(403, ['status' => 'erro', 'mensagem' => 'Codigo de pareamento incorreto']);
        }

        $type = $req['device_type'];
        $name = mb_substr(trim((string)($in['name'] ?? $req['model'] ?? $type)), 0, 120);
        $location = mb_substr(trim((string)($in['location'] ?? 'Residencia')), 0, 120);
        $mac = $req['mac_address'];

        // Gerar nova identidade e credencial unica
        $deviceId = $type . '-' . bin2hex(random_bytes(6));
        $token = 'casa_dev_' . bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $prefix = substr($token, 0, 20);

        $caps = json_decode($req['capabilities'] ?? '[]', true);
        if (!is_array($caps)) $caps = [];
        $scopes = provision_scopes($type);

        $metadata = [
            'provisioned_by' => $client['nome'] ?? 'mobile_app',
            'provisioned_via' => 'celular_authorization_flow',
            'pairing_request_id' => $requestId,
            'mac' => $mac,
            'authorized_at' => date('c')
        ];

        $pdo->beginTransaction();

        // 1. Registrar no cluster de dispositivos
        $stmtIns = $pdo->prepare("INSERT INTO dispositivos_cluster 
            (device_id, nome, tipo, mac_address, device_token, device_token_hash, status, health, capabilities, metadata, localizacao) 
            VALUES (:did, :n, :t, :m, 'HASHED', :h, 'online', 'ok', :cap, :meta, :loc)");
        $stmtIns->execute([
            ':did' => $deviceId,
            ':n' => $name,
            ':t' => $type,
            ':m' => $mac,
            ':h' => $hash,
            ':cap' => json_encode($caps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':meta' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':loc' => $location
        ]);
        registry_sync_capabilities($pdo,$deviceId,$caps);

        // 2. Registrar o token de API com escopos de menor privilegio
        $stmtTok = $pdo->prepare("INSERT INTO api_client_tokens 
            (nome, device_id, token_hash, token_prefix, scopes, ativo) 
            VALUES (:n, :d, :h, :p, :s, 1)");
        $stmtTok->execute([
            ':n' => $name,
            ':d' => $deviceId,
            ':h' => $hash,
            ':p' => $prefix,
            ':s' => json_encode($scopes, JSON_UNESCAPED_UNICODE)
        ]);

        // 3. Atualizar a solicitacao de pareamento com a chave para o dispositivo resgatar
        $stmtUp = $pdo->prepare("UPDATE device_pairing_requests 
            SET status = 'authorized', 
                issued_device_id = :did, 
                issued_token = :tok, 
                nome_atribuido = :n, 
                localizacao_atribuida = :loc, 
                autorizado_por = :by, 
                autorizado_em = NOW() 
            WHERE id = :id");
        $stmtUp->execute([
            ':did' => $deviceId,
            ':tok' => $token,
            ':n' => $name,
            ':loc' => $location,
            ':by' => $client['nome'] ?? 'celular',
            ':id' => $req['id']
        ]);

        $pdo->commit();

        api_v1_log($pdo, 'DEVICE_PAIRING_AUTHORIZED', 'INFO', $client['nome'], [
            'request_id' => $requestId,
            'device_id' => $deviceId,
            'mac' => $mac,
            'type' => $type
        ]);

        api_v1_json_response(200, [
            'status' => 'ok',
            'mensagem' => 'Dispositivo autorizado com sucesso!',
            'device' => [
                'device_id' => $deviceId,
                'name' => $name,
                'type' => $type,
                'mac' => $mac,
                'location' => $location,
                'scopes' => $scopes
            ]
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_v1_log($pdo, 'DEVICE_PAIRING_AUTH_ERROR', 'ALTO', $client['nome'], ['error' => $e->getMessage()]);
        api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Falha ao autorizar dispositivo: ' . $e->getMessage()]);
    }
}

if ($action === 'rejeitar_pareamento') {
    // Celular rejeita o pedido de pareamento
    $in = provision_input();
    $requestId = trim((string)($in['request_id'] ?? ''));
    if ($requestId === '') {
        api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'request_id obrigatorio']);
    }

    try {
        $stmt = $pdo->prepare("UPDATE device_pairing_requests SET status = 'rejected', autorizado_por = :by, autorizado_em = NOW() WHERE request_id = :rid AND status = 'pending'");
        $stmt->execute([
            ':by' => $client['nome'] ?? 'celular',
            ':rid' => $requestId
        ]);
        api_v1_log($pdo, 'DEVICE_PAIRING_REJECTED', 'AVISO', $client['nome'], ['request_id' => $requestId]);
        api_v1_json_response(200, ['status' => 'ok', 'mensagem' => 'Solicitacao rejeitada com sucesso']);
    } catch (Throwable $e) {
        api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Falha ao rejeitar solicitacao']);
    }
}

if ($action === 'list') {
    echo json_encode(['status' => 'ok', 'devices' => registry_list($pdo,false), 'registry'=>'dispositivos_cluster'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'create') {
    $in = provision_input();
    $type = strtolower(trim((string)($in['type'] ?? 'device')));
    $allowed = ['esp32cam', 'watch', 'esp32', 'esp8266', 'sensor', 'gateway', 'tv', 'mobile', 'device'];
    if (!in_array($type, $allowed, true)) api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'Tipo de device invalido']);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    if ($name === '') api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'Nome do device obrigatorio']);
    $location = mb_substr(trim((string)($in['location'] ?? 'Residencia')), 0, 120);
    $mac = preg_replace('/[^0-9A-Fa-f:]/', '', (string)($in['mac'] ?? ''));
    $deviceId = $type . '-' . bin2hex(random_bytes(6));
    $token = 'casa_dev_' . bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $prefix = substr($token, 0, 20);
    $caps = is_array($in['capabilities'] ?? null) ? $in['capabilities'] : [];
    $scopes = provision_scopes($type);
    $metadata = ['provisioned_by' => $client['nome'] ?? 'mobile', 'provisioned_at' => date('c'), 'transport_setup' => 'ble', 'transport_runtime' => in_array($type, ['watch', 'mobile'], true) ? 'ble_or_wifi' : 'wifi'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,mac_address,device_token,device_token_hash,status,health,capabilities,metadata,localizacao) VALUES(:did,:n,:t,:m,'HASHED',:h,'offline','unknown',:cap,:meta,:loc)");
        $stmt->execute([':did' => $deviceId, ':n' => $name, ':t' => $type, ':m' => $mac !== '' ? $mac : null, ':h' => $hash, ':cap' => json_encode($caps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':meta' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':loc' => $location ?: 'Residencia']);
        $internalId = (int)$pdo->lastInsertId();
        registry_sync_capabilities($pdo,$deviceId,$caps);
        $stmt = $pdo->prepare("INSERT INTO api_client_tokens(nome,device_id,token_hash,token_prefix,scopes,ativo) VALUES(:n,:d,:h,:p,:s,1)");
        $stmt->execute([':n' => $name, ':d' => $deviceId, ':h' => $hash, ':p' => $prefix, ':s' => json_encode($scopes, JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();
        api_v1_log($pdo, 'DEVICE_PROVISION_CREATED', 'INFO', $client['nome'] ?? null, ['device_id' => $deviceId, 'type' => $type]);
        echo json_encode(['status' => 'ok', 'device' => ['id' => $internalId, 'device_id' => $deviceId, 'name' => $name, 'type' => $type, 'location' => $location, 'token' => $token, 'scopes' => $scopes], 'notice' => 'Credencial exibida uma unica vez. O servidor armazena somente o hash.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_v1_log($pdo, 'DEVICE_PROVISION_ERROR', 'ALTO', $client['nome'] ?? null, ['error' => $e->getMessage()]);
        api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Falha ao criar identidade do device']);
    }
    exit;
}

if ($action === 'revoke') {
    $in = provision_input();
    $did = trim((string)($in['device_id'] ?? ''));
    if ($did === '') api_v1_json_response(400, ['status' => 'erro', 'mensagem' => 'device_id obrigatorio']);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE dispositivos_cluster SET credential_revoked_at=NOW(),status='revoked',health='revoked' WHERE device_id=:d")->execute([':d' => $did]);
        $pdo->prepare("UPDATE api_client_tokens SET ativo=0,revogado_em=NOW() WHERE device_id=:d")->execute([':d' => $did]);
        $pdo->commit();
        api_v1_log($pdo, 'DEVICE_REVOKED', 'ALTO', $client['nome'] ?? null, ['device_id' => $did]);
        api_v1_json_response(200, ['status' => 'ok', 'device_id' => $did]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_v1_json_response(500, ['status' => 'erro', 'mensagem' => 'Falha ao revogar device']);
    }
}

api_v1_json_response(404, ['status' => 'erro', 'mensagem' => 'Acao de provisionamento desconhecida']);
