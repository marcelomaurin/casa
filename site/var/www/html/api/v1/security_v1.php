<?php
// Segurança comum da API externa v1.

function api_v1_json_response($code, $payload) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_v1_token_from_request() {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) return trim($m[1]);
    if (!empty($_SERVER['HTTP_X_API_KEY'])) return trim($_SERVER['HTTP_X_API_KEY']);
    if (!empty($_SERVER['HTTP_X_DEVICE_TOKEN'])) return trim($_SERVER['HTTP_X_DEVICE_TOKEN']);
    return '';
}

function api_v1_client_ip() {
    // Não confiar cegamente em X-Forwarded-For. Cloudflare só é usado quando REMOTE_ADDR existe.
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : null;
}

function api_v1_log($pdo, $evento, $severidade = 'INFO', $cliente = null, $detalhes = []) {
    try {
        $stmt = $pdo->prepare("INSERT INTO api_v1_security_log
            (ip, cliente, rota, metodo, evento, severidade, detalhes)
            VALUES (:ip, :c, :r, :m, :e, :s, CAST(:d AS jsonb))");
        $stmt->execute([
            ':ip' => api_v1_client_ip(),
            ':c' => $cliente,
            ':r' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
            ':m' => $_SERVER['REQUEST_METHOD'] ?? '',
            ':e' => $evento,
            ':s' => $severidade,
            ':d' => json_encode($detalhes, JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Throwable $e) {}
}

function api_v1_basic_guard($pdo) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Cache-Control: no-store');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET','POST','OPTIONS'], true)) {
        api_v1_log($pdo, 'METHOD_BLOCKED', 'AVISO');
        api_v1_json_response(405, ['status'=>'erro','mensagem'=>'Método não permitido']);
    }

    if (isset($_GET['api_key'])) {
        api_v1_log($pdo, 'QUERY_TOKEN_BLOCKED', 'AVISO');
        api_v1_json_response(400, ['status'=>'erro','mensagem'=>'Token em URL não é permitido']);
    }

    $len = intval($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > 1024 * 1024) {
        api_v1_log($pdo, 'PAYLOAD_TOO_LARGE', 'AVISO', null, ['bytes'=>$len]);
        api_v1_json_response(413, ['status'=>'erro','mensagem'=>'Payload excede 1 MB']);
    }
}

function api_v1_rate_limit($pdo, $token, $limit = 60, $windowSeconds = 60) {
    $ip = api_v1_client_ip() ?: 'unknown';
    $key = hash('sha256', $ip . '|' . hash('sha256', $token));
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM api_v1_rate_limit WHERE chave=:k FOR UPDATE");
        $stmt->execute([':k'=>$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $now = time();

        if (!$row) {
            $pdo->prepare("INSERT INTO api_v1_rate_limit(chave, janela_inicio, contador) VALUES (:k,NOW(),1)")
                ->execute([':k'=>$key]);
            $pdo->commit();
            return;
        }

        if (!empty($row['bloqueado_ate']) && strtotime($row['bloqueado_ate']) > $now) {
            $pdo->commit();
            api_v1_json_response(429, ['status'=>'erro','mensagem'=>'Cliente temporariamente bloqueado por excesso de requisições']);
        }

        $start = strtotime($row['janela_inicio']);
        if (($now - $start) >= $windowSeconds) {
            $pdo->prepare("UPDATE api_v1_rate_limit SET janela_inicio=NOW(), contador=1, bloqueado_ate=NULL, atualizado_em=NOW() WHERE chave=:k")
                ->execute([':k'=>$key]);
            $pdo->commit();
            return;
        }

        $count = intval($row['contador']) + 1;
        $blockedUntil = null;
        if ($count > $limit) $blockedUntil = date('Y-m-d H:i:s', $now + 300);
        $pdo->prepare("UPDATE api_v1_rate_limit SET contador=:c, bloqueado_ate=:b, atualizado_em=NOW() WHERE chave=:k")
            ->execute([':c'=>$count, ':b'=>$blockedUntil, ':k'=>$key]);
        $pdo->commit();

        if ($count > $limit) {
            api_v1_log($pdo, 'RATE_LIMIT_BLOCK', 'ALTO', null, ['count'=>$count]);
            api_v1_json_response(429, ['status'=>'erro','mensagem'=>'Limite de requisições excedido']);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

function api_v1_auth_client($pdo, array $requiredScopes = []) {
    $token = api_v1_token_from_request();
    if ($token === '') {
        api_v1_log($pdo, 'AUTH_MISSING', 'ALTO');
        api_v1_json_response(401, ['status'=>'erro','mensagem'=>'Bearer token obrigatório']);
    }

    api_v1_rate_limit($pdo, $token);
    $hash = hash('sha256', $token);

    try {
        $stmt = $pdo->prepare("SELECT id,nome,scopes,ativo,expira_em FROM api_client_tokens WHERE token_hash=:h LIMIT 1");
        $stmt->execute([':h'=>$hash]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client || !$client['ativo'] || (!empty($client['expira_em']) && strtotime($client['expira_em']) < time())) {
            api_v1_log($pdo, 'AUTH_DENIED', 'ALTO');
            api_v1_json_response(401, ['status'=>'erro','mensagem'=>'Token inválido, inativo ou expirado']);
        }

        $scopes = $client['scopes'];
        if (is_string($scopes)) {
            $scopes = trim($scopes, '{}');
            $scopes = $scopes === '' ? [] : str_getcsv($scopes);
        }
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $scopes, true) && !in_array('*', $scopes, true)) {
                api_v1_log($pdo, 'SCOPE_DENIED', 'ALTO', $client['nome'], ['required'=>$scope]);
                api_v1_json_response(403, ['status'=>'erro','mensagem'=>'Token sem permissão para esta operação']);
            }
        }

        $pdo->prepare("UPDATE api_client_tokens SET ultimo_uso=NOW(), ultimo_ip=:ip WHERE id=:id")
            ->execute([':ip'=>api_v1_client_ip(), ':id'=>$client['id']]);
        api_v1_log($pdo, 'AUTH_OK', 'INFO', $client['nome']);
        return $client;
    } catch (PDOException $e) {
        api_v1_json_response(503, ['status'=>'erro','mensagem'=>'Estrutura de segurança da API ainda não foi aplicada no banco']);
    }
}
?>
