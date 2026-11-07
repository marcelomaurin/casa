<?php
require_once __DIR__.'/../../site/var/www/html/api/diagnostico_daemons.php';

function deve($cond,$msg){ if(!$cond){fwrite(STDERR,"Falha: ".$msg."\n");exit(1);} }
foreach ([
    ['Liste os daemons rodando','lista'],
    ['Quais serviços estão em execução?','lista'],
    ['Mostre os processos ativos','lista'],
    ['Qual o status do daemon?','trabalhador'],
    ['Qual daemon roda as tarefas?','trabalhador'],
    ['Mostre os logs do daemon','trabalhador'],
    ['Por que o Plano #178 continua pendente?','plano'],
    ['Quando solicitei o plano 153?','plano']
] as $t){
    $it=dd_intencao($t[0]);
    deve(($it['tipo']??null)===$t[1],$t[0].' => '.json_encode($it));
}
deve((dd_intencao('Por que o Plano #178 está pendente?')['id']??null)===178,'id do plano');
foreach (['Ligue a luz da sala','Como instalar o Linux?','Quem é o presidente?','Crie uma tarefa amanhã'] as $comando)
    deve(dd_intencao($comando)===null,'não transformar comando comum em diagnóstico: '.$comando);
$worker=[[
    'nome'=>'pi-sala','host'=>'rpi-sala','idade'=>12,'ativo'=>true,
    'ultimo_contato'=>'2026-11-07 14:00:00','executores'=>['jarvis','web','agente'],
    'filas'=>['geral'],'em_execucao'=>1
]];
$node=[[
    'nome'=>'Raspberry Sala','device_id'=>'rpi-sala','online'=>true,
    'ultimo_heartbeat'=>'2026-11-07 14:00:00','idade'=>10,
    'diagnostico'=>[
        'running_status'=>'ok','checked_at'=>'2026-11-07 13:59:50',
        'running_count'=>2,'running_services'=>['casa-tarefas.service','ssh.service'],
        'critical_services'=>[
            'casa-tarefas.service'=>[
                'ActiveState'=>'active','logs_status'=>'consultados',
                'log_errors'=>['Falha de conexão com a API']
            ]
        ]
    ]
]];
$r=dd_resumo_lista($worker,$node);
deve(strpos($r['resposta'],'ssh.service')!==false,'lista nomes reais');
deve(strpos($r['resposta'],'2 serviços systemd')!==false,'contagem de serviços');
$r2=dd_resumo_trabalhador($worker,$node);
deve(strpos($r2['resposta'],'casa-tarefas.service')!==false,'nome do daemon real');
deve(strpos($r2['resposta'],'Falha de conexão')!==false,'diagnóstico com journal');
$stale=$node;
$stale[0]['online']=false;
deve(strpos(dd_resumo_lista($worker,$stale)['resposta'],'não é possível afirmar')!==false,'não anunciar dados antigos como atuais');
deve(strpos(dd_resumo_trabalhador($worker,$stale)['resposta'],'Falha de conexão')===false,'não divulgar log antigo como atual');
deve(strpos(dd_resumo_lista([],[])['resposta'],'Não há inventário atual')!==false,'nenhum nó não significa zero serviços');
$source=file_get_contents(__DIR__.'/../../site/var/www/html/api/jarvis.php');
deve(strpos($source,'dd_intencao($comando)')!==false,'roteamento direto da consulta');
deve(strpos($source,'dd_consultar($pdo,$intencaoDiagnostico)')!==false,'consulta à telemetria antes da IA');
echo "COMPUTER: diagnóstico direto, nó offline, logs e identificação dos daemons OK\n";
