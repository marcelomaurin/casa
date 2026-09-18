<?php
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
verify_api_auth();

$pdo = get_db_pdo();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$acao = $_GET['acao'] ?? ($input['acao'] ?? 'listar');

function ia_json($data, $code=200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ia_valid_provider($v) {
    return in_array($v, ['openai_compatible','ollama','runpod'], true);
}
function ia_valid_hw($v) {
    return in_array($v, ['CPU','GPU_LOW','GPU_HIGH'], true);
}
function ia_valid_level($v) {
    return in_array($v, ['ESTUDANTE','ESTAGIARIO','PROFISSIONAL','PROFESSOR'], true);
}
function ia_openai_call($baseUrl,$apiKey,$model,$prompt,$timeout=45,$maxTokens=120,$temperature=0.2) {
    $baseUrl=rtrim(trim((string)$baseUrl),'/');
    if ($baseUrl==='' || trim((string)$model)==='') return ['ok'=>false,'http'=>0,'erro'=>'URL/modelo ausentes'];
    $url=preg_match('#/chat/completions$#i',$baseUrl) ? $baseUrl : $baseUrl.'/chat/completions';
    $headers=['Content-Type: application/json'];
    if (trim((string)$apiKey)!=='') $headers[]='Authorization: Bearer '.$apiKey;
    $payload=[
        'model'=>$model,
        'messages'=>[['role'=>'user','content'=>$prompt]],
        'stream'=>false,
        'temperature'=>(float)$temperature,
        'max_tokens'=>(int)$maxTokens
    ];
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>(int)$timeout
    ]);
    $res=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if ($res!==false && $http>=200 && $http<300) {
        $j=json_decode($res,true); $txt=$j['choices'][0]['message']['content'] ?? '';
        if (trim((string)$txt)!=='') return ['ok'=>true,'http'=>$http,'resposta'=>trim((string)$txt)];
        return ['ok'=>false,'http'=>$http,'erro'=>'Resposta sem choices[0].message.content'];
    }
    if ($res!==false) {
        $j=json_decode((string)$res,true);
        $detail=is_array($j)?($j['error']['message']??$j['message']??''):'';
    } else $detail='';
    return ['ok'=>false,'http'=>$http,'erro'=>$err ?: ($detail ?: 'HTTP '.$http)];
}
function ia_ollama_call($baseUrl,$model,$prompt,$timeout=30) {
    $url=rtrim(trim((string)$baseUrl),'/').'/api/generate';
    $payload=['model'=>$model,'prompt'=>$prompt,'stream'=>false,'options'=>['num_predict'=>80,'temperature'=>0.2]];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>(int)$timeout]);
    $res=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if ($res!==false && $http>=200 && $http<300) {
        $j=json_decode($res,true); $txt=$j['response']??'';
        if (trim((string)$txt)!=='') return ['ok'=>true,'http'=>$http,'resposta'=>trim((string)$txt)];
        return ['ok'=>false,'http'=>$http,'erro'=>'Resposta Ollama sem response'];
    }
    return ['ok'=>false,'http'=>$http,'erro'=>$err ?: 'HTTP '.$http];
}
function ia_test_row($row) {
    $prompt='Responda apenas: OK INTEGRACAO IA';
    if (($row['provedor']??'')==='ollama') return ia_ollama_call($row['base_url'],$row['modelo'],$prompt,(int)$row['timeout_segundos']);
    return ia_openai_call($row['base_url'],$row['api_key']??'',$row['modelo'],$prompt,(int)$row['timeout_segundos'],80,0.1);
}

if ($acao==='listar') {
    $rows=$pdo->query("SELECT id,nome,provedor,base_url,modelo,ativo,padrao,prioridade,classe_hardware,nivel_capacidade,timeout_segundos,max_tokens,temperatura,observacoes,ultima_tentativa,ultimo_sucesso,ultimo_erro,falhas_consecutivas,criado_em,atualizado_em,
        CASE WHEN api_key IS NULL OR api_key='' THEN 0 ELSE 1 END AS possui_api_key
        FROM ia_modelos ORDER BY padrao DESC, prioridade ASC, id ASC")->fetchAll();
    ia_json(['status'=>'sucesso','dados'=>$rows]);
}

if ($acao==='salvar') {
    $id=(int)($input['id']??0);
    $nome=trim((string)($input['nome']??''));
    $provedor=trim((string)($input['provedor']??'openai_compatible'));
    $base=trim((string)($input['base_url']??''));
    $modelo=trim((string)($input['modelo']??''));
    $hw=strtoupper(trim((string)($input['classe_hardware']??'CPU')));
    $nivel=strtoupper(trim((string)($input['nivel_capacidade']??'ESTUDANTE')));
    if ($nome===''||$base===''||$modelo==='') ia_json(['status'=>'erro','mensagem'=>'Nome, URL e modelo são obrigatórios'],400);
    if (!ia_valid_provider($provedor)||!ia_valid_hw($hw)||!ia_valid_level($nivel)) ia_json(['status'=>'erro','mensagem'=>'Classificação inválida'],400);
    $padrao=!empty($input['padrao'])?1:0;
    $ativo=array_key_exists('ativo',$input)?(!empty($input['ativo'])?1:0):1;
    $prioridade=max(1,(int)($input['prioridade']??100));
    $timeout=max(5,min(180,(int)($input['timeout_segundos']??45)));
    $maxTokens=max(32,min(32768,(int)($input['max_tokens']??600)));
    $temp=max(0,min(2,(float)($input['temperatura']??0.35)));
    $obs=trim((string)($input['observacoes']??''));
    $key=array_key_exists('api_key',$input)?trim((string)$input['api_key']):null;
    $pdo->beginTransaction();
    try {
        if ($padrao) $pdo->exec("UPDATE ia_modelos SET padrao=0");
        if ($id>0) {
            $sql="UPDATE ia_modelos SET nome=:nome,provedor=:provedor,base_url=:base,modelo=:modelo,ativo=:ativo,padrao=:padrao,prioridade=:prioridade,
                classe_hardware=:hw,nivel_capacidade=:nivel,timeout_segundos=:timeout,max_tokens=:max_tokens,temperatura=:temp,observacoes=:obs";
            $params=[':nome'=>$nome,':provedor'=>$provedor,':base'=>$base,':modelo'=>$modelo,':ativo'=>$ativo,':padrao'=>$padrao,':prioridade'=>$prioridade,':hw'=>$hw,':nivel'=>$nivel,':timeout'=>$timeout,':max_tokens'=>$maxTokens,':temp'=>$temp,':obs'=>$obs,':id'=>$id];
            if ($key!==null && $key!=='') { $sql.=",api_key=:api_key"; $params[':api_key']=$key; }
            $sql.=" WHERE id=:id";
            $pdo->prepare($sql)->execute($params);
        } else {
            $stmt=$pdo->prepare("INSERT INTO ia_modelos(nome,provedor,base_url,api_key,modelo,ativo,padrao,prioridade,classe_hardware,nivel_capacidade,timeout_segundos,max_tokens,temperatura,observacoes)
                VALUES(:nome,:provedor,:base,:api_key,:modelo,:ativo,:padrao,:prioridade,:hw,:nivel,:timeout,:max_tokens,:temp,:obs)");
            $stmt->execute([':nome'=>$nome,':provedor'=>$provedor,':base'=>$base,':api_key'=>$key??'',':modelo'=>$modelo,':ativo'=>$ativo,':padrao'=>$padrao,':prioridade'=>$prioridade,':hw'=>$hw,':nivel'=>$nivel,':timeout'=>$timeout,':max_tokens'=>$maxTokens,':temp'=>$temp,':obs'=>$obs]);
            $id=(int)$pdo->lastInsertId();
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM ia_modelos WHERE padrao=1 AND ativo=1")->fetchColumn()===0) {
            $pdo->prepare("UPDATE ia_modelos SET padrao=1 WHERE id=:id")->execute([':id'=>$id]);
        }
        $pdo->commit();
    } catch(Throwable $e) {
        $pdo->rollBack(); throw $e;
    }
    ia_json(['status'=>'sucesso','id'=>$id]);
}

if ($acao==='padrao') {
    $id=(int)($input['id']??0);
    if ($id<=0) ia_json(['status'=>'erro','mensagem'=>'ID inválido'],400);
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE ia_modelos SET padrao=0");
        $st=$pdo->prepare("UPDATE ia_modelos SET padrao=1,ativo=1 WHERE id=:id"); $st->execute([':id'=>$id]);
        if ($st->rowCount()===0) throw new RuntimeException('Modelo não encontrado');
        $pdo->commit();
    } catch(Throwable $e) { $pdo->rollBack(); ia_json(['status'=>'erro','mensagem'=>$e->getMessage()],404); }
    ia_json(['status'=>'sucesso']);
}

if ($acao==='excluir') {
    $id=(int)($input['id']??0);
    $st=$pdo->prepare("SELECT padrao FROM ia_modelos WHERE id=:id"); $st->execute([':id'=>$id]); $row=$st->fetch();
    if (!$row) ia_json(['status'=>'erro','mensagem'=>'Modelo não encontrado'],404);
    if (!empty($row['padrao'])) ia_json(['status'=>'erro','mensagem'=>'Defina outro modelo default antes de excluir este'],409);
    $pdo->prepare("DELETE FROM ia_modelos WHERE id=:id")->execute([':id'=>$id]);
    ia_json(['status'=>'sucesso']);
}

if ($acao==='testar') {
    $id=(int)($input['id']??0);
    $st=$pdo->prepare("SELECT * FROM ia_modelos WHERE id=:id"); $st->execute([':id'=>$id]); $row=$st->fetch();
    if (!$row) ia_json(['status'=>'erro','mensagem'=>'Modelo não encontrado'],404);
    $r=ia_test_row($row);
    $pdo->prepare("UPDATE ia_modelos SET ultima_tentativa=NOW(),ultimo_sucesso=IF(:ok=1,NOW(),ultimo_sucesso),ultimo_erro=:erro,falhas_consecutivas=IF(:ok=1,0,falhas_consecutivas+1) WHERE id=:id")
        ->execute([':ok'=>$r['ok']?1:0,':erro'=>$r['ok']?null:($r['erro']??'Falha'),':id'=>$id]);
    ia_json(['status'=>$r['ok']?'sucesso':'erro','teste'=>$r],$r['ok']?200:502);
}

ia_json(['status'=>'erro','mensagem'=>'Ação inválida'],404);
?>