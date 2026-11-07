<?php
// Gateway seguro da API externa v1.
// Valida cliente, escopo, rate limit e payload antes de encaminhar ao roteador legado.

require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../seguranca.php');
require_once(__DIR__ . '/security_v1.php');

$pdo = get_db_pdo();
api_v1_basic_guard($pdo);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$path = trim($uri, '/');
$subpath = '';
if (preg_match('#api/v1/(.*)#', $path, $m)) $subpath = trim($m[1], '/');
if ($subpath === 'gateway.php' || $subpath === 'index.php') $subpath = '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$acceptedScopes = ['status.read'];
if ($subpath === '' || $subpath === 'status') $acceptedScopes = ['status.read','mobile.read','watch.read'];
elseif ($subpath === 'comando') $acceptedScopes = ['jarvis.command','mobile.write','watch.write'];
elseif ($subpath === 'dispositivos/acionar') $acceptedScopes = ['devices.write','mobile.write'];
elseif ($subpath === 'dispositivos') $acceptedScopes = ['devices.read','mobile.read','watch.read'];
elseif ($subpath === 'sensores') $acceptedScopes = ['sensors.read','mobile.read'];
elseif ($subpath === 'clima') $acceptedScopes = ['climate.read','mobile.read'];
elseif ($subpath === 'camera/snapshot') $acceptedScopes = ['camera.read','mobile.read'];

$client = api_v1_auth_client_any($pdo, $acceptedScopes);
api_v1_log($pdo, 'REQUEST_ALLOWED', 'INFO', $client['nome'] ?? null, [
    'accepted_scopes' => $acceptedScopes,
    'subpath' => $subpath,
    'method' => $method
]);

if ($subpath === '' || $subpath === 'status') {
    $totalDev=$totalNodes=$pendingMobile=$pendingWatch=0;
    try { $totalDev=(int)$pdo->query("SELECT COUNT(*) FROM dispositivos_cluster WHERE status='online'")->fetchColumn(); } catch(Throwable $e){}
    try { $totalNodes=(int)$pdo->query("SELECT COUNT(*) FROM arm_nodes WHERE status='online'")->fetchColumn(); } catch(Throwable $e){}
    try { $pendingMobile=(int)$pdo->query("SELECT COUNT(*) FROM mobile_notificacoes WHERE entregue=0")->fetchColumn(); } catch(Throwable $e){}
    try { $pendingWatch=(int)$pdo->query("SELECT COUNT(*) FROM watch_notificacoes WHERE entregue=0")->fetchColumn(); } catch(Throwable $e){}
    api_v1_json_response(200,[
        'status'=>'sucesso',
        'sistema'=>'CASA_JARVIS',
        'versao_api'=>'v1.2.0',
        'dominio'=>'https://maurinsoft.com.br/casa',
        'cliente'=>$client['nome'] ?? 'cliente',
        'timestamp'=>date('c'),
        'endpoints'=>[
            'mobile'=>'/api/v1/mobile.php',
            'watch'=>'/api/v1/watch.php',
            'family'=>'/api/v1/family.php',
            'camera'=>'/api/v1/camera.php',
            'command'=>'/api/v1/comando'
        ],
        'resumo'=>[
            'dispositivos_online'=>$totalDev,
            'nos_online'=>$totalNodes,
            'notificacoes_mobile'=>$pendingMobile,
            'notificacoes_watch'=>$pendingWatch
        ]
    ]);
}

if ($subpath === 'comando') {
    $raw=file_get_contents('php://input');
    $input=$raw?json_decode($raw,true):[];
    if (!is_array($input)) $input=[];
    $cmd=trim((string)($input['comando'] ?? ''));
    if ($cmd==='') api_v1_json_response(400,['status'=>'erro','mensagem'=>'Parâmetro comando obrigatório']);

    $correlationId=api_v1_correlation_id($input['correlation_id'] ?? null);
    $internalToken=get_system_api_token();
    $ch=curl_init(casa_url_interna('api/jarvis.php'));
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode([
            'comando'=>$cmd,
            'ia_mode'=>$input['ia_mode'] ?? 'auto',
            'origem'=>$input['origem'] ?? 'API_V1',
            'idioma'=>$input['idioma'] ?? null,
            'locale'=>$input['locale'] ?? null,
            'correlation_id'=>$correlationId,
            'task_context'=>$input['task_context'] ?? null,
            // Daemons de tarefas executam partes de um plano já feito: não replanejar.
            'skip_planner'=>!empty($input['skip_planner'])
        ],JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Key: '.$internalToken],
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>100
    ]);
    $res=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if ($res===false || $http<200 || $http>=300) {
        $detail=is_string($res) ? json_decode($res,true) : null;
        $explanation=is_array($detail) ? trim((string)($detail['mensagem']??'')) : '';
        api_v1_json_response(502,[
            'status'=>'erro',
            'mensagem'=>$explanation !== '' ? mb_substr($explanation,0,240,'UTF-8') : 'O núcleo JARVIS não respondeu corretamente',
            'http_nucleo'=>$http,
            'motivo'=>$err !== '' ? 'falha de comunicacao ou timeout' : 'resposta recusada'
        ]);
    }
    $data=json_decode((string)$res,true);
    if (!is_array($data) || !in_array($data['status']??'', ['sucesso','ok'],true) ||
        trim((string)($data['resposta']??''))==='') {
        api_v1_json_response(502,[
            'status'=>'erro',
            'mensagem'=>is_array($data) && !empty($data['mensagem'])
                ? mb_substr((string)$data['mensagem'],0,240,'UTF-8')
                : 'O JARVIS não devolveu uma resposta válida da IA'
        ]);
    }
    api_v1_json_response(200,[
        'status'=>'sucesso',
        'comando'=>$cmd,
        'resposta'=>$data['resposta'] ?? $data['mensagem'] ?? '',
        'provedor_ia'=>$data['provedor'] ?? 'JARVIS',
        'acao_executada'=>$data['acao'] ?? $data['acao_executada'] ?? null,
        'audio_url'=>$data['audio_url'] ?? null,
        'id_plano'=>$data['id_plano'] ?? null,
        'status_jarvis'=>$data['status'] ?? null,
        'id_tarefa_raiz'=>$data['id_tarefa_raiz'] ?? null,
        'task_context'=>$data['task_context'] ?? null,
        'task_status'=>$data['task_status'] ?? null,
        'correlation_id'=>$data['task_context']['correlation_id'] ?? $correlationId,
        'tarefas_execucao'=>$data['tarefas_execucao'] ?? []
    ]);
}

$stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave='external_api_key' LIMIT 1");
$stmt->execute();
$master = $stmt->fetchColumn();
if (!$master) {
    $candidate = 'jarvis_sec_v1_' . bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO configuracoes_sistema(chave,valor,descricao)
        VALUES('external_api_key',:v,'Chave mestre interna da API externa')
        ON DUPLICATE KEY UPDATE valor=valor")
        ->execute([':v'=>$candidate]);
    $stmt->execute();
    $master = $stmt->fetchColumn() ?: $candidate;
}
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $master;
unset($_SERVER['HTTP_X_API_KEY']);
unset($_GET['api_key']);
require(__DIR__ . '/index.php');
