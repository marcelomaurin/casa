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

assert_diag(cti_pergunta_datas_tarefas('Quais as datas de solicitacao das tarefas?'), 'lista datas de solicitacao');
assert_diag(cti_pergunta_datas_tarefas('Quando solicitei a tarefa de atualizar Android?'), 'data de tarefa especifica');
assert_diag(!cti_pergunta_datas_tarefas('Quando devo agendar a tarefa da bomba?'), 'agendamento futuro nao vira consulta historica');
assert_diag(cti_palavras('Quais as datas de solicitação das tarefas?') === [], 'pergunta geral nao gera filtro de pesquisa');
assert_diag(cti_formatar_data('2026-11-07 10:13:08') === '07/11/2026 10:13', 'formato de data fiel ao banco');
$amostras=[
    ['id_tarefa'=>11,'titulo'=>'Revisar Android','projeto'=>'Casa','solicitada_em'=>'2026-11-03 08:00:00','id_fila'=>11],
    ['id_tarefa'=>12,'titulo'=>'Revisar ESP32','projeto'=>'Casa','solicitada_em'=>'2026-11-04 09:00:00','id_fila'=>12],
];
$listadas=cti_selecionar_datas($amostras,'Quais as datas de solicitacao das tarefas?');
assert_diag(count($listadas)===2 && $listadas[0]['id_tarefa']===12, 'lista generica ordenada pela data real');
$filtradas=cti_selecionar_datas($amostras,'Quando solicitei a tarefa de revisar Android?');
assert_diag(count($filtradas)===1 && $filtradas[0]['id_tarefa']===11, 'data por assunto');
$porId=cti_selecionar_datas($amostras,'Qual a data da tarefa #12?');
assert_diag(count($porId)===1 && $porId[0]['id_tarefa']===12, 'data por id');

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
