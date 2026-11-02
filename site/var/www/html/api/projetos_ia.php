<?php
// Gerador de rascunho de plano: nunca grava ou executa tarefas.
function casa_gerar_plano_ia(PDO $pdo, array $project): array {
    $q=$pdo->query("SELECT provedor,base_url,api_key,modelo,timeout_segundos FROM ia_modelos WHERE ativo=1 ORDER BY padrao DESC,prioridade ASC,id ASC LIMIT 1");
    $m=$q->fetch(PDO::FETCH_ASSOC);
    if (!$m) throw new RuntimeException('Configure um modelo ativo em IA Modelos.');
    $provider=strtolower((string)$m['provedor']);
    if (!in_array($provider,['ollama','openai','openai_compatible','custom','openrouter','deepseek','cerebras','runpod'],true))
        throw new RuntimeException('Provedor nao suportado neste gerador.');
    $base=rtrim((string)$m['base_url'],'/');
    $parts=parse_url($base);
    if (!$parts || !in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true) || empty($parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']))
        throw new RuntimeException('URL do modelo invalida.');
    // A URL vem somente da configuracao administrativa do CASA, nunca do formulario.
    $prompt="Crie um plano de desenvolvimento para revisao humana. Responda APENAS JSON valido, sem markdown. Formato: ".
        '{"agentes":[{"nome":"Desenvolvedor","especialidade":"PHP"}],"tarefas":[{"titulo":"Titulo","descricao":"Detalhes concretos","criterio_aceite":"Resultado verificavel","prioridade":3,"agente_indice":1,"depende_da_tarefa":null}]}'.
        ". Maximo 8 agentes, 20 tarefas. Prioridade inteira 1 a 5. Indices comecam em 1; dependencias apenas tarefas anteriores. ".
        "Nenhuma instrucao de executar comandos. Projeto: ".mb_substr((string)$project['nome'],0,180).
        ". Objetivo: ".mb_substr((string)$project['objetivo'],0,8000).
        ". Repositorio: ".mb_substr((string)($project['repositorio']??''),0,200);
    $ollama=$provider==='ollama';
    $url=$ollama?$base.'/api/generate':(preg_match('~/chat/completions$~',$base)?$base:$base.'/chat/completions');
    $payload=$ollama?['model'=>$m['modelo'],'prompt'=>$prompt,'stream'=>false,'options'=>['temperature'=>0.2,'num_predict'=>3500]]:
        ['model'=>$m['modelo'],'messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.2,'max_tokens'=>3500,'stream'=>false];
    $headers=['Content-Type: application/json'];
    if (!$ollama && trim((string)($m['api_key']??''))!=='') $headers[]='Authorization: Bearer '.$m['api_key'];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>min(90,max(10,(int)$m['timeout_segundos'])),
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if ($response===false || $status<200 || $status>=300 || strlen((string)$response)>300000)
        throw new RuntimeException('Modelo indisponivel ou resposta muito grande.');
    $data=json_decode((string)$response,true);
    $text=$ollama?($data['response']??null):($data['choices'][0]['message']['content']??null);
    if (!is_string($text)) throw new RuntimeException('Resposta do modelo invalida.');
    $text=trim($text);
    if (str_starts_with($text,'```')) $text=preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/i','',$text);
    $plan=json_decode(trim($text),true);
    if (!is_array($plan)||!isset($plan['agentes'],$plan['tarefas'])||!is_array($plan['agentes'])||!is_array($plan['tarefas'])||
        count($plan['agentes'])>8||count($plan['tarefas'])<1||count($plan['tarefas'])>20)
        throw new RuntimeException('IA nao retornou planejamento valido. Tente novamente.');
    return $plan;
}
