<?php
// CASA Projetos: aprovacao explicita e envio atomico para a fila ja existente.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/security_v1.php';
$pdo=get_db_pdo();
api_v1_basic_guard($pdo);
$client=api_v1_auth_client($pdo,['projects.manage']);
$method=$_SERVER['REQUEST_METHOD']??'GET';
$action=strtolower((string)($_GET['acao']??'status'));
$input=[];
if ($method==='POST') {
    $input=json_decode(file_get_contents('php://input'),true);
    if (!is_array($input)) api_v1_json_response(400,['status'=>'erro','mensagem'=>'JSON invalido']);
    $action=strtolower((string)($input['acao']??$action));
}
$id=(int)($input['projeto_id']??($_GET['projeto_id']??0));
if ($id<=0) api_v1_json_response(400,['status'=>'erro','mensagem'=>'projeto_id obrigatorio']);
try {
    if ($action==='status' && $method==='GET') {
        $q=$pdo->prepare('SELECT id,nome,status,jarvis_plano_id FROM casa_projetos WHERE id=?');
        $q->execute([$id]);$project=$q->fetch(PDO::FETCH_ASSOC);
        if (!$project) api_v1_json_response(404,['status'=>'erro','mensagem'=>'Projeto nao encontrado']);
        $q=$pdo->prepare("SELECT p.id,p.titulo,p.status AS status_planejado,p.criterio_aceite,p.jarvis_tarefa_id,
            j.status AS status_execucao,j.reservado_por,j.tentativas,j.resultado,j.erro,j.iniciado_em,j.concluido_em
            FROM casa_projeto_tarefas p LEFT JOIN jarvis_tarefas j ON j.id=p.jarvis_tarefa_id
            WHERE p.projeto_id=? ORDER BY p.id");
        $q->execute([$id]);$tasks=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tasks as &$t) {
            if ($t['resultado']!==null) $t['resultado']=json_decode($t['resultado'],true);
        } unset($t);
        api_v1_json_response(200,['status'=>'ok','projeto'=>$project,'tarefas'=>$tasks]);
    }
    if ($action!=='aprovar' || $method!=='POST') api_v1_json_response(405,['status'=>'erro','mensagem'=>'Use POST acao=aprovar ou GET acao=status']);
    if (($input['confirmar']??false)!==true) api_v1_json_response(400,['status'=>'erro','mensagem'=>'confirmar=true obrigatorio']);
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT * FROM casa_projetos WHERE id=? FOR UPDATE');$q->execute([$id]);$project=$q->fetch(PDO::FETCH_ASSOC);
    if (!$project) { $pdo->rollBack();api_v1_json_response(404,['status'=>'erro','mensagem'=>'Projeto nao encontrado']); }
    if ($project['status']!=='planejamento' || $project['jarvis_plano_id']!==null) {
        $pdo->rollBack();api_v1_json_response(409,['status'=>'erro','mensagem'=>'Projeto ja aprovado ou fora do planejamento']);
    }
    $q=$pdo->prepare('SELECT t.*,a.executor FROM casa_projeto_tarefas t LEFT JOIN casa_projeto_agentes a ON a.id=t.agente_id WHERE t.projeto_id=? ORDER BY t.id');
    $q->execute([$id]);$tasks=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$tasks) { $pdo->rollBack();api_v1_json_response(400,['status'=>'erro','mensagem'=>'Projeto sem tarefas']); }
    $seen=[];
    foreach ($tasks as $t) {
        $executor=strtolower((string)($t['executor']??'jarvis'));
        // Nao permitir execucao arbitraria de scripts, dispositivos ou extensoes via projetos.
        if (!in_array($executor,['jarvis','web'],true)) {
            $pdo->rollBack();api_v1_json_response(400,['status'=>'erro','mensagem'=>'Executor nao permitido: '.$executor]);
        }
        if ($t['depende_de']!==null && !isset($seen[(int)$t['depende_de']])) {
            $pdo->rollBack();api_v1_json_response(400,['status'=>'erro','mensagem'=>'Dependencia invalida ou posterior']);
        }
        $seen[(int)$t['id']]=true;
    }
    $corr='proj_'.$id.'_'.bin2hex(random_bytes(8));
    $plan=$pdo->prepare("INSERT INTO jarvis_planos(demanda_original,origem,status,resumo,correlation_id)
        VALUES(?,'PROJETOS','EXECUTANDO',?,?)");
    $plan->execute([$project['objetivo'],$project['nome'],$corr]);
    $planId=(int)$pdo->lastInsertId();
    $insert=$pdo->prepare("INSERT INTO jarvis_tarefas
        (id_plano,correlation_id,ordem,titulo,descricao,tipo,executor,fila,payload,depende_de,status,max_tentativas)
        VALUES(?,?,?,?,?,'IMEDIATA',?,'geral',?,?,'PENDENTE',3)");
    $update=$pdo->prepare("UPDATE casa_projeto_tarefas SET jarvis_tarefa_id=?,status='enfileirada' WHERE id=? AND projeto_id=?");
    $mapped=[];
    foreach ($tasks as $i=>$t) {
        $executor=strtolower((string)($t['executor']??'jarvis'));
        $instruction=$t['descricao']."\nCriterio de aceite: ".(string)($t['criterio_aceite']??'Nao informado');
        $payload=$executor==='web'?['query'=>$t['descricao'],'max_results'=>5,'fetch_pages'=>false]:['comando'=>$instruction];
        $dep=$t['depende_de']!==null?$mapped[(int)$t['depende_de']]:null;
        $insert->execute([$planId,$corr,$i+1,mb_substr($t['titulo'],0,180),$t['descricao'],$executor,
            json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$dep]);
        $queueId=(int)$pdo->lastInsertId();
        $mapped[(int)$t['id']]=$queueId;
        $update->execute([$queueId,$t['id'],$id]);
    }
    $pdo->prepare("UPDATE casa_projetos SET status='executando',jarvis_plano_id=? WHERE id=?")->execute([$planId,$id]);
    $pdo->commit();
    api_v1_log($pdo,'PROJETO_APROVADO','INFO',$client['nome']??null,['projeto_id'=>$id,'plano_id'=>$planId,'tarefas'=>count($mapped)]);
    api_v1_json_response(200,['status'=>'ok','projeto_id'=>$id,'jarvis_plano_id'=>$planId,'tarefas_enfileiradas'=>count($mapped)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('projetos_execucao: '.$e->getMessage());
    api_v1_json_response(500,['status'=>'erro','mensagem'=>'Falha ao consultar ou publicar tarefas']);
}
