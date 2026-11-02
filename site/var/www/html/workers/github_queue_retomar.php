<?php
// Migracao operacional explicita de UMA preparacao concluida pelo worker antigo.
if (PHP_SAPI!=='cli') { http_response_code(403);exit(1); }
require_once __DIR__.'/../api/db.php';
require_once __DIR__.'/../api/projetos_github.php';
if (($argv[1]??'')!=='--tarefa' || !ctype_digit($argv[2]??'') || (int)$argv[2]<1 || ($argv[3]??'')!=='--confirmar') {
    fwrite(STDERR,"Uso: php github_queue_retomar.php --tarefa ID_PROJETO_TAREFA --confirmar\n");exit(2);
}
$pdo=get_db_pdo();
try {
    $pdo->beginTransaction();
    $q=$pdo->prepare("SELECT t.id,t.projeto_id,t.jarvis_tarefa_id,p.repositorio,p.jarvis_plano_id,j.resultado,j.status,j.id_plano
        FROM casa_projeto_tarefas t JOIN casa_projetos p ON p.id=t.projeto_id JOIN jarvis_tarefas j ON j.id=t.jarvis_tarefa_id
        WHERE t.id=? AND j.fila='github' AND j.executor='github' AND p.status='executando' FOR UPDATE");
    $q->execute([(int)$argv[2]]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $old=$row?json_decode($row['resultado']??'',true):null;
    if (!$row || $row['status']!=='CONCLUIDA' || (int)$row['id_plano']!==(int)$row['jarvis_plano_id'] ||
        ($old['etapa']??'')!=='preparacao_branch' || ($old['status']??'')!=='aguardando_geracao_e_revisao')
        throw new RuntimeException('Tarefa nao e uma preparacao antiga elegivel');
    casa_github_repo_validar($row['repositorio'],casa_github_permitidos_ambiente());
    $q=$pdo->prepare("SELECT id FROM jarvis_tarefas WHERE depende_de=? AND status NOT IN ('PENDENTE','AGENDADA','CANCELADA') FOR UPDATE");
    $q->execute([$row['jarvis_tarefa_id']]);
    if ($q->fetchColumn()) throw new RuntimeException('Dependentes ja iniciadas; reconciliacao humana necessaria');
    $pdo->prepare("UPDATE jarvis_tarefas SET status='PENDENTE',tentativas=0,erro=NULL,concluido_em=NULL,
        reservado_por=NULL,reservado_ate=NULL,executar_em=NOW() WHERE id=?")->execute([$row['jarvis_tarefa_id']]);
    $pdo->prepare("UPDATE jarvis_planos SET status='EXECUTANDO',concluido_em=NULL WHERE id=?")->execute([$row['id_plano']]);
    $pdo->commit();echo "Preparacao devolvida ao fluxo completo\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
