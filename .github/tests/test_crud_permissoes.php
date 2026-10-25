<?php
// Permissoes do cadastro generico (api/crud.php): uma chave de placa ou de
// celular nao pode ler nem alterar usuarios, chaves e configuracoes.
// Cada requisicao roda em um processo PHP separado (o endpoint chama exit).
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');
putenv('JARVIS_SYSTEM_API_TOKEN=ci_system_token_crud_0123456789abcdef');

$endpoint = realpath(__DIR__ . '/../../site/var/www/html/api/crud.php');

// Modo filho: executa uma requisicao e sai.
if (($argv[1] ?? '') === '--request') {
    $spec = json_decode((string)stream_get_contents(STDIN), true);
    if (!empty($spec['session'])) {
        session_id('ci' . bin2hex(random_bytes(6)));
        @session_start();
        foreach ($spec['session'] as $k => $v) $_SESSION[$k] = $v;
    }
    $_GET = $spec['get'] ?? [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = $spec['method'] ?? 'GET';
    $_SERVER['REMOTE_ADDR'] = '198.51.100.88';
    $_SERVER['REQUEST_URI'] = '/casa/api/crud.php';
    foreach (($spec['headers'] ?? []) as $k => $v) $_SERVER[$k] = $v;
    $GLOBALS['__ci_body'] = json_encode($spec['body'] ?? []);
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', CiPhpStream::class);
    require $endpoint;
    exit(0);
}

// php://input simulado para o processo filho; demais php:// seguem o padrao.
class CiPhpStream {
    public $context;
    private $data = '';
    private $pos = 0;
    private $real = null;
    public function stream_open($path, $mode, $options, &$opened) {
        if ($path === 'php://input') { $this->data = (string)$GLOBALS['__ci_body']; return true; }
        stream_wrapper_restore('php');
        $this->real = fopen($path, $mode);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
        return $this->real !== false;
    }
    public function stream_read($count) {
        if ($this->real) return fread($this->real, $count);
        $r = substr($this->data, $this->pos, $count); $this->pos += strlen($r); return $r;
    }
    public function stream_write($d) { return $this->real ? fwrite($this->real, $d) : 0; }
    public function stream_eof() { return $this->real ? feof($this->real) : $this->pos >= strlen($this->data); }
    public function stream_stat() { return []; }
    public function stream_close() { if ($this->real) fclose($this->real); }
}

function fail_test(string $msg): void { fwrite(STDERR, "FALHA: $msg\n"); exit(10); }

function request(array $spec): array {
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, '--request'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($spec));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $json = json_decode($out, true);
    return ['raw' => $out, 'err' => $err, 'json' => is_array($json) ? $json : null];
}
function ok(array $r): bool { return ($r['json']['status'] ?? '') === 'sucesso'; }

require __DIR__ . '/../../site/var/www/html/api/db.php';
$pdo = get_db_pdo();

// Chaves de teste: uma de placa (escopo limitado) e uma de administrador ('*').
$tokDevice = 'ci_crud_device_' . bin2hex(random_bytes(12));
$tokAdmin = 'ci_crud_admin_' . bin2hex(random_bytes(12));
$pdo->exec("DELETE FROM api_client_tokens WHERE nome LIKE 'ci-crud-%'");
$ins = $pdo->prepare("INSERT INTO api_client_tokens(nome,token_hash,token_prefix,scopes,ativo) VALUES(:n,:h,:p,:s,1)");
$ins->execute([':n' => 'ci-crud-placa', ':h' => hash('sha256', $tokDevice), ':p' => substr($tokDevice, 0, 8), ':s' => json_encode(['device.read', 'device.write'])]);
$ins->execute([':n' => 'ci-crud-admin', ':h' => hash('sha256', $tokAdmin), ':p' => substr($tokAdmin, 0, 8), ':s' => json_encode(['*'])]);
$pdo->exec("INSERT INTO configuracoes_sistema(chave,valor,descricao) VALUES('openai_api_key','sk-ci-segredo-crud','teste') ON DUPLICATE KEY UPDATE valor='sk-ci-segredo-crud'");
$pdo->exec("DELETE FROM usuarios WHERE login LIKE 'ci_crud_%'");

$dev = ['HTTP_X_API_KEY' => $tokDevice];
$adm = ['HTTP_X_API_KEY' => $tokAdmin];

// 1. Chave de placa: nada de cadastro generico nem acoes de administracao.
$bloqueadas = [
    ['get' => ['tabela' => 'usuarios', 'acao' => 'listar']],
    ['get' => ['tabela' => 'api_client_tokens', 'acao' => 'listar']],
    ['get' => ['tabela' => 'configuracoes_sistema', 'acao' => 'listar']],
    ['get' => ['tabela' => 'frases', 'acao' => 'listar']],
    ['get' => ['acao' => 'listar_api_keys']],
    ['get' => ['acao' => 'obter_external_api_key']],
    ['method' => 'POST', 'get' => ['acao' => 'criar_api_key'], 'body' => ['nome' => 'ci-crud-invasor']],
    ['method' => 'POST', 'get' => ['tabela' => 'usuarios', 'acao' => 'criar'], 'body' => ['nome' => 'x', 'login' => 'ci_crud_invasor', 'senha' => 'x', 'perfil' => 'admin', 'ativo' => 1]],
    ['method' => 'POST', 'get' => ['tabela' => 'configuracoes_sistema', 'acao' => 'atualizar'], 'body' => ['chave' => 'openai_api_key', 'valor' => 'trocada']],
];
foreach ($bloqueadas as $spec) {
    $r = request($spec + ['headers' => $dev]);
    if (ok($r) || strpos($r['raw'], 'sk-ci-segredo-crud') !== false)
        fail_test('chave de placa conseguiu ' . json_encode($spec['get']) . ': ' . substr($r['raw'], 0, 200));
}
if ((int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE login='ci_crud_invasor'")->fetchColumn() !== 0) fail_test('placa criou usuario');
if ((int)$pdo->query("SELECT COUNT(*) FROM api_client_tokens WHERE nome='ci-crud-invasor'")->fetchColumn() !== 0) fail_test('placa criou chave de API');
if ($pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave='openai_api_key'")->fetchColumn() !== 'sk-ci-segredo-crud') fail_test('placa alterou configuracao');

// 2. Chave de placa continua podendo mandar heartbeat.
$r = request(['method' => 'POST', 'get' => ['acao' => 'heartbeat'], 'headers' => $dev, 'body' => ['device_id' => 'ci-crud-no', 'hostname' => 'ci-crud-no']]);
if (!ok($r)) fail_test('heartbeat da placa foi recusado: ' . $r['raw'] . $r['err']);

// 3. Administrador: lista sem senha nem resumo das chaves.
$r = request(['get' => ['tabela' => 'usuarios', 'acao' => 'listar'], 'headers' => $adm]);
if (!ok($r)) fail_test('admin nao listou usuarios: ' . $r['raw'] . $r['err']);
foreach ($r['json']['dados'] as $u) if (array_key_exists('senha', $u)) fail_test('listagem de usuarios expoe a senha');
$r = request(['get' => ['tabela' => 'api_client_tokens', 'acao' => 'listar'], 'headers' => $adm]);
if (!ok($r)) fail_test('admin nao listou chaves: ' . $r['raw'] . $r['err']);
foreach ($r['json']['dados'] as $t) if (array_key_exists('token_hash', $t) || array_key_exists('token', $t)) fail_test('listagem de chaves expoe token/token_hash');
$r = request(['get' => ['tabela' => 'configuracoes_sistema', 'acao' => 'listar'], 'headers' => $adm]);
if (!ok($r)) fail_test('admin nao listou configuracoes');

// 4. Sessao do painel com perfil admin: cria usuario e a senha vai como hash.
$sAdmin = ['auth_user' => 'ci_admin', 'auth_perfil' => 'admin'];
$r = request(['method' => 'POST', 'get' => ['tabela' => 'usuarios', 'acao' => 'criar'], 'session' => $sAdmin,
    'body' => ['nome' => 'CI Crud', 'login' => 'ci_crud_user', 'senha' => 'SenhaClara123', 'perfil' => 'familia', 'ativo' => 1]]);
if (!ok($r)) fail_test('sessao admin nao criou usuario: ' . $r['raw'] . $r['err']);
$hash = (string)$pdo->query("SELECT senha FROM usuarios WHERE login='ci_crud_user'")->fetchColumn();
if ($hash === 'SenhaClara123' || !password_verify('SenhaClara123', $hash)) fail_test('senha gravada em texto puro pelo cadastro generico');

// 5. Sessao com outro perfil: usa o cadastro comum, mas nao os dados sensiveis.
$sFamilia = ['auth_user' => 'ci_familia', 'auth_perfil' => 'familia'];
$r = request(['get' => ['tabela' => 'frases', 'acao' => 'listar'], 'session' => $sFamilia]);
if (!ok($r)) fail_test('sessao comum nao listou frases: ' . $r['raw'] . $r['err']);
foreach ([['tabela' => 'usuarios', 'acao' => 'listar'], ['tabela' => 'configuracoes_sistema', 'acao' => 'listar'], ['acao' => 'listar_api_keys']] as $g) {
    $r = request(['get' => $g, 'session' => $sFamilia]);
    if (ok($r)) fail_test('sessao comum acessou ' . json_encode($g));
}

$pdo->exec("DELETE FROM usuarios WHERE login LIKE 'ci_crud_%'");
$pdo->exec("DELETE FROM api_client_tokens WHERE nome LIKE 'ci-crud-%'");
$pdo->exec("DELETE FROM arm_nodes WHERE hostname='ci-crud-no'");
echo "OK: crud.php separa administrador, usuario do painel e chaves de dispositivo\n";
