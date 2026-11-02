<?php
require_once __DIR__.'/../api/fila_tarefas.php';
require_once __DIR__.'/../api/projetos_github_fluxo.php';

function casa_gh_fila_uma(PDO $pdo,string $worker,?callable $adapters=null): ?array {
    if (getenv('CASA_GITHUB_WORKER_ENABLED')!=='1' || getenv('CASA_GITHUB_AI_ENABLED')!=='1' ||
        getenv('CASA_GITHUB_WRITE_ENABLED')!=='1' || trim((string)getenv('CASA_GITHUB_REQUIRED_CHECKS'))==='')
        throw new RuntimeException('Configure worker, IA, escrita e checks obrigatorios antes de consumir a fila');
    if (!ft_nome_valido($worker) || strtolower($worker)==='servidor') throw new RuntimeException('Nome de worker invalido');
    ft_registrar_trabalhador($pdo,$worker,['github'],['github'],null,['host'=>gethostname(),'versao'=>'github-fluxo-1']);
    $jobs=ft_reservar($pdo,$worker,['github'],['github'],1,3600);
    if (!$jobs) return null;
    $job=$jobs[0];$id=(int)$job['id'];$state=[];$lock='casa_github_'.$id;$locked=false;
    try {
        $q=$pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Outra execucao ainda acompanha esta tarefa');
        $locked=true;$payload=$job['payload'];
        if (!is_array($payload) || ($payload['modo']??'')!=='github' ||
            !ctype_digit((string)($payload['tarefa_id']??'')) || (int)$payload['tarefa_id']<1 ||
            !ctype_digit((string)($payload['projeto_id']??'')) || (int)$payload['projeto_id']<1 ||
            $job['executor']!=='github' || $job['fila']!=='github') throw new RuntimeException('Payload GitHub invalido');
        $q=$pdo->prepare("SELECT t.*,p.repositorio,p.status AS projeto_status,p.jarvis_plano_id
            FROM casa_projeto_tarefas t JOIN casa_projetos p ON p.id=t.projeto_id
            WHERE t.id=? AND t.projeto_id=? AND t.jarvis_tarefa_id=? AND p.jarvis_plano_id=? AND p.status='executando'");
        $q->execute([(int)$payload['tarefa_id'],(int)$payload['projeto_id'],$id,$job['id_plano']]);$task=$q->fetch(PDO::FETCH_ASSOC);
        if (!$task) throw new RuntimeException('Tarefa nao corresponde ao projeto/plano aprovado');
        $project=['id'=>(int)$task['projeto_id'],'repositorio'=>$task['repositorio']];$task['id']=(int)$task['id'];
        $lease=static function()use($pdo,$id,$worker,$job):void {
            $q=$pdo->prepare("SELECT 1 FROM jarvis_tarefas j JOIN casa_projeto_tarefas t ON t.jarvis_tarefa_id=j.id
                JOIN casa_projetos p ON p.id=t.projeto_id
                WHERE j.id=? AND j.id_plano=? AND j.status='EXECUTANDO' AND j.reservado_por=?
                AND j.reservado_ate>NOW() AND j.executor='github' AND j.fila='github'
                AND p.status='executando' AND p.jarvis_plano_id=j.id_plano");
            $q->execute([$id,$job['id_plano'],$worker]);
            if (!$q->fetchColumn()) throw new RuntimeException('Reserva vencida ou projeto indisponivel');
        };
        $lease();$q=$pdo->prepare('SELECT resultado FROM jarvis_tarefas WHERE id=?');$q->execute([$id]);$raw=$q->fetchColumn();
        if ($raw!==null && $raw!==false && $raw!=='') {
            $state=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($state)) throw new RuntimeException('Checkpoint invalido');
            if (($state['etapa']??'')==='preparacao_branch') $state=[];
        }
        $save=static function(array $next)use($pdo,$id,$worker,$lease,&$state):void {
            $lease();$q=$pdo->prepare("UPDATE jarvis_tarefas SET resultado=? WHERE id=? AND status='EXECUTANDO'
                AND reservado_por=? AND reservado_ate>NOW()");
            $q->execute([json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id,$worker]);$lease();$state=$next;
        };
        $api=static function(string $method,string $route,?array $body=null)use($project,$lease):array {
            $lease();return casa_gh_fluxo_http($project['repositorio'],$method,$route,$body);
        };
        $services=['api'=>$api,'salvar'=>$save,'reserva'=>$lease,
            'gerar'=>static fn(array $ctx):array=>casa_gh_gerar_manifesto_ia($pdo,$ctx)];
        if ($adapters!==null) $services=$adapters($services);
        $state=casa_gh_fluxo_avancar($project,$task,$state,$services);
        $lease();
        if ($state['etapa']==='concluido') {
            $pdo->beginTransaction();
            try {
                $q=$pdo->prepare('SELECT id FROM jarvis_tarefas WHERE id=? FOR UPDATE');$q->execute([$id]);$lease();
                ft_concluir($pdo,$id,$worker,$state);
                $pdo->prepare("UPDATE casa_projeto_tarefas SET status='concluida' WHERE id=?")->execute([$task['id']]);$pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
        } elseif ($state['etapa']==='erro') {
            ft_falhar($pdo,$id,$worker,$state['status'],false,$state);
            $pdo->prepare("UPDATE casa_projeto_tarefas SET status='erro' WHERE id=?")->execute([$task['id']]);
        } else {
            // Esperar CI/humano nao gasta tentativas nem libera dependencias.
            $delay=$state['etapa']==='acompanhar'?60:5;
            $q=$pdo->prepare("UPDATE jarvis_tarefas SET status='PENDENTE', reservado_por=NULL,reservado_ate=NULL,
                tentativas=GREATEST(tentativas-1,0),executar_em=DATE_ADD(NOW(),INTERVAL ? SECOND)
                WHERE id=? AND status='EXECUTANDO' AND reservado_por=? AND reservado_ate>NOW()");
            $q->execute([$delay,$id,$worker]);
            if ($q->rowCount()!==1) throw new RuntimeException('Reserva perdida ao aguardar proxima etapa');
        }
        return ['jarvis_tarefa_id'=>$id,'etapa'=>$state['etapa'],'status'=>$state['status']??null,'pr_url'=>$state['pr_url']??null];
    } catch (Throwable $e) {
        try {
            $q=$pdo->prepare("SELECT 1 FROM jarvis_tarefas WHERE id=? AND status='EXECUTANDO' AND reservado_por=? AND reservado_ate>NOW()");
            $q->execute([$id,$worker]);
            if ($q->fetchColumn()) ft_falhar($pdo,$id,$worker,'Falha no fluxo GitHub; consulte o worker',true,$state?:null);
        } catch (Throwable $ignored) { /* Nunca sobrescrever a reserva de outro trabalhador. */ }
        throw $e;
    } finally {
        if ($locked) { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]); }
    }
}
