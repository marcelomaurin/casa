<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__.'/db.php');
verify_api_auth();

$pdo=get_db_pdo();

function tia_out($data,$code=200){
    http_response_code($code);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function tia_input(){
    $raw=file_get_contents('php://input');
    $j=json_decode($raw,true);
    return is_array($j)?$j:[];
}

function tia_dt($value,$fallback){
    $value=trim((string)$value);
    if($value==='')return $fallback;
    $ts=strtotime($value);
    return $ts===false?$fallback:date('Y-m-d H:i:s',$ts);
}

function tia_call_computer($prompt,$timeout=55){
    $token=get_system_api_token();
    $ch=curl_init('http://127.0.0.1/api/jarvis.php');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode([
            'comando'=>'/cloud '.$prompt,
            'skip_planner'=>true,
            'origem'=>'TELEMETRIA_IA'
        ],JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'X-API-Key: '.$token
        ],
        CURLOPT_CONNECTTIMEOUT=>3,
        CURLOPT_TIMEOUT=>$timeout
    ]);
    $raw=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);

    $j=$raw!==false?json_decode((string)$raw,true):null;
    if($raw!==false && $http>=200 && $http<300 && is_array($j)){
        return trim((string)($j['resposta']??''));
    }
    $msg=$err;
    if($msg===''){
        if(is_array($j) && !empty($j['mensagem']))$msg=(string)$j['mensagem'];
        else $msg='HTTP '.$http;
    }
    throw new RuntimeException($msg);
}

function tia_clean_sql($text){
    $text=trim((string)$text);
    $text=preg_replace('/^\x60\x60\x60(?:sql)?\s*/i','',$text);
    $text=preg_replace('/\s*\x60\x60\x60$/','',$text);
    $pos=stripos($text,'select ');
    if($pos!==false)$text=substr($text,$pos);
    return trim($text);
}

function tia_validate_sql($sql){
    if($sql==='')return 'SQL vazio.';
    if(strlen($sql)>8000)return 'SQL muito grande.';
    if(!preg_match('/^\s*SELECT\b/i',$sql))return 'Somente SELECT é permitido.';
    if(strpos($sql,';')!==false)return 'Ponto e vírgula e múltiplas instruções não são permitidos.';
    if(preg_match('/(--|#|\/\*|\*\/)/',$sql))return 'Comentários SQL não são permitidos.';

    $blocked='/\b(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|REPLACE|MERGE|CALL|EXEC|EXECUTE|SET|GRANT|REVOKE|LOCK|UNLOCK|LOAD|HANDLER|DO)\b/i';
    if(preg_match($blocked,$sql))return 'Operação de escrita ou administração bloqueada.';

    if(preg_match('/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK)\b/i',$sql)){
        return 'Função SQL não autorizada.';
    }

    if(preg_match('/\b(INFORMATION_SCHEMA|MYSQL|PERFORMANCE_SCHEMA|SYS)\b/i',$sql)){
        return 'Acesso a schema do sistema bloqueado.';
    }

    if(preg_match('/\b(WITH|UNION)\b/i',$sql)){
        return 'WITH e UNION não são permitidos.';
    }

    preg_match_all('/\b(?:FROM|JOIN)\s+`?([a-zA-Z0-9_]+)`?/i',$sql,$m);
    $tables=array_unique(array_map('strtolower',$m[1]??[]));

    if(count($tables)===0)return 'A consulta precisa ler telemetria_operacional.';
    foreach($tables as $table){
        if($table!=='telemetria_operacional'){
            return 'Somente a tabela telemetria_operacional pode ser consultada.';
        }
    }

    return null;
}

function tia_force_limit($sql){
    if(preg_match('/\bLIMIT\s+(\d+)\s*$/i',$sql,$m)){
        $n=max(1,min(200,(int)$m[1]));
        return preg_replace('/\bLIMIT\s+\d+\s*$/i','LIMIT '.$n,$sql);
    }
    return rtrim($sql).' LIMIT 200';
}

$in=tia_input();
$pergunta=trim((string)($in['pergunta']??''));
if($pergunta==='')tia_out(['status'=>'erro','mensagem'=>'Informe uma pergunta sobre a telemetria.'],400);

$inicio=tia_dt($in['inicio']??'',date('Y-m-d H:i:s',time()-86400));
$fim=tia_dt($in['fim']??'',date('Y-m-d H:i:s'));

$schemaPrompt =
"TABELA AUTORIZADA: telemetria_operacional\n".
"CAMPOS DISPONÍVEIS:\n".
"- id: identificador do evento\n".
"- data_hora: início do evento\n".
"- finalizado_em: término do processamento\n".
"- origem: origem lógica, por exemplo COMPUTER, GOOGLE_HOME_HARDWARE, SCHEDULER\n".
"- canal: web, api, hardware, google_home, scheduler\n".
"- ip_cliente: IP do cliente quando conhecido\n".
"- operacao: tipo da operação executada\n".
"- solicitacao: texto ou comando recebido\n".
"- resposta_ia: resposta produzida pela IA\n".
"- acao_executada: o que o sistema efetivamente fez\n".
"- status: estado do processamento, por exemplo RECEBIDO, PROCESSANDO, SUCESSO, ERRO\n".
"- modelo: modelo de IA utilizado\n".
"- duracao_ms: duração em milissegundos\n".
"- detalhes: JSON com metadados adicionais\n\n".
"REGRAS OBRIGATÓRIAS:\n".
"1. Gere UMA ÚNICA instrução SELECT MySQL/MariaDB.\n".
"2. Consulte SOMENTE telemetria_operacional.\n".
"3. Nunca use INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, TRUNCATE, REPLACE, CALL, SET, GRANT ou qualquer escrita.\n".
"4. Não use comentários SQL, ponto e vírgula, UNION, WITH, INFORMATION_SCHEMA, arquivos ou funções de espera.\n".
"5. Considere como período permitido data_hora entre '".$inicio."' e '".$fim."'.\n".
"6. Prefira COUNT, AVG, MIN, MAX e GROUP BY para perguntas analíticas.\n".
"7. Para detalhes, selecione apenas colunas necessárias e limite a no máximo 100 linhas.\n".
"8. Retorne SOMENTE o SQL, sem markdown e sem explicação.";

try{
    $sqlPrompt=
        "Você é o gerador SQL somente leitura da telemetria do sistema CASA/COMPUTER.\n\n".
        $schemaPrompt.
        "\n\nPERGUNTA DO USUÁRIO:\n".$pergunta;

    $generated=tia_call_computer($sqlPrompt,55);
    $sql=tia_clean_sql($generated);
    $validation=tia_validate_sql($sql);

    if($validation!==null){
        try{
            $st=$pdo->prepare(
                "INSERT INTO telemetria_operacional ".
                "(origem,canal,operacao,solicitacao,acao_executada,status,detalhes) ".
                "VALUES('TELEMETRIA_IA','web','SQL_BLOQUEADO',:q,:a,'BLOQUEADO',:d)"
            );
            $st->execute([
                ':q'=>$pergunta,
                ':a'=>$validation,
                ':d'=>json_encode(['sql'=>$sql],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
        }catch(Throwable $ignored){}
        tia_out(['status'=>'erro','mensagem'=>'Consulta bloqueada: '.$validation],400);
    }

    $sql=tia_force_limit($sql);

    try{$pdo->exec("SET SESSION TRANSACTION READ ONLY");}catch(Throwable $ignored){}
    $pdo->beginTransaction();

    $started=microtime(true);
    $stmt=$pdo->query($sql);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $elapsed=(int)round((microtime(true)-$started)*1000);

    $pdo->rollBack();
    try{$pdo->exec("SET SESSION TRANSACTION READ WRITE");}catch(Throwable $ignored){}

    $dataJson=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(strlen($dataJson)>60000)$dataJson=substr($dataJson,0,60000).'...';

    $answerPrompt=
        "Você é o analista da telemetria do sistema CASA/COMPUTER. ".
        "Responda em português, de forma objetiva e técnica. ".
        "Use SOMENTE os dados fornecidos. Não invente eventos, causas, horários, IPs ou ações. ".
        "Quando não houver dados suficientes, informe isso claramente. ".
        "Explique números e padrões de forma textual.\n\n".
        "PERGUNTA: ".$pergunta."\n".
        "PERÍODO: ".$inicio." até ".$fim."\n".
        "SQL EXECUTADO: ".$sql."\n".
        "DADOS RETORNADOS: ".$dataJson;

    $resposta=tia_call_computer($answerPrompt,55);

    try{
        $ip=$_SERVER['HTTP_CF_CONNECTING_IP']??($_SERVER['REMOTE_ADDR']??null);
        $st=$pdo->prepare(
            "INSERT INTO telemetria_operacional ".
            "(origem,canal,ip_cliente,operacao,solicitacao,resposta_ia,acao_executada,status,modelo,duracao_ms,detalhes) ".
            "VALUES('TELEMETRIA_IA','web',:ip,'ANALISE_SQL',:q,:r,'SELECT SOMENTE LEITURA','SUCESSO','COMPUTER',:ms,:d)"
        );
        $st->execute([
            ':ip'=>$ip,
            ':q'=>$pergunta,
            ':r'=>$resposta,
            ':ms'=>$elapsed,
            ':d'=>json_encode([
                'sql'=>$sql,
                'linhas'=>count($rows),
                'inicio'=>$inicio,
                'fim'=>$fim
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ]);
    }catch(Throwable $ignored){}

    tia_out([
        'status'=>'sucesso',
        'pergunta'=>$pergunta,
        'resposta'=>$resposta,
        'sql'=>$sql,
        'linhas'=>count($rows),
        'duracao_sql_ms'=>$elapsed,
        'inicio'=>$inicio,
        'fim'=>$fim
    ]);

}catch(Throwable $e){
    if($pdo->inTransaction()){
        try{$pdo->rollBack();}catch(Throwable $ignored){}
    }
    try{$pdo->exec("SET SESSION TRANSACTION READ WRITE");}catch(Throwable $ignored){}
    error_log('Telemetria IA: '.$e->getMessage());
    tia_out(['status'=>'erro','mensagem'=>'Falha na análise da telemetria.'],500);
}
