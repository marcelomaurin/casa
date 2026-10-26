<?php
// Painel da TV (api/v1/tv.php): cameras ESP32-CAM aparecem, com estado pelo
// sinal de vida, e nenhum token de camera sai na resposta.
// Cada requisicao roda em um processo PHP separado (o endpoint chama exit).
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');
putenv('JARVIS_SYSTEM_API_TOKEN=ci_system_token_stats_0123456789abcde');

$endpoint = realpath(__DIR__ . '/../../site/var/www/html/api/v1/tv.php');

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
$tvTok = 'ci_tv_' . bin2hex(random_bytes(12));
$pdo->exec("DELETE FROM api_client_tokens WHERE nome = 'ci-tv'");
$pdo->exec("DELETE FROM dispositivos_cluster WHERE device_id IN ('ci-tv-cam-on','ci-tv-cam-off','tv-ci')");
$pdo->prepare("INSERT INTO api_client_tokens(nome,device_id,token_hash,token_prefix,scopes,ativo) VALUES('ci-tv','tv-ci',:h,:p,:s,1)")
    ->execute([':h' => hash('sha256', $tvTok), ':p' => substr($tvTok, 0, 8), ':s' => json_encode(['device.read','device.write','commands.read','events.write','home.read','alerts.read','camera.read','family.read'])]);
// A TV pareada tem registro proprio em dispositivos_cluster (como no provision.php).
$pdo->exec("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status) VALUES('tv-ci','CI TV','tv',CONCAT('HASHED:',UUID()),'online')");
$segredo = 'CamTokenSecretoCI0123456789';
$meta = json_encode(['token' => $segredo, 'snapshot_url' => 'http://192.168.2.57:80/capture', 'stream_url' => 'http://192.168.2.57:80/stream']);
$ins = $pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,ip_address,device_token,status,ultimo_heartbeat,metadata) VALUES(:d,:n,'espcam',:ip,:t,:s,:h,:m)");
$ins->execute([':d' => 'ci-tv-cam-on', ':n' => 'CI Garagem', ':ip' => '192.168.2.57', ':t' => $segredo, ':s' => 'offline', ':h' => date('Y-m-d H:i:s'), ':m' => $meta]);
$pdo->exec("UPDATE dispositivos_cluster SET ultimo_heartbeat = NOW() - INTERVAL 5 SECOND WHERE device_id = 'ci-tv-cam-on'");
$ins->execute([':d' => 'ci-tv-cam-off', ':n' => 'CI Quintal', ':ip' => '192.168.2.58', ':t' => $segredo . 'B', ':s' => 'online', ':h' => date('Y-m-d H:i:s'), ':m' => '{}']);
$pdo->exec("UPDATE dispositivos_cluster SET ultimo_heartbeat = NOW() - INTERVAL 10 MINUTE WHERE device_id = 'ci-tv-cam-off'");

// 1. Sem chave: recusado.
$r = request(['get' => ['acao' => 'dashboard']]);
if (($r['json']['status'] ?? '') === 'ok') fail_test('painel da TV entregue sem chave');

// 2. Com a chave da TV: cameras espcam listadas, estado pelo sinal de vida.
$r = request(['get' => ['acao' => 'dashboard'], 'headers' => ['HTTP_AUTHORIZATION' => 'Bearer ' . $tvTok]]);
if (($r['json']['status'] ?? '') !== 'ok') fail_test('painel da TV falhou: ' . $r['raw'] . $r['err']);
$cams = [];
foreach ($r['json']['cameras'] ?? [] as $c) $cams[$c['device_id']] = $c;
if (!isset($cams['ci-tv-cam-on'], $cams['ci-tv-cam-off'])) fail_test('cameras espcam nao apareceram para a TV: ' . json_encode(array_keys($cams)));
if ($cams['ci-tv-cam-on']['status'] !== 'online') fail_test('camera com sinal recente deveria estar online (campo status gravado era offline)');
if ($cams['ci-tv-cam-off']['status'] !== 'offline') fail_test('camera sem sinal ha 10 min deveria estar offline (campo status gravado era online)');
if ($cams['ci-tv-cam-on']['capture_url'] !== 'http://192.168.2.57:80/capture') fail_test('capture_url deveria vir do registro da camera');
if (array_key_exists('metadata', $cams['ci-tv-cam-on'])) fail_test('metadata nao deve ser repassado a TV');

// 3. Nenhum segredo de camera na resposta.
if (strpos($r['raw'], $segredo) !== false) fail_test('token de camera exposto no painel da TV');

$pdo->exec("DELETE FROM dispositivos_cluster WHERE device_id IN ('ci-tv-cam-on','ci-tv-cam-off','tv-ci')");
$pdo->exec("DELETE FROM api_client_tokens WHERE nome = 'ci-tv'");
echo "OK: painel da TV lista as ESP32-CAM com estado real e sem expor tokens\n";
