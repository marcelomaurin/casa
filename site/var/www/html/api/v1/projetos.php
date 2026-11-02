<?php
// CASA - Projetos e agentes v1. Criação e planejamento; execução exige aprovação futura.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/security_v1.php';
$pdo = get_db_pdo();
api_v1_basic_guard($pdo);
$client = api_v1_auth_client_any($pdo, ['tasks.worker', 'projects.manage']);
$scopes = $client['scopes'] ?? [];
$canWrite = in_array('*', $scopes, true) || in_array('projects.manage', $scopes, true);
$action = strtolower((string)($_GET['acao'] ?? 'listar'));
$input = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) api_v1_json_response(400, ['status'=>'erro','mensagem'=>'JSON invalido']);
    if (isset($input['acao'])) $action = strtolower((string)$input['acao']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !in_array($action, ['listar','detalhes'], true))
    api_v1_json_response(405, ['status'=>'erro','mensagem'=>'Use POST']);
if (!in_array($action, ['listar','detalhes'], true) && !$canWrite)
    api_v1_json_response(403, ['status'=>'erro','mensagem'=>'Escopo projects.manage necessario']);
function project_text($value, $limit, $field) {
    if (!is_string($value) || trim($value)==='' || mb_strlen($value)>$limit)
        api_v1_json_response(400, ['status'=>'erro','mensagem'=>"Campo invalido: $field"]);
    return trim($value);
}
try {
    if ($action === 'listar') {
        $rows = $pdo->query('SELECT id,nome,objetivo,repositorio,status,criado_em,atualizado_em FROM casa_projetos ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        api_v1_json_response(200, ['status'=>'ok','projetos'=>$rows]);
    }
    if ($action === 'detalhes') {
        $id = (int)($_GET['id'] ?? ($input['id'] ?? 0));
        if ($id <= 0) api_v1_json_response(400, ['status'=>'erro','mensagem'=>'id obrigatorio']);
        $q=$pdo->prepare('SELECT * FROM casa_projetos WHERE id=?');$q->execute([$id]);$project=$q->fetch(PDO::FETCH_ASSOC);
        if (!$project) api_v1_json_response(404, ['status'=>'erro','mensagem'=>'Projeto nao encontrado']);
        $q=$pdo->prepare('SELECT * FROM casa_projeto_agentes WHERE projeto_id=? ORDER BY id');$q->execute([$id]);$agents=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT * FROM casa_projeto_tarefas WHERE projeto_id=? ORDER BY prioridade,id');$q->execute([$id]);$tasks=$q->fetchAll(PDO::FETCH_ASSOC);
        api_v1_json_response(200, ['status'=>'ok','projeto'=>$project,'agentes'=>$agents,'tarefas'=>$tasks]);
    }
    if ($action === 'criar') {
        $name=project_text($input['nome']??null,180,'nome');
        $objective=project_text($input['objetivo']??null,10000,'objetivo');
        $repo=trim((string)($input['repositorio']??''));
        if ($repo!=='' && !preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~',$repo)) api_v1_json_response(400,['status'=>'erro','mensagem'=>'Repositorio deve ser owner/repo']);
        $q=$pdo->prepare('INSERT INTO casa_projetos(nome,objetivo,repositorio,criado_por) VALUES(?,?,?,?)');
        $q->execute([$name,$objective,$repo?:null,substr((string)($client['nome']??'API'),0,120)]);
        $id=(int)$pdo->lastInsertId();
        api_v1_log($pdo,'PROJETO_CRIADO','INFO',$client['nome']??null,['projeto_id'=>$id]);
        api_v1_json_response(201,['status'=>'ok','id'=>$id]);
    }
    if ($action === 'agente') {
        $id=(int)($input['projeto_id']??0);
        $name=project_text($input['nome']??null,120,'nome');
        $specialty=project_text($input['especialidade']??null,80,'especialidade');
        $executor=project_text($input['executor']??'jarvis',80,'executor');
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/',$executor)) api_v1_json_response(400,['status'=>'erro','mensagem'=>'Executor invalido']);
        $q=$pdo->prepare('INSERT INTO casa_projeto_agentes(projeto_id,nome,especialidade,executor) VALUES(?,?,?,?)');
        $q->execute([$id,$name,$specialty,$executor]);
        api_v1_json_response(201,['status'=>'ok','id'=>(int)$pdo->lastInsertId()]);
    }
    if ($action === 'tarefa') {
        $id=(int)($input['projeto_id']??0);
        $title=project_text($input['titulo']??null,200,'titulo');
        $description=project_text($input['descricao']??null,10000,'descricao');
        $criteria=trim((string)($input['criterio_aceite']??''));
        if (mb_strlen($criteria)>10000) api_v1_json_response(400,['status'=>'erro','mensagem'=>'Criterio muito longo']);
        $agent=isset($input['agente_id'])?(int)$input['agente_id']:null;
        $dependency=isset($input['depende_de'])?(int)$input['depende_de']:null;
        $priority=(int)($input['prioridade']??3);
        if ($id<=0 || $priority<1 || $priority>5) api_v1_json_response(400,['status'=>'erro','mensagem'=>'Projeto ou prioridade invalida']);
        if ($agent!==null) {$q=$pdo->prepare('SELECT 1 FROM casa_projeto_agentes WHERE id=? AND projeto_id=?');$q->execute([$agent,$id]);if (!$q->fetchColumn()) api_v1_json_response(400,['status'=>'erro','mensagem'=>'Agente nao pertence ao projeto']);}
        if ($dependency!==null) {$q=$pdo->prepare('SELECT 1 FROM casa_projeto_tarefas WHERE id=? AND projeto_id=?');$q->execute([$dependency,$id]);if (!$q->fetchColumn()) api_v1_json_response(400,['status'=>'erro','mensagem'=>'Dependencia nao pertence ao projeto']);}
        $q=$pdo->prepare('INSERT INTO casa_projeto_tarefas(projeto_id,agente_id,depende_de,titulo,descricao,criterio_aceite,prioridade) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$id,$agent,$dependency,$title,$description,$criteria?:null,$priority]);
        api_v1_json_response(201,['status'=>'ok','id'=>(int)$pdo->lastInsertId()]);
    }
    api_v1_json_response(404,['status'=>'erro','mensagem'=>'Acao desconhecida']);
} catch (PDOException $e) {
    error_log('projetos v1: '.$e->getMessage());
    api_v1_json_response(500,['status'=>'erro','mensagem'=>'Falha no banco; verifique migracao e referencias']);
}
