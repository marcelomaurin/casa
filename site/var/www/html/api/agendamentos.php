<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__.'/db.php');
verify_api_auth();

$pdo=get_db_pdo();
$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in)) $in=[];
$acao=strtolower(trim((string)($in['acao']??($_GET['acao']??'listar'))));

function ag_out($data,$code=200){http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function ag_cron_valid($expr){
    $p=preg_split('/\s+/',trim((string)$expr));
    if(count($p)!==5)return false;
    foreach($p as $x) if(!preg_match('/^(\*|\*\/\d+|\d+|\d+-\d+|\d+(,\d+)+)$/',$x)) return false;
    return true;
}
function ag_cron_legacy($expr){
    $p=preg_split('/\s+/',trim($expr));
    $horario='';$dias='*';
    if(count($p)===5){
        [$m,$h,$dom,$mes,$dow]=$p;
        if(ctype_digit($m)&&ctype_digit($h)) $horario=sprintf('%02d:%02d',(int)$h,(int)$m);
        elseif(preg_match('/^\*\/\d+$/',$m)&&$h==='*') $horario=$m;
        $dias=$dow;
    }
    return [$horario,$dias];
}
function ag_post_json($url,$payload,$timeout=45){
    $ch=curl_init($url);
    $headers=['Content-Type: application/json'];
    if(function_exists('get_system_api_token')){$t=get_system_api_token();if($t)$headers[]='X-API-Key: '.$t;}
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$timeout]);
    $res=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($res!==false&&$http>=200&&$http<300){$j=json_decode($res,true);return ['ok'=>true,'dados'=>is_array($j)?$j:[]];}
    return ['ok'=>false,'erro'=>$err?:('HTTP '.$http)];
}
function ag_execute(PDO $pdo,array $t){
    $tipo=$t['tipo_acao'];$payload=(string)$t['payload'];$target=(string)($t['target_node']??'local');
    if($tipo==='comando_jarvis'){
        $r=ag_post_json('http://127.0.0.1/api/jarvis.php',['comando'=>$payload,'skip_planner'=>true,'origem'=>'SCHEDULER_MANUAL'],45);
    }elseif($tipo==='aviso_fala'){
        $r=ag_post_json('http://127.0.0.1:8097/falar',['texto'=>$payload,'speaker'=>'padrao','reproduzir'=>true],20);
    }elseif($tipo==='dispositivo_devpar'){
        parse_str($payload,$p);$id=(int)($p['iddevice']??0);$par=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($p['devparname']??'dev1'));$val=(string)($p['valor']??'1');
        if($id<=0)return ['ok'=>false,'erro'=>'ID do equipamento inválido'];
        $st=$pdo->prepare("UPDATE devpar SET devvalue=:v,atualizado_em=CURRENT_TIMESTAMP WHERE iddevice=:id AND devparname=:p");
        $st->execute([':v'=>$val,':id'=>$id,':p'=>$par]);
        $r=['ok'=>true,'dados'=>['equipamento'=>$id,'parametro'=>$par,'valor'=>$val]];
    }else return ['ok'=>false,'erro'=>'Tipo de ação não suportado'];
    if($r['ok']){
        $pdo->prepare("UPDATE tarefas_agendadas SET ultima_execucao=NOW() WHERE id=:id")->execute([':id'=>$t['id']]);
        try{$pdo->prepare("INSERT INTO comandos_log(comando,origem,resultado) VALUES(:c,'SCHEDULER_MANUAL','Executado')")->execute([':c'=>'Agendamento: '.$t['titulo']]);}catch(Throwable $e){}
    }
    return $r;
}

try{
    if($acao==='listar'){
        $rows=$pdo->query("SELECT * FROM tarefas_agendadas ORDER BY ativo DESC,id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        ag_out(['status'=>'sucesso','dados'=>$rows,'total'=>count($rows)]);
    }
    if($acao==='criar'){
        $titulo=trim((string)($in['titulo']??''));$cron=trim((string)($in['cron_expr']??''));$executor=strtolower(trim((string)($in['executor']??'ia')));
        if($titulo==='')ag_out(['status'=>'erro','mensagem'=>'Título obrigatório.'],400);
        if(!ag_cron_valid($cron))ag_out(['status'=>'erro','mensagem'=>'Expressão cron inválida. Use 5 campos.'],400);
        if(!in_array($executor,['ia','fala','equipamento'],true))ag_out(['status'=>'erro','mensagem'=>'Executor inválido.'],400);
        [$horario,$dias]=ag_cron_legacy($cron);
        $payload=trim((string)($in['payload']??''));$target=trim((string)($in['target_node']??'local'))?:'local';
        if($executor==='equipamento'){
            $id=(int)($in['device_id']??0);if($id<=0)ag_out(['status'=>'erro','mensagem'=>'ID do equipamento obrigatório.'],400);
            $payload=http_build_query(['iddevice'=>$id,'devparname'=>trim((string)($in['devparname']??'dev1'))?:'dev1','valor'=>(string)($in['valor']??'1')]);
            $target='equipamento:'.$id;$tipo='dispositivo_devpar';
        }elseif($executor==='fala'){$tipo='aviso_fala';if($payload==='')ag_out(['status'=>'erro','mensagem'=>'Mensagem de fala obrigatória.'],400);}
        else{$tipo='comando_jarvis';if($payload==='')ag_out(['status'=>'erro','mensagem'=>'Comando para a IA obrigatório.'],400);}
        $st=$pdo->prepare("INSERT INTO tarefas_agendadas(titulo,descricao,horario,dias_semana,cron_expr,executor_tipo,tipo_acao,payload,target_node,ativo,modo_agendamento) VALUES(:t,:d,:h,:ds,:c,:e,:a,:p,:n,1,'RECORRENTE')");
        $st->execute([':t'=>$titulo,':d'=>trim((string)($in['descricao']??'')),':h'=>$horario,':ds'=>$dias,':c'=>$cron,':e'=>$executor,':a'=>$tipo,':p'=>$payload,':n'=>$target]);
        ag_out(['status'=>'sucesso','mensagem'=>'Agendamento criado.','id'=>$pdo->lastInsertId()]);
    }
    $id=(int)($in['id']??($_GET['id']??0));
    if($id<=0)ag_out(['status'=>'erro','mensagem'=>'ID inválido.'],400);
    if($acao==='toggle'){
        $pdo->prepare("UPDATE tarefas_agendadas SET ativo=:a WHERE id=:id")->execute([':a'=>!empty($in['ativo'])?1:0,':id'=>$id]);
        ag_out(['status'=>'sucesso']);
    }
    if($acao==='excluir'){
        $pdo->prepare("DELETE FROM tarefas_agendadas WHERE id=:id")->execute([':id'=>$id]);ag_out(['status'=>'sucesso']);
    }
    if($acao==='executar'){
        $st=$pdo->prepare("SELECT * FROM tarefas_agendadas WHERE id=:id");$st->execute([':id'=>$id]);$t=$st->fetch(PDO::FETCH_ASSOC);
        if(!$t)ag_out(['status'=>'erro','mensagem'=>'Agendamento não encontrado.'],404);
        $r=ag_execute($pdo,$t);
        if(!$r['ok'])ag_out(['status'=>'erro','mensagem'=>$r['erro']??'Falha ao executar.'],502);
        ag_out(['status'=>'sucesso','mensagem'=>'Agendamento executado.','resultado'=>$r['dados']??null]);
    }
    ag_out(['status'=>'erro','mensagem'=>'Ação desconhecida.'],400);
}catch(Throwable $e){
    error_log('Agendamentos: '.$e->getMessage());
    ag_out(['status'=>'erro','mensagem'=>'Falha ao processar agendamento.'],500);
}
