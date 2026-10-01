<?php
// Segurança: contagem de falhas por IP, bloqueio com data, limpeza após sucesso e registro de eventos.
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');
define('CASA_SEGURANCA_SEM_AUTOCHECK', true);

require __DIR__ . '/../../site/var/www/html/api/db.php';
require __DIR__ . '/../../site/var/www/html/api/seguranca.php';
$pdo = get_db_pdo();

function fail_test(string $msg): void { fwrite(STDERR, "FALHA: $msg\n"); exit(10); }

$ip = '203.0.113.201';
$_SERVER['REMOTE_ADDR'] = $ip;
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.9.9.9';  // ignorado: definido pelo cliente
if (get_client_ip() !== $ip) fail_test('X-Forwarded-For não pode definir o IP');
$pdo->prepare("DELETE FROM seguranca_ips_bloqueados WHERE ip_address=:ip")->execute([':ip' => $ip]);
$pdo->prepare("DELETE FROM seguranca_logs WHERE origem_ip=:ip")->execute([':ip' => $ip]);

for ($i = 1; $i < SEG_MAX_FALHAS; $i++) {
    if (registrar_falha_seguranca("teste $i", 'AVISO', 'painel', 'ci') !== null) fail_test("bloqueou cedo na falha $i");
}
if (seguranca_bloqueio_ativo($pdo, $ip)) fail_test('bloqueio antes do limite');

// Falhas fora da janela não somam.
$pdo->prepare("UPDATE seguranca_ips_bloqueados SET ultima_falha=DATE_SUB(NOW(), INTERVAL " . (SEG_JANELA_MIN + 1) . " MINUTE) WHERE ip_address=:ip")->execute([':ip' => $ip]);
registrar_falha_seguranca('fora da janela', 'AVISO', 'painel', 'ci');
$n = (int)$pdo->query("SELECT tentativas_falhas FROM seguranca_ips_bloqueados WHERE ip_address='$ip'")->fetchColumn();
if ($n !== 1) fail_test("contador deveria reiniciar fora da janela (=$n)");

$bloq = null;
for ($i = 2; $i <= SEG_MAX_FALHAS; $i++) $bloq = registrar_falha_seguranca("teste $i", 'AVISO', 'painel', 'ci');
if (!$bloq || empty($bloq['bloqueado_em']) || empty($bloq['bloqueado_ate'])) fail_test('bloqueio deveria trazer data de início e fim');
$min = (int)$pdo->query("SELECT TIMESTAMPDIFF(MINUTE, bloqueado_em, bloqueado_ate) FROM seguranca_ips_bloqueados WHERE ip_address='$ip'")->fetchColumn();
if ($min !== SEG_BLOQUEIO_MIN) fail_test("duração do bloqueio ($min)");

// Sucesso não remove bloqueio ativo.
seguranca_limpar_falhas($ip);
if (!seguranca_bloqueio_ativo($pdo, $ip)) fail_test('sucesso não deve remover bloqueio ativo');

$ev = $pdo->query("SELECT evento, origem, usuario FROM seguranca_logs WHERE origem_ip='$ip' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$tipos = array_count_values(array_column($ev, 'evento'));
if (($tipos['FALHA_AUTENTICACAO'] ?? 0) !== 2 * SEG_MAX_FALHAS - 1 || ($tipos['BLOQUEIO_IP_AUTOMATICO'] ?? 0) !== 1) fail_test('eventos registrados: ' . json_encode($tipos));
if ($ev[0]['origem'] !== 'painel' || $ev[0]['usuario'] !== 'ci') fail_test('origem/usuário do evento');

// Bloqueio vencido é liberado e registrado.
$pdo->prepare("UPDATE seguranca_ips_bloqueados SET bloqueado_ate=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE ip_address=:ip")->execute([':ip' => $ip]);
if (seguranca_bloqueio_ativo($pdo, $ip)) fail_test('bloqueio vencido ainda ativo');
verificar_bloqueio_ip();
if ((int)$pdo->query("SELECT COUNT(*) FROM seguranca_ips_bloqueados WHERE ip_address='$ip'")->fetchColumn() !== 0) fail_test('bloqueio vencido não removido');
if (!(int)$pdo->query("SELECT COUNT(*) FROM seguranca_logs WHERE origem_ip='$ip' AND evento='DESBLOQUEIO_AUTOMATICO'")->fetchColumn()) fail_test('desbloqueio automático não registrado');

// Após login bem-sucedido o contador é zerado.
registrar_falha_seguranca('uma falha', 'AVISO', 'painel', 'ci');
seguranca_limpar_falhas($ip);
if ((int)$pdo->query("SELECT COUNT(*) FROM seguranca_ips_bloqueados WHERE ip_address='$ip'")->fetchColumn() !== 0) fail_test('falhas não zeradas após sucesso');

echo "Segurança OK: janela de falhas, bloqueio com data, liberação automática e eventos com origem.\n";
