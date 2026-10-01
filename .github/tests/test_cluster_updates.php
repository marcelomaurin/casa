<?php
// Atualização dos clusters pelo Git: política, relatório dos nós, pedido forçado e visão do painel.
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';
require __DIR__ . '/../../site/var/www/html/api/v1/updates_common.php';
$pdo = get_db_pdo();

function fail_test(string $msg): void { fwrite(STDERR, "FALHA: $msg\n"); exit(10); }
function node(array $ov, string $id): array { foreach ($ov['nodes'] as $n) if ($n['device_id'] === $id) return $n; fail_test("nó $id ausente"); }

updates_nodes_ensure_schema($pdo);
$pdo->exec("DELETE FROM cluster_update_nodes WHERE device_id LIKE 'ci-upd-%'");
$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,capabilities,ultimo_heartbeat) VALUES('ci-upd-a','Raspberry CI A','linux-arm','tok-ci-upd-a','online',JSON_ARRAY('arm-agent','gateway'),NOW()),('ci-upd-b','Raspberry CI B','linux-arm','tok-ci-upd-b','online',JSON_ARRAY('arm-agent'),NOW()) ON DUPLICATE KEY UPDATE status='online'")->execute();

if (!updates_device_allowed(['capabilities' => ['arm-agent'], 'tipo' => 'linux-arm'])) fail_test('nó ARM deve consultar a política');
if (updates_device_allowed(['capabilities' => ['relay'], 'tipo' => 'esp8266'])) fail_test('ESP não deve consultar a política');

$latest = str_repeat('b', 40); $old = str_repeat('a', 40);
// Cache do commit da master (evita depender do GitHub no CI).
$pdo->prepare("INSERT INTO param(chave,valor) VALUES('CLUSTER_UPDATE_HEAD_master',:v) ON DUPLICATE KEY UPDATE valor=VALUES(valor),atualizado_em=NOW()")
    ->execute([':v' => json_encode(['sha' => $latest, 'message' => 'ci', 'date' => '', 'author' => '', 'source' => 'github'])]);

$p = updates_policy($pdo, 'ci-upd-a');
if ($p['mode'] !== 'git' || $p['branch'] !== 'master' || $p['force_seq'] !== 0) fail_test('política inicial');

$ov = updates_overview($pdo);
if (($ov['latest']['sha'] ?? '') !== $latest) fail_test('commit da master');
if (node($ov, 'ci-upd-a')['estado'] !== 'sem_atualizador') fail_test('nó sem relatório');

updates_node_report($pdo, 'ci-upd-a', ['hostname' => 'rpi-a', 'branch' => 'master', 'commit' => $latest, 'remote_commit' => $latest, 'result' => 'updated',
    'updater_version' => '2.0.0', 'last_check' => gmdate('Y-m-d\TH:i:s\Z'), 'last_update' => gmdate('Y-m-d\TH:i:s\Z'),
    'components' => ['arm-agent' => ['status' => 'updated', 'commit' => $latest, 'files_changed' => 2], 'invasor' => ['status' => 'updated'], 'tts' => ['status' => 'not_installed']]]);
updates_node_report($pdo, 'ci-upd-b', ['commit' => $old, 'result' => 'up-to-date', 'branch' => 'master', 'components' => []]);

$ov = updates_overview($pdo);
$a = node($ov, 'ci-upd-a'); $b = node($ov, 'ci-upd-b');
if ($a['estado'] !== 'atualizado' || $a['commit'] !== $latest || $a['updater_version'] !== '2.0.0') fail_test('nó A atualizado');
if (isset($a['componentes']['invasor']) || ($a['componentes']['arm-agent']['files_changed'] ?? 0) !== 2) fail_test('componentes filtrados');
if ($b['estado'] !== 'desatualizado') fail_test('nó B desatualizado');

// Pedido forçado: entregue na política e baixado quando o nó informa que tratou.
if (updates_request_force($pdo, ['ci-upd-b', 'inválido com espaço'], 'WEB:ci') !== 1) fail_test('forçar um nó');
if (updates_policy($pdo, 'ci-upd-b')['force_seq'] !== 1) fail_test('force_seq entregue');
if (!node(updates_overview($pdo), 'ci-upd-b')['forcar_pendente']) fail_test('pendência visível no painel');
updates_node_report($pdo, 'ci-upd-b', ['commit' => $latest, 'result' => 'updated', 'force_handled' => 1, 'branch' => 'master']);
$b = node(updates_overview($pdo), 'ci-upd-b');
if ($b['forcar_pendente'] || $b['estado'] !== 'atualizado') fail_test('pedido forçado atendido');

$pdo->exec("UPDATE cluster_update_nodes SET last_report_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE device_id='ci-upd-a'");
if (node(updates_overview($pdo), 'ci-upd-a')['estado'] !== 'sem_contato') fail_test('nó sem contato');
updates_node_report($pdo, 'ci-upd-a', ['commit' => $latest, 'result' => 'failed', 'error' => 'RuntimeError: x', 'branch' => 'master']);
if (node(updates_overview($pdo), 'ci-upd-a')['estado'] !== 'falhou') fail_test('nó com falha');

echo "Atualização dos clusters OK: política Git, relatórios, pedido forçado e estados do painel.\n";
