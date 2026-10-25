<?php
// ESP32-CAM <-> site: autenticacao do api/camera_discovery.php.
// Cada requisicao roda em um processo PHP separado (o endpoint chama exit),
// com $_GET/$_SERVER/corpo montados a partir de uma especificacao JSON.
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');
putenv('JARVIS_SYSTEM_API_TOKEN=ci_system_token_camera_0123456789');

$endpoints = [
    'legacy' => realpath(__DIR__ . '/../../site/var/www/html/api/camera_discovery.php'),
    'v1' => realpath(__DIR__ . '/../../site/var/www/html/api/v1/camera.php'),
];

// Modo filho: executa uma requisicao e sai.
if (($argv[1] ?? '') === '--request') {
    $spec = json_decode((string)stream_get_contents(STDIN), true);
    $_GET = $spec['get'] ?? [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = $spec['method'] ?? 'GET';
    $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
    $_SERVER['REQUEST_URI'] = '/casa/api/camera_discovery.php';
    foreach (($spec['headers'] ?? []) as $k => $v) $_SERVER[$k] = $v;
    $GLOBALS['__ci_body'] = json_encode($spec['body'] ?? []);
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', CiPhpStream::class);
    require $endpoints[$spec['endpoint'] ?? 'legacy'];
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

require __DIR__ . '/../../site/var/www/html/api/db.php';
$pdo = get_db_pdo();

$apiKey = ['HTTP_X_API_KEY' => getenv('JARVIS_SYSTEM_API_TOKEN')];
$did = 'esp32cam-c1test';
$goodToken = 'CamTokenCiValido0123456789';
$pdo->prepare("DELETE FROM dispositivos_cluster WHERE device_id IN (:a,:b)")->execute([':a' => $did, ':b' => 'esp32cam-intruso']);

// Garante as tabelas criadas pelo proprio endpoint (redes_wifi_casa etc.).
request(['get' => ['acao' => 'listar'], 'headers' => $apiKey]);
$pdo->exec("DELETE FROM redes_wifi_casa");

// 1. Registro anonimo e recusado e nao grava nada.
$r = request(['method' => 'POST', 'get' => ['acao' => 'register_token'],
    'body' => ['device_id' => 'esp32cam-intruso', 'token' => 'TokenDoAtacante0123456789', 'ip' => '203.0.113.9']]);
if (!empty($r['json']['ok'])) fail_test('register_token anonimo foi aceito: ' . $r['raw']);
$n = $pdo->query("SELECT COUNT(*) FROM dispositivos_cluster WHERE device_id='esp32cam-intruso'")->fetchColumn();
if ((int)$n !== 0) fail_test('register_token anonimo gravou a camera');

// 2. Registro com chave de API funciona.
$r = request(['method' => 'POST', 'get' => ['acao' => 'register_token'], 'headers' => $apiKey,
    'body' => ['device_id' => $did, 'token' => $goodToken, 'mac' => 'AA:BB:CC:00:11:22', 'ip' => '192.168.2.57']]);
if (empty($r['json']['ok'])) fail_test('register_token com chave falhou: ' . $r['raw'] . $r['err']);

// 3. Leituras anonimas sao recusadas: listar, redes Wi-Fi e snapshot.
foreach (['listar', 'get_house_wifis', 'get_snapshot', 'get_tokens'] as $acao) {
    $r = request(['get' => ['acao' => $acao, 'device_id' => $did]]);
    if (!empty($r['json']['ok']) || strpos($r['raw'], $goodToken) !== false || strpos($r['raw'], '<svg') !== false)
        fail_test("$acao anonimo devolveu dados: " . substr($r['raw'], 0, 200));
}

// 4. listar autenticado nao expoe o token da camera.
$r = request(['get' => ['acao' => 'listar'], 'headers' => $apiKey]);
if (empty($r['json']['ok'])) fail_test('listar com chave falhou: ' . $r['raw'] . $r['err']);
if (strpos($r['raw'], $goodToken) !== false) fail_test('listar expoe o token da camera');

// 5. Sem redes Wi-Fi padrao (senha123).
$r = request(['get' => ['acao' => 'get_house_wifis'], 'headers' => $apiKey]);
if (strpos($r['raw'], 'senha123') !== false) fail_test('redes Wi-Fi padrao recriadas');

// 6. Heartbeat: token errado -> 401; token certo -> ok.
$hb = ['acao' => 'heartbeat', 'device_id' => $did, 'ip' => '192.168.2.58', 'rssi' => '-60'];
$r = request(['get' => $hb, 'headers' => ['HTTP_X_CAMERA_TOKEN' => 'TokenErrado0123456789xx']]);
if (!empty($r['json']['ok'])) fail_test('heartbeat com token errado foi aceito');
$r = request(['get' => $hb, 'headers' => ['HTTP_X_CAMERA_TOKEN' => $goodToken]]);
if (empty($r['json']['ok'])) fail_test('heartbeat com token certo falhou: ' . $r['raw'] . $r['err']);
$ip = $pdo->query("SELECT ip_address FROM dispositivos_cluster WHERE device_id='{$did}'")->fetchColumn();
if ($ip !== '192.168.2.58') fail_test('heartbeat nao atualizou o IP');

// 7. Heartbeat de camera nao registrada -> recusado e nao cria registro.
$r = request(['get' => ['acao' => 'heartbeat', 'device_id' => 'esp32cam-intruso'],
    'headers' => ['HTTP_X_CAMERA_TOKEN' => 'TokenDoAtacante0123456789']]);
if (!empty($r['json']['ok'])) fail_test('heartbeat de camera nao registrada foi aceito');

// 8. API v1 (firmware 2.0.1): so heartbeat, com token valido.
$r = request(['endpoint' => 'v1', 'get' => array_merge($hb, ['ip' => '192.168.2.59']), 'headers' => ['HTTP_X_CAMERA_TOKEN' => $goodToken]]);
if (empty($r['json']['ok'])) fail_test('heartbeat v1 com token certo falhou: ' . $r['raw'] . $r['err']);
$ip = $pdo->query("SELECT ip_address FROM dispositivos_cluster WHERE device_id='{$did}'")->fetchColumn();
if ($ip !== '192.168.2.59') fail_test('heartbeat v1 nao atualizou o IP');
$r = request(['endpoint' => 'v1', 'get' => $hb, 'headers' => ['HTTP_X_CAMERA_TOKEN' => 'TokenErrado0123456789xx']]);
if (!empty($r['json']['ok'])) fail_test('heartbeat v1 com token errado foi aceito');
$r = request(['endpoint' => 'v1', 'get' => $hb]);
if (!empty($r['json']['ok'])) fail_test('heartbeat v1 sem X-Camera-Token foi aceito');
foreach (['listar', 'get_house_wifis', 'register_token', 'get_tokens'] as $acao) {
    $r = request(['endpoint' => 'v1', 'get' => ['acao' => $acao], 'headers' => ['HTTP_X_CAMERA_TOKEN' => $goodToken] + $apiKey]);
    if (!empty($r['json']['ok']) || strpos($r['raw'], $goodToken) !== false)
        fail_test("API v1 de camera aceitou a acao $acao: " . substr($r['raw'], 0, 200));
}

$pdo->prepare("DELETE FROM dispositivos_cluster WHERE device_id=:d")->execute([':d' => $did]);
echo "OK: camera_discovery exige autenticacao, protege tokens e valida o heartbeat\n";
