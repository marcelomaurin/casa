<?php
// A hospedagem nao acessa IPs da LAN. O agente busca falas pela fila HTTPS.
require_once __DIR__.'/task_engine.php';

function gh_bridge_snapshot(array $in,int $now): array {
    $devices=[];
    foreach (array_slice(is_array($in['devices']??null)?$in['devices']:[],0,50) as $d) {
        if (!is_array($d)) continue;
        $uuid=substr((string)($d['uuid']??''),0,80);
        if (!preg_match('/^[a-zA-Z0-9-]{1,80}$/D',$uuid)) continue;
        $devices[]=['uuid'=>$uuid,'name'=>substr((string)($d['name']??'Google Home'),0,160),
            'host'=>filter_var($d['host']??'',FILTER_VALIDATE_IP)?:null];
    }
    return ['received_at'=>$now,'devices'=>$devices,'agent_online'=>true,
        'online'=>($in['online']??false)===true && count($devices)>0,'queue_alive'=>($in['queue_alive']??false)===true,
        'version'=>substr((string)($in['version']??''),0,40),'trabalhador'=>substr((string)($in['trabalhador']??''),0,80)];
}

function gh_bridge_status(PDO $pdo,?int $now=null): array {
    $st=$pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave='google_home_agent_status' LIMIT 1");
    $data=json_decode((string)$st->fetchColumn(),true)?:[];
    $now=$now??time();$seen=$data['received_at']??0;
    $recent=is_int($seen) && $seen>0 && $seen<=$now && $seen>=($now-90);
    $agentOnline=$recent && !empty($data['online']) && !empty($data['queue_alive']);

    $regDevices=[];
    try {
        $stDev=$pdo->query("SELECT id, device_id, nome, ip_address, mac_address, status, localizacao, metadata, ultimo_heartbeat FROM dispositivos_cluster WHERE tipo='google_home' ORDER BY id ASC");
        while ($row=$stDev->fetch(PDO::FETCH_ASSOC)) {
            $meta=json_decode((string)($row['metadata']??''),true)?:[];
            $regDevices[]=[
                'id'=>(int)$row['id'],
                'uuid'=>$row['device_id'],
                'name'=>$row['nome'],
                'host'=>$row['ip_address'],
                'mac'=>$row['mac_address'],
                'tipo_conexao'=>$meta['tipo_conexao']??($row['mac_address']?'bluetooth':'wifi'),
                'status'=>$row['status']?:'registrado',
                'location'=>$row['localizacao']?:'Residência',
                'last_seen'=>$row['ultimo_heartbeat']
            ];
        }
    } catch (Throwable $e) {}

    // Mescla dispositivos descobertos pelo agente e os cadastrados manualmente
    $allDevices=$regDevices;
    $seenIds=[];
    foreach ($regDevices as $rd) { $seenIds[$rd['uuid']]=true; if ($rd['name']) $seenIds[$rd['name']]=true; }
    if ($recent && !empty($data['devices']) && is_array($data['devices'])) {
        foreach ($data['devices'] as $d) {
            $u=$d['uuid']??$d['name']??'';
            if ($u!=='' && empty($seenIds[$u])) {
                $allDevices[]=['uuid'=>$d['uuid']??$u,'name'=>$d['name']??'Google Home','host'=>$d['host']??null,'status'=>'online','location'=>'Wi-Fi Cast'];
                $seenIds[$u]=true;
            }
        }
    }

    $hasDevices=count($allDevices)>0;
    $online=$agentOnline || $hasDevices;
    $msg=$agentOnline ? 'Agente Google Home conectado pela fila.' :
         ($hasDevices ? count($allDevices).' dispositivo(s) Google Home configurado(s).' :
         ($recent ? 'Agente conectado; aguardando pareamento com o Google Home.' : 'Agente Google Home sem contato recente. Adicione seu dispositivo abaixo.'));

    $hwStatus=$hasDevices ? count($allDevices).' CADASTRADO(S)' : ($agentOnline?'ONLINE':'OFFLINE');

    return [
        'status'=>$online?'ok':'offline',
        'online'=>$online,
        'agent_online'=>$recent,
        'devices'=>$allDevices,
        'registered_devices'=>$regDevices,
        'hardware_status'=>$hwStatus,
        'mensagem'=>$msg,
        'transport'=>'fila_https',
        'last_seen'=>$data['received_at']??null
    ];
}

function gh_enqueue_speech(PDO $pdo,string $text,string $device='',?array $context=null): array {
    $text=trim($text);
    if ($text==='' || mb_strlen($text,'UTF-8')>4000 || strlen($device)>160) throw new InvalidArgumentException('Texto ou destino invalido');
    $ctx=$context??te_begin($pdo,$text,'CASA_GOOGLE_HOME','google_home');
    $payload=['modo'=>'falar','texto'=>$text,'device'=>$device?:null,'speaker'=>'padrao','task_context'=>te_public_context($ctx)];
    $ownTransaction=!$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $id=te_add_subtask($pdo,$ctx,'Reproduzir mensagem no Google Home','google_home',$payload);
        $pdo->prepare("UPDATE jarvis_tarefas SET fila='geral',max_tentativas=3 WHERE id=:id")->execute([':id'=>$id]);
        // Concluir apenas a recepcao. O plano permanece aberto ate a fala ser confirmada.
        if (!empty($ctx['root_created'])) te_complete($pdo,(int)$ctx['id_tarefa_raiz'],['fala_enfileirada'=>true,'id_tarefa'=>$id]);
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) { if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();throw $e; }
    return ['status'=>'pendente','mensagem'=>'Mensagem na fila do Google Home.','fala_enfileirada'=>true,'falou'=>false,
        'id_tarefa'=>$id,'context'=>$ctx];
}
