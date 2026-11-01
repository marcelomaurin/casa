<?php
// CASA/JARVIS - Retrato AO VIVO da casa para a IA.
//
// A cada pedido, o JARVIS recebe um resumo compacto do que está acontecendo agora,
// lido do banco: dispositivos e seus estados, sensores, nós, alertas, eventos,
// últimos comandos e tarefas em andamento. Sem isso a IA só tinha a documentação
// do projeto e respondia "consulte a API" em vez de dizer o que está ligado.
//
// Cada seção é independente: se uma tabela não existir nesta instalação, só ela some.
// O texto tem teto de caracteres (configuracoes_sistema.jarvis_contexto_max_chars).

function cc_tempo(?string $quando): string {
    if (!$quando) return 'nunca';
    $t = strtotime($quando);
    if (!$t) return '?';
    $s = time() - $t;
    if ($s < 0) return 'agora';
    if ($s < 90) return 'há ' . $s . ' s';
    if ($s < 5400) return 'há ' . round($s / 60) . ' min';
    if ($s < 172800) return 'há ' . round($s / 3600) . ' h';
    return 'há ' . round($s / 86400) . ' dias';
}

function cc_json($v): array {
    if (is_array($v)) return $v;
    if (!is_string($v) || $v === '') return [];
    $d = json_decode($v, true);
    return is_array($d) ? $d : [];
}

function cc_txt($v, int $max = 60): string {
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
    return mb_strlen($v, 'UTF-8') > $max ? mb_substr($v, 0, $max - 1, 'UTF-8') . '…' : $v;
}

function cc_ligado($v): ?string {
    if ($v === null || $v === '') return null;
    if (is_bool($v)) return $v ? 'ligado' : 'desligado';
    $s = strtolower(trim((string)$v));
    if (in_array($s, ['1','on','true','ligado','ligada','aberto'], true)) return 'ligado';
    if (in_array($s, ['0','off','false','desligado','desligada','fechado'], true)) return 'desligado';
    return null;
}

/** Executa uma seção sem deixar erro de banco escapar. */
function cc_secao(callable $fn): array {
    try { $r = $fn(); return is_array($r) ? $r : []; } catch (Throwable $e) { return []; }
}

/** Dispositivos do cluster com estado, presença e último contato. */
function cc_dispositivos(PDO $pdo, int $limite): array {
    $rows = $pdo->query("SELECT * FROM dispositivos_cluster ORDER BY (status='online') DESC, nome LIMIT " . (int)($limite + 1))->fetchAll(PDO::FETCH_ASSOC);
    $desejado = [];
    try {
        foreach ($pdo->query("SELECT device_id, desired_state FROM device_desired_state")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $desejado[$d['device_id']] = cc_json($d['desired_state']);
        }
    } catch (Throwable $e) {}

    $ativos = array_values(array_filter($rows, fn($r) => empty($r['credential_revoked_at']) && (!array_key_exists('ativo', $r) || (int)$r['ativo'] === 1)));
    $total = count($ativos);
    $online = count(array_filter($ativos, fn($r) => strtolower((string)$r['status']) === 'online'));
    $linhas = ["DISPOSITIVOS: {$total} cadastrado(s), {$online} online, " . ($total - $online) . " fora do ar."];
    foreach (array_slice($ativos, 0, $limite) as $r) {
        $meta = cc_json($r['metadata'] ?? null);
        $des = $desejado[$r['device_id']] ?? [];
        $estado = null;
        foreach ([$meta['relay_on'] ?? null, $meta['power'] ?? null, $meta['estado'] ?? null, $des['relay_on'] ?? null] as $cand) {
            if (($estado = cc_ligado($cand)) !== null) break;
        }
        $partes = [cc_txt($r['nome'] ?: $r['device_id'], 40) . ' (' . $r['device_id'] . (isset($r['tipo']) && $r['tipo'] !== '' ? ', ' . $r['tipo'] : '') . ')'];
        $partes[] = strtolower((string)$r['status']) === 'online' ? 'online' : 'FORA DO AR';
        if ($estado) $partes[] = $estado;
        if (!empty($r['reles_status'])) {
            $rel = cc_json($r['reles_status']);
            if ($rel) {
                $desc = [];
                foreach ($rel as $k => $v) { $e = cc_ligado(is_array($v) ? ($v['state'] ?? $v['estado'] ?? null) : $v); $desc[] = 'relé ' . $k . ' ' . ($e ?? cc_txt(is_scalar($v) ? $v : json_encode($v), 10)); }
                $partes[] = implode(', ', array_slice($desc, 0, 8));
            } else {
                $partes[] = 'relés: ' . cc_txt($r['reles_status'], 40);
            }
        }
        if (!empty($r['localizacao'])) $partes[] = 'local: ' . cc_txt($r['localizacao'], 30);
        if (isset($r['battery_pct']) && $r['battery_pct'] !== null) $partes[] = 'bateria ' . (int)$r['battery_pct'] . '%';
        if (!empty($r['health']) && !in_array(strtolower((string)$r['health']), ['ok','online'], true)) $partes[] = 'saúde: ' . cc_txt($r['health'], 20);
        $partes[] = 'último contato ' . cc_tempo($r['ultimo_heartbeat'] ?? null);
        $linhas[] = '- ' . implode(' | ', $partes);
    }
    if (count($ativos) > $limite) $linhas[] = '- (lista resumida; há mais dispositivos)';
    return $linhas;
}

/** Equipamentos do cadastro antigo (luz da sala, irrigação...) e seus parâmetros. */
function cc_equipamentos(PDO $pdo): array {
    $rows = $pdo->query("SELECT d.iddevice, d.devname, p.devparname, p.devvalue, p.atualizado_em
        FROM devices d JOIN devpar p ON p.iddevice=d.iddevice ORDER BY d.iddevice, p.devparname LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $porDev = [];
    foreach ($rows as $r) {
        $v = $r['devvalue'];
        $leg = $r['devparname'] === 'dev1' ? (cc_ligado($v) ?? $v) : ($r['devparname'] . '=' . cc_txt($v, 20));
        $porDev[$r['iddevice']]['nome'] = $r['devname'];
        $porDev[$r['iddevice']]['vals'][] = $leg;
        $porDev[$r['iddevice']]['quando'] = max($porDev[$r['iddevice']]['quando'] ?? '', (string)$r['atualizado_em']);
    }
    $linhas = ['EQUIPAMENTOS (cadastro clássico):'];
    foreach ($porDev as $id => $d) {
        $linhas[] = '- #' . $id . ' ' . cc_txt($d['nome'], 40) . ': ' . implode(', ', $d['vals']) . ' (alterado ' . cc_tempo($d['quando']) . ')';
    }
    return $linhas;
}

/** Última leitura de cada sensor nas últimas 24 h (três fontes). */
function cc_sensores(PDO $pdo): array {
    $linhas = [];
    $nomes = [];
    try { foreach ($pdo->query("SELECT iddevice, devname FROM devices")->fetchAll(PDO::FETCH_ASSOC) as $d) $nomes[$d['iddevice']] = $d['devname']; } catch (Throwable $e) {}
    try {
        $rows = $pdo->query("SELECT s.* FROM sensores_telemetria s JOIN (
                SELECT MAX(id) mid FROM sensores_telemetria WHERE data_hora >= NOW() - INTERVAL 1 DAY GROUP BY iddevice, sensor_nome
            ) m ON m.mid=s.id ORDER BY s.data_hora DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $linhas[] = '- ' . cc_txt($r['sensor_nome'], 30) . (isset($nomes[$r['iddevice']]) ? ' (' . cc_txt($nomes[$r['iddevice']], 25) . ')' : '')
                . ': ' . rtrim(rtrim(number_format((float)$r['valor_numerico'], 2, ',', ''), '0'), ',') . ' ' . cc_txt($r['unidade'] ?? '', 8)
                . ' (' . cc_tempo($r['data_hora']) . ')';
        }
    } catch (Throwable $e) {}
    try {
        // id_dispositivo aponta para dispositivos_cluster.id (número interno): mostra o nome.
        $rows = $pdo->query("SELECT i.*, COALESCE(d.nome, d.device_id) AS nome_disp FROM iot_leituras i JOIN (
                SELECT MAX(id) mid FROM iot_leituras WHERE data_hora >= NOW() - INTERVAL 1 DAY GROUP BY id_dispositivo, tipo_sensor
            ) m ON m.mid=i.id LEFT JOIN dispositivos_cluster d ON d.id=i.id_dispositivo ORDER BY i.data_hora DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $v = [];
            if ($r['temperatura_c'] !== null) $v[] = number_format((float)$r['temperatura_c'], 1, ',', '') . ' °C';
            if ($r['umidade_pct'] !== null) $v[] = number_format((float)$r['umidade_pct'], 0, ',', '') . '% umidade';
            if ($v) $linhas[] = '- ' . cc_txt($r['tipo_sensor'] ?: 'sensor', 20) . ' (' . cc_txt($r['nome_disp'] ?? $r['id_dispositivo'], 30) . '): ' . implode(', ', $v) . ' (' . cc_tempo($r['data_hora']) . ')';
        }
    } catch (Throwable $e) {}
    try {
        $rows = $pdo->query("SELECT e.* FROM device_environment_readings e JOIN (
                SELECT MAX(id) mid FROM device_environment_readings WHERE observed_at >= NOW() - INTERVAL 1 DAY GROUP BY device_id
            ) m ON m.mid=e.id ORDER BY e.observed_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $v = [];
            if ($r['temperature_c'] !== null) $v[] = number_format((float)$r['temperature_c'], 1, ',', '') . ' °C';
            if ($r['humidity_pct'] !== null) $v[] = number_format((float)$r['humidity_pct'], 0, ',', '') . '% umidade';
            if ($v) $linhas[] = '- ambiente (' . cc_txt($r['device_id'], 30) . '): ' . implode(', ', $v) . ' (' . cc_tempo($r['observed_at']) . ')';
        }
    } catch (Throwable $e) {}
    return $linhas ? array_merge(['SENSORES (última leitura de cada um, 24 h):'], $linhas) : ['SENSORES: nenhuma leitura nas últimas 24 h.'];
}

function cc_nos(PDO $pdo): array {
    $rows = $pdo->query("SELECT hostname, device_id, papel, status, ultimo_ping FROM arm_nodes ORDER BY status, hostname LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $l = ['NÓS DO CLUSTER:'];
    foreach ($rows as $r) {
        $l[] = '- ' . cc_txt($r['hostname'] ?: $r['device_id'], 30) . ($r['papel'] ? ' (' . cc_txt($r['papel'], 20) . ')' : '') . ': ' . ($r['status'] ?: '?') . ', último contato ' . cc_tempo($r['ultimo_ping']);
    }
    return $l;
}

/** Alertas e eventos que pedem atenção. */
function cc_alertas(PDO $pdo): array {
    $l = [];
    try {
        $rows = $pdo->query("SELECT tipo, severidade, mensagem, pessoa_ref, criado_em FROM assistencia_eventos
            WHERE confirmado=0 AND criado_em >= NOW() - INTERVAL 1 DAY ORDER BY criado_em DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $l[] = '- PEDIDO DE ASSISTÊNCIA NÃO CONFIRMADO: ' . cc_txt($r['tipo'], 20) . ' / ' . cc_txt($r['severidade'], 12) . ($r['pessoa_ref'] ? ' / ' . cc_txt($r['pessoa_ref'], 25) : '') . ': ' . cc_txt($r['mensagem'], 80) . ' (' . cc_tempo($r['criado_em']) . ')';
    } catch (Throwable $e) {}
    try {
        $r = $pdo->query("SELECT COUNT(*) n, MAX(data_hora) ult FROM seguranca_logs WHERE severidade IN ('ALTO','CRITICO','CRÍTICO') AND data_hora >= NOW() - INTERVAL 1 DAY")->fetch(PDO::FETCH_ASSOC);
        if ($r && (int)$r['n'] > 0) $l[] = '- Segurança: ' . (int)$r['n'] . ' evento(s) grave(s) em 24 h, o último ' . cc_tempo($r['ult']) . '.';
    } catch (Throwable $e) {}
    try {
        $r = $pdo->query("SELECT COUNT(*) n, SUM(movimento=1) mov, SUM(quantidade_faces>0) faces, MAX(data_hora) ult FROM camera_eventos WHERE data_hora >= NOW() - INTERVAL 6 HOUR")->fetch(PDO::FETCH_ASSOC);
        if ($r && (int)$r['n'] > 0) {
            $l[] = '- Câmeras (6 h): ' . (int)$r['n'] . ' evento(s), ' . (int)$r['mov'] . ' com movimento, ' . (int)$r['faces'] . ' com rosto; o último ' . cc_tempo($r['ult']) . '.';
            $ult = $pdo->query("SELECT nome_dispositivo, localizacao, resposta_ia, data_hora FROM camera_eventos WHERE data_hora >= NOW() - INTERVAL 6 HOUR AND resposta_ia IS NOT NULL AND resposta_ia<>'' ORDER BY data_hora DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ult as $u) $l[] = '  · ' . cc_txt($u['nome_dispositivo'] ?: $u['localizacao'], 25) . ' (' . cc_tempo($u['data_hora']) . '): ' . cc_txt($u['resposta_ia'], 110);
        }
    } catch (Throwable $e) {}
    try {
        $rows = $pdo->query("SELECT device_id, tipo, criado_em FROM device_events WHERE prioridade IN ('high','critical','alta','critica') AND criado_em >= NOW() - INTERVAL 6 HOUR ORDER BY criado_em DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $l[] = '- Evento de dispositivo: ' . cc_txt($r['device_id'], 30) . ' — ' . cc_txt($r['tipo'], 30) . ' (' . cc_tempo($r['criado_em']) . ')';
    } catch (Throwable $e) {}
    return $l ? array_merge(['ALERTAS E EVENTOS:'], $l) : ['ALERTAS E EVENTOS: nada pendente.'];
}

function cc_comandos(PDO $pdo): array {
    $rows = $pdo->query("SELECT comando, origem, resultado, data_hora FROM comandos_log WHERE data_hora >= NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $l = ['ÚLTIMOS COMANDOS (24 h):'];
    foreach ($rows as $r) $l[] = '- ' . cc_txt($r['comando'], 50) . ' — ' . cc_txt($r['origem'], 20) . ', ' . cc_txt($r['resultado'], 30) . ' (' . cc_tempo($r['data_hora']) . ')';
    return $l;
}

function cc_tarefas(PDO $pdo): array {
    $rows = $pdo->query("SELECT p.id, p.demanda_original, p.criado_em,
            (SELECT COUNT(*) FROM jarvis_tarefas t WHERE t.id_plano=p.id AND t.status IN ('PENDENTE','AGENDADA','EXECUTANDO','AGUARDANDO','AGUARDANDO_PARTES')) abertas
        FROM jarvis_planos p WHERE p.status='EM_EXECUCAO' AND p.criado_em >= NOW() - INTERVAL 7 DAY ORDER BY p.id DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_values(array_filter($rows, fn($r) => (int)$r['abertas'] > 0));
    if (!$rows) return [];
    $l = ['TAREFAS EM ANDAMENTO:'];
    foreach ($rows as $r) $l[] = '- Plano #' . $r['id'] . ': ' . cc_txt($r['demanda_original'], 70) . ' — ' . (int)$r['abertas'] . ' tarefa(s) aberta(s) (' . cc_tempo($r['criado_em']) . ')';
    return $l;
}

function cc_familia(PDO $pdo): array {
    $rows = $pdo->query("SELECT cliente, dispositivo, plataforma, MAX(ultimo_ping) ult FROM family_presence
        WHERE ultimo_ping >= NOW() - INTERVAL 10 MINUTE GROUP BY cliente, dispositivo, plataforma ORDER BY ult DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $nomes = array_map(fn($r) => cc_txt($r['cliente'] ?: $r['dispositivo'], 25) . ($r['plataforma'] ? ' (' . cc_txt($r['plataforma'], 12) . ')' : ''), $rows);
    return ['APLICATIVOS DA FAMÍLIA CONECTADOS (10 min): ' . implode(', ', $nomes) . '.'];
}

/** Trecho do retrato da casa: o que o mordomo está acompanhando e o que fez. */
function cc_mordomo(PDO $pdo): array {
    $l = [];
    $ab = $pdo->query("SELECT titulo, severidade, primeira_vez FROM jarvis_mordomo_observacoes WHERE status='ABERTA'
        ORDER BY FIELD(severidade,'alta','media','baixa'), primeira_vez DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ab as $o) $l[] = '- Acompanhando [' . $o['severidade'] . ']: ' . cc_txt($o['titulo'], 120) . ' (desde ' . cc_tempo($o['primeira_vez']) . ')';
    $dec = $pdo->query("SELECT d.acao, d.mensagem, d.ordem, d.motivo, d.executado, d.criado_em, o.titulo FROM jarvis_mordomo_decisoes d
        LEFT JOIN jarvis_mordomo_observacoes o ON o.id=d.observacao_id WHERE d.criado_em >= NOW() - INTERVAL 1 DAY ORDER BY d.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dec as $d) {
        $txt = $d['acao'] === 'agir' ? ('agiu: ' . cc_txt($d['ordem'], 50) . ($d['executado'] ? '' : ' (falhou)'))
            : ($d['acao'] === 'avisar' ? ('avisou: ' . cc_txt($d['mensagem'] ?: $d['titulo'], 90)) : ('ignorou: ' . cc_txt($d['titulo'], 60) . ($d['motivo'] ? ' — ' . cc_txt($d['motivo'], 60) : '')));
        $l[] = '- Decisão ' . cc_tempo($d['criado_em']) . ': ' . $txt;
    }
    return $l ? array_merge(['MORDOMO (o que você está acompanhando e o que já fez):'], $l) : [];
}

/**
 * Monta o retrato. $max limita o total de caracteres (seções inteiras são cortadas
 * do fim para o começo, na ordem de menor prioridade).
 */
function casa_contexto(PDO $pdo, int $max = 3500): array {
    $dias = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
    $secoes = [
        'agora' => ['Agora: ' . date('d/m/Y H:i') . ' (' . $dias[(int)date('w')] . ').'],
        'alertas' => cc_secao(fn() => cc_alertas($pdo)),
        'mordomo' => cc_secao(fn() => cc_mordomo($pdo)),
        'dispositivos' => cc_secao(fn() => cc_dispositivos($pdo, 30)),
        'equipamentos' => cc_secao(fn() => cc_equipamentos($pdo)),
        'sensores' => cc_secao(fn() => cc_sensores($pdo)),
        'nos' => cc_secao(fn() => cc_nos($pdo)),
        'tarefas' => cc_secao(fn() => cc_tarefas($pdo)),
        'familia' => cc_secao(fn() => cc_familia($pdo)),
        'comandos' => cc_secao(fn() => cc_comandos($pdo)),
    ];
    // Prioridade para cortar quando passar do teto (cortadas primeiro).
    $corte = ['comandos', 'familia', 'nos', 'tarefas', 'mordomo', 'equipamentos', 'sensores'];
    $montar = function () use (&$secoes) {
        $blocos = [];
        foreach ($secoes as $s) if ($s) $blocos[] = implode("\n", $s);
        return implode("\n\n", $blocos);
    };
    $texto = $montar();
    foreach ($corte as $nome) {
        if (mb_strlen($texto, 'UTF-8') <= $max) break;
        $secoes[$nome] = [];
        $texto = $montar();
    }
    // Última defesa: encurta a lista de dispositivos linha a linha.
    while (mb_strlen($texto, 'UTF-8') > $max && count($secoes['dispositivos']) > 2) {
        array_splice($secoes['dispositivos'], -1, 1);
        $texto = $montar();
    }
    return ['texto' => $texto, 'secoes' => array_keys(array_filter($secoes)), 'chars' => mb_strlen($texto, 'UTF-8')];
}

/** Bloco pronto para o pedido à IA. */
function casa_contexto_prompt(PDO $pdo, int $max = 3500): array {
    $c = casa_contexto($pdo, $max);
    $c['prompt'] = "\n\n=== ESTADO ATUAL DA CASA (dados ao vivo do banco, lidos agora) ===\n" . $c['texto'] .
        "\n=== FIM DO ESTADO ATUAL ===\n" .
        "Para perguntas sobre a casa (o que está ligado, dispositivos, sensores, temperatura, quem está online, alertas, status), " .
        "responda com base no ESTADO ATUAL acima, citando nomes e valores. Você já tem esses dados: nunca diga que é preciso " .
        "consultar uma API ou um endpoint. Se algo não estiver no estado atual, diga que não há informação disponível sobre isso. " .
        "A documentação do projeto (quando aparecer) é referência técnica, não o estado da casa.";
    return $c;
}
