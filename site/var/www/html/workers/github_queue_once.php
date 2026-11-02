<?php
// Consome UMA tarefa da fila GitHub por invocacao. Nao gera/aplica codigo automaticamente.
if (PHP_SAPI!=='cli') { http_response_code(403); exit(1); }
require_once __DIR__.'/../api/db.php';
require_once __DIR__.'/../api/fila_tarefas.php';
if (getenv('CASA_GITHUB_WORKER_ENABLED')!=='1') {
    fwrite(STDERR,"Worker GitHub desabilitado\n");exit(2);
}
$worker=(string)getenv('CASA_GITHUB_WORKER_NAME');
if (!ft_nome_valido($worker) || $worker==='servidor') {
    fwrite(STDERR,"CASA_GITHUB_WORKER_NAME invalido\n");exit(2);
}
$pdo=get_db_pdo();
try {
    $jobs=ft_reservar($pdo,$worker,['github'],['github'],1,300);
    if (!$jobs) { echo "Fila GitHub vazia\n";exit(0); }
    $job=$jobs[0];$jarvisId=(int)$job['id'];
    try {
        $payload=$job['payload'];
        if (!is_array($payload) || ($payload['modo']??'')!=='github' ||
            !is_numeric($payload['tarefa_id']??null) || (int)$payload['tarefa_id']<1 ||
            !is_numeric($payload['projeto_id']??null) || (int)$payload['projeto_id']<1)
            throw new RuntimeException('Payload de tarefa GitHub invalido');
        $q=$pdo->prepare("SELECT t.id FROM casa_projeto_tarefas t JOIN casa_projetos p ON p.id=t.projeto_id
            WHERE t.id=? AND t.projeto_id=? AND t.jarvis_tarefa_id=? AND p.jarvis_plano_id=? AND p.status='executando'");
        $q->execute([(int)$payload['tarefa_id'],(int)$payload['projeto_id'],$jarvisId,(int)$job['id_plano']]);
        if (!$q->fetchColumn()) throw new RuntimeException('Tarefa de projeto nao corresponde a reserva');
        $cmd=[PHP_BINARY,__DIR__.'/github_worker.php','--tarefa',(string)$payload['tarefa_id'],'--executar'];
        // Chamada direta com argumentos fixos, sem shell e sem aceitar comandos do payload.
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($proc)) throw new RuntimeException('Nao foi possivel iniciar worker');
        fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
        $exit=proc_close($proc);
        if ($exit!==0) throw new RuntimeException('Worker falhou: '.mb_substr(trim($err),0,1000));
        $result=json_decode(trim($out),true);
        if (!is_array($result) || ($result['status']??'')!=='branch_criada' ||
            (int)($result['tarefa_id']??0)!==(int)$payload['tarefa_id'])
            throw new RuntimeException('Resposta inesperada do worker');
        // Nao concluir: a tarefa ainda precisa de geracao, revisao e PR.
        ft_liberar($pdo,$jarvisId,$worker);
        echo json_encode(['jarvis_tarefa_id'=>$jarvisId,'resultado'=>$result],JSON_UNESCAPED_UNICODE)."\n";
    } catch (Throwable $e) {
        ft_falhar($pdo,$jarvisId,$worker,$e->getMessage(),true);
        throw $e;
    }
} catch (Throwable $e) {
    fwrite(STDERR,"Fila GitHub: ".$e->getMessage()."\n");exit(1);
}
