<?php
/**
 * Diagnostico deterministico do COMPUTER. A fonte sao heartbeats autenticados
 * dos nos Linux e trabalhadores da fila; nunca a suposicao do modelo de IA.
 * Nao executa shell nem se conecta a equipamentos sem autorizacao.
 */

function dd_normalizar(string $texto): string {
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $texto = strtr($texto, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e',
        'í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    return preg_replace('/\s+/u', ' ', $texto);
}

/** null = deixar o pedido seguir ao fluxo normal da IA. */
function dd_intencao(string $comando): ?array {
    $c = dd_normalizar($comando);
    if (preg_match('/\b(?:plano|tarefa)\s*(?:numero|n[º°o]\.?|#)?\s*(\d{1,9})\b/u', $c, $m)
        && preg_match('/\b(?:status|situacao|pendente|rodou|executad[ao]|parad[ao]|demor|motivo|por que|porque|quando|data|criad[ao]|solicitad[ao]|iniciad[ao]|terminad[ao])\b/u', $c)) {
        return ['tipo'=>'plano','id'=>(int)$m[1]];
    }
    // Pedido de logs/estado tem prioridade sobre verbos como 'mostre'.
    if (preg_match('/\b(?:logs?|erros?|status|estado)\b/u', $c)
        && preg_match('/\b(?:daemons?|casa-tarefas|servico de tarefas)\b/u', $c)) {
        return ['tipo'=>'trabalhador'];
    }
    if (preg_match('/\b(?:daemons?|servic[oo]s?|processos?)\b/u', $c)
        && preg_match('/\b(?:list[ae]r?|liste|mostre|quais|quantos|exib[ae]|rodando|ativos|em execucao)\b/u', $c)) {
        return ['tipo'=>'lista'];
    }
    if (preg_match('/\b(?:daemons?|workers?|trabalhadores?|servic[oo]s?|executor(?:es)?)\b/u', $c)
        && preg_match('/\b(?:status|estado|saude|rodando|ativo|ativo[s]?|logs?|erros?|parad[ao]|responsavel|executa|roda|atende|funcionando|qual)\b/u', $c)
        && (preg_match('/\b(?:tarefa[s]?|fila|planejador|daemon|worker|casa-tarefas|executa)\b/u', $c)
            || preg_match('/\b(?:status|estado|logs?)\s+d[oa]s?\s+daemons?\b/u', $c))) {
        return ['tipo'=>'trabalhador'];
    }
    return null;
}

function dd_lista_json($value): array {
    if (is_array($value)) return array_values(array_filter($value, 'is_string'));
    $v=json_decode((string)$value,true);
    return is_array($v) ? array_values(array_filter($v,'is_string')) : [];
}

function dd_trabalhadores(PDO $pdo): array {
    try {
        $rows=$pdo->query("SELECT nome,host,executores,filas,em_execucao,ultimo_contato,
                    TIMESTAMPDIFF(SECOND,ultimo_contato,NOW()) AS idade
                FROM jarvis_trabalhadores ORDER BY ultimo_contato DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $r['idade']=is_numeric($r['idade']) ? max(0,(int)$r['idade']) : null;
        $r['ativo']=$r['idade']!==null && $r['idade']<=300;
        $r['executores']=dd_lista_json($r['executores'] ?? null);
        $r['filas']=dd_lista_json($r['filas'] ?? null);
        $r['em_execucao']=(int)($r['em_execucao'] ?? 0);
    }
    unset($r);
    return $rows;
}

function dd_nos(PDO $pdo): array {
    try {
        $rows=$pdo->query("SELECT device_id,nome,tipo,status,ultimo_heartbeat,credential_revoked_at,metadata,
                TIMESTAMPDIFF(SECOND,ultimo_heartbeat,NOW()) AS idade
            FROM dispositivos_cluster ORDER BY ultimo_heartbeat DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) { return []; }
    $saida=[];
    foreach($rows as $r) {
        $meta=json_decode((string)($r['metadata']??''),true);
        if (!is_array($meta)) continue;
        $diag=$meta['daemon_diagnostics']??null;
        $linux = is_array($diag) || !empty($meta['services']) ||
            strpos(strtolower((string)($meta['platform']??'')),'linux')!==false ||
            strpos(strtolower((string)($r['tipo']??'')),'linux')!==false;
        if (!$linux) continue;
        $idade=is_numeric($r['idade'])?max(0,(int)$r['idade']):null;
        $online=$idade!==null && $idade<=40 && empty($r['credential_revoked_at'])
            && strtolower((string)$r['status'])==='online';
        $saida[]=[
            'device_id'=>(string)$r['device_id'],
            'nome'=>(string)($r['nome'] ?: ($meta['hostname']??$r['device_id'])),
            'online'=>$online, 'idade'=>$idade,
            'ultimo_heartbeat'=>$r['ultimo_heartbeat'],
            'diagnostico'=>is_array($diag)?$diag:[],
            'servicos_catalogados'=>is_array($meta['services']??null)?$meta['services']:[]
        ];
    }
    return $saida;
}

function dd_resumo_trabalhador(array $workers, array $nodes): array {
    $linhas = ['O executor de tarefas do CASA é o serviço Linux casa-tarefas.service, que executa o programa casa-tarefas.py. Task Engine é o mecanismo de planejamento no site, não o nome do daemon.'];
    $vivos=array_values(array_filter($workers,fn($w)=>!empty($w['ativo'])));
    if (!$workers) {
        $linhas[]='Não há registro de heartbeat de trabalhadores da fila no banco. Isso não comprova que o processo esteja desligado: ele pode estar sem conexão ou sem credenciais.';
    } else {
        $linhas[]='Trabalhadores com contato recente (até 300 segundos): '.count($vivos).' de '.count($workers).'.';
        foreach(array_slice($workers,0,12) as $w){
            $linhas[]='Executor '.$w['nome'].': '.($w['ativo']?'com heartbeat recente':'sem heartbeat recente').
                ', último contato '.($w['ultimo_contato']?:'não informado').
                ', executores '.(implode(', ',$w['executores'])?:'não informados').
                ', tarefas em execução '.$w['em_execucao'].'.';
        }
    }
    $logs=[];
    foreach ($nodes as $n) {
        $d=$n['diagnostico'];
        $svc=$d['critical_services']['casa-tarefas.service']??null;
        if (!is_array($svc)) continue;
        $state=$svc['ActiveState']??($svc['state']??'desconhecido');
        $linhas[]='No nó '.$n['nome'].': serviço casa-tarefas.service '.
            ($n['online']?'reportado recentemente':'com telemetria desatualizada').
            ', ActiveState='.$state.', último heartbeat '.($n['ultimo_heartbeat']?:'não informado').'.';
        if (!$n['online']) continue; // Estado antigo e logs antigos não são atuais.
        if (($svc['logs_status']??'')==='sem_acesso') {
            $linhas[]='O agente não tem permissão para ler os logs do serviço neste nó.';
        } elseif (($svc['logs_status']??'')==='consultados') {
            $linhas[]='O agente consultou o journal do casa-tarefas.service; erros recentes: '.
                count($svc['log_errors']??[]).'.';
            foreach(array_slice($svc['log_errors']??[],0,3) as $err)
                if (is_string($err)) $logs[]='Nó '.$n['nome'].': '.mb_substr($err,0,240,'UTF-8');
        }
    }
    if ($logs) {
        $linhas[]='Últimas linhas de erro do journal (podem não estar relacionadas à tarefa consultada):';
        foreach($logs as $err) $linhas[]=$err;
    }
    if (!$vivos) $linhas[]='Nenhum executor da fila confirmou atividade recente. Verifique no próprio nó se o systemd informa falha, desligamento ou perda de rede.';
    return ['resposta'=>implode("\n",$linhas),'trabalhadores'=>$workers,'nos'=>$nodes];
}

function dd_resumo_lista(array $workers, array $nodes): array {
    $linhas=['Consulta à telemetria autenticada dos nós Linux; não foi executado um comando remoto pelo site.'];
    $completos=0;
    foreach($nodes as $n){
        $d=$n['diagnostico'];
        if (!$n['online']){
            $linhas[]='Nó '.$n['nome'].': sem contato recente; não é possível afirmar quais daemons estão rodando agora.';
            continue;
        }
        if (($d['running_status']??'')!=='ok') {
            $linhas[]='Nó '.$n['nome'].': online, porém a coleta dos daemons systemd está indisponível ou não foi instalada.';
            continue;
        }
        $completos++;
        $units=array_values(array_filter($d['running_services']??[],'is_string'));
        $linhas[]='Nó '.$n['nome'].': '.(int)($d['running_count']??count($units)).
            ' serviços systemd em execução, coletados em '.($d['checked_at']??'horário não informado').
            ($d['running_truncated']??false?' (lista limitada a 100)':'').'.';
        $linhas[]= $units ? implode(', ',array_slice($units,0,100)).'.' : 'Nenhum serviço na lista informada.';
    }
    if (!$completos) $linhas[]='Não há inventário atual de daemons em nenhum nó. Atualize o casa-node-agent.py e verifique se os heartbeats estão chegando à API.';
    $vivos=array_values(array_filter($workers,fn($w)=>!empty($w['ativo'])));
    $linhas[]='Executores de tarefas com heartbeat recente: '.($vivos?implode(', ',array_column($vivos,'nome')):'nenhum registrado').'.';
    return ['resposta'=>implode("\n",$linhas),'trabalhadores'=>$workers,'nos'=>$nodes];
}

function dd_resumo_plano(PDO $pdo,int $id,array $workers,array $nodes): array {
    $st=$pdo->prepare("SELECT id,status,demanda_original,criado_em,concluido_em FROM jarvis_planos WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$id]);
    $plan=$st->fetch(PDO::FETCH_ASSOC);
    if (!$plan){
        return ['resposta'=>'Não localizei o Plano número '.$id.' no banco. Um número de tarefa e um número de plano são identificadores diferentes.','plano'=>null];
    }
    $st=$pdo->prepare("SELECT id,titulo,executor,fila,status,tentativas,reservado_por,erro,depende_de,executar_em,
                        criado_em,iniciado_em,concluido_em
                        FROM jarvis_tarefas WHERE id_plano=:id ORDER BY ordem,id LIMIT 80");
    $st->execute([':id'=>$id]);
    $tarefas=$st->fetchAll(PDO::FETCH_ASSOC);
    $linhas=['Plano número '.$id.'. Solicitado em '.$plan['criado_em'].
        '. Situação registrada: '.$plan['status'].'. '.mb_substr((string)$plan['demanda_original'],0,180,'UTF-8').'.'];
    foreach($tarefas as $t) {
        $s=strtoupper((string)$t['status']);
        $exe=strtolower((string)$t['executor']);
        $fila=strtolower((string)($t['fila']?:'geral'));
        $linhas[]='Tarefa número '.$t['id'].': '.mb_substr((string)$t['titulo'],0,100,'UTF-8').
            ', estado '.$s.', executor '.$exe.', criada em '.$t['criado_em'].'.';
        if ($t['erro']) $linhas[]='Erro registrado: '.mb_substr((string)$t['erro'],0,300,'UTF-8').'.';
        if ($s==='AGENDADA' && $t['executar_em']) {
            $linhas[]='Programada para '.$t['executar_em'].'.';
        } elseif($s==='AGUARDANDO' && $t['depende_de']) {
            $linhas[]='Aguardando dependência da tarefa número '.$t['depende_de'].'.';
        } elseif(in_array($s,['PENDENTE','AGENDADA','AGUARDANDO','EXECUTANDO'],true)
            && !in_array($exe,['computer','planejador','ia','dispositivo','servidor'],true)) {
            $compativeis=[];
            foreach($workers as $w) {
                $filas=$w['filas'];
                if (!empty($w['ativo']) && in_array($exe,$w['executores'],true)
                    && ($fila==='geral' || $fila===$w['nome'] || in_array($fila,$filas,true))) {
                    $compativeis[]=$w['nome'];
                }
            }
            if (!$compativeis) {
                $linhas[]='Não há daemon com heartbeat recente compatível com executor '.$exe.
                    ' na fila '.$fila.'. A ausência de heartbeat não distingue um processo parado de uma falha de rede.';
            } else {
                $linhas[]='Daemon compatível com contato recente: '.implode(', ',$compativeis).
                    '. Ainda pode existir dependência, reserva ou erro de execução.';
            }
        }
    }
    if (!$tarefas) $linhas[]='Nenhuma subtarefa encontrada para esse plano.';
    $res=dd_resumo_trabalhador($workers,$nodes);
    $linhas[]=$res['resposta'];
    return ['resposta'=>implode("\n",$linhas),'plano'=>$plan,'tarefas'=>$tarefas,'trabalhadores'=>$workers,'nos'=>$nodes];
}

function dd_consultar(PDO $pdo,array $intencao): array {
    $workers=dd_trabalhadores($pdo);
    $nodes=dd_nos($pdo);
    if($intencao['tipo']==='lista')return dd_resumo_lista($workers,$nodes);
    if($intencao['tipo']==='trabalhador')return dd_resumo_trabalhador($workers,$nodes);
    if($intencao['tipo']==='plano')return dd_resumo_plano($pdo,(int)$intencao['id'],$workers,$nodes);
    return ['resposta'=>'Consulta desconhecida.'];
}
