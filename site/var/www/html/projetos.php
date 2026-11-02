<?php
// Painel de projetos CASA: usa sessao web, sem expor tokens de API no navegador.
if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }
require_once __DIR__.'/api/db.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\'; script-src \'none\'; base-uri \'none\'; form-action \'self\'');
if (empty($_SESSION['projetos_csrf'])) $_SESSION['projetos_csrf']=bin2hex(random_bytes(24));
$csrf=$_SESSION['projetos_csrf'];
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$pdo=get_db_pdo();$error='';$notice='';
$selected=max(0,(int)($_GET['id']??$_POST['projeto_id']??0));
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!hash_equals($csrf,(string)($_POST['csrf']??''))) {http_response_code(403);$error='Sessão expirada. Atualize a página.';}
    else {
        try {
            $action=(string)($_POST['acao']??'');
            if ($action==='criar') {
                $name=trim((string)($_POST['nome']??''));$goal=trim((string)($_POST['objetivo']??''));$repo=trim((string)($_POST['repositorio']??''));
                if ($name===''||mb_strlen($name)>180||$goal===''||mb_strlen($goal)>10000||($repo!==''&&!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~',$repo))) throw new InvalidArgumentException('Nome, objetivo ou repositório inválido.');
                $q=$pdo->prepare('INSERT INTO casa_projetos(nome,objetivo,repositorio,criado_por) VALUES(?,?,?,?)');
                $q->execute([$name,$goal,$repo?:null,mb_substr((string)$_SESSION['auth_user'],0,120)]);
                $selected=(int)$pdo->lastInsertId();
            } elseif ($action==='agente' && $selected>0) {
                $name=trim((string)($_POST['nome']??''));$specialty=trim((string)($_POST['especialidade']??''));
                if ($name===''||mb_strlen($name)>120||$specialty===''||mb_strlen($specialty)>80) throw new InvalidArgumentException('Agente inválido.');
                $q=$pdo->prepare('INSERT INTO casa_projeto_agentes(projeto_id,nome,especialidade,executor) SELECT id,?,?,? FROM casa_projetos WHERE id=? AND status=?');
                $q->execute([$name,$specialty,'jarvis',$selected,'planejamento']);
                if (!$q->rowCount()) throw new InvalidArgumentException('Projeto indisponível para edição.');
            } elseif ($action==='tarefa' && $selected>0) {
                $title=trim((string)($_POST['titulo']??''));$desc=trim((string)($_POST['descricao']??''));$criterion=trim((string)($_POST['criterio']??''));
                $agent=(int)($_POST['agente_id']??0);$dep=(int)($_POST['depende_de']??0);$priority=(int)($_POST['prioridade']??3);
                if ($title===''||mb_strlen($title)>200||$desc===''||mb_strlen($desc)>10000||mb_strlen($criterion)>10000||$priority<1||$priority>5) throw new InvalidArgumentException('Dados da tarefa inválidos.');
                $q=$pdo->prepare('SELECT status FROM casa_projetos WHERE id=?');$q->execute([$selected]);
                if ($q->fetchColumn()!=='planejamento') throw new InvalidArgumentException('Projeto não está em planejamento.');
                if ($agent) {$q=$pdo->prepare('SELECT 1 FROM casa_projeto_agentes WHERE id=? AND projeto_id=?');$q->execute([$agent,$selected]);if (!$q->fetchColumn()) throw new InvalidArgumentException('Agente inválido.');}
                if ($dep) {$q=$pdo->prepare('SELECT 1 FROM casa_projeto_tarefas WHERE id=? AND projeto_id=?');$q->execute([$dep,$selected]);if (!$q->fetchColumn()) throw new InvalidArgumentException('Dependência inválida.');}
                $q=$pdo->prepare('INSERT INTO casa_projeto_tarefas(projeto_id,agente_id,depende_de,titulo,descricao,criterio_aceite,prioridade) VALUES(?,?,?,?,?,?,?)');
                $q->execute([$selected,$agent?:null,$dep?:null,$title,$desc,$criterion?:null,$priority]);
            } else throw new InvalidArgumentException('Ação inválida.');
            header('Location: /casa/projetos.php'.($selected?'?id='.$selected:''),true,303);exit;
        } catch (InvalidArgumentException $e) {$error=$e->getMessage();}
          catch (Throwable $e) {error_log('projetos painel: '.$e->getMessage());$error='Falha ao salvar. Verifique a migração do banco.';}
    }
}
try {
    $projects=$pdo->query('SELECT id,nome,status,repositorio,atualizado_em FROM casa_projetos ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    $project=null;$agents=[];$tasks=[];$progress=['total'=>0,'done'=>0,'failed'=>0,'running'=>0,'percent'=>0];
    if ($selected) {
        $q=$pdo->prepare('SELECT * FROM casa_projetos WHERE id=?');$q->execute([$selected]);$project=$q->fetch(PDO::FETCH_ASSOC);
        if ($project) {
            $q=$pdo->prepare('SELECT * FROM casa_projeto_agentes WHERE projeto_id=? ORDER BY id');$q->execute([$selected]);$agents=$q->fetchAll(PDO::FETCH_ASSOC);
            $q=$pdo->prepare('SELECT t.*,j.status AS fila_status,j.erro,j.tentativas,j.resultado FROM casa_projeto_tarefas t LEFT JOIN jarvis_tarefas j ON j.id=t.jarvis_tarefa_id WHERE t.projeto_id=? ORDER BY t.id');$q->execute([$selected]);$tasks=$q->fetchAll(PDO::FETCH_ASSOC);\n            $progress['total']=count($tasks);foreach($tasks as $task){$st=$task['fila_status']??$task['status'];if($st==='CONCLUIDA')$progress['done']++;elseif($st==='ERRO')$progress['failed']++;elseif(in_array($st,['EXECUTANDO','PENDENTE','AGUARDANDO','AGENDADA'],true))$progress['running']++;}if($progress['total'])$progress['percent']=(int)round(100*$progress['done']/$progress['total']);
        }
    }
} catch(Throwable $e) {error_log('projetos painel: '.$e->getMessage());$error='Falha ao carregar dados. Verifique a migração do banco.';$projects=[];$project=null;$agents=[];$tasks=[];$progress=['total'=>0,'done'=>0,'failed'=>0,'running'=>0,'percent'=>0];}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Casa | Projetos e Agentes</title>
<style>
:root{font-family:system-ui,Arial,sans-serif;color-scheme:dark;background:#0e1622;color:#e8eff8}
body{max-width:1150px;margin:0 auto;padding:20px}a{color:#8dd5f5}nav{display:flex;gap:20px;align-items:center;flex-wrap:wrap}
h1{font-size:1.8rem}h2{font-size:1.25rem}section{background:#172638;border:1px solid #31465a;border-radius:12px;padding:18px;margin:16px 0}
label{display:block;margin:10px 0 4px}input,textarea,select{box-sizing:border-box;width:100%;background:#0e1c2b;color:white;border:1px solid #58738c;border-radius:6px;padding:10px}
textarea{min-height:75px}button{margin-top:12px;padding:10px 18px;background:#69b8e0;color:#081521;border:0;border-radius:7px;font-weight:700;cursor:pointer}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.grid section{margin:0}
table{border-collapse:collapse;width:100%;display:block;overflow:auto}th,td{text-align:left;padding:10px;border-bottom:1px solid #31465a;white-space:normal}
small,.muted{color:#b5c6d5}.error{color:#ffc3c3}.item{padding:9px;border-bottom:1px solid #31465a}progress{width:100%;height:22px;accent-color:#69b8e0}.result{white-space:pre-wrap;overflow-wrap:anywhere;max-height:240px;overflow:auto;background:#0e1c2b;padding:10px;border-radius:6px}
</style></head><body><nav><a href="/casa/index.php">← Casa</a><strong>Projetos e Agentes</strong><a href="/casa/projetos.php">Todos os projetos</a></nav>
<h1>Gerenciamento de projetos</h1><p class="muted">Cadastre projetos, agentes e tarefas. A execução exige aprovação separada pela API autenticada.</p>
<?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
<div class="grid"><section><h2>Novo projeto</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="acao" value="criar">
<label>Nome<input name="nome" maxlength="180" required></label><label>Objetivo<textarea name="objetivo" maxlength="10000" required></textarea></label>
<label>Repositório GitHub (opcional)<input name="repositorio" placeholder="usuario/repositorio"></label><button>Criar projeto</button></form></section>
<section><h2>Projetos recentes</h2><?php foreach($projects as $p):?><div class="item"><a href="?id=<?= (int)$p['id']?>"><?=h($p['nome'])?></a> <small><?=h($p['status'])?></small><div class="muted"><?=h($p['repositorio']??'')?></div></div><?php endforeach;?><?php if(!$projects):?><p>Nenhum projeto cadastrado.</p><?php endif;?></section></div>
<?php if($project):?><section><h2><?=h($project['nome'])?></h2><p><?=nl2br(h($project['objetivo']))?></p><p>Status: <strong><?=h($project['status'])?></strong> · Repositório: <?=h($project['repositorio']??'—')?></p></section>
<section><h2>Progresso</h2><progress value="<?= (int)$progress['done']?>" max="<?= max(1,(int)$progress['total'])?>"></progress><p><?= (int)$progress['percent']?>% concluído · <?= (int)$progress['done']?> concluídas · <?= (int)$progress['running']?> em andamento · <?= (int)$progress['failed']?> com erro · <?= (int)$progress['total']?> no total</p><p class="muted">O progresso é calculado pelo estado real da fila, sem alterar automaticamente o estado do projeto.</p></section>\n<div class="grid"><section><h2>Agentes (<?=count($agents)?>)</h2><?php foreach($agents as $a):?><div class="item">#<?= (int)$a['id']?> <?=h($a['nome'])?> · <?=h($a['especialidade'])?></div><?php endforeach;?>
<?php if($project['status']==='planejamento'):?><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="acao" value="agente"><input type="hidden" name="projeto_id" value="<?= (int)$selected?>">
<label>Nome<input name="nome" maxlength="120" required></label><label>Especialidade<input name="especialidade" maxlength="80" required></label><button>Adicionar agente</button></form><?php endif;?></section>
<section><h2>Tarefas (<?=count($tasks)?>)</h2><?php if($project['status']==='planejamento'):?><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="acao" value="tarefa"><input type="hidden" name="projeto_id" value="<?= (int)$selected?>">
<label>Título<input name="titulo" maxlength="200" required></label><label>Descrição<textarea name="descricao" maxlength="10000" required></textarea></label><label>Critério de aceite<textarea name="criterio" maxlength="10000"></textarea></label>
<label>Agente<select name="agente_id"><option value="0">Sem agente</option><?php foreach($agents as $a):?><option value="<?= (int)$a['id']?>"><?=h($a['nome'])?></option><?php endforeach;?></select></label>
<label>Depende de<select name="depende_de"><option value="0">Sem dependência</option><?php foreach($tasks as $t):?><option value="<?= (int)$t['id']?>">#<?= (int)$t['id']?> <?=h($t['titulo'])?></option><?php endforeach;?></select></label>
<label>Prioridade<select name="prioridade"><option value="1">1 - Urgente</option><option value="2">2 - Alta</option><option value="3" selected>3 - Normal</option><option value="4">4 - Baixa</option><option value="5">5 - Opcional</option></select></label><button>Adicionar tarefa</button></form><?php endif;?></section></div>
<section><h2>Andamento da execução</h2><table><thead><tr><th>ID</th><th>Tarefa</th><th>Prioridade</th><th>Fila</th><th>Tentativas</th><th>Erro</th><th>Resultado</th></tr></thead><tbody><?php foreach($tasks as $t):?><tr><td><?= (int)$t['id']?></td><td><?=h($t['titulo'])?></td><td><?= (int)$t['prioridade']?></td><td><?=h($t['fila_status']??$t['status'])?></td><td><?= (int)($t['tentativas']??0)?></td><td><?=h($t['erro']??'')?></td><td><?php if(!empty($t['resultado'])):?><details><summary>Ver resultado</summary><pre class="result"><?=h(mb_substr((string)$t['resultado'],0,12000))?></pre></details><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table><p class="muted">Atualize a página para consultar os resultados mais recentes. A aprovação continua protegida pela API autenticada /api/v1/projetos_execucao.php.</p></section>
<?php elseif($selected):?><p class="error">Projeto não encontrado.</p><?php endif;?></body></html>
