<?php
// API v1 para o Casa Mobile: acompanhamento/gestao de daemons e localizacao.
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
function v1(string $base, string $metodo, string $rota, string $token, ?array $corpo = null): array {
    $ch = curl_init($base . '/api/v1/' . $rota);
    $h = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    if ($corpo !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $metodo]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$raw, true), $raw];
}

$pdo = get_db_pdo();
$base = rtrim(getenv('CASA_SITE_BASE_URL') ?: 'http://127.0.0.1:8099', '/');
$suf = bin2hex(random_bytes(4));

// Usuarios: um administrador e um familiar comum.
$pdo->prepare("INSERT INTO usuarios (login, nome, senha, perfil, ativo) VALUES (:l, 'Admin CI', :s, 'admin', 1)")
    ->execute([':l' => "ci_admin_{$suf}", ':s' => password_hash('x', PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO usuarios (login, nome, senha, perfil, ativo) VALUES (:l, 'Familia CI', :s, 'familia', 1)")
    ->execute([':l' => "ci_familia_{$suf}", ':s' => password_hash('x', PASSWORD_DEFAULT)]);

// Dois celulares pareados: um pelo administrador, outro pelo familiar.
$escopos = json_encode(['status.read', 'mobile.read', 'mobile.write', 'family.read', 'family.write']);
$tokens = [];
foreach (['admin' => "ci_admin_{$suf}", 'familia' => "ci_familia_{$suf}"] as $quem => $pareadoPor) {
    $dev = "mobile-ci-{$quem}-{$suf}";
    $tok = "casa_mob_ci_{$quem}_" . bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO dispositivos_cluster (device_id, nome, tipo, device_token, device_token_hash, status, metadata)
        VALUES (:d, :n, 'mobile', :p, :h, 'online', :m)")
        ->execute([':d' => $dev, ':n' => "Celular {$quem}", ':p' => "HASHED:{$dev}", ':h' => hash('sha256', $tok),
            ':m' => json_encode(['paired_by' => $pareadoPor])]);
    $pdo->prepare("INSERT INTO api_client_tokens (nome, device_id, token_hash, token_prefix, scopes, ativo) VALUES (:n, :d, :h, :p, :s, 1)")
        ->execute([':n' => "Celular: {$quem}", ':d' => $dev, ':h' => hash('sha256', $tok), ':p' => substr($tok, 0, 20), ':s' => $escopos]);
    $tokens[$quem] = ['token' => $tok, 'device' => $dev];
}

// No do cluster com um servico ativo.
$pdo->prepare("INSERT INTO dispositivos_cluster (device_id, nome, tipo, device_token, device_token_hash, status, ip_address, ultimo_heartbeat, capabilities, metadata)
    VALUES (:d, 'No CI', 'arm-agent', :p, :h, 'online', NULL, NOW(), :c, :m)")
    ->execute([':d' => "node-ci-{$suf}", ':p' => "HASHED:node-ci-{$suf}", ':h' => hash('sha256', 'n' . $suf),
        ':c' => json_encode(['arm-agent']), ':m' => json_encode(['assigned_services' => ['casa-cluster-site', 'casa-tts'], 'services' => ['casa-tts' => 'active']])]);
$noNumero = (int)$pdo->lastInsertId();

// --- Daemons ---
[$c, $j, $raw] = v1($base, 'GET', 'daemons.php?acao=listar', $tokens['familia']['token']);
confere($c === 200 && isset($j['nos']) && is_array($j['nos']), 'celular lista os daemons', $raw);
confere(($j['pode_controlar'] ?? null) === false, 'celular do familiar nao controla daemons', $j['pode_controlar'] ?? null);
$achou = null;
foreach ((array)($j['nos'] ?? []) as $n) if (($n['device_id'] ?? '') === "node-ci-{$suf}") $achou = $n;
confere($achou !== null && !empty($achou['servicos']), 'no do teste aparece com servicos', $achou);

[$c] = v1($base, 'POST', 'daemons.php?acao=controlar', $tokens['familia']['token'],
    ['no' => "device:{$noNumero}", 'servico' => 'casa-tts', 'operacao' => 'restart', 'confirmar' => true]);
confere($c === 403, 'familiar nao reinicia daemon', $c);

[$c, $j] = v1($base, 'GET', 'daemons.php?acao=listar', $tokens['admin']['token']);
confere(($j['pode_controlar'] ?? null) === true, 'celular pareado pelo administrador pode controlar');

[$c] = v1($base, 'POST', 'daemons.php?acao=controlar', $tokens['admin']['token'],
    ['no' => "device:{$noNumero}", 'servico' => 'casa-tts', 'operacao' => 'restart']);
confere($c === 428, 'controle exige confirmacao', $c);
[$c] = v1($base, 'POST', 'daemons.php?acao=controlar', $tokens['admin']['token'],
    ['no' => "device:{$noNumero}", 'servico' => 'casa-cluster-site', 'operacao' => 'stop', 'confirmar' => true]);
confere($c === 403, 'servico protegido nao para', $c);
[$c] = v1($base, 'POST', 'daemons.php?acao=controlar', $tokens['admin']['token'],
    ['no' => "device:{$noNumero}", 'servico' => 'rm -rf', 'operacao' => 'stop', 'confirmar' => true]);
confere($c === 422, 'nome de servico invalido recusado', $c);
[$c, $j, $raw] = v1($base, 'POST', 'daemons.php?acao=controlar', $tokens['admin']['token'],
    ['no' => "device:{$noNumero}", 'servico' => 'casa-tts', 'operacao' => 'stop', 'confirmar' => true]);
confere($c === 200 && ($j['state'] ?? '') === 'inactive', 'administrador para o daemon (comando enfileirado)', $raw);
$fila = (int)$pdo->query("SELECT COUNT(*) FROM device_commands WHERE device_id='node-ci-{$suf}' AND comando='service.control'")->fetchColumn();
confere($fila >= 1, 'comando foi para a fila do no', $fila);

// --- Localizacao ---
[$c, $j] = v1($base, 'POST', 'localizacao.php?acao=enviar', $tokens['familia']['token'], ['latitude' => 95, 'longitude' => 10]);
confere($c === 422, 'latitude invalida recusada', $c);
[$c, $j, $raw] = v1($base, 'POST', 'localizacao.php?acao=enviar', $tokens['familia']['token'],
    ['latitude' => -21.0547, 'longitude' => -47.9926, 'precisao' => 12.5, 'bateria' => 81, 'origem' => 'gps', 'capturado_em' => time() * 1000]);
confere($c === 200 && ($j['gravado'] ?? false) === true, 'celular envia a localizacao', $raw);
[$c, $j] = v1($base, 'POST', 'localizacao.php?acao=enviar', $tokens['familia']['token'], ['latitude' => -21.06, 'longitude' => -47.99]);
confere($c === 200 && ($j['gravado'] ?? true) === false, 'envios muito proximos sao ignorados', $j);
[$c, $j, $raw] = v1($base, 'GET', 'localizacao.php?acao=ultimas', $tokens['admin']['token']);
$pos = null;
foreach ((array)($j['aparelhos'] ?? []) as $a) if (($a['device_id'] ?? '') === $tokens['familia']['device']) $pos = $a;
confere($pos !== null && abs((float)$pos['latitude'] + 21.0547) < 0.0001 && (int)$pos['bateria'] === 81, 'ultima posicao visivel para a familia', $raw);
[$c, $j] = v1($base, 'GET', 'localizacao.php?acao=historico&horas=24', $tokens['familia']['token']);
confere($c === 200 && count($j['pontos'] ?? []) === 1, 'historico do proprio aparelho', $j);
[$c, $j] = v1($base, 'POST', 'localizacao.php?acao=apagar', $tokens['familia']['token'], []);
confere($c === 200 && (int)($j['removidos'] ?? 0) === 1, 'aparelho apaga o proprio historico', $j);

// Limpeza
foreach ($tokens as $t) {
    $pdo->prepare('DELETE FROM api_client_tokens WHERE device_id=:d')->execute([':d' => $t['device']]);
    $pdo->prepare('DELETE FROM dispositivos_cluster WHERE device_id=:d')->execute([':d' => $t['device']]);
    $pdo->prepare('DELETE FROM mobile_localizacoes WHERE device_id=:d')->execute([':d' => $t['device']]);
}
$pdo->prepare('DELETE FROM device_commands WHERE device_id=:d')->execute([':d' => "node-ci-{$suf}"]);
$pdo->prepare('DELETE FROM dispositivos_cluster WHERE device_id=:d')->execute([':d' => "node-ci-{$suf}"]);
$pdo->prepare("DELETE FROM usuarios WHERE login IN (:a, :b)")->execute([':a' => "ci_admin_{$suf}", ':b' => "ci_familia_{$suf}"]);

if ($falhas > 0) { fwrite(STDERR, "{$falhas} verificacao(oes) falharam\n"); exit(1); }
echo "Daemons e localizacao: tudo certo\n";
