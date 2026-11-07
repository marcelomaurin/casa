<?php
// Testes sem banco para diagnostico de tarefas e heartbeat.
require_once __DIR__ . '/../../site/var/www/html/api/contexto_casa.php';

function assert_diag(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FALHOU: " . $message . "\n"); exit(1); }
}

assert_diag(cti_pergunta_sobre_tarefa('Quando solicitei a tarefa de atualizar Android?'), 'consulta de data');
assert_diag(cti_pergunta_sobre_tarefa('Por que a tarefa nao rodou?'), 'consulta de motivo');
assert_diag(cti_pergunta_sobre_tarefa('Qual o status do daemon de tarefas?'), 'consulta do daemon');
assert_diag(!cti_pergunta_sobre_tarefa('Quando chegar em casa, ligue a luz'), 'comando condicional nao e consulta');
assert_diag(!cti_pergunta_sobre_tarefa('Execute a tarefa de revisar a planta'), 'comando de execucao nao e consulta');

$ativo = ['nome'=>'pi-sala','executores'=>'["jarvis","web"]','filas'=>'[]','ultimo_contato'=>date('Y-m-d H:i:s'),'sem_contato'=>25];
$inativo = ['nome'=>'pi-sala','executores'=>'["jarvis","web"]','filas'=>'[]','ultimo_contato'=>date('Y-m-d H:i:s', time()-900),'sem_contato'=>900];
$t = ['fila_id'=>21,'status_execucao'=>'PENDENTE','executor'=>'jarvis','fila'=>'geral','dependencia_status'=>null,'depende_de'=>null,'etapa_github'=>null];
assert_diag(cti_ativo($ativo), 'worker com heartbeat');
assert_diag(!cti_ativo($inativo), 'worker sem heartbeat');
assert_diag(cti_compativel($t,$ativo), 'worker compativel');
assert_diag(str_contains(cti_motivo($t, [$inativo]), 'SEM WORKER ATIVO'), 'pendente por falta de worker ativo');
assert_diag(str_contains(cti_motivo($t, [$ativo]), 'Worker compativel'), 'pendente com worker ativo precisa investigar');
assert_diag(str_contains(cti_motivo($t, [], false), 'Telemetria'), 'telemetria indisponivel nao e worker desligado');
$t['status_execucao']='EXECUTANDO'; $t['reservado_por']='pi-sala';
assert_diag(str_contains(cti_motivo($t, [$inativo]), 'SEM CONTATO RECENTE'), 'execucao interrompida por worker inativo');
$t['status_execucao']='PENDENTE'; $t['depende_de']=14; $t['dependencia_status']='PENDENTE';
assert_diag(str_contains(cti_motivo($t, [$ativo]), 'BLOQUEADA'), 'dependencia tem prioridade no diagnostico');
$t=['fila_id'=>null,'projeto_status'=>'planejamento'];
assert_diag(str_contains(cti_motivo($t, []), 'NAO DESPACHADA'), 'projeto nao aprovado');
echo "Diagnostico de tarefas/daemons OK\n";
