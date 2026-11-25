<?php
// Garante que o CRUD central e restrito ao administrador e que o IP do cliente
// nao pode ser forjado pelo cabecalho CF-Connecting-IP.
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';

$falhas = 0;
function confere(bool $ok, string $msg, $detalhe = null): void {
    global $falhas;
    if ($ok) { echo "[OK] {$msg}\n"; return; }
    $falhas++;
    fwrite(STDERR, "[FALHA] {$msg}\n");
    if ($detalhe !== null) fwrite(STDERR, (is_string($detalhe) ? $detalhe : json_encode($detalhe, JSON_UNESCAPED_UNICODE)) . "\n");
}

// 1. IP do cliente: CF-Connecting-IP so vale vindo da Cloudflare.
$casos = [
    ['200.1.2.3', '192.168.2.10', '200.1.2.3'],
    ['162.158.10.5', '177.10.20.30', '177.10.20.30'],
    ['2606:4700::1', '177.10.20.30', '177.10.20.30'],
    ['2607:f8b0::1', '192.168.2.1', '2607:f8b0::1'],
    ['200.1.2.3', null, '200.1.2.3'],
];
foreach ($casos as [$remoto, $cf, $esperado]) {
    $_SERVER['REMOTE_ADDR'] = $remoto;
    if ($cf === null) unset($_SERVER['HTTP_CF_CONNECTING_IP']); else $_SERVER['HTTP_CF_CONNECTING_IP'] = $cf;
    confere(casa_client_ip() === $esperado, "IP de {$remoto} com CF=" . ($cf ?? '-') . " => {$esperado}", casa_client_ip());
}
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['REMOTE_ADDR']);

// 2. CRUD via HTTP.
$pdo = get_db_pdo();
$base = rtrim(getenv('CASA_SITE_BASE_URL') ?: 'http://127.0.0.1:8099', '/');
$sistema = get_system_api_token();
if ($sistema === '') {
    $sistema = 'ci_sistema_' . bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor, descricao) VALUES ('system_api_token', :v, 'CI') ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
        ->execute([':v' => $sistema]);
}
confere($sistema !== '', 'chave do sistema disponivel para o teste');

$cliente = 'cli_crud_' . bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO api_client_tokens (nome, token_hash, token_prefix, scopes, ativo, criado_em) VALUES (:n, :h, :p, :s, 1, NOW())")
    ->execute([':n' => 'ci-crud-' . bin2hex(random_bytes(3)), ':h' => hash('sha256', $cliente), ':p' => substr($cliente, 0, 14) . '...', ':s' => json_encode(['*'])]);
$clienteId = (int)$pdo->lastInsertId();

function crud_http(string $base, string $query, string $token, ?array $corpo = null, array $extra = []): array {
    $ch = curl_init($base . '/api/crud.php?' . $query);
    $h = array_merge(['Accept: application/json', 'Authorization: Bearer ' . $token], $extra);
    if ($corpo !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20]);
    if ($corpo !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo)); }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$raw, true), $raw];
}

[$c] = crud_http($base, 'tabela=usuarios&acao=listar', $cliente);
confere($c === 403, 'token de cliente nao lista usuarios', $c);
[$c] = crud_http($base, 'acao=criar_api_key', $cliente, ['nome' => 'invasor', 'scopes' => ['*']]);
confere($c === 403, 'token de cliente nao cria chave de API', $c);
[$c] = crud_http($base, 'tabela=api_client_tokens&acao=listar', $cliente);
confere($c === 403, 'token de cliente nao lista chaves', $c);
[$c] = crud_http($base, 'acao=obter_external_api_key', $cliente);
confere($c === 403, 'token de cliente nao le a chave mestre', $c);
[$c] = crud_http($base, 'acao=heartbeat', $cliente, ['device_id' => 'ci-crud-node', 'hostname' => 'ci-crud-node']);
confere($c === 200, 'token de cliente ainda envia heartbeat', $c);

[$c, $j, $raw] = crud_http($base, 'tabela=usuarios&acao=listar', $sistema);
confere($c === 200, 'chave do sistema lista usuarios', $raw);
$temSenha = false;
foreach ((array)($j['dados'] ?? []) as $u) if (is_array($u) && array_key_exists('senha', $u)) $temSenha = true;
confere(!$temSenha, 'listagem de usuarios nao expoe a senha');
[$c] = crud_http($base, 'tabela=usuarios&acao=criar', $sistema, ['login' => 'x' . bin2hex(random_bytes(3)), 'senha' => 'texto-puro']);
confere($c === 403, 'escrita generica em usuarios bloqueada', $c);
[$c] = crud_http($base, 'tabela=frases&acao=listar', $sistema);
confere($c === 200, 'administrador continua usando o CRUD', $c);

// 3. Chave de API nova: so o resumo e gravado, a chave completa volta uma vez.
[$c, $j] = crud_http($base, 'acao=criar_api_key', $sistema, ['nome' => 'ci-resumo-' . bin2hex(random_bytes(3)), 'scopes' => ['devices:read']]);
$novaId = (int)($j['token']['id'] ?? 0);
confere($c === 200 && $novaId > 0 && !empty($j['chave_completa']), 'administrador cria chave e recebe a chave completa', $j);
$guardada = $pdo->query('SELECT token, token_hash FROM api_client_tokens WHERE id=' . $novaId)->fetch(PDO::FETCH_ASSOC);
confere(empty($guardada['token']) && $guardada['token_hash'] === hash('sha256', (string)($j['chave_completa'] ?? '')), 'chave nova gravada so como resumo', $guardada);
[$c, $j] = crud_http($base, 'acao=listar_api_keys', $sistema);
$vazou = false;
foreach ((array)($j['dados'] ?? []) as $k) if (is_array($k) && array_key_exists('token', $k)) $vazou = true;
confere($c === 200 && !$vazou, 'listagem de chaves nao devolve a chave completa', $c);
$pdo->prepare('DELETE FROM api_client_tokens WHERE id=:id')->execute([':id' => $novaId]);

// 4. Tokens de dispositivo: marcador HASHED nao autentica; texto antigo autentica e ganha resumo.
$sufixo = bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO dispositivos_cluster (device_id, nome, tipo, device_token, device_token_hash, status) VALUES (:d, 'ci marcador', 'ci', :t, :h, 'online')")
    ->execute([':d' => 'ci-hashed-' . $sufixo, ':t' => 'HASHED:ci-hashed-' . $sufixo, ':h' => hash('sha256', 'segredo-' . $sufixo)]);
[$c] = crud_http($base, 'acao=heartbeat', 'HASHED:ci-hashed-' . $sufixo, ['device_id' => 'ci-crud-node']);
confere($c === 401, 'marcador HASHED nao serve como token', $c);
confere(casa_dispositivo_por_token($pdo, 'segredo-' . $sufixo) !== null, 'token verdadeiro autentica pelo resumo');
$legado = 'legado-' . $sufixo;
$pdo->prepare("INSERT INTO dispositivos_cluster (device_id, nome, tipo, device_token, status) VALUES (:d, 'ci legado', 'ci', :t, 'online')")
    ->execute([':d' => 'ci-legado-' . $sufixo, ':t' => $legado]);
[$c] = crud_http($base, 'acao=heartbeat', $legado, ['device_id' => 'ci-crud-node']);
confere($c === 200, 'token antigo em texto continua funcionando', $c);
$hashLegado = $pdo->query("SELECT device_token_hash FROM dispositivos_cluster WHERE device_id='ci-legado-{$sufixo}'")->fetchColumn();
confere($hashLegado === hash('sha256', $legado), 'token antigo recebe resumo no primeiro uso', $hashLegado);
$pdo->exec("DELETE FROM dispositivos_cluster WHERE device_id IN ('ci-hashed-{$sufixo}','ci-legado-{$sufixo}')");

// 5. Senha em texto puro e convertida para password_hash no primeiro acesso valido.
$login = 'ci_senha_' . $sufixo;
$pdo->prepare("INSERT INTO usuarios (login, nome, senha, ativo) VALUES (:l, 'CI', 'senha-antiga', 1)")->execute([':l' => $login]);
$u = $pdo->query("SELECT * FROM usuarios WHERE login='{$login}'")->fetch(PDO::FETCH_ASSOC);
confere(!casa_verificar_senha($pdo, $u, 'errada'), 'senha errada recusada');
confere(casa_verificar_senha($pdo, $u, 'senha-antiga'), 'senha antiga em texto aceita uma vez');
$novaSenha = (string)$pdo->query("SELECT senha FROM usuarios WHERE login='{$login}'")->fetchColumn();
confere(password_get_info($novaSenha)['algo'] !== null && password_get_info($novaSenha)['algo'] !== 0 && password_verify('senha-antiga', $novaSenha), 'senha convertida para password_hash');
$pdo->prepare('DELETE FROM usuarios WHERE login=:l')->execute([':l' => $login]);

$pdo->prepare('DELETE FROM api_client_tokens WHERE id=:id')->execute([':id' => $clienteId]);
$pdo->prepare("DELETE FROM arm_nodes WHERE device_id='ci-crud-node'")->execute();

if ($falhas > 0) { fwrite(STDERR, "{$falhas} verificacao(oes) falharam\n"); exit(1); }
echo "CRUD e IP do cliente: tudo certo\n";
