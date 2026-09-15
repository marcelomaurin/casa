<?php
// CASA/JARVIS - helpers do Control Plane universal de devices.

require_once(__DIR__ . '/security_v1.php');

function device_v1_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function device_v1_safe_id(string $id): string {
    $id = trim($id);
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{2,119}$/', $id) ? $id : '';
}

function device_v1_token_hash(string $token): string {
    return hash('sha256', $token);
}

function device_v1_load(PDO $pdo, string $deviceId, bool $write = false): array {
    $deviceId = device_v1_safe_id($deviceId);
    if ($deviceId === '') api_v1_json_response(400, ['status'=>'erro','mensagem'=>'device_id invalido']);

    $token = api_v1_token_from_request();
    if ($token === '') api_v1_json_response(401, ['status'=>'erro','mensagem'=>'Bearer token obrigatorio']);
    api_v1_rate_limit($pdo, $token, $write ? 120 : 180, 60);

    $hash = device_v1_token_hash($token);
    $stmt = $pdo->prepare("SELECT id,device_id,nome,tipo,device_token,device_token_hash,credential_revoked_at,capabilities,status,config_version
        FROM dispositivos_cluster WHERE device_id=:d LIMIT 1");
    $stmt->execute([':d'=>$deviceId]);
    $dev = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dev || !empty($dev['credential_revoked_at'])) {
        api_v1_log($pdo,'DEVICE_AUTH_DENIED','ALTO',$deviceId,['reason'=>'missing_or_revoked']);
        api_v1_json_response(401,['status'=>'erro','mensagem'=>'Device inexistente ou credencial revogada']);
    }

    $storedHash = trim((string)($dev['device_token_hash'] ?? ''));
    $legacy = (string)($dev['device_token'] ?? '');
    $ok = $storedHash !== '' ? hash_equals($storedHash, $hash) : ($legacy !== '' && hash_equals($legacy, $token));
    if (!$ok) {
        api_v1_log($pdo,'DEVICE_AUTH_DENIED','ALTO',$deviceId,['reason'=>'token_mismatch']);
        api_v1_json_response(401,['status'=>'erro','mensagem'=>'Credencial do device invalida']);
    }

    // Migracao transparente: depois do primeiro uso seguro, passa a existir hash.
    if ($storedHash === '') {
        try { $pdo->prepare("UPDATE dispositivos_cluster SET device_token_hash=:h WHERE id=:id AND device_token_hash IS NULL")
            ->execute([':h'=>$hash,':id'=>$dev['id']]); } catch (Throwable $e) {}
    }

    $dev['capabilities'] = api_v1_decode_scopes($dev['capabilities'] ?? null);
    api_v1_log($pdo,'DEVICE_AUTH_OK','INFO',$deviceId,['write'=>$write]);
    return $dev;
}

function device_v1_require_capability(array $dev, string $cap): void {
    $caps = $dev['capabilities'] ?? [];
    if (!$caps || in_array('*',$caps,true) || in_array($cap,$caps,true)) return;
    api_v1_json_response(403,['status'=>'erro','mensagem'=>'Device sem capability para esta operacao','required'=>$cap]);
}

function device_v1_priority(string $p): string {
    $p = strtolower(trim($p));
    return in_array($p,['low','normal','high','critical'],true) ? $p : 'normal';
}

function device_v1_client_device(PDO $pdo, array $client): ?array {
    $deviceId = trim((string)($client['device_id'] ?? ''));
    if ($deviceId === '') return null;
    $stmt=$pdo->prepare("SELECT id,device_id,nome,tipo,status,capabilities,localizacao,ultimo_heartbeat,health,config_version FROM dispositivos_cluster WHERE device_id=:d LIMIT 1");
    $stmt->execute([':d'=>$deviceId]);
    $r=$stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
