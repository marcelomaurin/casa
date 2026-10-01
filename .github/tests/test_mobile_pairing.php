<?php
// Pareamento do Casa Mobile por QR Code (ticket de uso único -> credencial do celular).
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';
require __DIR__ . '/../../site/var/www/html/api/mobile_pairing.php';
$pdo = get_db_pdo();

function fail_test(string $msg): void { fwrite(STDERR, "FALHA: $msg\n"); exit(10); }
function expect_error(callable $fn, string $needle, string $label): void {
    try { $fn(); } catch (RuntimeException $e) { if (stripos($e->getMessage(), $needle) === false) fail_test("$label: mensagem inesperada: " . $e->getMessage()); return; }
    fail_test("$label: deveria ter falhado");
}

$t = mobile_pair_create($pdo, 'operador_ci', 'https://exemplo.test/casa');
$qr = json_decode($t['qr'], true);
if (($qr['t'] ?? '') !== 'casa-pair' || ($qr['url'] ?? '') !== 'https://exemplo.test/casa' || ($qr['code'] ?? '') !== $t['code']) fail_test('payload do QR');
$hash = $pdo->query("SELECT code_hash FROM mobile_pairing_tickets WHERE id=" . (int)$t['id'])->fetchColumn();
if ($hash !== hash('sha256', $t['code'])) fail_test('banco deve guardar só o hash do código');
if (mobile_pair_status($pdo, $t['id'])['status'] !== 'waiting') fail_test('status inicial');

expect_error(fn() => mobile_pair_redeem($pdo, 'codigo-inexistente', []), 'não reconhecido', 'código inválido');

$r = mobile_pair_redeem($pdo, $t['code'], ['device_name' => 'Celular do Marcelo', 'device_model' => 'Samsung SM-S911B', 'os_version' => 'Android 15'], '10.0.0.5');
if (strpos($r['token'], 'casa_mob_') !== 0 || strpos($r['device_id'], 'mobile-') !== 0) fail_test('credencial retornada');
$s = mobile_pair_status($pdo, $t['id']);
if ($s['status'] !== 'paired' || $s['device_nome'] !== 'Celular do Marcelo') fail_test('status após pareamento');
expect_error(fn() => mobile_pair_redeem($pdo, $t['code'], []), 'já foi usado', 'reuso do QR');

// A credencial do celular funciona na API v1 com os escopos do app.
$row = $pdo->prepare("SELECT scopes,ativo,device_id FROM api_client_tokens WHERE token_hash=:h");
$row->execute([':h' => hash('sha256', $r['token'])]);
$tok = $row->fetch(PDO::FETCH_ASSOC);
if (!$tok || !(int)$tok['ativo'] || $tok['device_id'] !== $r['device_id']) fail_test('token não registrado');
$scopes = json_decode($tok['scopes'], true);
foreach (['mobile.read', 'mobile.write', 'status.read', 'family.read', 'devices.provision', 'commands.read'] as $need) if (!in_array($need, $scopes, true)) fail_test("escopo ausente: $need");
$dev = $pdo->prepare("SELECT tipo,device_token_hash,metadata FROM dispositivos_cluster WHERE device_id=:d");
$dev->execute([':d' => $r['device_id']]);
$d = $dev->fetch(PDO::FETCH_ASSOC);
if (!$d || $d['tipo'] !== 'mobile' || $d['device_token_hash'] !== hash('sha256', $r['token'])) fail_test('celular não registrado em dispositivos_cluster');
if ((json_decode($d['metadata'], true)['paired_by'] ?? '') !== 'operador_ci') fail_test('metadata paired_by');

$list = array_column(mobile_pair_list($pdo), 'device_id');
if (!in_array($r['device_id'], $list, true)) fail_test('lista de celulares');

// Ticket vencido não serve.
$t2 = mobile_pair_create($pdo, 'operador_ci', 'https://exemplo.test/casa');
$pdo->prepare("UPDATE mobile_pairing_tickets SET expira_em=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=:id")->execute([':id' => $t2['id']]);
expect_error(fn() => mobile_pair_redeem($pdo, $t2['code'], []), 'expirou', 'ticket vencido');
if (mobile_pair_status($pdo, $t2['id'])['status'] !== 'expired') fail_test('status expirado');

// Revogação desativa a credencial.
if (!mobile_pair_revoke($pdo, $r['device_id'])) fail_test('revogar');
$row->execute([':h' => hash('sha256', $r['token'])]);
if ((int)$row->fetch(PDO::FETCH_ASSOC)['ativo'] !== 0) fail_test('token deveria estar inativo após revogar');

echo "Pareamento mobile OK: QR de uso único, credencial do celular, expiração, reuso e revogação.\n";
