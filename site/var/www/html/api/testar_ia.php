<?php
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/db.php');
verify_api_auth();

$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in)) $in=$_POST;

function out($data,$code=200){
    http_response_code($code);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function curl_json($url,$payload,$headers,$timeout=45){
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>$timeout
    ]);
    $res=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);
    $json=$res!==false?json_decode((string)$res,true):null;
    return [$http,$err,$res,$json];
}

$mode=strtolower(trim((string)($in['mode']??'local')));
$prompt='Responda somente: OK CASA';

if($mode==='local'){
    $base=rtrim(trim((string)($in['local_url']??'')),'/');
    $model=trim((string)($in['local_model']??''));
    if($base===''||$model==='') out(['status'=>'erro','mensagem'=>'Servidor local e modelo são obrigatórios.'],400);
    [$http,$err,$raw,$j]=curl_json($base.'/api/generate',[
        'model'=>$model,'prompt'=>$prompt,'stream'=>false,'options'=>['num_predict'=>16,'temperature'=>0]
    ],['Content-Type: application/json'],30);
    if($http>=200&&$http<300&&!empty($j['response'])){
        out(['status'=>'sucesso','provedor'=>'LOCAL','http'=>$http,'resposta'=>trim((string)$j['response'])]);
    }
    out(['status'=>'erro','mensagem'=>$err?:($j['error']??('HTTP '.$http)),'http'=>$http],502);
}

$provider=strtolower(trim((string)($in['provider']??'')));
$model=trim((string)($in['model']??''));
$key=trim((string)($in['api_key']??''));
$base=rtrim(trim((string)($in['base_url']??'')),'/');
if($provider===''||$model==='') out(['status'=>'erro','mensagem'=>'Provedor e modelo são obrigatórios.'],400);

if($provider==='runpod'){
    $endpoint=trim((string)($in['runpod_endpoint_id']??''));
    $protocol=strtolower(trim((string)($in['runpod_protocol']??'openai')));
    if($endpoint===''||$key==='') out(['status'=>'erro','mensagem'=>'RunPod exige Endpoint ID e API Key.'],400);

    if($protocol==='native'){
        $url='https://api.runpod.ai/v2/'.$endpoint.'/runsync';
        [$http,$err,$raw,$j]=curl_json($url,['input'=>[
            'model'=>$model,
            'messages'=>[['role'=>'user','content'=>$prompt]],
            'max_tokens'=>16,'temperature'=>0
        ]],['Content-Type: application/json','Authorization: Bearer '.$key],45);
        if($http>=200&&$http<300){
            $o=$j['output']??null;
            $txt=is_string($o)?$o:($o['choices'][0]['message']['content']??$o['text']??$o['response']??$o['output']??'');
            if($txt!=='') out(['status'=>'sucesso','provedor'=>'RUNPOD NATIVO','http'=>$http,'resposta'=>is_string($txt)?trim($txt):'OK']);
        }
        out(['status'=>'erro','mensagem'=>$err?:($j['error']??$j['message']??('HTTP '.$http)),'http'=>$http],502);
    } else {
        $base='https://api.runpod.ai/v2/'.$endpoint.'/openai/v1';
    }
}

if($provider==='gemini'){
    if($key==='') out(['status'=>'erro','mensagem'=>'API Key do Google Gemini obrigatória.'],400);
    if($base==='') $base='https://generativelanguage.googleapis.com/v1beta';
    $url=$base.'/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($key);
    [$http,$err,$raw,$j]=curl_json($url,['contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],'generationConfig'=>['maxOutputTokens'=>16,'temperature'=>0]],['Content-Type: application/json'],45);
    $txt=$j['candidates'][0]['content']['parts'][0]['text']??'';
    if($http>=200&&$http<300&&$txt!=='') out(['status'=>'sucesso','provedor'=>'GOOGLE GEMINI','http'=>$http,'resposta'=>trim($txt)]);
    out(['status'=>'erro','mensagem'=>$err?:($j['error']['message']??('HTTP '.$http)),'http'=>$http],502);
}

if($provider==='anthropic'){
    if($key==='') out(['status'=>'erro','mensagem'=>'API Key da Anthropic obrigatória.'],400);
    if($base==='') $base='https://api.anthropic.com/v1';
    [$http,$err,$raw,$j]=curl_json($base.'/messages',[
        'model'=>$model,'max_tokens'=>16,'temperature'=>0,
        'messages'=>[['role'=>'user','content'=>$prompt]]
    ],['Content-Type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'],45);
    $txt=$j['content'][0]['text']??'';
    if($http>=200&&$http<300&&$txt!=='') out(['status'=>'sucesso','provedor'=>'ANTHROPIC','http'=>$http,'resposta'=>trim($txt)]);
    out(['status'=>'erro','mensagem'=>$err?:($j['error']['message']??('HTTP '.$http)),'http'=>$http],502);
}

$defaults=[
    'openai'=>'https://api.openai.com/v1',
    'openrouter'=>'https://openrouter.ai/api/v1',
    'cerebras'=>'https://api.cerebras.ai/v1',
    'deepseek'=>'https://api.deepseek.com/v1'
];
if($provider!=='custom'&&isset($defaults[$provider])&&$base==='') $base=$defaults[$provider];
if($base==='') out(['status'=>'erro','mensagem'=>'URL do provedor não informada.'],400);

$url=preg_match('#/chat/completions$#i',$base)?$base:$base.'/chat/completions';
$headers=['Content-Type: application/json'];
if($key!=='') $headers[]='Authorization: Bearer '.$key;
[$http,$err,$raw,$j]=curl_json($url,[
    'model'=>$model,
    'messages'=>[['role'=>'user','content'=>$prompt]],
    'max_tokens'=>16,'temperature'=>0,'stream'=>false
],$headers,45);
$txt=$j['choices'][0]['message']['content']??'';
if($http>=200&&$http<300&&$txt!==''){
    out(['status'=>'sucesso','provedor'=>strtoupper($provider),'http'=>$http,'resposta'=>trim($txt)]);
}
out(['status'=>'erro','mensagem'=>$err?:($j['error']['message']??$j['message']??('HTTP '.$http)),'http'=>$http],502);
