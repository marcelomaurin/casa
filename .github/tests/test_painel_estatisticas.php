<?php
// Estatisticas dos cartoes do painel (api/painel_estatisticas.php): exige a
// sessao do painel e devolve so contagens, nunca senhas ou chaves.
// Cada requisicao roda em um processo PHP separado (o endpoint chama exit).
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');
putenv('JARVIS_SYSTEM_API_TOKEN=ci_system_token_stats_0123456789abcde');

$endpoint = realpath(__DIR__ . '/../../site/var/www/html/api/painel_estatisticas.php');

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
$pdo->exec("INSERT INTO configuracoes_sistema(chave,valor,descricao) VALUES('openai_api_key','sk-ci-segredo-stats','teste') ON DUPLICATE KEY UPDATE valor='sk-ci-segredo-stats'");

// 1. Sem sessao (nem com a chave mestra): recusado.
$r = request(['get' => []]);
if (($r['json']['status'] ?? '') === 'ok') fail_test('estatisticas sem sessao foram entregues');
$r = request(['get' => [], 'headers' => ['HTTP_X_API_KEY' => getenv('JARVIS_SYSTEM_API_TOKEN')]]);
if (($r['json']['status'] ?? '') === 'ok') fail_test('estatisticas entregues para chave de API (devem exigir sessao)');

// 2. Com sessao: numeros dos itens do menu, sem segredo nenhum.
$r = request(['get' => [], 'session' => ['auth_user' => 'ci_admin', 'auth_perfil' => 'admin']]);
if (($r['json']['status'] ?? '') !== 'ok') fail_test('estatisticas com sessao falharam: ' . $r['raw'] . $r['err']);
$itens = $r['json']['itens'] ?? [];
foreach (['devices', 'cenas', 'iot', 'nodes', 'usuarios', 'api', 'health'] as $id)
    if (!isset($itens[$id])) fail_test("item sem estatistica: $id");
foreach ($itens as $id => $m) {
    if (!array_key_exists('valor', $m) && empty($m['serie'])) fail_test("item vazio: $id");
    if (isset($m['serie']) && (count($m['serie']) !== 7 || array_filter($m['serie'], fn($n) => !is_int($n)))) fail_test("serie invalida em $id");
}
foreach (['sk-ci-segredo-stats', 'token_hash', 'senha', '$2y$'] as $proibido)
    if (stripos($r['raw'], $proibido) !== false) fail_test("estatisticas expoem: $proibido");

// 3. Todo item do menu com estatistica existe no menu (sem id solto).
$menu = file_get_contents(__DIR__ . '/../../site/var/www/html/lcars-site.js');
foreach (array_keys($itens) as $id)
    if (strpos($menu, "{id:'{$id}'") === false) fail_test("estatistica para item que nao existe no menu: $id");

echo "OK: estatisticas do painel exigem sessao, cobrem " . count($itens) . " itens e nao expoem segredos\n";
