<?php
// CASA/JARVIS - Pareamento do Casa Mobile (celular) por QR Code.
//
// Fluxo:
//  1. Operador logado no site gera um ticket em Segurança › Acessos pessoais.
//     O QR mostra {"t":"casa-pair","v":1,"url":<base>,"code":<código>}.
//     O código vale 10 minutos e só pode ser usado uma vez; o banco guarda só o hash.
//  2. O app lê o QR e chama POST /api/v1/auth.php {acao:"pair", code, device_name, ...}.
//  3. O servidor registra o celular em dispositivos_cluster (tipo "mobile") e cria uma
//     credencial própria em api_client_tokens, devolvida uma única vez ao app.
//  4. O site acompanha o ticket e lista os celulares pareados, com opção de revogar.
// Apenas celulares usam este fluxo (TV e hardwares têm provisionamento próprio).

if (!function_exists('mobile_pair_ensure_schema')) {

if (!defined('MOBILE_PAIR_TTL_SECONDS')) define('MOBILE_PAIR_TTL_SECONDS', 600);

function mobile_pair_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_pairing_tickets (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code_hash CHAR(64) NOT NULL,
        criado_por VARCHAR(120) NOT NULL,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expira_em DATETIME NOT NULL,
        usado_em DATETIME NULL,
        device_id VARCHAR(120) NULL,
        device_nome VARCHAR(160) NULL,
        usado_ip VARCHAR(45) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_mobile_pair_code (code_hash),
        KEY idx_mobile_pair_expira (expira_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** Escopos da credencial do celular: tudo o que o Casa Mobile usa na API v1. */
function mobile_pair_scopes(): array {
    return ['status.read','mobile.read','mobile.write','home.read','home.write','devices.read','devices.write','devices.provision',
        'family.read','family.write','watch.read','watch.write','jarvis.command','sensors.read','climate.read','camera.read',
        'alerts.read','device.read','device.write','commands.read','events.write','telemetry.write'];
}

function mobile_pair_base_url(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'maurinsoft.com.br');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) $host = 'maurinsoft.com.br';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || stripos($host, 'maurinsoft.com.br') !== false;
    return ($https ? 'https' : 'http') . '://' . $host . '/casa';
}

/** Cria um ticket de uso único. Retorna id, código (só aqui, em claro), payload do QR e validade. */
function mobile_pair_create(PDO $pdo, string $createdBy, ?string $baseUrl = null): array {
    mobile_pair_ensure_schema($pdo);
    // Limpeza oportunista de tickets antigos não usados.
    $pdo->exec("DELETE FROM mobile_pairing_tickets WHERE usado_em IS NULL AND expira_em < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $code = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    // Validade calculada pelo próprio MySQL (mesmo relógio usado na conferência).
    $pdo->prepare("INSERT INTO mobile_pairing_tickets(code_hash,criado_por,expira_em) VALUES(:h,:c,DATE_ADD(NOW(), INTERVAL " . (int)MOBILE_PAIR_TTL_SECONDS . " SECOND))")
        ->execute([':h' => hash('sha256', $code), ':c' => substr($createdBy ?: 'site', 0, 120)]);
    $id = (int)$pdo->lastInsertId();
    $expires = (string)$pdo->query("SELECT expira_em FROM mobile_pairing_tickets WHERE id=" . $id)->fetchColumn();
    $url = $baseUrl ?: mobile_pair_base_url();
    $payload = json_encode(['t' => 'casa-pair', 'v' => 1, 'url' => $url, 'code' => $code], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return ['id' => $id, 'code' => $code, 'qr' => $payload, 'url' => $url, 'expires_at' => $expires, 'ttl' => MOBILE_PAIR_TTL_SECONDS];
}

function mobile_pair_status(PDO $pdo, int $id): array {
    mobile_pair_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT id,expira_em,usado_em,device_id,device_nome,(expira_em < NOW()) AS vencido FROM mobile_pairing_tickets WHERE id=:id");
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['status' => 'not_found'];
    if ($r['usado_em']) return ['status' => 'paired', 'device_id' => $r['device_id'], 'device_nome' => $r['device_nome'], 'paired_at' => $r['usado_em']];
    if ((int)$r['vencido'] === 1) return ['status' => 'expired'];
    return ['status' => 'waiting', 'expires_at' => $r['expira_em']];
}

/**
 * Troca o código do QR por uma credencial permanente do celular.
 * Lança RuntimeException com mensagem amigável quando o código não serve.
 */
function mobile_pair_redeem(PDO $pdo, string $code, array $info, ?string $ip = null): array {
    mobile_pair_ensure_schema($pdo);
    $code = trim($code);
    if ($code === '' || strlen($code) > 128) throw new RuntimeException('Código de pareamento ausente ou inválido.');
    $hash = hash('sha256', $code);
    $st = $pdo->prepare("SELECT id,criado_por,usado_em,(expira_em < NOW()) AS vencido FROM mobile_pairing_tickets WHERE code_hash=:h LIMIT 1");
    $st->execute([':h' => $hash]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new RuntimeException('QR Code de pareamento não reconhecido. Gere um novo no site.');
    if ($t['usado_em']) throw new RuntimeException('Este QR Code já foi usado. Gere um novo no site.');
    if ((int)$t['vencido'] === 1) throw new RuntimeException('Este QR Code expirou. Gere um novo no site.');

    $name = trim(preg_replace('/\s+/', ' ', (string)($info['device_name'] ?? '')));
    if ($name === '') $name = 'Celular';
    $name = mb_substr($name, 0, 100);
    $model = mb_substr(trim((string)($info['device_model'] ?? '')), 0, 100);
    $deviceId = 'mobile-' . bin2hex(random_bytes(6));
    $token = 'casa_mob_' . bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $scopes = mobile_pair_scopes();
    $caps = ['mobile', 'notifications', 'voice', 'ble-gateway', 'provisioning'];
    $meta = ['paired_by' => $t['criado_por'], 'paired_at' => date('c'), 'pairing' => 'qr', 'model' => $model,
        'android' => mb_substr((string)($info['os_version'] ?? ''), 0, 40), 'app_version' => mb_substr((string)($info['app_version'] ?? ''), 0, 40)];

    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        // Consome o ticket de forma atômica (protege contra duas leituras simultâneas do mesmo QR).
        $claim = $pdo->prepare("UPDATE mobile_pairing_tickets SET usado_em=NOW(),device_id=:d,device_nome=:n,usado_ip=:ip WHERE id=:id AND usado_em IS NULL AND expira_em>=NOW()");
        $claim->execute([':d' => $deviceId, ':n' => $name, ':ip' => $ip, ':id' => $t['id']]);
        if ($claim->rowCount() !== 1) throw new RuntimeException('Este QR Code já foi usado ou expirou. Gere um novo no site.');
        $pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,model,device_token,device_token_hash,status,health,capabilities,metadata,localizacao,ultimo_heartbeat) VALUES(:d,:n,'mobile',:m,:ph,:h,'online','ok',:c,:meta,'Pessoal',NOW())")
            // device_token é UNIQUE: guarda um marcador por device (o segredo fica só como hash).
            ->execute([':d' => $deviceId, ':n' => $name, ':m' => $model !== '' ? $model : null, ':ph' => 'HASHED:' . $deviceId, ':h' => $tokenHash,
                ':c' => json_encode($caps, JSON_UNESCAPED_SLASHES), ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $pdo->prepare("INSERT INTO api_client_tokens(nome,device_id,token_hash,token_prefix,scopes,ativo) VALUES(:n,:d,:h,:p,:s,1)")
            ->execute([':n' => mb_substr('Celular: ' . $name, 0, 120), ':d' => $deviceId, ':h' => $tokenHash, ':p' => substr($token, 0, 20), ':s' => json_encode($scopes)]);
        if ($started) $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException && !($e instanceof PDOException)) throw $e;
        error_log('mobile_pair_redeem: ' . $e->getMessage());
        throw new RuntimeException('Falha ao registrar o celular. Tente novamente.');
    }
    return ['device_id' => $deviceId, 'name' => $name, 'token' => $token, 'scopes' => $scopes, 'paired_by' => $t['criado_por']];
}

function mobile_pair_list(PDO $pdo): array {
    $rows = $pdo->query("SELECT d.device_id,d.nome,d.model,d.status,d.ultimo_heartbeat,d.metadata,d.credential_revoked_at,d.criado_em,
            (SELECT MAX(t.ultimo_uso) FROM api_client_tokens t WHERE t.device_id=d.device_id) AS ultimo_uso
        FROM dispositivos_cluster d WHERE d.tipo='mobile' ORDER BY d.credential_revoked_at IS NOT NULL, COALESCE(d.ultimo_heartbeat,d.criado_em) DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $m = json_decode((string)$r['metadata'], true); $r['metadata'] = is_array($m) ? $m : []; }
    return $rows;
}

function mobile_pair_revoke(PDO $pdo, string $deviceId): bool {
    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE dispositivos_cluster SET credential_revoked_at=NOW(),status='revoked',health='revoked' WHERE device_id=:d AND tipo='mobile'");
    $st->execute([':d' => $deviceId]);
    $ok = $st->rowCount() > 0;
    if ($ok) $pdo->prepare("UPDATE api_client_tokens SET ativo=0,revogado_em=NOW() WHERE device_id=:d")->execute([':d' => $deviceId]);
    if ($started) $pdo->commit();
    return $ok;
}

}
