<?php
// Mordomo (api/mordomo.php): detectores, memória das observações, decisão da IA,
// autonomia (observar/avisar/agir), ordem só direta, lembretes e falha da IA.
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require_once __DIR__ . '/../../site/var/www/html/api/db.php';
require_once __DIR__ . '/../../site/var/www/html/api/mordomo.php';
$pdo = get_db_pdo();
foreach (['1.33', '1.34', '1.35'] as $v) casa_execute_schema_file($pdo, __DIR__ . "/../../site/var/www/html/api/migrations/{$v}.sql");
require_once __DIR__ . '/../../site/var/www/html/api/family_common.php';
if (function_exists('family_ensure_schema')) family_ensure_schema($pdo);

function falha(string $m): void { fwrite(STDERR, "FALHOU: {$m}\n"); exit(10); }
function igual($a, $b, string $m): void { if ($a !== $b) falha($m . ' (esperado ' . var_export($b, true) . ', veio ' . var_export($a, true) . ')'); }
function cfg(PDO $pdo, string $k, string $v): void {
    $pdo->prepare("INSERT INTO configuracoes_sistema (chave,valor) VALUES (:k,:v) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([':k' => $k, ':v' => $v]);
}
function obs(PDO $pdo, string $tipo): ?array {
    $s = $pdo->prepare("SELECT * FROM jarvis_mordomo_observacoes WHERE tipo=:t ORDER BY id DESC LIMIT 1"); $s->execute([':t' => $tipo]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---- Ambiente controlado ----
$pdo->exec("DELETE FROM jarvis_mordomo_decisoes; DELETE FROM jarvis_mordomo_observacoes; DELETE FROM mobile_notificacoes");
$pdo->exec("DELETE FROM assistencia_eventos; DELETE FROM sensores_telemetria; DELETE FROM seguranca_logs; DELETE FROM camera_eventos");
$pdo->exec("DELETE FROM jarvis_trabalhadores");
$pdo->exec("DELETE FROM dispositivos_cluster WHERE device_id LIKE 'md\\_%'");
// Dados de outros testes no mesmo banco não podem gerar achados aqui.
$pdo->exec("UPDATE dispositivos_cluster SET ultimo_heartbeat=NOW()-INTERVAL 3 DAY, battery_pct=NULL");
$pdo->exec("UPDATE devpar SET devvalue='0' WHERE devparname='dev1'");
$pdo->exec("DELETE FROM iot_leituras; DELETE FROM device_environment_readings");
$pdo->exec("DELETE FROM devpar WHERE iddevice IN (901,902); DELETE FROM devices WHERE iddevice IN (901,902)");
cfg($pdo, 'mordomo_silencio', '0-0');      // nunca em silêncio durante o teste
cfg($pdo, 'mordomo_temp_max', '35');
cfg($pdo, 'mordomo_irrigacao_max_min', '30');

$pdo->exec("INSERT INTO devices (iddevice,devname) VALUES (901,'Irrigação do pomar'),(902,'Sensor sala')");
$pdo->exec("INSERT INTO devpar (iddevice,devparname,devvalue,atualizado_em) VALUES (901,'dev1','1',NOW()-INTERVAL 2 HOUR)");
$pdo->exec("INSERT INTO dispositivos_cluster (device_id,nome,tipo,device_token,status,localizacao,ultimo_heartbeat,battery_pct) VALUES
    ('md_esp','Portão','esp32','x1','online','Frente',NOW()-INTERVAL 30 MINUTE,NULL),
    ('md_watch','Relógio','watch','x2','online','',NOW()-INTERVAL 30 MINUTE,NULL),
    ('md_sensor','Sensor porta','esp32','x3','online','Porta',NOW()-INTERVAL 1 MINUTE,12)");
$pdo->exec("INSERT INTO sensores_telemetria (iddevice,sensor_nome,valor_numerico,unidade,data_hora) VALUES (902,'Temperatura sala',38.5,'°C',NOW()-INTERVAL 2 MINUTE)");
$pdo->exec("INSERT INTO assistencia_eventos (tipo,severidade,mensagem,pessoa_ref,confirmado,criado_em) VALUES ('queda','alta','Possível queda','vovó',0,NOW()-INTERVAL 3 MINUTE)");

// IA simulada: guarda o pedido e responde com decisões fixas.
$pedidos = [];
$respostaIa = null;
$ia = function (string $pedido) use (&$pedidos, &$respostaIa) {
    $pedidos[] = $pedido;
    return $respostaIa === null ? ['ok' => false, 'http' => 0, 'dados' => [], 'erro' => 'fora do ar'] : ['ok' => true, 'http' => 200, 'dados' => ['resposta' => $respostaIa]];
};
$ordens = [];
$executar = function (string $ordem) use (&$ordens) {
    $ordens[] = $ordem;
    return ['ok' => true, 'http' => 200, 'dados' => ['status' => 'sucesso', 'resposta' => 'Afirmativo. Irrigação desligada.']];
};

// 1. Detectores: assistência, portão fora do ar (relógio ignorado), bateria, temperatura, irrigação.
$achados = md_detectores($pdo, md_config($pdo));
$tipos = array_column($achados, 'tipo'); sort($tipos);
igual($tipos, ['assistencia', 'bateria_baixa', 'dispositivo_fora', 'irrigacao_longa', 'temperatura'], 'detectores');
foreach ($achados as $a) if ($a['tipo'] === 'dispositivo_fora' && strpos($a['titulo'], 'Relógio') !== false) falha('relógio deveria ser ignorado');

// 2. Autonomia "avisar": pedido de "agir" vira aviso com sugestão; nada é executado.
cfg($pdo, 'mordomo_autonomia', 'avisar');
$achadosPorTipo = array_column($achados, 'tipo');
$n = count($achados);
$decs = [];
for ($i = 1; $i <= $n; $i++) $decs[] = ['observacao' => $i, 'acao' => 'avisar', 'mensagem' => 'aviso ' . $i];
$idxIrr = array_search('irrigacao_longa', array_column(md_detectores($pdo, md_config($pdo)), 'tipo'), true);
$decs[$idxIrr] = ['observacao' => $idxIrr + 1, 'acao' => 'agir', 'ordem' => 'desligue a irrigação', 'mensagem' => 'Irrigação ligada há 2 h', 'motivo' => 'desperdício'];
$respostaIa = "```json\n" . json_encode(['decisoes' => $decs], JSON_UNESCAPED_UNICODE) . "\n```";
$r = md_ciclo($pdo, $ia, $executar);
igual($r['novas'], 5, 'cinco novidades');
igual(count($pedidos), 1, 'uma chamada à IA por ciclo');
if (strpos($pedidos[0], 'NÃO use "agir"') === false) falha('pedido deveria proibir agir no modo avisar');
igual($ordens, [], 'modo avisar não executa ordem');
$irr = array_values(array_filter($r['decisoes'], fn($d) => strpos($d['observacao'], 'Irrigação') !== false))[0];
igual($irr['acao'], 'avisar', 'agir virou avisar');
$notif = (int)$pdo->query("SELECT COUNT(*) FROM mobile_notificacoes WHERE mensagem LIKE 'Mordomo:%'")->fetchColumn();
igual($notif, 5, 'cinco avisos no celular');
$sug = $pdo->query("SELECT mensagem FROM mobile_notificacoes WHERE mensagem LIKE '%Sugestão: desligue a irrigação%'")->fetchColumn();
if (!$sug) falha('aviso deveria trazer a sugestão');

// 3. Mesmo estado no ciclo seguinte: nada novo, sem chamar a IA.
$r = md_ciclo($pdo, $ia, $executar);
igual([$r['novas'], count($pedidos)], [0, 1], 'sem repetição');

// 4. Irrigação desligada resolve; ligada de novo (há 2 h) reabre e volta à IA.
$pdo->exec("UPDATE devpar SET devvalue='0' WHERE iddevice=901");
$r = md_ciclo($pdo, $ia, $executar);
igual($r['resolvidas'], 1, 'irrigação resolvida');
igual(obs($pdo, 'irrigacao_longa')['status'], 'RESOLVIDA', 'status resolvida');

// 5. Autonomia "agir": ordem permitida é executada (pela via de ordem direta).
cfg($pdo, 'mordomo_autonomia', 'agir');
$pdo->exec("UPDATE devpar SET devvalue='1', atualizado_em=NOW()-INTERVAL 2 HOUR WHERE iddevice=901");
$respostaIa = json_encode(['decisoes' => [['observacao' => 1, 'acao' => 'agir', 'ordem' => 'desligue a irrigação', 'mensagem' => 'Desliguei a irrigação', 'motivo' => 'ligada há 2 h']]]);
$r = md_ciclo($pdo, $ia, $executar);
igual($r['novas'], 1, 'irrigação reaberta');
if (strpos(end($pedidos), 'Você PODE usar "agir"') === false) falha('pedido deveria permitir agir');
igual($ordens, ['desligue a irrigação'], 'ordem executada');
igual($r['decisoes'][0]['executado'], true, 'execução registrada');
$d = $pdo->query("SELECT acao,ordem,executado,origem FROM jarvis_mordomo_decisoes ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
igual([$d['acao'], $d['ordem'], (int)$d['executado'], $d['origem']], ['agir', 'desligue a irrigação', 1, 'ia'], 'decisão gravada');

// 6. Lembrete do caso grave ainda aberto.
cfg($pdo, 'mordomo_lembrete_min', '15');
$pdo->exec("UPDATE jarvis_mordomo_observacoes SET ultimo_aviso=NOW()-INTERVAL 20 MINUTE WHERE tipo='assistencia'");
$r = md_ciclo($pdo, $ia, $executar);
igual($r['lembretes'], 1, 'lembrete do pedido de assistência');
$r = md_ciclo($pdo, $ia, $executar);
igual($r['lembretes'], 0, 'não repete o lembrete logo em seguida');

// 7. IA fora do ar: médio/grave avisa, baixo ignora.
$respostaIa = null;
$pdo->exec("INSERT INTO seguranca_logs (origem_ip,evento,detalhes,severidade,data_hora) VALUES ('1.2.3.4','Força bruta','x','ALTO',NOW())");
$r = md_ciclo($pdo, $ia, $executar);
igual($r['novas'], 1, 'evento de segurança novo');
igual($r['decisoes'][0]['acao'], 'avisar', 'aviso automático sem IA');
igual($pdo->query("SELECT origem FROM jarvis_mordomo_decisoes ORDER BY id DESC LIMIT 1")->fetchColumn(), 'padrao', 'origem padrão');

// 8. Modo observar: registra, não decide.
cfg($pdo, 'mordomo_autonomia', 'observar');
$pdo->exec("UPDATE dispositivos_cluster SET battery_pct=5 WHERE device_id='md_esp'");
$pdo->exec("UPDATE dispositivos_cluster SET ultimo_heartbeat=NOW() WHERE device_id='md_esp'");
$antes = count($pedidos);
$r = md_ciclo($pdo, $ia, $executar);
igual([count($r['decisoes']), count($pedidos)], [0, $antes], 'observar não decide');

// 9. Leitura das decisões e horário de silêncio.
igual(md_ler_decisoes('lixo', 2), [], 'resposta inválida');
igual(count(md_ler_decisoes('{"decisoes":[{"observacao":1,"acao":"explodir"},{"observacao":9,"acao":"avisar"},{"observacao":2,"acao":"ignorar"}]}', 2)), 1, 'filtra ação e índice inválidos');
igual([md_no_intervalo('22-7', 23), md_no_intervalo('22-7', 3), md_no_intervalo('22-7', 12), md_no_intervalo('1-5', 2)], [true, true, false, true], 'intervalos');

// 10. O retrato da casa mostra o que o mordomo acompanha e o que fez.
$c = casa_contexto($pdo, 6000);
if (strpos($c['texto'], 'MORDOMO (o que você está acompanhando') === false || strpos($c['texto'], 'agiu: desligue a irrigação') === false) falha('retrato sem o mordomo');

// 11. "Já vi": não volta à IA nem avisa enquanto o problema existir; some -> resolvida; volta -> novo.
cfg($pdo, 'mordomo_autonomia', 'avisar');
$respostaIa = '{"decisoes":[]}';
$pdo->exec("UPDATE dispositivos_cluster SET ultimo_heartbeat=NOW()-INTERVAL 30 MINUTE WHERE device_id='md_esp'");
md_ciclo($pdo, $ia, $executar);
$o = $pdo->query("SELECT * FROM jarvis_mordomo_observacoes WHERE chave='offline:md_esp'")->fetch(PDO::FETCH_ASSOC);
igual($o['status'], 'ABERTA', 'portão fora do ar aberto');
md_dispensar($pdo, (int)$o['id'], 'teste');
$antes = count($pedidos);
$r = md_ciclo($pdo, $ia, $executar);
igual([$r['novas'], count($pedidos)], [0, $antes], 'caso visto não volta à IA');
igual($pdo->query("SELECT status FROM jarvis_mordomo_observacoes WHERE id=" . (int)$o['id'])->fetchColumn(), 'DISPENSADA', 'continua visto');
$pdo->exec("UPDATE dispositivos_cluster SET ultimo_heartbeat=NOW() WHERE device_id='md_esp'");
md_ciclo($pdo, $ia, $executar);
igual($pdo->query("SELECT status FROM jarvis_mordomo_observacoes WHERE id=" . (int)$o['id'])->fetchColumn(), 'RESOLVIDA', 'resolve quando volta');
$pdo->exec("UPDATE dispositivos_cluster SET ultimo_heartbeat=NOW()-INTERVAL 30 MINUTE WHERE device_id='md_esp'");
$r = md_ciclo($pdo, $ia, $executar);
igual(in_array('offline:md_esp', array_map(fn($d) => $d, array_column($pdo->query("SELECT chave FROM jarvis_mordomo_observacoes WHERE status='ABERTA'")->fetchAll(PDO::FETCH_ASSOC), 'chave')), true), true, 'nova queda vira caso aberto de novo');
try { md_dispensar($pdo, 999999, 'teste'); falha('dispensou caso inexistente'); } catch (RuntimeException $e) {}
// Confirmar atendimento confirma o pedido de assistência.
$ab = $pdo->query("SELECT id FROM jarvis_mordomo_observacoes WHERE tipo='assistencia' AND status='ABERTA' LIMIT 1")->fetchColumn();
if ($ab) {
    $r = md_dispensar($pdo, (int)$ab, 'teste');
    igual($r['assistencia_confirmada'], true, 'assistência confirmada');
}
igual((string)$pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave='mordomo_ultimo_ciclo'")->fetchColumn() !== '', true, 'último ciclo gravado');

cfg($pdo, 'mordomo_autonomia', 'avisar');
echo "Mordomo OK: detectores, memória, autonomia (observar/avisar/agir), ordem direta, lembrete, IA fora do ar, já visto e retrato.\n";
