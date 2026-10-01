<?php
// Cenas, regras e agendamentos acionando relés via estado desejado.
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';
require __DIR__ . '/../../site/var/www/html/api/v1/rules_scheduler.php';
$pdo = get_db_pdo();

function fail_test(string $msg): void { fwrite(STDERR, "FALHA: $msg\n"); exit(10); }
function desired(PDO $pdo, string $d) { $s = $pdo->prepare("SELECT desired_state FROM device_desired_state WHERE device_id=:d"); $s->execute([':d' => $d]); $j = json_decode((string)$s->fetchColumn(), true); return is_array($j) && array_key_exists('relay_on', $j) ? $j['relay_on'] : null; }

// --- cron ---
$t = new DateTimeImmutable('2026-10-05 08:00:00'); // segunda-feira
if (!automation_cron_matches('0 8 * * *', $t)) fail_test('cron diario');
if (!automation_cron_matches('0 8 * * 1-5', $t)) fail_test('cron seg-sex');
if (automation_cron_matches('0 8 * * 0,6', $t)) fail_test('cron fim de semana');
if (!automation_cron_matches('*/15 * * * *', $t)) fail_test('cron */15');
if (automation_cron_matches('*/15 * * * *', $t->modify('+5 minutes'))) fail_test('cron */15 negativo');
if (!automation_cron_matches('0 8 * * 7', $t->modify('-1 day'))) fail_test('cron domingo=7');

// --- intenção de comando ---
if (automation_relay_intent('power_on', []) !== true) fail_test('power_on');
if (automation_relay_intent('desligar', []) !== false) fail_test('desligar');
if (automation_relay_intent('set_relay', ['relay_on' => 'ligado']) !== true) fail_test('set_relay');
if (automation_relay_intent('volume_up', []) !== null) fail_test('comando não-relé');

// --- dispositivos ---
$relay = 'ci_relay_bomba';
$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,capabilities,metadata,ultimo_heartbeat)
VALUES(:d,'Bomba CI','esp8266','token_ci_relay','online',JSON_ARRAY('relay','desired_state'),JSON_OBJECT('relay_on',false),NOW())
ON DUPLICATE KEY UPDATE capabilities=VALUES(capabilities),metadata=VALUES(metadata)")->execute([':d' => $relay]);
$pdo->prepare("DELETE FROM device_desired_state WHERE device_id=:d")->execute([':d' => $relay]);
$other = 'ci_bridge_tv';
$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,capabilities,ultimo_heartbeat)
VALUES(:d,'TV CI bridge','tv','token_ci_tvb','online',JSON_ARRAY('power'),NOW())
ON DUPLICATE KEY UPDATE capabilities=VALUES(capabilities)")->execute([':d' => $other]);

$list = automation_action_devices($pdo);
$found = array_values(array_filter($list, fn($d) => $d['device_id'] === $relay));
if (!$found || !$found[0]['is_relay']) fail_test('lista de dispositivos não marcou relé');

// --- cena: liga relé + comando para dispositivo não-relé ---
$pdo->prepare("DELETE FROM scenes WHERE slug='ci-cena-reles'")->execute();
$pdo->prepare("INSERT INTO scenes(slug,nome,descricao,ativo,stop_on_error,risk_level) VALUES('ci-cena-reles','Cena CI','',1,0,1)")->execute();
$sceneId = (int)$pdo->lastInsertId();
$ins = $pdo->prepare("INSERT INTO scene_actions(scene_id,ordem,device_id,comando,payload,prioridade,risk_level,ttl_seconds,enabled) VALUES(:s,:o,:d,:c,'{}','normal',1,300,1)");
$ins->execute([':s' => $sceneId, ':o' => 10, ':d' => $relay, ':c' => 'power_on']);
$ins->execute([':s' => $sceneId, ':o' => 20, ':d' => $other, ':c' => 'power_on']);
$r = automation_scene_execute($pdo, automation_scene_load($pdo, $sceneId), 'CI', false);
if ($r['http'] !== 202) fail_test('cena não executou: ' . json_encode($r));
if (desired($pdo, $relay) !== true) fail_test('cena não ligou o relé');
if ($r['body']['relays_applied'] !== 1) fail_test('contagem de relés aplicados');
$st = $pdo->prepare("SELECT lifecycle_status FROM device_commands WHERE id=:id");
foreach ($r['body']['queued'] as $q) {
    $st->execute([':id' => $q['command_id']]); $ls = $st->fetchColumn();
    if ($q['device_id'] === $relay && $ls !== 'DONE') fail_test('comando do relé deveria estar DONE');
    if ($q['device_id'] === $other && $ls !== 'QUEUED') fail_test('comando de não-relé deveria continuar QUEUED');
}

// --- regra por evento desliga o relé ---
$pdo->prepare("DELETE FROM automation_rules WHERE slug='ci-regra-rele'")->execute();
$pdo->prepare("INSERT INTO automation_rules(slug,nome,enabled,trigger_type,trigger_config,conditions_json,cooldown_seconds) VALUES('ci-regra-rele','Regra CI',1,'event',JSON_OBJECT('device_id',:src,'event_type','ci.botao'),JSON_ARRAY(JSON_OBJECT('field','data.pressed','op','eq','value',true)),0)")->execute([':src' => $other]);
$ruleId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO automation_rule_actions(rule_id,ordem,device_id,comando,payload,prioridade,risk_level,ttl_seconds,enabled) VALUES(:r,10,:d,'power_off','{}','normal',1,300,1)")->execute([':r' => $ruleId, ':d' => $relay]);
rules_engine_process_event($pdo, ['device_id' => $other, 'type' => 'ci.botao', 'data' => ['pressed' => false]]);
if (desired($pdo, $relay) !== true) fail_test('regra disparou com condição falsa');
rules_engine_process_event($pdo, ['device_id' => $other, 'type' => 'ci.botao', 'data' => ['pressed' => true]]);
if (desired($pdo, $relay) !== false) fail_test('regra não desligou o relé');

// --- agendamentos executados pelo servidor ---
$pdo->prepare("DELETE FROM tarefas_agendadas WHERE titulo LIKE 'CI %'")->execute();
$pdo->prepare("INSERT INTO tarefas_agendadas(titulo,descricao,horario,dias_semana,cron_expr,executor_tipo,tipo_acao,payload,target_node,ativo,modo_agendamento) VALUES('CI cena','','','*','* * * * *','cena','executar_cena',:p,'cena:ci',1,'RECORRENTE')")->execute([':p' => 'scene_id=' . $sceneId]);
$runs = automation_schedules_tick($pdo);
$ok = array_filter($runs, fn($x) => $x['ok']);
if (count($ok) !== 1) fail_test('agendamento de cena não executou: ' . json_encode($runs));
if (desired($pdo, $relay) !== true) fail_test('agendamento de cena não ligou o relé');
if (automation_schedules_tick($pdo)) fail_test('agendamento executou duas vezes no mesmo minuto');

$pdo->prepare("INSERT INTO tarefas_agendadas(titulo,descricao,horario,dias_semana,cron_expr,executor_tipo,tipo_acao,payload,target_node,ativo,modo_agendamento) VALUES('CI rele','','','*','* * * * *','rele','dispositivo_rele',:p,'rele:ci',1,'RECORRENTE')")->execute([':p' => http_build_query(['device_id' => $relay, 'relay_on' => 0])]);
$runs = automation_schedules_tick($pdo);
if (count(array_filter($runs, fn($x) => $x['ok'])) !== 1) fail_test('agendamento de relé não executou: ' . json_encode($runs));
if (desired($pdo, $relay) !== false) fail_test('agendamento de relé não desligou');

echo "Automação de relés OK: cron, cena, regra por evento e agendamentos de cena/relé.\n";
