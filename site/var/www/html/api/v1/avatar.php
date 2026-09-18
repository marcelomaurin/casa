<?php
header('Content-Type: application/json; charset=utf-8');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/security_v1.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
$client = api_v1_auth_client_any($pdo, ['jarvis.command','mobile.write','watch.write','avatar.use']);
$acao = $_GET['acao'] ?? '';

function avatar_cfg(PDO $pdo, string $key, string $default=''): string {
    try {
        $st=$pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave=:k LIMIT 1");
        $st->execute([':k'=>$key]);
        $v=$st->fetchColumn();
        return $v===false ? $default : (string)$v;
    } catch(Throwable $e) {
        return $default;
    }
}

function avatar_openai_url(string $base, string $suffix): string {
    $base=rtrim(trim($base),'/');
    if ($base==='') return '';
    if (preg_match('#/'.preg_quote(trim($suffix,'/'),'#').'$#i',$base)) return $base;
    return $base . '/' . trim($suffix,'/');
}

function avatar_model_health(PDO $pdo, int $id, bool $ok, ?string $error): void {
    if ($id<=0) return;
    try {
        $st=$pdo->prepare("UPDATE ia_modelos
            SET ultima_tentativa=NOW(),
                ultimo_sucesso=IF(:ok1=1,NOW(),ultimo_sucesso),
                ultimo_erro=:erro,
                falhas_consecutivas=IF(:ok2=1,0,falhas_consecutivas+1)
            WHERE id=:id");
        $st->execute([
            ':ok1'=>$ok?1:0, ':ok2'=>$ok?1:0,
            ':erro'=>$ok?null:$error, ':id'=>$id
        ]);
    } catch(Throwable $e) {}
}

if ($acao === 'stt') {
    if (empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
        api_v1_json_response(400,['status'=>'erro','mensagem'=>'Arquivo de áudio obrigatório']);
    }

    $base=avatar_cfg($pdo,'stt_base_url','https://api.openai.com/v1');
    $key=avatar_cfg($pdo,'stt_api_key','');
    $model=avatar_cfg($pdo,'stt_model','whisper-1');
    $url=avatar_openai_url($base,'audio/transcriptions');

    if ($url==='' || $model==='') {
        api_v1_json_response(503,['status'=>'erro','mensagem'=>'STT não configurado']);
    }

    $headers=[];
    if ($key!=='') $headers[]='Authorization: Bearer '.$key;

    $tmp=$_FILES['audio']['tmp_name'];
    $mime=$_FILES['audio']['type'] ?: 'audio/wav';
    $name=basename($_FILES['audio']['name'] ?: 'speech.wav');

    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>[
            'model'=>$model,
            'file'=>new CURLFile($tmp,$mime,$name),
            'language'=>'pt'
        ],
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>90
    ]);
    $res=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);

    if ($res===false || $http<200 || $http>=300) {
        $detail='';
        if (is_string($res) && $res!=='') {
            $j=json_decode($res,true);
            if (is_array($j)) $detail=$j['error']['message']??$j['message']??'';
        }
        api_v1_log($pdo,'AVATAR_STT_ERROR','WARN',$client['nome']??null,['http'=>$http,'erro'=>$err?:$detail]);
        api_v1_json_response(502,['status'=>'erro','mensagem'=>'Falha no STT','http'=>$http,'detalhe'=>$err?:$detail]);
    }

    $j=json_decode((string)$res,true);
    $text=is_array($j)?trim((string)($j['text']??$j['texto']??'')):'';
    if ($text==='') api_v1_json_response(502,['status'=>'erro','mensagem'=>'STT respondeu sem texto']);

    api_v1_log($pdo,'AVATAR_STT_OK','INFO',$client['nome']??null,['model'=>$model]);
    api_v1_json_response(200,['status'=>'sucesso','texto'=>$text,'modelo'=>$model]);
}

if ($acao === 'vision') {
    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        api_v1_json_response(400,['status'=>'erro','mensagem'=>'Imagem obrigatória']);
    }

    $prompt=trim((string)($_POST['prompt']??'Descreva objetivamente a cena e o que for relevante para responder ao usuário.'));
    $raw=file_get_contents($_FILES['image']['tmp_name']);
    if ($raw===false || $raw==='') api_v1_json_response(400,['status'=>'erro','mensagem'=>'Imagem vazia']);
    $mime=$_FILES['image']['type'] ?: 'image/jpeg';
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) {
        api_v1_json_response(415,['status'=>'erro','mensagem'=>'Formato de imagem não suportado']);
    }
    $dataUrl='data:'.$mime.';base64,'.base64_encode($raw);

    $models=[];
    try {
        $models=$pdo->query("SELECT * FROM ia_modelos
            WHERE ativo=1 AND provedor IN('openai_compatible','runpod')
            ORDER BY padrao DESC, prioridade ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) {}

    if (!$models) api_v1_json_response(503,['status'=>'erro','mensagem'=>'Nenhum modelo multimodal compatível cadastrado']);

    $diag=[];
    foreach ($models as $m) {
        $base=rtrim((string)$m['base_url'],'/');
        $url=avatar_openai_url($base,'chat/completions');
        $headers=['Content-Type: application/json'];
        if (!empty($m['api_key'])) $headers[]='Authorization: Bearer '.$m['api_key'];

        $payload=[
            'model'=>$m['modelo'],
            'messages'=>[[
                'role'=>'user',
                'content'=>[
                    ['type'=>'text','text'=>$prompt],
                    ['type'=>'image_url','image_url'=>['url'=>$dataUrl]]
                ]
            ]],
            'temperature'=>(float)($m['temperatura']??0.2),
            'max_tokens'=>(int)($m['max_tokens']??500),
            'stream'=>false
        ];

        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>max(20,(int)($m['timeout_segundos']??60))
        ]);
        $res=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $err=curl_error($ch);
        curl_close($ch);

        $text='';
        $detail='';
        if ($res!==false) {
            $j=json_decode((string)$res,true);
            if (is_array($j)) {
                $text=trim((string)($j['choices'][0]['message']['content']??''));
                $detail=(string)($j['error']['message']??$j['message']??'');
            }
        }

        $ok=$http>=200 && $http<300 && $text!=='';
        avatar_model_health($pdo,(int)$m['id'],$ok,$ok?null:($err?:($detail?:'HTTP '.$http)));
        $diag[]=['id'=>(int)$m['id'],'nome'=>$m['nome'],'modelo'=>$m['modelo'],'http'=>$http,'ok'=>$ok,'erro'=>$ok?null:($err?:$detail)];

        if ($ok) {
            api_v1_log($pdo,'AVATAR_VISION_OK','INFO',$client['nome']??null,['model_id'=>(int)$m['id']]);
            api_v1_json_response(200,[
                'status'=>'sucesso','descricao'=>$text,
                'modelo_usado'=>['id'=>(int)$m['id'],'nome'=>$m['nome'],'modelo'=>$m['modelo']],
                'diagnostico'=>$diag
            ]);
        }
    }

    api_v1_log($pdo,'AVATAR_VISION_ERROR','WARN',$client['nome']??null,['diagnostico'=>$diag]);
    api_v1_json_response(502,['status'=>'erro','mensagem'=>'Nenhum modelo aceitou a visão do Kinect','diagnostico'=>$diag]);
}

api_v1_json_response(404,['status'=>'erro','mensagem'=>'Ação inválida','acoes'=>['stt','vision']]);
?>