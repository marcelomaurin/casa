<?php
putenv('JARVIS_DB_HOST=127.0.0.1');putenv('JARVIS_DB_PORT=3306');putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');putenv('JARVIS_DB_PASS=root');putenv('JARVIS_AUTO_INIT_DB=1');
require __DIR__.'/../../site/var/www/html/api/db.php';
require __DIR__.'/../../site/var/www/html/api/google_home_bridge.php';
require_once __DIR__.'/../../site/var/www/html/api/fila_tarefas.php';
$pdo=get_db_pdo();
function gh_test($v,$name){if(!$v)throw new RuntimeException($name);echo 'OK '.$name."\n";}
$s=gh_bridge_snapshot(['online'=>true,'queue_alive'=>true,'devices'=>[['uuid'=>'uuid-test','name'=>'Sala','host'=>'192.168.2.50','token'=>'secret']]],1000);
gh_test(!isset($s['devices'][0]['token']),'snapshot filtra campos privados');
$stmt=$pdo->prepare("INSERT INTO configuracoes_sistema(chave,valor,descricao) VALUES('google_home_agent_status',:v,'teste') ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
$stmt->execute([':v'=>json_encode($s)]);
gh_test(gh_bridge_status($pdo,1050)['online'],'heartbeat recente fica online');
gh_test(!gh_bridge_status($pdo,1100)['online'],'heartbeat vencido fica offline');
gh_test(!gh_bridge_status($pdo,999)['online'],'horario futuro nao parece online');
$s['queue_alive']=false;$stmt->execute([':v'=>json_encode($s)]);
gh_test(!gh_bridge_status($pdo,1050)['online'],'worker parado nao parece disponivel');
$pdo->exec("UPDATE jarvis_tarefas SET status='CANCELADA' WHERE executor='google_home' AND status NOT IN ('CONCLUIDA','ERRO','CANCELADA')");
$r=gh_enqueue_speech($pdo,'mensagem de teste','uuid-test');$id=$r['id_tarefa'];$ctx=$r['context'];
gh_test($r['status']==='pendente' && !$r['falou'],'enfileirar nao declara fala concluida');
$tasks=ft_reservar($pdo,'google-home-ci',['google_home'],[],1,300);
gh_test(count($tasks)===1 && $tasks[0]['id']===$id && $tasks[0]['payload']['texto']==='mensagem de teste','agente reserva texto e destino');
$st=$pdo->prepare('SELECT status FROM jarvis_planos WHERE id=:id');$st->execute([':id'=>$ctx['id_plano']]);
gh_test($st->fetchColumn()==='EM_EXECUCAO','plano aguarda reproducao');
ft_concluir($pdo,$id,'google-home-ci',['reproducao'=>'iniciada']);
$st->execute([':id'=>$ctx['id_plano']]);gh_test($st->fetchColumn()==='CONCLUIDO','plano termina com confirmacao do agente');
try {gh_enqueue_speech($pdo,'');throw new LogicException('aceitou vazio');}catch(InvalidArgumentException $e){gh_test(true,'texto vazio recusado');}
$pdo->exec("DELETE FROM configuracoes_sistema WHERE chave='google_home_agent_status'");
