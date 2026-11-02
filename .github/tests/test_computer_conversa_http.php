<?php
// Banco real + HTTP real + provedor de IA controlado, sem rede externa ou hardware.
foreach (['HOST'=>'127.0.0.1','PORT'=>'3306','NAME'=>'casa_test','USER'=>'root','PASS'=>'root'] as $k=>$v) putenv('JARVIS_DB_'.$k.'='.$v);
putenv('JARVIS_AUTO_INIT_DB=1');
require __DIR__.'/../../site/var/www/html/api/db.php';
require __DIR__.'/../../site/var/www/html/api/task_engine.php';
$token='conversation-ci-'.bin2hex(random_bytes(16));putenv('JARVIS_SYSTEM_API_TOKEN='.$token);
$pdo=get_db_pdo();$checks=0;
function chat_ok(bool $v,string $m):void {global $checks;if(!$v)throw new RuntimeException($m);$checks++;}
function chat_counts(PDO $pdo):array {return [(int)$pdo->query('SELECT COUNT(*) FROM jarvis_planos')->fetchColumn(),(int)$pdo->query('SELECT COUNT(*) FROM jarvis_tarefas')->fetchColumn()];}
function chat_port():string {$s=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($s,false);fclose($s);return $address;}
function chat_server(string $address,string $root,?string $router,string $log){
    $argv=[PHP_BINARY,'-S',$address,'-t',$root];if($router)$argv[]=$router;
    $p=proc_open($argv,[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,null,getenv());
    if(!is_resource($p))throw new RuntimeException('Servidor de teste indisponivel');fclose($pipes[0]);
    for($i=0;$i<40;$i++){$s=@stream_socket_client('tcp://'.$address,$err,$msg,.1);if($s){fclose($s);return $p;}usleep(100000);}
    proc_terminate($p);proc_close($p);throw new RuntimeException('Servidor nao iniciou');
}
function chat_post(string $url,string $token,string $text):array {
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Key: '.$token],
        CURLOPT_POSTFIELDS=>json_encode(['comando'=>$text,'origem'=>'DAEMON_TAREFAS','reproduzir_casa'=>false])]);
    $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $data=is_string($raw)?json_decode($raw,true):null;
    if($code!==200 || !is_array($data))throw new RuntimeException('Resposta HTTP invalida: '.$code.' '.substr((string)$raw,0,500));
    return $data;
}
$temp=sys_get_temp_dir().'/casa-conversation-'.bin2hex(random_bytes(6));mkdir($temp,0700);
$modelAddress=chat_port();$appAddress=chat_port();$model=null;$server=null;$saved=[];
$config=['ia_provider'=>'remote','ia_remote_provider'=>'custom','ia_remote_base_url'=>'http://'.$modelAddress,
    'ia_remote_model'=>'conversation-fixture','ia_remote_api_key'=>'','ia_routing_mode'=>'cloud_only','jarvis_contexto_casa'=>'sempre'];
$noise=te_begin($pdo,'computador Bom dia - conversa antiga de teste','CI','computer');
$noiseChild=te_add_subtask($pdo,$noise,'Interpretar saudacao antiga','computer',null,null,'EXECUTANDO');
try {
    file_put_contents($temp.'/reply.txt','Sobre Frontier, qual aspecto você quer saber?');
    file_put_contents($temp.'/router.php',<<<'PHP'
<?php
$body=json_decode(file_get_contents('php://input'),true);
file_put_contents(__DIR__.'/request.json',json_encode($body));
header('Content-Type: application/json');
echo json_encode(['choices'=>[['message'=>['content'=>file_get_contents(__DIR__.'/reply.txt')]]]]);
PHP);
    $model=chat_server($modelAddress,$temp,$temp.'/router.php',$temp.'/model.log');
    foreach($config as $key=>$value){
        $s=$pdo->prepare('SELECT valor FROM configuracoes_sistema WHERE chave=:k');$s->execute([':k'=>$key]);$saved[$key]=$s->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO configuracoes_sistema(chave,valor,descricao) VALUES(:k,:v,'CI conversa') ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([':k'=>$key,':v'=>$value]);
    }
    $server=chat_server($appAddress,realpath(__DIR__.'/../../site/var/www/html'),null,$temp.'/app.log');
    $url='http://'.$appAddress.'/api/jarvis.php';
    foreach(['computador Bom dia','computador Frontier','Qual é a capital da França?','Amanhã vai chover?',
        'Como criar uma tarefa para amanhã?','Explique OAuth; depois explique TLS'] as $text){
        $before=chat_counts($pdo);$r=chat_post($url,$token,$text);
        chat_ok(chat_counts($pdo)===$before,'Pergunta criou registros: '.$text);
        chat_ok(($r['tipo_solicitacao']??'')==='conversa' && empty($r['tarefas_execucao']) && !isset($r['id_plano'],$r['task_context']),'Resposta de conversa virou plano: '.$text);
        if($text==='computador Bom dia')chat_ok($r['resposta']==='Bom dia! Como posso ajudar?','Saudacao incorreta');
        else {
            $request=json_decode(file_get_contents($temp.'/request.json'),true);$messages=$request['messages'];
            chat_ok(end($messages)['content']===$text,'Modelo nao recebeu a pergunta original');
            chat_ok(!str_contains($messages[0]['content'],'Plano #'.$noise['id_plano']),'Contexto operacional desviou a conversa');
        }
    }
    // Uma pergunta sobre a casa pode usar sensores, sem ser uma tarefa nem receber a fila.
    $before=chat_counts($pdo);$r=chat_post($url,$token,'Como estão os sensores da casa?');
    chat_ok(chat_counts($pdo)===$before,'Consulta de sensores criou plano');
    chat_ok(!in_array('tarefas',$r['contexto_casa']['secoes']??[],true),'Consulta de sensores recebeu tarefas irrelevantes');
    file_put_contents($temp.'/reply.txt','[[CMD:DESLIGAR_LUZ_SALA]]');
    $before=chat_counts($pdo);$r=chat_post($url,$token,'Explique como desligar a luz da sala');
    chat_ok(chat_counts($pdo)===$before && $r['acao']===null,'Marca da IA executou uma pergunta');
    chat_ok(!str_contains($r['resposta'],'Comando executado') && !str_contains($r['resposta'],'[[CMD:'),'Marca recusada virou confirmacao falsa');
    $before=chat_counts($pdo);$r=chat_post($url,$token,'Ligue a luz da sala');
    chat_ok(($r['tipo_solicitacao']??'')==='tarefa' && ($r['id_plano']??0)>0 && chat_counts($pdo)[0]>$before[0],'Ordem explicita perdeu rastreamento');
    echo "OK: $checks verificacoes HTTP de conversa sem tarefas\n";
} finally {
    foreach([$server,$model] as $p)if(is_resource($p)){proc_terminate($p);proc_close($p);}
    foreach($saved as $key=>$row){
        if($row)$pdo->prepare('UPDATE configuracoes_sistema SET valor=:v WHERE chave=:k')->execute([':k'=>$key,':v'=>$row['valor']]);
        else $pdo->prepare('DELETE FROM configuracoes_sistema WHERE chave=:k')->execute([':k'=>$key]);
    }
    te_complete($pdo,$noiseChild);te_finish($pdo,$noise,'Fixture de conversa antiga encerrada');
    foreach(glob($temp.'/*')?:[] as $path)unlink($path);rmdir($temp);
}
