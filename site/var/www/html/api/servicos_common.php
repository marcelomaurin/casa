<?php
// CASA/JARVIS - Estado dos servicos em segundo plano (os que ja existem em clusters/).
//
// Nenhum servico precisa mudar: o estado vem do rastro que cada um ja deixa no
// banco (sinal de vida, registro no historico, ultima execucao). Tres tipos:
//   periodico   -> manda sinal em intervalo fixo: ATIVO se o ultimo sinal esta
//                  dentro da janela esperada, PARADO se passou dela;
//   sob demanda -> so trabalha quando chamado: mostra o ultimo uso, sem alerta;
//   sem rastro  -> nao deixa nada no site: aparece como SEM SINAL, sem chute.
// Agendador e monitor do cluster tem verificacao propria (veja abaixo).
//
// Usado por api/servicos_status.php (tela de servicos) e por
// api/painel_estatisticas.php (cartao e analise da area SISTEMA).

// Segundos desde o ultimo registro (calculado no proprio banco, sem fuso do PHP).
function serv_idade(PDO $pdo, string $sql): ?int {
    try {
        $v = $pdo->query($sql)->fetchColumn();
        return ($v === false || $v === null) ? null : max(0, (int)$v);
    } catch (Throwable $e) {
        return null;
    }
}

function serv_contagem(PDO $pdo, string $sql): ?int {
    try {
        $v = $pdo->query($sql)->fetchColumn();
        return $v === false ? null : (int)$v;
    } catch (Throwable $e) {
        return null;
    }
}

// Registros por dia nos ultimos 7 dias (do mais antigo para hoje).
function serv_serie(PDO $pdo, string $tabela, string $coluna, string $filtro = ''): ?array {
    try {
        // "Ha quantos dias" e calculado pelo relogio do banco: se PHP e banco
        // estiverem em fusos diferentes, as chaves montadas no PHP deslizariam
        // um dia (o "hoje" saia zerado).
        $sql = "SELECT DATEDIFF(CURDATE(), DATE(`{$coluna}`)) AS atras, COUNT(*) AS n FROM `{$tabela}`
                WHERE `{$coluna}` >= CURDATE() - INTERVAL 6 DAY AND `{$coluna}` < CURDATE() + INTERVAL 1 DAY" . ($filtro ? " AND ({$filtro})" : '') . "
                GROUP BY atras";
        $serie = array_fill(0, 7, 0);
        foreach ($pdo->query($sql) as $r) {
            $atras = (int)$r['atras'];
            if ($atras >= 0 && $atras <= 6) $serie[6 - $atras] = (int)$r['n'];
        }
        return $serie;
    } catch (Throwable $e) {
        return null;
    }
}

function servicos_status(PDO $pdo): array {
    $arm = "(tipo IN ('linux-arm','arm-agent') OR capabilities LIKE '%arm-agent%')";
    $armD = "(d.tipo IN ('linux-arm','arm-agent') OR d.capabilities LIKE '%arm-agent%')";
    $s = [];

    // --- Periodicos -----------------------------------------------------------
    $s[] = [
        'id' => 'node-agent', 'nome' => 'Agente dos nós ARM', 'unidade' => 'casa-node-agent.service',
        'funcao' => 'Sinal de vida, comandos e resultados de cada nó Linux ARM',
        'tipo' => 'periodico', 'janela' => 120,
        'idade' => serv_idade($pdo, "SELECT TIMESTAMPDIFF(SECOND, MAX(h.criado_em), NOW()) FROM device_heartbeats h JOIN dispositivos_cluster d ON d.device_id = h.device_id WHERE {$armD}"),
        'detalhe' => (function () use ($pdo, $arm) {
            $t = serv_contagem($pdo, "SELECT COUNT(*) FROM dispositivos_cluster WHERE {$arm}");
            $o = serv_contagem($pdo, "SELECT COUNT(*) FROM dispositivos_cluster WHERE {$arm} AND ultimo_heartbeat >= NOW() - INTERVAL 60 SECOND");
            return $t === null ? null : "{$o} de {$t} nó(s) com sinal no último minuto";
        })(),
        'serie' => serv_serie($pdo, 'device_heartbeats', 'criado_em', "device_id IN (SELECT device_id FROM dispositivos_cluster WHERE {$arm})"),
        'serie_legenda' => 'sinais de vida por dia',
    ];
    $s[] = [
        'id' => 'avatar', 'nome' => 'Terminal Avatar', 'unidade' => 'casa-avatar.service',
        'funcao' => 'Terminal Raspberry Pi com Kinect: visão e voz',
        'tipo' => 'periodico', 'janela' => 120,
        'idade' => serv_idade($pdo, "SELECT TIMESTAMPDIFF(SECOND, MAX(criado_em), NOW()) FROM device_heartbeats WHERE device_id LIKE 'avatar%'"),
        'serie' => serv_serie($pdo, 'device_heartbeats', 'criado_em', "device_id LIKE 'avatar%'"),
        'serie_legenda' => 'sinais de vida por dia',
    ];
    $s[] = [
        'id' => 'google-home', 'nome' => 'Ponte Google Home', 'unidade' => 'casa-google-home.service',
        'funcao' => 'Descobre caixas de som e reproduz as respostas do assistente',
        'tipo' => 'periodico', 'janela' => 120,
        'idade' => serv_idade($pdo, "SELECT TIMESTAMPDIFF(SECOND, MAX(ultimo_heartbeat), NOW()) FROM dispositivos_cluster WHERE tipo = 'google_home'"),
        'serie' => serv_serie($pdo, 'comandos_log', 'data_hora', "origem = 'GOOGLE_HOME_HARDWARE'"),
        'serie_legenda' => 'comandos por dia',
    ];
    $s[] = [
        'id' => 'espcam', 'nome' => 'Serviço das câmeras', 'unidade' => 'espcam_daemon.py',
        'funcao' => 'Captura as câmeras, publica os quadros e mede o sinal Wi-Fi delas',
        'tipo' => 'periodico', 'janela' => 300,
        'idade' => serv_idade($pdo, "SELECT TIMESTAMPDIFF(SECOND, MAX(coletado_em), NOW()) FROM camera_wifi_survey"),
        'serie' => serv_serie($pdo, 'camera_wifi_survey', 'coletado_em'),
        'serie_legenda' => 'medições por dia',
    ];

    // --- Verificacao propria -------------------------------------------------
    // Agendador: atrasado se ha tarefa ativa vencida ha mais de 5 min sem rodar.
    $atrasadas = serv_contagem($pdo, "SELECT COUNT(*) FROM tarefas_agendadas WHERE ativo = 1 AND proxima_execucao IS NOT NULL AND proxima_execucao < NOW() - INTERVAL 5 MINUTE");
    $s[] = [
        'id' => 'scheduler', 'nome' => 'Agendador', 'unidade' => 'casa-scheduler.service',
        'funcao' => 'Executa os agendamentos e as tarefas planejadas pelo assistente',
        'tipo' => 'agendador', 'atrasadas' => $atrasadas,
        'idade' => serv_idade($pdo, "SELECT TIMESTAMPDIFF(SECOND, MAX(ultima_execucao), NOW()) FROM tarefas_agendadas"),
        'detalhe' => $atrasadas ? "{$atrasadas} tarefa(s) vencida(s) sem executar" : null,
        'serie' => serv_serie($pdo, 'comandos_log', 'data_hora', "origem = 'SCHEDULER'"),
        'serie_legenda' => 'execuções por dia',
    ];
    // Monitor do cluster: marca como offline o no sem sinal ha 40 s. Se ha no
    // marcado como online mas sem sinal ha mais de 2 min, o monitor parou.
    $orfaos = serv_contagem($pdo, "SELECT COUNT(*) FROM dispositivos_cluster WHERE {$arm} AND status = 'online' AND (ultimo_heartbeat IS NULL OR ultimo_heartbeat < NOW() - INTERVAL 120 SECOND)");
    $s[] = [
        'id' => 'cluster-monitor', 'nome' => 'Monitor do cluster', 'unidade' => 'casa-cluster-monitor.service',
        'funcao' => 'Marca como offline o nó que para de mandar sinal de vida',
        'tipo' => 'monitor', 'orfaos' => $orfaos,
        'detalhe' => $orfaos === null ? null : ($orfaos ? "{$orfaos} nó(s) ainda marcado(s) como online sem sinal" : 'todo nó marcado como online tem sinal recente'),
    ];

    // --- Sob demanda ----------------------------------------------------------
    $s[] = [
        'id' => 'tts', 'nome' => 'Síntese de voz', 'unidade' => 'casa-tts.service',
        'funcao' => 'Gera o áudio das falas do assistente',
        'tipo' => 'demanda',
        'idade' => serv_idade($pdo, "SELECT TIMESTAMPDIFF(SECOND, MAX(COALESCE(processado_em, criado_em)), NOW()) FROM falas"),
        'serie' => serv_serie($pdo, 'falas', 'criado_em'),
        'serie_legenda' => 'falas por dia',
    ];
    $ultimoErro = null;
    try { $ultimoErro = $pdo->query("SELECT status FROM internet_pesquisas ORDER BY data_hora DESC LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}
    $s[] = [
        'id' => 'web-agent', 'nome' => 'Pesquisa na internet', 'unidade' => 'jarvis-web-agent.service',
        'funcao' => 'Pesquisa e extrai páginas para o assistente',
        'tipo' => 'demanda',
        'idade' => serv_idade($pdo, "SELECT TIMESTAMPDIFF(SECOND, MAX(data_hora), NOW()) FROM internet_pesquisas"),
        'detalhe' => ($ultimoErro && stripos((string)$ultimoErro, 'erro') !== false) ? 'a última pesquisa terminou com erro' : null,
        'serie' => serv_serie($pdo, 'internet_pesquisas', 'data_hora'),
        'serie_legenda' => 'pesquisas por dia',
    ];
    $url = null;
    try { $url = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'external_tunnel_url' LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}
    $s[] = [
        'id' => 'tunnel', 'nome' => 'Túnel de acesso externo', 'unidade' => 'casa-tunnel.service',
        'funcao' => 'Publica um endereço para acessar a casa de fora',
        'tipo' => 'demanda',
        'idade' => serv_idade($pdo, "SELECT TIMESTAMPDIFF(SECOND, MAX(data_hora), NOW()) FROM comandos_log WHERE origem = 'TUNEL_EXTERNO'"),
        'detalhe' => $url ? 'endereço publicado' : 'nenhum endereço publicado',
        'serie' => serv_serie($pdo, 'comandos_log', 'data_hora', "origem = 'TUNEL_EXTERNO'"),
        'serie_legenda' => 'publicações por dia',
    ];

    // --- Sem rastro no site ---------------------------------------------------
    foreach ([
        ['runpod-agent', 'Agente RunPod', 'casa-runpod-agent.service', 'Liga e desliga a GPU remota e o acesso SSH a ela'],
        ['ssh-agent', 'Acesso SSH aos nós', 'casa-ssh-agent.service', 'Executa comandos nos nós do cluster por SSH'],
        ['cluster-site', 'Site local do cluster', 'casa-cluster-site.service', 'Painel local servido dentro da rede da casa'],
    ] as [$id, $nome, $unidade, $funcao]) {
        $s[] = ['id' => $id, 'nome' => $nome, 'unidade' => $unidade, 'funcao' => $funcao, 'tipo' => 'sem_rastro'];
    }

    // Estado final de cada servico.
    foreach ($s as &$x) {
        $idade = $x['idade'] ?? null;
        switch ($x['tipo']) {
            case 'periodico':
                $x['estado'] = $idade === null ? 'sem_sinal' : ($idade <= $x['janela'] ? 'ativo' : 'parado');
                break;
            case 'agendador':
                $x['estado'] = ($x['atrasadas'] ?? 0) > 0 ? 'atrasado' : ($idade === null ? 'sem_sinal' : 'ativo');
                break;
            case 'monitor':
                $x['estado'] = $x['orfaos'] === null ? 'sem_sinal' : ($x['orfaos'] > 0 ? 'parado' : 'ativo');
                break;
            case 'demanda':
                $x['estado'] = $idade === null ? 'sem_uso' : 'sob_demanda';
                break;
            default:
                $x['estado'] = 'sem_sinal';
        }
        if (isset($x['serie']) && $x['serie'] === null) unset($x['serie'], $x['serie_legenda']);
        unset($x['janela'], $x['atrasadas'], $x['orfaos']);
    }
    unset($x);
    return $s;
}

// Resumo para o cartao: servicos ativos de quantos podem ser verificados.
function servicos_resumo(array $lista): array {
    $verificaveis = array_values(array_filter($lista, fn($x) => in_array($x['estado'], ['ativo', 'parado', 'atrasado'], true)));
    $ativos = count(array_filter($verificaveis, fn($x) => $x['estado'] === 'ativo'));
    return ['ativos' => $ativos, 'verificaveis' => count($verificaveis),
            'problemas' => array_values(array_map(fn($x) => $x['nome'], array_filter($verificaveis, fn($x) => $x['estado'] !== 'ativo')))];
}
