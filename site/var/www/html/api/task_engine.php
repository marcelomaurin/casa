<?php
require_once(__DIR__ . '/v1/device_registry.php');
// CASA / COMPUTER - motor generico de tarefas para qualquer solicitacao de IA.
// Toda pergunta externa cria uma tarefa-raiz. Modulos internos anexam subtarefas ao mesmo contexto.

function te_json($value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function te_has_parent_column(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) return $available;
    try {
        $db=(string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='jarvis_tarefas' AND COLUMN_NAME='tarefa_pai_id'");
        $st->execute([':db'=>$db]);
        $available=((int)$st->fetchColumn())>0;
    } catch (Throwable $e) {
        $available=false;
    }
    return $available;
}

function te_context_from_input($value): ?array {
    if (!is_array($value)) return null;
    $plan = (int)($value['id_plano'] ?? 0);
    $root = (int)($value['id_tarefa_raiz'] ?? 0);
    if ($plan <= 0 || $root <= 0) return null;
    return [
        'id_plano'=>$plan,
        'id_tarefa_raiz'=>$root,
        'current_task_id'=>(int)($value['current_task_id'] ?? 0),
        'origem'=>(string)($value['origem'] ?? ''),
        'modulo'=>(string)($value['modulo'] ?? '')
    ];
}

function te_begin(PDO $pdo, string $pergunta, string $origem='COMPUTER', string $modulo='computer', ?array $existing=null): array {
    if ($existing && !empty($existing['id_plano']) && !empty($existing['id_tarefa_raiz'])) {
        return [
            'id_plano'=>(int)$existing['id_plano'],
            'id_tarefa_raiz'=>(int)$existing['id_tarefa_raiz'],
            'current_task_id'=>(int)($existing['current_task_id'] ?? 0),
            'origem'=>$origem,
            'modulo'=>$modulo,
            'root_created'=>false
        ];
    }

    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare(
            "INSERT INTO jarvis_planos(demanda_original,origem,status,resumo,dados_plano) ".
            "VALUES(:d,:o,'EM_EXECUCAO',:r,:j)"
        );
        $st->execute([
            ':d'=>$pergunta,
            ':o'=>$origem ?: 'COMPUTER',
            ':r'=>'Solicitação em processamento',
            ':j'=>te_json(['modulo'=>$modulo,'tipo'=>'PERGUNTA_IA'])
        ]);
        $plan=(int)$pdo->lastInsertId();

        $root=$pdo->prepare(
            "INSERT INTO jarvis_tarefas(id_plano,ordem,titulo,descricao,tipo,executor,payload,status,iniciado_em) ".
            "VALUES(:p,1,:t,:d,'IMEDIATA',:e,:j,'EXECUTANDO',NOW())"
        );
        $root->execute([
            ':p'=>$plan,
            ':t'=>'Responder solicitação do usuário',
            ':d'=>$pergunta,
            ':e'=>$modulo,
            ':j'=>te_json(['pergunta'=>$pergunta,'origem'=>$origem,'modulo'=>$modulo])
        ]);
        $rootId=(int)$pdo->lastInsertId();
        $pdo->commit();

        return [
            'id_plano'=>$plan,
            'id_tarefa_raiz'=>$rootId,
            'current_task_id'=>$rootId,
            'origem'=>$origem,
            'modulo'=>$modulo,
            'root_created'=>true
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function te_next_order(PDO $pdo, int $plan): int {
    $st=$pdo->prepare("SELECT COALESCE(MAX(ordem),0)+1 FROM jarvis_tarefas WHERE id_plano=:p");
    $st->execute([':p'=>$plan]);
    return max(2,(int)$st->fetchColumn());
}

function te_add_subtask(PDO $pdo, array $ctx, string $titulo, string $executor, $payload=null, ?int $dependeDe=null, string $status='PENDENTE'): int {
    $order=te_next_order($pdo,(int)$ctx['id_plano']);
    $desc=is_array($payload) ? (string)($payload['descricao'] ?? '') : '';

    if (te_has_parent_column($pdo)) {
        $sql="INSERT INTO jarvis_tarefas ".
             "(id_plano,ordem,titulo,descricao,tipo,executor,payload,depende_de,tarefa_pai_id,status,iniciado_em) ".
             "VALUES(:p,:o,:t,:d,'IMEDIATA',:e,:j,:dep,:pai,:s,CASE WHEN :s2='EXECUTANDO' THEN NOW() ELSE NULL END)";
        $params=[
            ':p'=>$ctx['id_plano'], ':o'=>$order, ':t'=>$titulo, ':d'=>$desc,
            ':e'=>$executor, ':j'=>te_json($payload), ':dep'=>$dependeDe,
            ':pai'=>$ctx['id_tarefa_raiz'], ':s'=>$status, ':s2'=>$status
        ];
    } else {
        $sql="INSERT INTO jarvis_tarefas ".
             "(id_plano,ordem,titulo,descricao,tipo,executor,payload,depende_de,status,iniciado_em) ".
             "VALUES(:p,:o,:t,:d,'IMEDIATA',:e,:j,:dep,:s,CASE WHEN :s2='EXECUTANDO' THEN NOW() ELSE NULL END)";
        $params=[
            ':p'=>$ctx['id_plano'], ':o'=>$order, ':t'=>$titulo, ':d'=>$desc,
            ':e'=>$executor, ':j'=>te_json($payload), ':dep'=>$dependeDe,
            ':s'=>$status, ':s2'=>$status
        ];
    }

    $st=$pdo->prepare($sql);
    $st->execute($params);
    return (int)$pdo->lastInsertId();
}

function te_start(PDO $pdo, int $taskId): void {
    if($taskId<=0)return;
    $pdo->prepare("UPDATE jarvis_tarefas SET status='EXECUTANDO', iniciado_em=COALESCE(iniciado_em,NOW()) WHERE id=:id")
        ->execute([':id'=>$taskId]);
}

function te_complete(PDO $pdo, int $taskId, $resultado=null): void {
    if($taskId<=0)return;
    $pdo->prepare("UPDATE jarvis_tarefas SET status='CONCLUIDA',resultado=:r,erro=NULL,concluido_em=NOW() WHERE id=:id")
        ->execute([':r'=>te_json($resultado),':id'=>$taskId]);
}

function te_fail(PDO $pdo, int $taskId, string $erro, $resultado=null): void {
    if($taskId<=0)return;
    $pdo->prepare("UPDATE jarvis_tarefas SET status='ERRO',resultado=:r,erro=:e,concluido_em=NOW() WHERE id=:id")
        ->execute([':r'=>te_json($resultado),':e'=>mb_substr($erro,0,4000,'UTF-8'),':id'=>$taskId]);
}

function te_finish(PDO $pdo, array $ctx, string $resposta, $dados=null): void {
    $root=(int)$ctx['id_tarefa_raiz'];
    $plan=(int)$ctx['id_plano'];
    te_complete($pdo,$root,['resposta'=>$resposta,'dados'=>$dados]);
    $pdo->prepare("UPDATE jarvis_planos SET status='CONCLUIDO',resumo=:r,concluido_em=NOW() WHERE id=:id")
        ->execute([':r'=>mb_substr($resposta,0,2000,'UTF-8'),':id'=>$plan]);
}

function te_finish_error(PDO $pdo, array $ctx, string $erro): void {
    te_fail($pdo,(int)$ctx['id_tarefa_raiz'],$erro);
    $pdo->prepare("UPDATE jarvis_planos SET status='ERRO',resumo=:r,concluido_em=NOW() WHERE id=:id")
        ->execute([':r'=>mb_substr($erro,0,2000,'UTF-8'),':id'=>$ctx['id_plano']]);
}

function te_public_context(array $ctx): array {
    return [
        'id_plano'=>(int)$ctx['id_plano'],
        'id_tarefa_raiz'=>(int)$ctx['id_tarefa_raiz'],
        'origem'=>$ctx['origem'] ?? '',
        'modulo'=>$ctx['modulo'] ?? ''
    ];
}

function te_list_tasks(PDO $pdo, array $ctx): array {
    $parentCol=te_has_parent_column($pdo) ? "tarefa_pai_id" : "NULL AS tarefa_pai_id";
    $st=$pdo->prepare("SELECT id,ordem,titulo,tipo,executor,status,depende_de,".$parentCol.",erro,iniciado_em,concluido_em FROM jarvis_tarefas WHERE id_plano=:p ORDER BY ordem,id");
    $st->execute([':p'=>$ctx['id_plano']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


function te_task_belongs_to_plan(PDO $pdo, int $taskId, int $planId): bool {
    $st=$pdo->prepare("SELECT COUNT(*) FROM jarvis_tarefas WHERE id=:t AND id_plano=:p");
    $st->execute([':t'=>$taskId,':p'=>$planId]);
    return ((int)$st->fetchColumn())>0;
}

function te_device_action(PDO $pdo, array $ctx, int $taskId, array $action, bool $confirm=false): array {
    if($taskId<=0 || !te_task_belongs_to_plan($pdo,$taskId,(int)$ctx['id_plano'])){
        throw new RuntimeException('task_not_in_plan');
    }

    $deviceId=trim((string)($action['device_id'] ?? ''));
    $command=substr(trim((string)($action['command'] ?? $action['comando'] ?? '')),0,120);
    if($deviceId==='' || $command==='') throw new RuntimeException('device_id_and_command_required');

    $priority=strtolower(trim((string)($action['priority'] ?? $action['prioridade'] ?? 'normal')));
    if(!in_array($priority,['low','normal','high','critical'],true)) $priority='normal';
    $ttl=max(10,min(86400,(int)($action['ttl_seconds'] ?? 300)));
    $risk=max(0,min(4,(int)($action['risk_level'] ?? 1)));
    $required=substr(trim((string)($action['required_capability'] ?? '')),0,120);
    $payload=is_array($action['payload'] ?? null)?$action['payload']:[];

    registry_require($pdo,$deviceId);

    if($required!==''){
        $resolved=registry_require_capability($pdo,$deviceId,$required);
        $risk=max($risk,(int)$resolved['risk_level']);
    }
    if($risk>=3 && !$confirm) throw new RuntimeException('confirmation_required');

    $corr='task_'.(int)$ctx['id_plano'].'_'.$taskId.'_'.bin2hex(random_bytes(8));
    $startedTransaction=!$pdo->inTransaction();
    if($startedTransaction)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("INSERT INTO jarvis_acoes(id_plano,id_tarefa,tipo,device_id,comando,payload,required_capability,prioridade,risk_level,ttl_seconds,correlation_id,status)
            VALUES(:p,:t,'DEVICE_COMMAND',:d,:c,:j,:cap,:pr,:risk,:ttl,:corr,'PLANNED')");
        $st->execute([
            ':p'=>$ctx['id_plano'],':t'=>$taskId,':d'=>$deviceId,':c'=>$command,
            ':j'=>te_json($payload),':cap'=>$required!==''?$required:null,':pr'=>$priority,
            ':risk'=>$risk,':ttl'=>$ttl,':corr'=>$corr
        ]);
        $actionId=(int)$pdo->lastInsertId();
        $idem='task_'.$taskId.'_action_'.$actionId;

        $cmd=$pdo->prepare("INSERT INTO device_commands(device_id,comando,payload,prioridade,correlation_id,idempotency_key,status,lifecycle_status,max_retries,requested_by,risk_level,expira_em)
            VALUES(:d,:c,:j,:pr,:corr,:idem,'pending','QUEUED',3,:rb,:risk,DATE_ADD(NOW(),INTERVAL :ttl SECOND))");
        $cmd->bindValue(':d',$deviceId);
        $cmd->bindValue(':c',$command);
        $cmd->bindValue(':j',te_json($payload));
        $cmd->bindValue(':pr',$priority);
        $cmd->bindValue(':corr',$corr);
        $cmd->bindValue(':idem',$idem);
        $cmd->bindValue(':rb','TASK:'.$taskId);
        $cmd->bindValue(':risk',$risk,PDO::PARAM_INT);
        $cmd->bindValue(':ttl',$ttl,PDO::PARAM_INT);
        $cmd->execute();
        $commandId=(int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE jarvis_acoes SET command_id=:c,status='QUEUED' WHERE id=:a")
            ->execute([':c'=>$commandId,':a'=>$actionId]);
        $pdo->prepare("UPDATE jarvis_tarefas SET status='AGUARDANDO', iniciado_em=COALESCE(iniciado_em,NOW()) WHERE id=:t")
            ->execute([':t'=>$taskId]);

        if($startedTransaction)$pdo->commit();
        return [
            'action_id'=>$actionId,'command_id'=>$commandId,'correlation_id'=>$corr,
            'status'=>'QUEUED','device_id'=>$deviceId,'command'=>$command,'risk_level'=>$risk
        ];
    }catch(Throwable $e){
        if($startedTransaction && $pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function te_sync_device_command(PDO $pdo, int $commandId): ?array {
    if($commandId<=0)return null;
    $st=$pdo->prepare("SELECT a.id action_id,a.id_plano,a.id_tarefa,a.status action_status,
        c.id command_id,c.lifecycle_status,c.resultado,c.erro
        FROM jarvis_acoes a JOIN device_commands c ON c.id=a.command_id
        WHERE c.id=:id LIMIT 1");
    $st->execute([':id'=>$commandId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;

    $life=strtoupper((string)$row['lifecycle_status']);
    $terminal=false;
    $taskStatus='AGUARDANDO';
    $actionStatus=$life;
    $result=null;
    $error=$row['erro'] ?? null;

    if(!empty($row['resultado'])){
        $decoded=json_decode((string)$row['resultado'],true);
        $result=is_array($decoded)?$decoded:$row['resultado'];
    }

    if($life==='EXECUTING'){
        $taskStatus='EXECUTANDO';
    }elseif($life==='DONE'){
        $terminal=true;$taskStatus='CONCLUIDA';$actionStatus='DONE';
    }elseif(in_array($life,['FAILED','EXPIRED'],true)){
        $terminal=true;$taskStatus='ERRO';$actionStatus=$life;
        if(!$error)$error=$life==='EXPIRED'?'Comando expirado':'Falha no dispositivo';
    }

    $pdo->prepare("UPDATE jarvis_acoes SET status=:s,resultado=:r,erro=:e,
        iniciado_em=CASE WHEN :started=1 THEN COALESCE(iniciado_em,NOW()) ELSE iniciado_em END,
        concluido_em=CASE WHEN :done=1 THEN NOW() ELSE concluido_em END WHERE id=:id")
        ->execute([
            ':s'=>$actionStatus,':r'=>te_json($result),':e'=>$error,
            ':started'=>in_array($life,['EXECUTING','DONE','FAILED'],true)?1:0,
            ':done'=>$terminal?1:0,':id'=>$row['action_id']
        ]);

    if($terminal){
        if($taskStatus==='CONCLUIDA'){
            te_complete($pdo,(int)$row['id_tarefa'],[
                'action_id'=>(int)$row['action_id'],'command_id'=>$commandId,
                'lifecycle_status'=>$life,'result'=>$result
            ]);
        }else{
            te_fail($pdo,(int)$row['id_tarefa'],(string)$error,[
                'action_id'=>(int)$row['action_id'],'command_id'=>$commandId,
                'lifecycle_status'=>$life,'result'=>$result
            ]);
        }
    }else{
        $pdo->prepare("UPDATE jarvis_tarefas SET status=:s,iniciado_em=COALESCE(iniciado_em,NOW()) WHERE id=:id")
            ->execute([':s'=>$taskStatus,':id'=>$row['id_tarefa']]);
    }

    return [
        'action_id'=>(int)$row['action_id'],'task_id'=>(int)$row['id_tarefa'],
        'plan_id'=>(int)$row['id_plano'],'command_id'=>$commandId,
        'lifecycle_status'=>$life,'task_status'=>$taskStatus
    ];
}

function te_list_actions(PDO $pdo, array $ctx): array {
    $st=$pdo->prepare("SELECT id,id_tarefa,tipo,device_id,comando,required_capability,prioridade,risk_level,
        correlation_id,command_id,status,erro,criado_em,iniciado_em,concluido_em
        FROM jarvis_acoes WHERE id_plano=:p ORDER BY id");
    $st->execute([':p'=>$ctx['id_plano']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


function te_sync_device_actions_for_device(PDO $pdo, string $deviceId): array {
    $st=$pdo->prepare("SELECT a.command_id FROM jarvis_acoes a
        JOIN device_commands c ON c.id=a.command_id
        WHERE c.device_id=:d AND a.command_id IS NOT NULL
          AND a.status NOT IN('DONE','FAILED','EXPIRED')");
    $st->execute([':d'=>$deviceId]);
    $out=[];
    foreach($st->fetchAll(PDO::FETCH_COLUMN) as $id){
        $sync=te_sync_device_command($pdo,(int)$id);
        if($sync)$out[]=$sync;
    }
    return $out;
}
