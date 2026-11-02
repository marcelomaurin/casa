<?php
// Worker CLI de preparacao de branch GitHub. Sem edicao de codigo ou merge.
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../api/db.php';
require_once __DIR__.'/../api/projetos_github_cliente.php';
if (($argv[1]??'')!=='--tarefa' || !ctype_digit((string)($argv[2]??''))) {
    fwrite(STDERR,"Uso: php github_worker.php --tarefa ID [--executar]\n");exit(2);
}
$id=(int)$argv[2];
if ($id<1) exit(2);
$executar=in_array('--executar',$argv,true);
try {
    $pdo=get_db_pdo();
    $q=$pdo->prepare("SELECT t.id,t.projeto_id,t.titulo,t.descricao,t.criterio_aceite,t.jarvis_tarefa_id,
        p.repositorio,p.status AS projeto_status,p.jarvis_plano_id,
        j.status AS fila_status
        FROM casa_projeto_tarefas t JOIN casa_projetos p ON p.id=t.projeto_id
        LEFT JOIN jarvis_tarefas j ON j.id=t.jarvis_tarefa_id WHERE t.id=?");
    $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['projeto_status']!=='executando' || !$r['jarvis_plano_id'] || !$r['jarvis_tarefa_id'])
        throw new RuntimeException('Tarefa inexistente ou nao aprovada');
    if (!in_array(strtoupper((string)$r['fila_status']),['PENDENTE','AGENDADA','EXECUTANDO','RESERVADA'],true))
        throw new RuntimeException('Tarefa indisponivel na fila');
    $project=['id'=>(int)$r['projeto_id'],'repositorio'=>$r['repositorio']];
    $task=['id'=>(int)$r['id'],'projeto_id'=>(int)$r['projeto_id'],
        'titulo'=>$r['titulo'],'descricao'=>$r['descricao'],'criterio_aceite'=>$r['criterio_aceite']];
    $ctx=casa_github_preparar_tarefa($project,$task);
    if (!$executar) {
        echo json_encode(['modo'=>'simulacao','contexto'=>$ctx],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        exit(0);
    }
    if (getenv('CASA_GITHUB_WORKER_ENABLED')!=='1')
        throw new RuntimeException('Worker desabilitado. Defina CASA_GITHUB_WORKER_ENABLED=1');
    // Nao e seguro disparar alteracoes remotas sem reserva exclusiva da fila.
    if (strtoupper((string)$r['fila_status'])!=='RESERVADA')
        throw new RuntimeException('A tarefa deve estar reservada pelo executor antes da operacao');
    throw new RuntimeException('Execucao bloqueada ate integrar verificacao do dono da reserva');
} catch (Throwable $e) {
    fwrite(STDERR,"Worker GitHub: ".$e->getMessage()."\n");exit(1);
}
