<?php
// Ponte de atualizacao: dispositivos leem releases LIBERADAS; publicador indica a versao.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Token, X-Device-Id');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__.'/../db.php';
require_once __DIR__.'/device_common.php';
require_once __DIR__.'/updates_common.php';
$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
$action = (string)($_GET['acao'] ?? 'current');
$input = device_v1_input();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$writes = ['register','activate','report'];
if (!in_array($action,['current','report','register','activate','releases','status'],true)) api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida']);
if ($method !== (in_array($action,$writes,true) ? 'POST' : 'GET')) api_v1_json_response(405,['status'=>'erro','mensagem'=>'Metodo nao permitido']);

try {
    if ($action === 'current' || $action === 'report') {
        $deviceId = (string)($input['device_id'] ?? $_GET['device_id'] ?? $_SERVER['HTTP_X_DEVICE_ID'] ?? '');
        $dev = device_v1_load($pdo,$deviceId,$action === 'report');
        // Exige capability explicita; um dispositivo nao pode publicar releases.
        if (!in_array('update-agent',$dev['capabilities'],true)) api_v1_json_response(403,['status'=>'erro','mensagem'=>'Capability update-agent obrigatoria']);
        if ($action === 'current') api_v1_json_response(200,['status'=>'ok','release'=>updates_current($pdo)]);
        $id = (int)($input['release_id'] ?? 0);
        $component = (string)($input['component'] ?? '');
        $status = (string)($input['status'] ?? '');
        if (!isset(updates_catalog()[$component]) || !in_array($status,['updated','rolled_back','failed','not_installed'],true)) throw new InvalidArgumentException('Relatorio invalido');
        $s = $pdo->prepare('SELECT manifest FROM cluster_update_releases WHERE id=:id AND released_at IS NOT NULL');
        $s->execute([':id'=>$id]);
        $raw = $s->fetchColumn();
        if (!$raw) throw new InvalidArgumentException('Release nao liberada');
        $manifest = json_decode($raw,true);
        if (!isset($manifest['components'][$component])) throw new InvalidArgumentException('Integrador ausente na release');
        $installed = (string)($input['installed_version'] ?? '');
        if ($installed !== '' && !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$installed)) throw new InvalidArgumentException('Versao instalada invalida');
        if ($status === 'updated' && $installed !== $manifest['version']) throw new InvalidArgumentException('Versao instalada diverge da release');
        $details = is_array($input['details'] ?? null) ? api_v1_redact($input['details']) : [];
        $s = $pdo->prepare('INSERT INTO cluster_update_reports(device_id,release_id,component,status,installed_version,details) VALUES(:d,:r,:c,:s,:v,:j) ON DUPLICATE KEY UPDATE status=VALUES(status),installed_version=VALUES(installed_version),details=VALUES(details),updated_at=NOW()');
        $s->execute([':d'=>$dev['device_id'],':r'=>$id,':c'=>$component,':s'=>$status,':v'=>$installed ?: null,':j'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        api_v1_json_response(200,['status'=>'ok']);
    }

    $client = api_v1_auth_client($pdo,[in_array($action,['register','activate'],true) ? 'updates.publish' : 'updates.read']);
    if ($action === 'register') {
        if (!is_array($input['manifest'] ?? null)) throw new InvalidArgumentException('manifest obrigatorio');
        $release = updates_register($pdo,$input['manifest'],(string)$client['nome']);
        api_v1_log($pdo,'CLUSTER_RELEASE_REGISTERED','INFO',$client['nome'],$release);
        api_v1_json_response(201,['status'=>'ok','release'=>$release]);
    }
    if ($action === 'activate') {
        $release = updates_activate($pdo,(string)($input['version'] ?? ''));
        api_v1_log($pdo,'CLUSTER_RELEASE_ACTIVATED','INFO',$client['nome'],$release);
        api_v1_json_response(200,['status'=>'ok','release'=>$release]);
    }
    if ($action === 'releases') {
        $rows = $pdo->query('SELECT id,version,manifest_sha256,created_at,released_at FROM cluster_update_releases ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        api_v1_json_response(200,['status'=>'ok','releases'=>$rows,'current'=>updates_current($pdo)]);
    }
    $rows = $pdo->query('SELECT device_id,release_id,component,status,installed_version,details,updated_at FROM cluster_update_reports ORDER BY updated_at DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
    api_v1_json_response(200,['status'=>'ok','reports'=>$rows,'current'=>updates_current($pdo)]);
} catch (InvalidArgumentException $e) {
    api_v1_json_response(400,['status'=>'erro','mensagem'=>$e->getMessage()]);
} catch (DomainException $e) {
    api_v1_json_response(409,['status'=>'erro','mensagem'=>$e->getMessage()]);
} catch (Throwable $e) {
    api_v1_log($pdo,'CLUSTER_UPDATE_ERROR','WARN');
    api_v1_json_response(503,['status'=>'erro','mensagem'=>'Falha no controle de versoes dos integradores']);
}
