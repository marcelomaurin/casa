<?php
// Diagnostico de tarefas para o JARVIS. Apenas leitura: nenhuma pergunta executa uma tarefa.
// A ausencia de heartbeat prova falta de contato, nao necessariamente processo desligado.

function cti_pergunta_sobre_tarefa(string $texto): bool {
    $q = mb_strtolower(trim($texto), 'UTF-8');
    if (!preg_match('/(taref|projet|plano|daemon|worker|trabalhador|fila de execu|solicita[cç][aã]o)/u', $q)) return false;
    return (bool)(
        preg_match('/^\s*(quando|por que|porque|pq|qual|quais|como|quem|onde|me diga|me informe|mostre|liste|consulte|verifique|status|diagn[oó]stico|hist[oó]rico)\b/u', $q)
        || preg_match('/(n[aã]o (rod|execut|inici)|ainda n[aã]o|pendente|parad[ao]|sem executar|data da solicita|data de cria)/u', $q)
        || str_contains($q, '?')
    );
}

function cti_trabalhadores(PDO $pdo): array {
    return $pdo->query("SELECT nome,executores,filas,ultimo_contato,
        TIMESTAMPDIFF(SECOND,ultimo_contato,NOW()) AS sem_contato
        FROM jarvis_trabalhadores ORDER BY ultimo_contato DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
}

function cti_ativo(array $w): bool {
    return $w['sem_contato'] !== null && (int)$w['sem_contato'] >= 0 && (int)$w['sem_contato'] <= 300;
}

function cti_compativel(array $t, array $w): bool {
    $executor = strtolower(trim((string)($t['executor'] ?? '')));
    $fila = strtolower(trim((string)($t['fila'] ?? '')));
    if ($executor === '' || $fila === '') return false;
    $executores = array_map('strtolower', cc_json($w['executores'] ?? null));
    $filas = array_map('strtolower', cc_json($w['filas'] ?? null));
    return in_array($executor, $executores, true)
        && ($fila === 'geral' || $fila === strtolower((string)$w['nome']) || in_array($fila, $filas, true));
}

function cti_palavras(string $pergunta): array {
    $stop = array_fill_keys(explode(' ', 'quando porque qual quais como quem onde tarefa tarefas projeto projetos plano planos daemon daemons worker workers trabalhador trabalhadores executou executar executada executado execucao rodou rodar rodando iniciado iniciar iniciada iniciado status pendente pendentes parada parado data datas datahora dia dias solicitacao solicitacoes solicitacoes solicitei solicitado solicitada solicitados solicitadas criacao criada criado criadas criados cadastrada cadastrado cadastradas cadastrados registrada registrado registradas registrados qual quais foi foram quaisdas tenho hoje ontem fiz meu minha meus minhas ainda nunca nao foi foram para pelo pela que esta estão estao uma uns umas sobre quero saber poderia me diga diga mostre verifique verifica olhe veja motivo razao isso aquele aquela desta desse dessa existe historico'), true);
    $terms = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($pergunta, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    $r = [];
    foreach ($terms as $word) {
        $normal = strtr($word, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
        if (mb_strlen($word, 'UTF-8') < 4 || isset($stop[$normal])) continue;
        $r[$word] = true;
    }
    return array_slice(array_keys($r), 0, 6);
}

function cti_motivo(array $t, array $workers, bool $telemetriaOk = true): string {
    $pid = (string)($t['projeto_status'] ?? '');
    if (empty($t['fila_id'])) {
        if ($pid === 'planejamento') return 'NAO DESPACHADA: projeto em planejamento; depende de aprovacao para entrar na fila';
        return 'SEM REGISTRO NA FILA: projeto/tarefa cadastrados, mas nao vinculados a uma execucao; verificar despacho';
    }
    $status = strtoupper((string)($t['status_execucao'] ?? ''));
    if ($status === 'CONCLUIDA') return 'Concluida; consultar resultado e comprovantes';
    if ($status === 'ERRO' || $status === 'FALHOU') return 'Falhou: ' . cc_txt($t['erro'] ?? 'sem descricao', 180);
    if ($status === 'EXECUTANDO') {
        $nome = (string)($t['reservado_por'] ?? '');
        if (!$telemetriaOk) return 'EXECUTANDO no banco, mas telemetria indisponivel; confirmar trabalhador ' . $nome;
        $worker = null;
        foreach ($workers as $w) if ($nome !== '' && strcasecmp($nome, (string)$w['nome']) === 0) { $worker = $w; break; }
        if ($worker === null) return 'EXECUTANDO no banco, mas sem heartbeat identificado do trabalhador ' . ($nome ?: 'nao informado');
        if (!cti_ativo($worker)) return 'EXECUTANDO no banco, mas worker ' . $nome . ' SEM CONTATO RECENTE (ultimo ' . cc_tempo($worker['ultimo_contato']) . '); possivel execucao interrompida';
        if (!empty($t['reservado_ate']) && strtotime($t['reservado_ate']) < time()) {
            return 'Worker com heartbeat recente, mas reserva vencida; verificar renovacao da tarefa';
        }
        return 'Executando com worker ' . $nome . ' com heartbeat recente';
    }
    if (!empty($t['depende_de']) && ($t['dependencia_status'] ?? '') !== 'CONCLUIDA') {
        return 'BLOQUEADA: depende da tarefa #' . $t['depende_de'] . ' (status ' . ($t['dependencia_status'] ?: 'indisponivel') . ')';
    }
    if (!empty($t['executar_em']) && strtotime($t['executar_em']) > time()) {
        return 'AGENDADA: horario previsto ainda nao chegou (' . $t['executar_em'] . ')';
    }
    if (($t['etapa_github'] ?? '') === 'acompanhar') {
        return 'Em acompanhamento GitHub: aguardando validacoes CI ou revisao/merge humano; verificar PR e checks';
    }
    if ($status === 'AGUARDANDO' || $status === 'AGUARDANDO_PARTES') {
        return 'Aguardando condicao, confirmacao de dispositivo ou conclusao de subtarefas; consultar dependencias';
    }
    $fila = (string)($t['fila'] ?? '');
    if ($fila === '') return 'Sem fila de worker: verificar fluxo de execucao direta/agendada pelo site';
    if (!$telemetriaOk) return 'Telemetria dos workers indisponivel: nao e possivel determinar se ha executor ativo';
    $compativeis = array_values(array_filter($workers, fn($w) => cti_compativel($t, $w)));
    $ativos = array_values(array_filter($compativeis, 'cti_ativo'));
    if (!$ativos) {
        if (!$compativeis) return 'SEM EXECUTOR COMPATIVEL REGISTRADO para executor=' . ($t['executor'] ?? '?') . ', fila=' . $fila;
        usort($compativeis, fn($a, $b) => strtotime($b['ultimo_contato'] ?? '1970-01-01') <=> strtotime($a['ultimo_contato'] ?? '1970-01-01'));
        $w = $compativeis[0];
        return 'SEM WORKER ATIVO (sem heartbeat recente); ultimo contato de ' . $w['nome'] . ': ' . cc_tempo($w['ultimo_contato']) . '. Possivel daemon parado, sem rede ou sem autorizacao';
    }
    return 'Worker compativel com heartbeat recente (' . implode(', ', array_column($ativos, 'nome')) . '), mas tarefa nao foi reservada; investigar logs, erro da API, agendamento e capacidade do executor';
}

function cti_contexto_tarefas(PDO $pdo, string $pergunta): string {
    if (!cti_pergunta_sobre_tarefa($pergunta)) return '';
    $saida = ['DIAGNOSTICO AO VIVO DE TAREFAS. Use somente os registros apresentados; nao invente datas ou causas.'];
    $telemetriaOk = true;
    try {
        $workers = cti_trabalhadores($pdo);
        $saida = array_merge($saida, cti_resumo_workers($workers));
    } catch (Throwable $e) {
        $workers = [];
        $telemetriaOk = false;
        $saida[] = 'Telemetria dos workers INDISPONIVEL: nao e possivel afirmar se o daemon esta ativo.';
    }
    $terms = cti_palavras($pergunta);
    $idBusca = null;
    if (preg_match('/(?:tarefa\s*#?|#)(\d{1,12})/ui', $pergunta, $m)) $idBusca = (int)$m[1];
    $tarefas = [];
    try {
        $sql = "SELECT pt.id AS id_cadastro,pt.titulo,pt.descricao,pt.criado_em AS solicitada_em,
                   pt.status AS status_cadastro,p.nome AS projeto,p.status AS projeto_status,
                   j.id AS fila_id,j.executor,j.fila,j.status AS status_execucao,
                   j.criado_em AS enfileirada_em,j.executar_em,j.iniciado_em,j.concluido_em,
                   j.tentativas,j.max_tentativas,j.reservado_por,j.reservado_ate,
                   j.depende_de,d.status AS dependencia_status,j.erro,JSON_UNQUOTE(JSON_EXTRACT(j.resultado, '$.etapa')) AS etapa_github
                FROM casa_projeto_tarefas pt
                JOIN casa_projetos p ON p.id=pt.projeto_id
                LEFT JOIN jarvis_tarefas j ON j.id=pt.jarvis_tarefa_id
                LEFT JOIN jarvis_tarefas d ON d.id=j.depende_de
                ORDER BY pt.criado_em DESC LIMIT 250";
        $tarefas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $saida[] = 'Consulta de projetos/tarefas indisponivel: verificar schema e conexao.';
    }
    try {
        $sql = "SELECT j.id AS id_cadastro,j.titulo,j.descricao,j.criado_em AS solicitada_em,
                   j.status AS status_cadastro,p.demanda_original AS projeto,p.status AS projeto_status,
                   j.id AS fila_id,j.executor,j.fila,j.status AS status_execucao,
                   j.criado_em AS enfileirada_em,j.executar_em,j.iniciado_em,j.concluido_em,
                   j.tentativas,j.max_tentativas,j.reservado_por,j.reservado_ate,
                   j.depende_de,d.status AS dependencia_status,j.erro,JSON_UNQUOTE(JSON_EXTRACT(j.resultado, '$.etapa')) AS etapa_github
                FROM jarvis_tarefas j JOIN jarvis_planos p ON p.id=j.id_plano
                LEFT JOIN jarvis_tarefas d ON d.id=j.depende_de
                WHERE j.fila IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM casa_projeto_tarefas ct WHERE ct.jarvis_tarefa_id=j.id)
                ORDER BY j.criado_em DESC LIMIT 250";
        $tarefas = array_merge($tarefas, $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $saida[] = 'Consulta de fila avulsa indisponivel; podem existir outras tarefas.';
    }
    $pontuadas = [];
    foreach ($tarefas as $i => $t) {
        $haystack = mb_strtolower((string)$t['titulo'] . ' ' . (string)$t['descricao'] . ' ' . (string)$t['projeto'], 'UTF-8');
        $score = count($terms) === 0 ? 1 : 0;
        foreach ($terms as $word) if (mb_stripos($haystack, $word, 0, 'UTF-8') !== false) $score++;
        if ($idBusca && ($idBusca === (int)$t['id_cadastro'] || $idBusca === (int)($t['fila_id'] ?? 0))) $score += 10;
        if ($score > 0) $pontuadas[] = ['score'=>$score,'idx'=>$i,'t'=>$t];
    }
    usort($pontuadas, fn($a,$b) => ($b['score'] <=> $a['score']) ?: ($b['idx'] <=> $a['idx']));
    if (!$pontuadas) $saida[] = 'Nenhuma tarefa correspondente identificada entre os registros recentes; solicitar ID ou titulo exato, sem inventar diagnostico.';
    foreach (array_slice($pontuadas, 0, 5) as $item) {
        $t = $item['t'];
        $saida[] = '- Tarefa #' . $t['id_cadastro'] . ' (' . cc_txt($t['titulo'], 110) . '), projeto ' . cc_txt($t['projeto'], 65) .
            '; solicitada em: ' . ($t['solicitada_em'] ?: 'nao informada') .
            '; enfileirada em: ' . ($t['enfileirada_em'] ?: 'nao enfileirada') .
            '; status cadastro: ' . $t['status_cadastro'] .
            '; status execucao: ' . ($t['status_execucao'] ?: 'sem execucao') .
            '; executor/fila: ' . ($t['executor'] ?: '-') . '/' . ($t['fila'] ?: '-') .
            '; tentativas: ' . ($t['tentativas'] ?? 0) . '/' . ($t['max_tentativas'] ?? '-') .
            '; inicio: ' . ($t['iniciado_em'] ?: 'nao iniciado') .
            '; termino: ' . ($t['concluido_em'] ?: 'nao concluido') .
            '; diagnostico: ' . cti_motivo($t, $workers, $telemetriaOk);
    }
    $saida[] = 'Se nao houver trabalhador com heartbeat nos ultimos 300 segundos, relatar SEM CONTATO, nao afirmar com certeza que o processo systemd foi desligado. Informar o que precisa ser verificado.';
    return mb_substr(implode("\n", $saida), 0, 6500, 'UTF-8');
}

function cti_resumo_workers(array $workers): array {
    $l = ['TELEMETRIA DOS EXECUTORES (heartbeat com validade de 300 s):'];
    if (!$workers) return array_merge($l, ['- Nenhum worker registrado na tabela jarvis_trabalhadores.']);
    foreach (array_slice($workers, 0, 8) as $w) {
        $l[] = '- ' . cc_txt($w['nome'], 50) . ': ' .
            (cti_ativo($w) ? 'COM CONTATO RECENTE' : 'SEM CONTATO RECENTE') .
            '; ultimo contato: ' . cc_tempo($w['ultimo_contato']) .
            '; executores: ' . cc_txt(implode(',', cc_json($w['executores'])), 70);
    }
    return $l;
}


/** Perguntas factuais sobre datas nao devem ser replanejadas nem respondidas por adivinhacao. */
function cti_pergunta_datas_tarefas(string $pergunta): bool {
    $q = mb_strtolower(trim($pergunta), 'UTF-8');
    $q = strtr($q, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    if (!preg_match('/\b(tarefa(s)?|solicitacao|solicitacoes|projeto(s)?|plano(s)?)\b/', $q)) return false;
    // Nao confundir data historica com pedido de agendamento futuro.
    if (preg_match('/\b(agendar|agende|agendamento|marque|programar|programe|amanha|futur[ao])\b/', $q)) return false;
    if (preg_match('/\b(data(s)?|dia(s)?)\b/', $q) &&
        preg_match('/\b(qual|quais|quando|liste|list[ae]|mostre|me informe|me diga|informe)\b/', $q)) return true;
    if (preg_match('/\bquando\b/', $q) &&
        preg_match('/(solicit|criad|criei|cadastr|registr|pedid|pedi|abert|abri)/', $q)) return true;
    return false;
}

/** Exibe o horario persistido pelo banco sem converter para um fuso nao confirmado. */
function cti_formatar_data($valor): string {
    $s = trim((string)$valor);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $s, $m))
        return 'nao registrada';
    return $m[3].'/'.$m[2].'/'.$m[1].' '.$m[4].':'.$m[5];
}

/** Filtra por ID ou palavras do titulo; sem filtro, lista as tarefas mais recentes. */
function cti_selecionar_datas(array $tarefas, string $pergunta, int $limite = 20): array {
    $termos = cti_palavras($pergunta);
    $id = null;
    if (preg_match('/(?:tarefa\s*#?|#)(\d{1,12})/ui', $pergunta, $m)) $id = (int)$m[1];
    $out = [];
    foreach ($tarefas as $t) {
        if ($id !== null && $id !== (int)($t['id_tarefa'] ?? 0) &&
            $id !== (int)($t['id_fila'] ?? 0)) continue;
        $txt = mb_strtolower((string)($t['titulo'] ?? '') . ' ' . (string)($t['projeto'] ?? ''), 'UTF-8');
        $score = 0;
        foreach ($termos as $p) if (mb_stripos($txt, $p, 0, 'UTF-8') !== false) $score++;
        if ($termos && $score === 0 && $id === null) continue;
        $t['_score'] = $score;
        $out[] = $t;
    }
    usort($out, static function ($a, $b) use ($termos) {
        if ($termos && $a['_score'] !== $b['_score']) return $b['_score'] <=> $a['_score'];
        return strcmp((string)$b['solicitada_em'], (string)$a['solicitada_em']);
    });
    return array_slice($out, 0, max(1, min($limite, 50)));
}

/** Retorna as datas reais das solicitacoes, inclusive projetos ainda nao aprovados. */
function cti_responder_datas_tarefas(PDO $pdo, string $pergunta): array {
    $tarefas = []; $consultasOk = 0; $fontesComErro = [];
    try {
        $sql = "SELECT t.id AS id_tarefa,t.titulo,p.nome AS projeto,
                   t.criado_em AS solicitada_em,p.criado_em AS projeto_criado_em,
                   j.criado_em AS enfileirada_em,j.id AS id_fila,
                   COALESCE(j.status,t.status) AS status
                FROM casa_projeto_tarefas t
                JOIN casa_projetos p ON p.id=t.projeto_id
                LEFT JOIN jarvis_tarefas j ON j.id=t.jarvis_tarefa_id
                ORDER BY t.criado_em DESC,t.id DESC LIMIT 250";
        $tarefas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); $consultasOk++;
    } catch (Throwable $e) { $fontesComErro[] = 'projetos'; }
    try {
        $sql = "SELECT j.id AS id_tarefa,j.titulo,p.demanda_original AS projeto,
                   p.criado_em AS solicitada_em,p.criado_em AS projeto_criado_em,
                   j.criado_em AS enfileirada_em,j.id AS id_fila,
                   j.status
                FROM jarvis_tarefas j JOIN jarvis_planos p ON p.id=j.id_plano
                WHERE j.fila IS NOT NULL AND NOT EXISTS
                   (SELECT 1 FROM casa_projeto_tarefas t WHERE t.jarvis_tarefa_id=j.id)
                ORDER BY j.criado_em DESC,j.id DESC LIMIT 250";
        $tarefas = array_merge($tarefas,$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)); $consultasOk++;
    } catch (Throwable $e) { $fontesComErro[] = 'fila de tarefas'; }
    if ($consultasOk === 0) return ['ok'=>false,'resposta'=>'Nao consegui consultar as datas das tarefas no banco do Casa. Verifique a conexao e as migracoes.','tarefas'=>[]];
    $selecionadas = cti_selecionar_datas($tarefas, $pergunta);
    if (!$selecionadas) {
        return ['ok'=>true,'resposta'=>'Nao encontrei tarefas correspondentes nos registros consultados. Informe o titulo ou ID para uma busca mais precisa.','tarefas'=>[]];
    }
    $linhas = ['Datas de solicitacao registradas no banco do Casa (hora informada pelo banco):'];
    $dados = [];
    foreach ($selecionadas as $t) {
        $id=(int)$t['id_tarefa'];
        $titulo=cc_txt($t['titulo'],90);
        $projeto=cc_txt($t['projeto'] ?? '',75);
        $data=cti_formatar_data($t['solicitada_em'] ?? '');
        $status=(string)($t['status'] ?? 'nao informado');
        $linha="- Tarefa #$id, $titulo: solicitada em $data; status $status";
        if ($projeto !== '') $linha.='; projeto: '.$projeto;
        if (!empty($t['enfileirada_em'])) $linha.='; entrou na fila em '.cti_formatar_data($t['enfileirada_em']);
        $linhas[]=$linha.'.';
        $dados[]=['id'=>$id,'titulo'=>$titulo,'projeto'=>$projeto,
            'solicitada_em'=>$t['solicitada_em'] ?? null,
            'projeto_criado_em'=>$t['projeto_criado_em'] ?? null,
            'enfileirada_em'=>$t['enfileirada_em'] ?? null,'status'=>$status];
    }
    if (count($tarefas) > count($selecionadas) && !cti_palavras($pergunta))
        $linhas[]='Mostrando as '.count($selecionadas).' tarefas mais recentes dentre os registros consultados; outras podem existir.';
    if ($fontesComErro) $linhas[]='Aviso: consulta parcial; fonte indisponivel: '.implode(', ',$fontesComErro).'.';
    return ['ok'=>true,'resposta'=>implode("\n",$linhas),'tarefas'=>$dados];
}
