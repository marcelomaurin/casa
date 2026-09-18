<?php
// CASA / COMPUTER - motor generico de tarefas para qualquer solicitacao de IA.
// Toda pergunta externa cria uma tarefa-raiz. Modulos internos anexam subtarefas ao mesmo contexto.

function te_json($value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    $st=$pdo->prepare(
        "INSERT INTO jarvis_tarefas ".
        "(id_plano,ordem,titulo,descricao,tipo,executor,payload,depende_de,tarefa_pai_id,status,iniciado_em) ".
        "VALUES(:p,:o,:t,:d,'IMEDIATA',:e,:j,:dep,:pai,:s,CASE WHEN :s2='EXECUTANDO' THEN NOW() ELSE NULL END)"
    );
    $desc=is_array($payload) ? (string)($payload['descricao'] ?? '') : '';
    $st->execute([
        ':p'=>$ctx['id_plano'],
        ':o'=>$order,
        ':t'=>$titulo,
        ':d'=>$desc,
        ':e'=>$executor,
        ':j'=>te_json($payload),
        ':dep'=>$dependeDe,
        ':pai'=>$ctx['id_tarefa_raiz'],
        ':s'=>$status,
        ':s2'=>$status
    ]);
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
    $st=$pdo->prepare("SELECT id,ordem,titulo,tipo,executor,status,depende_de,tarefa_pai_id,erro,iniciado_em,concluido_em FROM jarvis_tarefas WHERE id_plano=:p ORDER BY ordem,id");
    $st->execute([':p'=>$ctx['id_plano']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
