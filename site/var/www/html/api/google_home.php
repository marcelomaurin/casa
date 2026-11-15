<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__.'/db.php');
require_once(__DIR__.'/task_engine.php');
require_once(__DIR__.'/google_home_bridge.php');
verify_api_auth();

function gh_out($data,$code=200){http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function gh_call($payload,$timeout=55){
    $url=casa_url_interna('api/jarvis.php');
    $ch=curl_init($url);
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Key: '.get_system_api_token()]];
    if($payload!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch,$opts);
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    $j=$raw!==false?json_decode((string)$raw,true):null;
    if($raw!==false&&$http>=200&&$http<300&&is_array($j)&&($j['status']??'')!=='erro')return ['ok'=>true,'data'=>$j];
    $msg=$err?:($j['detail']??$j['mensagem']??('HTTP '.$http));
    return ['ok'=>false,'erro'=>is_string($msg)?$msg:json_encode($msg,JSON_UNESCAPED_UNICODE),'http'=>$http];
}

$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in))$in=[];
$acao=strtolower(trim((string)($in['acao']??($_GET['acao']??'status'))));

if($acao==='status'){
    gh_out(gh_bridge_status(get_db_pdo()));
}
if($acao==='resultado'){
    $pdo=get_db_pdo();$st=$pdo->prepare("SELECT id,status,resultado,erro FROM jarvis_tarefas WHERE id=:id AND executor='google_home' AND fila IS NOT NULL");
    $st->execute([':id'=>(int)($in['id_tarefa']??$_GET['id_tarefa']??0)]);$row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) gh_out(['status'=>'erro','mensagem'=>'Tarefa nao encontrada'],404);
    gh_out(['status'=>'ok','tarefa'=>$row]);
}
if($acao==='falar'){
    try {
        $pdo=get_db_pdo();$r=gh_enqueue_speech($pdo,(string)($in['texto']??''),trim((string)($in['device']??'')));
        $ctx=$r['context'];unset($r['context']);gh_out(te_attach_context($r,$ctx,$pdo),202);
    } catch (InvalidArgumentException $e) { gh_out(['status'=>'erro','mensagem'=>$e->getMessage()],400); }
}
if($acao==='comando'){
    $cmd=trim((string)($in['comando']??''));if($cmd==='')gh_out(['status'=>'erro','mensagem'=>'Comando vazio.'],400);
    $pdo=get_db_pdo();
    $existingTaskContext=te_context_from_input($in['task_context']??null);
    $taskContext=te_begin($pdo,$cmd,trim((string)($in['origem']??'GOOGLE_HOME')),'google_home',$existingTaskContext,$in['correlation_id']??null);
    $taskId=te_add_subtask($pdo,$taskContext,'Executar comando para Google Home','jarvis',[
        'comando'=>$cmd,'device'=>trim((string)($in['device']??''))?:null
    ],null,'EXECUTANDO');
    $r=gh_call(['comando'=>$cmd,'origem'=>'GOOGLE_HOME_HARDWARE','task_context'=>te_public_context($taskContext)],60);
    if(!$r['ok']){
        te_fail_with_plan($pdo,$taskContext,$taskId,(string)$r['erro']);
        gh_out(te_attach_context(['status'=>'erro','mensagem'=>$r['erro']],$taskContext,$pdo),502);
    }
    te_complete($pdo,$taskId,$r['data']);
    $text=trim((string)($r['data']['resposta']??$r['data']['mensagem']??''));
    if ($text==='') { te_finish_error($pdo,$taskContext,'COMPUTER sem resposta');gh_out(['status'=>'erro','mensagem'=>'COMPUTER sem resposta'],502); }
    $speech=gh_enqueue_speech($pdo,$text,trim((string)($in['device']??'')),$taskContext);unset($speech['context']);
    gh_out(te_attach_context(array_merge($r['data'],$speech),$taskContext,$pdo),202);
}
if($acao==='heartbeat'||$acao==='agent_status'){
    $now=time();
    $disps=$in['dispositivos']??[];
    $conectado=!empty($in['conectado']);
    $macAlvo=strtoupper(trim((string)($in['mac_alvo']??'')));

    $pdo=get_db_pdo();

    // 1. Atualiza configuracoes_sistema para o status geral do agente
    $snapshot=[
        'received_at'=>$now,
        'devices'=>$in['devices']??[],
        'bluetooth_devices'=>$disps,
        'agent_online'=>true,
        'online'=>true,
        'queue_alive'=>true,
        'version'=>$in['versao']??'1.0.0',
        'trabalhador'=>$in['trabalhador']??'google-home-bt'
    ];
    $st=$pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor) VALUES ('google_home_agent_status', :val) ON DUPLICATE KEY UPDATE valor = :val");
    $st->execute([':val'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE)]);

    // 2. Quando um dispositivo existir no bluetooth o mesmo deve ser adicionado
    $adicionados=[];
    if(is_array($disps)){
        foreach($disps as $d){
            $mac=strtoupper(trim((string)($d['mac']??'')));
            $nome=trim((string)($d['nome']??'Google Home'));
            if(!$mac) continue;
            $ehGoogle=!empty($d['google_home_provavel'])||($macAlvo&&$mac===$macAlvo)||stripos($nome,'google')!==false||stripos($nome,'nest')!==false||stripos($nome,'home')!==false;

            $chk=$pdo->prepare("SELECT id FROM dispositivos_cluster WHERE mac_address=:m AND tipo='google_home' LIMIT 1");
            $chk->execute([':m'=>$mac]);
            $existingId=$chk->fetchColumn();

            if($existingId){
                $up=$pdo->prepare("UPDATE dispositivos_cluster SET status='online', ultimo_heartbeat=NOW() WHERE id=:id");
                $up->execute([':id'=>$existingId]);
            } elseif($ehGoogle){
                $deviceId='gh-bt-'.bin2hex(random_bytes(4));
                $token=bin2hex(random_bytes(16));
                $caps=json_encode(['google-home','bluetooth','audio-sink','voice-bridge'],JSON_UNESCAPED_UNICODE);
                $meta=json_encode(['tipo_conexao'=>'bluetooth','auto_descoberto'=>true,'criado_em'=>date('c')],JSON_UNESCAPED_UNICODE);
                $ins=$pdo->prepare("INSERT INTO dispositivos_cluster (device_id,nome,tipo,mac_address,device_token,status,capabilities,metadata,localizacao,ultimo_heartbeat) VALUES (:did,:n,'google_home',:m,:t,'online',:c,:meta,'Residência',NOW())");
                $ins->execute([':did'=>$deviceId,':n'=>$nome,':m'=>$mac,':t'=>$token,':c'=>$caps,':meta'=>$meta]);
                $adicionados[]=['mac'=>$mac,'nome'=>$nome,'id'=>(int)$pdo->lastInsertId()];
            }
        }
    }

    // 3. REGRA: Dispositivos offline devem ser deletados
    $del=$pdo->exec("DELETE FROM dispositivos_cluster WHERE tipo='google_home' AND (ultimo_heartbeat IS NULL OR ultimo_heartbeat < (NOW() - INTERVAL 120 SECOND))");

    gh_out([
        'status'=>'sucesso',
        'mensagem'=>'Heartbeat processado com sucesso.',
        'auto_adicionados'=>$adicionados,
        'removidos_offline'=>(int)$del
    ]);
}

if($acao==='cadastrar'||$acao==='adicionar'){
    $nome=trim((string)($in['nome']??''));
    if($nome==='')gh_out(['status'=>'erro','mensagem'=>'Nome do dispositivo é obrigatório.'],400);
    $ip=trim((string)($in['ip_address']??''));
    if($ip!==''&&!filter_var($ip,FILTER_VALIDATE_IP))gh_out(['status'=>'erro','mensagem'=>'Endereço IP inválido.'],400);
    $mac=strtoupper(trim((string)($in['mac_address']??'')));
    if($mac!==''&&!preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/',$mac))gh_out(['status'=>'erro','mensagem'=>'Endereço MAC inválido.'],400);
    $tipoConexao=trim((string)($in['tipo_conexao']??'wifi'));
    if(!in_array($tipoConexao,['wifi','bluetooth','virtual'],true))$tipoConexao='wifi';
    $loc=trim((string)($in['localizacao']??'Residência'))?:'Residência';

    $pdo=get_db_pdo();
    $deviceId='gh-'.bin2hex(random_bytes(4));
    $token=bin2hex(random_bytes(16));
    $caps=json_encode(['google-home','cast-speaker','voice-bridge',$tipoConexao],JSON_UNESCAPED_UNICODE);
    $meta=json_encode([
        'tipo_conexao'=>$tipoConexao,
        'cadastrado_por'=>$_SESSION['auth_user']??'admin',
        'criado_em'=>date('c')
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $st=$pdo->prepare("INSERT INTO dispositivos_cluster (device_id,nome,tipo,ip_address,mac_address,device_token,status,capabilities,metadata,localizacao,ultimo_heartbeat) VALUES (:did,:nome,'google_home',:ip,:mac,:token,'online',:caps,:meta,:loc,NOW())");
    $st->execute([
        ':did'=>$deviceId,
        ':nome'=>$nome,
        ':ip'=>$ip?:null,
        ':mac'=>$mac?:null,
        ':token'=>$token,
        ':caps'=>$caps,
        ':meta'=>$meta,
        ':loc'=>$loc
    ]);
    $newId=(int)$pdo->lastInsertId();
    gh_out([
        'status'=>'sucesso',
        'mensagem'=>'Dispositivo Google Home adicionado com sucesso.',
        'device'=>[
            'id'=>$newId,
            'device_id'=>$deviceId,
            'nome'=>$nome,
            'ip_address'=>$ip?:null,
            'mac_address'=>$mac?:null,
            'tipo_conexao'=>$tipoConexao,
            'localizacao'=>$loc
        ]
    ]);
}

if($acao==='bluetooth_dispositivos'||$acao==='scan_bt'){
    $btDisps=[];
    try {
        $ch=curl_init('http://127.0.0.1:8094/api/v1/bluetooth/dispositivos');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8]);
        $raw=curl_exec($ch);
        curl_close($ch);
        if($raw){
            $j=json_decode($raw,true);
            if(!empty($j['dispositivos'])&&is_array($j['dispositivos'])){
                $btDisps=$j['dispositivos'];
            }
        }
    } catch (Throwable $e) {}

    $pdo=get_db_pdo();

    // Fallback: se local curl nao responder (ex: em nuvem na Hostinger), busca da telemetria recente do daemon
    if (empty($btDisps)) {
        try {
            $st = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave='google_home_agent_status' LIMIT 1");
            $rawSnap = $st->fetchColumn();
            if ($rawSnap) {
                $snap = json_decode($rawSnap, true);
                if (!empty($snap['bluetooth_devices']) && is_array($snap['bluetooth_devices'])) {
                    $btDisps = $snap['bluetooth_devices'];
                }
            }
        } catch (Throwable $e) {}
    }

    // Se houver dispositivos descobertos e auto_add=1 (ou default se for provavel google home)
    $autoAdd=!empty($in['auto_add'])||!empty($_GET['auto_add']);
    $adicionados=[];
    if(!empty($btDisps)){
        foreach($btDisps as $d){
            $mac=strtoupper(trim((string)($d['mac']??'')));
            $nome=trim((string)($d['nome']??'Google Home'));
            if(!$mac) continue;

            // Se autoAdd ou nome parecer Google/Nest/Speaker, garante cadastro se ja nao existir
            if($autoAdd || !empty($d['google_home_provavel']) || stripos($nome,'google')!==false || stripos($nome,'nest')!==false){
                $chk=$pdo->prepare("SELECT id FROM dispositivos_cluster WHERE mac_address=:m AND tipo='google_home' LIMIT 1");
                $chk->execute([':m'=>$mac]);
                if(!$chk->fetchColumn()){
                    $deviceId='gh-bt-'.bin2hex(random_bytes(4));
                    $token=bin2hex(random_bytes(16));
                    $caps=json_encode(['google-home','bluetooth','audio-sink','voice-bridge'],JSON_UNESCAPED_UNICODE);
                    $meta=json_encode(['tipo_conexao'=>'bluetooth','auto_descoberto'=>true,'criado_em'=>date('c')],JSON_UNESCAPED_UNICODE);
                    $ins=$pdo->prepare("INSERT INTO dispositivos_cluster (device_id,nome,tipo,mac_address,device_token,status,capabilities,metadata,localizacao,ultimo_heartbeat) VALUES (:did,:n,'google_home',:m,:t,'online',:c,:meta,'Residência',NOW())");
                    $ins->execute([':did'=>$deviceId,':n'=>$nome,':m'=>$mac,':t'=>$token,':c'=>$caps,':meta'=>$meta]);
                    $adicionados[]=['mac'=>$mac,'nome'=>$nome,'device_id'=>$deviceId];
                }
            }
        }
    }

    gh_out([
        'status'=>'sucesso',
        'dispositivos'=>$btDisps,
        'auto_adicionados'=>$adicionados,
        'total'=>count($btDisps)
    ]);
}

if($acao==='limpar_offline'){
    $pdo=get_db_pdo();
    $st=$pdo->exec("DELETE FROM dispositivos_cluster WHERE tipo='google_home' AND (status='offline' OR ultimo_heartbeat IS NULL OR ultimo_heartbeat < (NOW() - INTERVAL 120 SECOND))");
    // Tambem forca varredura de status que deleta dispositivos inalcancaveis
    $status=gh_bridge_status($pdo);
    gh_out([
        'status'=>'sucesso',
        'mensagem'=>'Dispositivos offline foram removidos do sistema.',
        'removidos'=>$st,
        'online_restantes'=>count($status['devices']??[])
    ]);
}

if($acao==='excluir'||$acao==='remover'){
    $id=(int)($in['id']??$_GET['id']??0);
    $deviceId=trim((string)($in['device_id']??$_GET['device_id']??''));
    if($id<=0&&$deviceId==='')gh_out(['status'=>'erro','mensagem'=>'ID ou identificador do dispositivo não informado.'],400);

    $pdo=get_db_pdo();
    if($id>0){
        $st=$pdo->prepare("DELETE FROM dispositivos_cluster WHERE id=:id AND tipo='google_home'");
        $st->execute([':id'=>$id]);
    } else {
        $st=$pdo->prepare("DELETE FROM dispositivos_cluster WHERE device_id=:did AND tipo='google_home'");
        $st->execute([':did'=>$deviceId]);
    }
    gh_out(['status'=>'sucesso','mensagem'=>'Dispositivo Google Home removido com sucesso.']);
}

gh_out(['status'=>'erro','mensagem'=>'Ação desconhecida.'],400);
