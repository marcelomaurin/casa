<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once(__DIR__.'/db.php');
require_once(__DIR__.'/task_engine.php');
verify_api_auth();
date_default_timezone_set('America/Sao_Paulo');

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

function tia_is_daily_summary($question){
    $q=mb_strtolower(trim((string)$question),'UTF-8');
    if($q==='')return false;
    if(preg_match('/\b(ontem|amanh[aã]|semana|m[eê]s|ano|entre|desde|at[eé]|per[ií]odo|dia \d{1,2}|\d{1,2}[\/\-]\d{1,2})\b/u',$q)) return false;
    return preg_match('/\b(resumo|relat[oó]rio|o que ocorreu|o que aconteceu|aconteceu|ocorreu|movimenta[cç][aã]o|atividade do dia|resumo do dia)\b/u',$q)===1;
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
$existingTaskContext=te_context_from_input($in['task_context'] ?? null);
$taskContext=te_begin($pdo,$pergunta,'TELEMETRIA_IA','telemetria',$existingTaskContext);
if($pergunta==='')tia_out(['status'=>'erro','mensagem'=>'Informe uma pergunta sobre a telemetria.'],400);

$modoResumoHoje=tia_is_daily_summary($pergunta);
if($modoResumoHoje){
    $inicio=date('Y-m-d 00:00:00');
    $fim=date('Y-m-d H:i:s');
}else{
    $inicio=tia_dt($in['inicio']??'',date('Y-m-d H:i:s',time()-86400));
    $fim=tia_dt($in['fim']??'',date('Y-m-d H:i:s'));
}

$taskIntent=te_add_subtask($pdo,$taskContext,'Interpretar pergunta e período','telemetria',[
    'pergunta'=>$pergunta,'inicio'=>$inicio,'fim'=>$fim,'resumo_hoje'=>$modoResumoHoje
],null,'EXECUTANDO');
te_complete($pdo,$taskIntent,['inicio'=>$inicio,'fim'=>$fim,'resumo_hoje'=>$modoResumoHoje]);

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
    $taskSql=te_add_subtask($pdo,$taskContext,'Gerar e validar consulta somente leitura','telemetria_sql',[
        'pergunta'=>$pergunta,'inicio'=>$inicio,'fim'=>$fim
    ],$taskIntent,'EXECUTANDO');
    if($modoResumoHoje){
        $sql="SELECT data_hora,origem,canal,ip_cliente,operacao,solicitacao,resposta_ia,acao_executada,status,modelo,duracao_ms ".
             "FROM telemetria_operacional ".
             "WHERE data_hora BETWEEN '".addslashes($inicio)."' AND '".addslashes($fim)."' ".
             "ORDER BY data_hora ASC LIMIT 200";
        $validation=tia_validate_sql($sql);
    }else{
        $sqlPrompt=
            "Você é o gerador SQL somente leitura da telemetria do sistema CASA/COMPUTER.\n\n".
            $schemaPrompt.
            "\n\nPERGUNTA DO USUÁRIO:\n".$pergunta;

        $generated=tia_call_computer($sqlPrompt,55);
        $sql=tia_clean_sql($generated);
        $validation=tia_validate_sql($sql);
    }

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
    te_complete($pdo,$taskSql,['sql'=>$sql]);

    $taskQuery=te_add_subtask($pdo,$taskContext,'Executar consulta de dados','telemetria_sql',[
        'sql'=>$sql
    ],$taskSql,'EXECUTANDO');

    try{$pdo->exec("SET SESSION TRANSACTION READ ONLY");}catch(Throwable $ignored){}
    $pdo->beginTransaction();

    $started=microtime(true);
    $stmt=$pdo->query($sql);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $elapsed=(int)round((microtime(true)-$started)*1000);

    $pdo->rollBack();
    try{$pdo->exec("SET SESSION TRANSACTION READ WRITE");}catch(Throwable $ignored){}
    te_complete($pdo,$taskQuery,['linhas'=>count($rows),'duracao_ms'=>$elapsed]);

    $dataJson=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(strlen($dataJson)>60000)$dataJson=substr($dataJson,0,60000).'...';

    if($modoResumoHoje){
        $answerPrompt=
            "Você é o analista operacional da telemetria do sistema CASA/COMPUTER. ".
            "Gere um RELATÓRIO DO DIA em português, claro e objetivo, usando SOMENTE os dados fornecidos. ".
            "O relatório deve sintetizar o que ocorreu hoje desde 00:00 até agora. ".
            "Inclua, quando existirem dados: volume e tipos de operações, principais solicitações, ações realmente executadas, ".
            "falhas/erros e respectivos horários, origens/canais, IPs externos relevantes, respostas da IA que mereçam destaque, ".
            "operações mais lentas, padrões recorrentes e qualquer anomalia observável. ".
            "Não invente causas nem eventos. Diferencie fato observado de interpretação. ".
            "Se não houver eventos suficientes, diga explicitamente. ".
            "Não mostre SQL ao usuário na resposta final.\n\n".
            "PERÍODO DO RELATÓRIO: ".$inicio." até ".$fim."\n".
            "DADOS DA TELEMETRIA: ".$dataJson;
    }else{
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
    }

    $taskAnalysis=te_add_subtask($pdo,$taskContext,'Analisar dados e produzir resposta','ia',[
        'linhas'=>count($rows),'pergunta'=>$pergunta
    ],$taskQuery,'EXECUTANDO');
    $resposta=tia_call_computer($answerPrompt . "\n\nCONTEXTO_TAREFA: " . te_json(te_public_context($taskContext)),55);
    te_complete($pdo,$taskAnalysis,['resposta'=>$resposta]);
    te_finish($pdo,$taskContext,$resposta,['sql'=>$sql,'linhas'=>count($rows)]);

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
        'fim'=>$fim,
        'modo_relatorio_hoje'=>$modoResumoHoje,
        'id_plano'=>$taskContext['id_plano'],
        'id_tarefa_raiz'=>$taskContext['id_tarefa_raiz'],
        'tarefas_execucao'=>te_list_tasks($pdo,$taskContext)
    ]);

}catch(Throwable $e){
    if($pdo->inTransaction()){
        try{$pdo->rollBack();}catch(Throwable $ignored){}
    }
    try{$pdo->exec("SET SESSION TRANSACTION READ WRITE");}catch(Throwable $ignored){}
    error_log('Telemetria IA: '.$e->getMessage());
    tia_out(['status'=>'erro','mensagem'=>'Falha na análise da telemetria.'],500);
}
