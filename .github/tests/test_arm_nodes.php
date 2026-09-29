<?php
require __DIR__.'/../../site/var/www/html/api/arm_nodes_registry.php';
function check_node(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$now = 1700000000;
$legacy = [['id'=>1, 'device_id'=>'arm-1', 'hostname'=>'shared-host', 'status'=>'online', 'last_seen_epoch'=>$now-500, 'cpu_info'=>'old CPU'], ['id'=>2, 'device_id'=>null, 'hostname'=>'legacy', 'status'=>'online']];
$devices = [
    ['id'=>10, 'device_id'=>'arm-1', 'nome'=>'Agent 1', 'status'=>'online', 'metadata'=>json_encode(['platform'=>'linux-arm', 'hostname'=>'shared-host', 'cpu'=>['cores'=>4,'load_1m'=>0], 'ram'=>['used_mb'=>0,'total_mb'=>1024]]), 'last_seen_epoch'=>$now-30, 'device_token'=>'DO-NOT-EXPOSE'],
    ['id'=>11, 'device_id'=>'arm-2', 'nome'=>'Agent 2', 'status'=>'online', 'metadata'=>'{"hostname":"shared-host"}', 'capabilities'=>'["arm-agent"]', 'last_seen_epoch'=>$now-121],
    ['id'=>12, 'device_id'=>'phone', 'tipo'=>'android', 'capabilities'=>'["gateway"]', 'last_seen_epoch'=>$now],
    ['id'=>13, 'device_id'=>'avatar', 'manufacturer'=>'Raspberry Pi', 'last_seen_epoch'=>$now],
    ['id'=>14, 'device_id'=>'revoked', 'capabilities'=>'{"arm-agent":true}', 'status'=>'revoked', 'last_seen_epoch'=>$now],
];
$nodes = arm_nodes_merge($legacy, $devices, $now);
check_node(count($nodes) === 5, 'Merge must include modern and legacy agents, excluding phones');
$byId = array_column($nodes, null, 'device_id');
check_node($byId['arm-1']['source'] === 'dispositivos_cluster', 'Registry wins on shared ID');
check_node($byId['arm-1']['online'], 'Fresh heartbeat online');
check_node(!$byId['arm-2']['online'], 'Stale heartbeat offline');
check_node($byId['arm-2']['hostname'] === $byId['arm-1']['hostname'], 'Distinct programs on one host preserved');
check_node($byId['']['status'] === 'registered', 'No heartbeat is not online');
check_node($byId['revoked']['status'] === 'revoked' && !$byId['revoked']['online'], 'Revoked never online');
check_node(strpos($byId['arm-1']['cpu'], 'carga 0') !== false, 'Zero CPU preserved');
check_node(strpos($byId['arm-1']['ram'], '0 MB usados') !== false, 'Zero RAM preserved');
check_node(strpos(json_encode($nodes), 'DO-NOT-EXPOSE') === false, 'Credentials excluded');
check_node(!arm_node_is_agent(['metadata'=>'invalid', 'capabilities'=>'{"arm-agent":false}']), 'Malformed metadata and disabled capability');
check_node(arm_node_is_agent(['capabilities'=>[['name'=>'arm-agent', 'enabled'=>true]]]), 'Capability objects');
$many=[];
for ($i=0; $i<151; $i++) $many[]=['id'=>$i,'device_id'=>'n-'.$i,'capabilities'=>'["arm-agent"]'];
check_node(count(arm_nodes_merge([], $many, $now)) === 151, 'No old CRUD truncation at 100');

// Run the real SQL projection in the CI test database, including secret fields.
if (getenv('JARVIS_DB_NAME') === 'casa_test') {
    require __DIR__.'/../../site/var/www/html/api/db.php';
    $pdo = get_db_pdo();
    $pdo->beginTransaction();
    try {
        $id = 'arm-list-test-'.bin2hex(random_bytes(6));
        $st=$pdo->prepare("INSERT INTO dispositivos_cluster(device_id,nome,tipo,device_token,status,capabilities,metadata,ultimo_heartbeat) VALUES(:id,'Test ARM','agent','PRIVATE','online',:caps,:meta,NOW())");
        $st->execute([':id'=>$id,':caps'=>'["arm-agent"]',':meta'=>'{"platform":"linux-arm","hostname":"ci-host"}']);
        $st=$pdo->prepare("INSERT INTO arm_nodes(device_id,hostname,status,ultimo_ping) VALUES(:id,'ci-host','online',NOW())");
        $st->execute([':id'=>$id]);
        $result=array_values(array_filter(arm_nodes_list($pdo),fn($n)=>$n['device_id']===$id));
        check_node(count($result) === 1 && $result[0]['online'], 'Actual database merge and heartbeat');
        check_node(!isset($result[0]['device_token']), 'SQL projection hides tokens');
    } finally { $pdo->rollBack(); }
}
echo "ARM nodes: OK (sources, identity, heartbeat, filtering, telemetry, credentials, full list)\n";
