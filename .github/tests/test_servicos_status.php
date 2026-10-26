<?php
// Servicos em segundo plano (api/servicos_status.php): o estado de cada servico
// sai do rastro que ele ja deixa no banco. Testa os casos de cada tipo.
// Cada requisicao roda em um processo PHP separado (o endpoint chama exit).
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');
putenv('JARVIS_SYSTEM_API_TOKEN=ci_system_token_stats_0123456789abcde');

$endpoint = realpath(__DIR__ . '/../../site/var/www/html/api/servicos_status.php');

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
$sess = ['auth_user' => 'ci_admin', 'auth_perfil' => 'admin'];
function servico(array $r, string $id): array {
    foreach ($r['json']['servicos'] ?? [] as $s) if ($s['id'] === $id) return $s;
    fail_test("servico ausente: $id");
}

// Limpa rastros de execucoes anteriores do teste.
$pdo->exec("DELETE FROM device_heartbeats WHERE device_id = 'ci-serv-no'");
$pdo->exec("DELETE FROM dispositivos_cluster WHERE device_id = 'ci-serv-no'");
$pdo->exec("DELETE FROM tarefas_agendadas WHERE titulo = 'CI tarefa vencida'");
$pdo->exec("UPDATE dispositivos_cluster SET status = 'offline' WHERE (tipo IN ('linux-arm','arm-agent') OR capabilities LIKE '%arm-agent%')");

// 1. Sem sessao: recusado.
$r = request(['get' => []]);
if (($r['json']['status'] ?? '') === 'ok') fail_test('servicos entregues sem sessao');

// 2. Estrutura: todos os servicos conhecidos, com estado valido.
$r = request(['get' => [], 'session' => $sess]);
if (($r['json']['status'] ?? '') !== 'ok') fail_test('servicos com sessao falharam: ' . $r['raw'] . $r['err']);
$validos = ['ativo', 'parado', 'atrasado', 'sob_demanda', 'sem_uso', 'sem_sinal'];
$ids = array_column($r['json']['servicos'], 'id');
foreach (['node-agent', 'avatar', 'google-home', 'espcam', 'scheduler', 'cluster-monitor', 'tts', 'web-agent', 'tunnel', 'runpod-agent', 'ssh-agent', 'cluster-site'] as $id)
    if (!in_array($id, $ids, true)) fail_test("servico ausente: $id");
foreach ($r['json']['servicos'] as $s) {
    if (!in_array($s['estado'], $validos, true)) fail_test("estado invalido em {$s['id']}: {$s['estado']}");
    if (isset($s['serie']) && count($s['serie']) !== 7) fail_test("serie invalida em {$s['id']}");
}
foreach (['runpod-agent', 'ssh-agent', 'cluster-site'] as $id)
    if (servico($r, $id)['estado'] !== 'sem_sinal') fail_test("$id nao deixa rastro e deve aparecer como sem sinal");

// 3. No ARM com sinal recente: agente ativo e monitor ativo; o sinal de hoje
//    aparece na ultima posicao da serie (dia calculado pelo relogio do banco).
$pdo->exec("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,capabilities,ultimo_heartbeat) VALUES('ci-serv-no','CI no','linux-arm',CONCAT('ci-',UUID()),'online','[\"arm-agent\"]',NOW() - INTERVAL 5 SECOND)");
$pdo->exec("INSERT INTO device_heartbeats(device_id,health,criado_em) VALUES('ci-serv-no','ok',NOW() - INTERVAL 5 SECOND)");
$r = request(['get' => [], 'session' => $sess]);
$na = servico($r, 'node-agent');
if ($na['estado'] !== 'ativo') fail_test('agente ARM com sinal recente deveria estar ativo: ' . json_encode($na));
if (($na['serie'][6] ?? 0) < 1) fail_test('sinal de hoje fora da posicao de hoje na serie: ' . json_encode($na['serie']));
if (servico($r, 'cluster-monitor')['estado'] !== 'ativo') fail_test('monitor deveria estar ativo com os nos consistentes');

// 4. No marcado como online mas sem sinal ha 10 min: agente parado e monitor parado.
$pdo->exec("UPDATE dispositivos_cluster SET ultimo_heartbeat = NOW() - INTERVAL 10 MINUTE WHERE device_id = 'ci-serv-no'");
$pdo->exec("UPDATE device_heartbeats SET criado_em = NOW() - INTERVAL 10 MINUTE WHERE device_id = 'ci-serv-no'");
$r = request(['get' => [], 'session' => $sess]);
if (servico($r, 'node-agent')['estado'] !== 'parado') fail_test('agente ARM sem sinal ha 10 min deveria estar parado');
if (servico($r, 'cluster-monitor')['estado'] !== 'parado') fail_test('monitor deveria estar parado com no online sem sinal');

// 5. Tarefa ativa vencida ha 40 min sem executar: agendador atrasado.
$pdo->exec("INSERT INTO tarefas_agendadas(titulo,horario,ativo,tipo_acao,payload,proxima_execucao) VALUES('CI tarefa vencida','07:00:00',1,'rele','{}',NOW() - INTERVAL 40 MINUTE)");
$r = request(['get' => [], 'session' => $sess]);
if (servico($r, 'scheduler')['estado'] !== 'atrasado') fail_test('agendador com tarefa vencida deveria estar atrasado');
$res = $r['json']['resumo'] ?? [];
if (!in_array('Agendador', $res['problemas'] ?? [], true)) fail_test('resumo deveria listar o agendador como problema');

$pdo->exec("DELETE FROM tarefas_agendadas WHERE titulo = 'CI tarefa vencida'");
$pdo->exec("DELETE FROM device_heartbeats WHERE device_id = 'ci-serv-no'");
$pdo->exec("DELETE FROM dispositivos_cluster WHERE device_id = 'ci-serv-no'");
echo "OK: servicos em segundo plano: estados ativo/parado/atrasado e sem sinal conferidos\n";
