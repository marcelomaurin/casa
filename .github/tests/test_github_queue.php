<?php
// Integracao real MySQL/MariaDB, APIs externas simuladas. Somente banco de teste.
if (getenv('JARVIS_DB_NAME')!=='casa_test') throw new RuntimeException('Requer casa_test');
define('CASA_GITHUB_FIXTURE_ONLY',true);
require __DIR__.'/../../tests/test_projetos_github_fluxo.php';
require __DIR__.'/../../site/var/www/html/api/db.php';
require __DIR__.'/../../site/var/www/html/workers/github_queue_runtime.php';
foreach (['WORKER_ENABLED','AI_ENABLED','WRITE_ENABLED'] as $key) putenv('CASA_GITHUB_'.$key.'=1');
$pdo=get_db_pdo();
foreach (['1.33','1.34','1.36','1.37'] as $v) casa_execute_schema_file($pdo,__DIR__.'/../../site/var/www/html/api/migrations/'.$v.'.sql');
$pdo->exec("INSERT INTO jarvis_planos(demanda_original,origem,status) VALUES('Teste GitHub','TESTE','EXECUTANDO')");$plan=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO casa_projetos(nome,objetivo,repositorio,status,criado_por,jarvis_plano_id) VALUES('Teste GitHub','Testar fluxo','example/casa','executando','teste',?)")->execute([$plan]);$project=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO casa_projeto_tarefas(projeto_id,titulo,descricao,criterio_aceite,status) VALUES(?,'Corrigir src/app.php','Alterar src/app.php','Retornar 2','enfileirada')")->execute([$project]);$task=(int)$pdo->lastInsertId();
$payload=json_encode(['modo'=>'github','projeto_id'=>$project,'tarefa_id'=>$task]);
$pdo->prepare("INSERT INTO jarvis_tarefas(id_plano,ordem,titulo,tipo,executor,fila,payload,status,max_tentativas) VALUES(?,1,'GitHub','IMEDIATA','github','github',?,'PENDENTE',3)")->execute([$plan,$payload]);$job=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE casa_projeto_tarefas SET jarvis_tarefa_id=? WHERE id=?')->execute([$job,$task]);
$pdo->prepare("INSERT INTO jarvis_tarefas(id_plano,ordem,titulo,tipo,executor,fila,depende_de,status) VALUES(?,2,'Dependente','IMEDIATA','teste_github_dependente','geral',?,'PENDENTE')")->execute([$plan,$job]);$dep=(int)$pdo->lastInsertId();
$fake=new GithubFake();$branch=casa_github_branch_tarefa($project,$task);
$adapter=static function(array $services)use($fake,$branch):array {
    $lease=$services['reserva'];
    $services['api']=static function($m,$r,$p=null)use($fake,$branch,$lease) {
        $lease();
        // A fixture usa IDs fixos; o contrato real usa IDs auto_increment.
        $mapped=str_replace($branch,'casa/projeto-1/tarefa-2',$r);
        $result=$fake->api($m,$mapped,$p);
        if ($fake->pr) $fake->pr['head']['ref']=$branch;
        if (isset($result['head']['ref'])) $result['head']['ref']=$branch;
        foreach ($result as &$item) if (is_array($item) && isset($item['head']['ref'])) $item['head']['ref']=$branch;
        return $result;
    };
    $services['gerar']=static fn($ctx)=>$fake->changes;
    return $services;
};
$read=static function()use($pdo,$job):array { $q=$pdo->prepare('SELECT * FROM jarvis_tarefas WHERE id=?');$q->execute([$job]);return $q->fetch(PDO::FETCH_ASSOC); };
$step=static function()use($pdo,$job,$adapter):array {
    $pdo->prepare('UPDATE jarvis_tarefas SET executar_em=NOW() WHERE id=?')->execute([$job]);
    return casa_gh_fila_uma($pdo,'github-integration',$adapter);
};
try {
    $step();check($read()['status']==='PENDENTE','geracao nao conclui fila');
    check(json_decode($read()['resultado'],true)['etapa']==='publicar','manifesto persistido no banco');
    $step();$step();
    for ($i=0;$i<5;$i++) $step();
    check((int)$read()['tentativas']===0,'espera humana nao esgota tentativas');
    check(ft_reservar($pdo,'dependent',['teste_github_dependente'],[],1,60)===[],'dependente bloqueada antes do merge');
    $fake->approve();$step();check($read()['status']==='PENDENTE','aprovacao nao conclui fila');
    $fake->pr['merged']=true;$fake->pr['state']='closed';$fake->pr['merged_by']=['type'=>'User','login'=>'maintainer'];
    $step();check($read()['status']==='CONCLUIDA','merge humano conclui fila');
    $reserved=ft_reservar($pdo,'dependent',['teste_github_dependente'],[],1,60);
    check(array_column($reserved,'id')===[$dep],'dependente liberada somente apos merge');
    ft_concluir($pdo,$dep,'dependent',['ok'=>true]);
    $q=$pdo->prepare('SELECT status FROM jarvis_planos WHERE id=?');$q->execute([$plan]);
    check($q->fetchColumn()==='CONCLUIDO','plano fecha depois das dependencias');
    echo "OK: integracao fila GitHub, checkpoints, CI e revisao humana\n";
} finally {
    $pdo->prepare('DELETE FROM casa_projetos WHERE id=?')->execute([$project]);
    $pdo->prepare('DELETE FROM jarvis_planos WHERE id=?')->execute([$plan]);
}
