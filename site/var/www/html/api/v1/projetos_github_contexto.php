<?php
// Contexto de tarefa aprovada para agente GitHub externo.
// Somente leitura; nenhuma operacao remota e realizada.
require_once __DIR__.'/../db.php';
require_once __DIR__.'/security_v1.php';
require_once __DIR__.'/../projetos_github.php';
$pdo=get_db_pdo();
api_v1_basic_guard($pdo);
$client=api_v1_auth_client($pdo,['tasks.worker']);
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
    api_v1_json_response(405,['status'=>'erro','mensagem'=>'Use GET']);
$id=filter_input(INPUT_GET,'tarefa_id',FILTER_VALIDATE_INT);
if (!$id || $id<1) api_v1_json_response(400,['status'=>'erro','mensagem'=>'tarefa_id obrigatorio']);
try {
    $q=$pdo->prepare("SELECT t.id,t.projeto_id,t.titulo,t.descricao,t.criterio_aceite,t.jarvis_tarefa_id,
        p.id AS pid,p.nome,p.repositorio,p.status AS projeto_status,p.jarvis_plano_id,
        j.status AS fila_status
        FROM casa_projeto_tarefas t JOIN casa_projetos p ON p.id=t.projeto_id
        LEFT JOIN jarvis_tarefas j ON j.id=t.jarvis_tarefa_id WHERE t.id=? LIMIT 1");
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) api_v1_json_response(404,['status'=>'erro','mensagem'=>'Tarefa nao encontrada']);
    if ($row['projeto_status']!=='executando' || !$row['jarvis_plano_id'] || !$row['jarvis_tarefa_id'] ||
        !in_array(strtoupper((string)$row['fila_status']),['PENDENTE','AGENDADA','EXECUTANDO','RESERVADA'],true))
        api_v1_json_response(409,['status'=>'erro','mensagem'=>'Tarefa nao aprovada ou indisponivel']);
    $project=['id'=>(int)$row['pid'],'repositorio'=>$row['repositorio']];
    $task=['id'=>(int)$row['id'],'projeto_id'=>(int)$row['projeto_id'],'titulo'=>$row['titulo'],
        'descricao'=>$row['descricao'],'criterio_aceite'=>$row['criterio_aceite']];
    $context=casa_github_preparar_tarefa($project,$task);
    api_v1_json_response(200,['status'=>'ok','tarefa_id'=>$id,'fila_tarefa_id'=>(int)$row['jarvis_tarefa_id'],
        'contexto'=>$context,'nota'=>'Somente contexto; reserva e conclusao exigem API de fila']);
} catch (DomainException|InvalidArgumentException $e) {
    api_v1_json_response(403,['status'=>'erro','mensagem'=>'Repositorio ou tarefa nao autorizado']);
} catch (Throwable $e) {
    error_log('projetos_github_contexto: '.$e->getMessage());
    api_v1_json_response(500,['status'=>'erro','mensagem'=>'Falha ao preparar contexto']);
}
