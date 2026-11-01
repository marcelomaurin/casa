<?php
// CASA/JARVIS - Mordomo: acompanha a casa sozinho e decide o que fazer.
//
// Um ciclo (chamado periodicamente pelo daemon casa-tarefas em /api/v1/mordomo.php):
//   1. Detectores leem o banco e listam o que merece atenção (sem IA, baratos).
//   2. Cada achado vira uma observação com chave estável; achado que some é resolvido.
//   3. Só o que é NOVO vai para a IA, junto com o retrato da casa, que decide por item:
//      avisar, agir (ordem direta permitida) ou ignorar.
//   4. A decisão é executada conforme a autonomia e registrada.
//
// Autonomia (configuracoes_sistema.mordomo_autonomia):
//   observar - só registra;  avisar (padrão) - registra e avisa;
//   agir     - também executa ordens, MAS só as que o JARVIS reconhece como ordem
//              direta (luz, irrigação, relé, cena). A IA não consegue inventar ação.
//
// Avisos: notificação no celular sempre; Telegram e fala na casa (pelo daemon) quando
// a severidade é alta, ou média fora do horário de silêncio.

require_once(__DIR__ . '/contexto_casa.php');
require_once(__DIR__ . '/task_engine.php');
require_once(__DIR__ . '/fila_tarefas.php');

const MD_SEVERIDADES = ['baixa' => 1, 'media' => 2, 'alta' => 3];

function md_config(PDO $pdo): array {
    $padrao = [
        'mordomo_autonomia' => 'avisar',
        'mordomo_offline_min' => '10',
        'mordomo_bateria_min' => '20',
        'mordomo_temp_max' => '35',
        'mordomo_temp_min' => '8',
        'mordomo_irrigacao_max_min' => '30',
        'mordomo_silencio' => '22-7',
        'mordomo_lembrete_min' => '15',
        'mordomo_ignorar_tipos' => 'mobile,celular,smartphone,watch,relogio,tv',
    ];
    try {
        $st = $pdo->query("SELECT chave, valor FROM configuracoes_sistema WHERE chave LIKE 'mordomo\\_%'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (trim((string)$r['valor']) !== '') $padrao[$r['chave']] = trim((string)$r['valor']);
    } catch (Throwable $e) {}
    $a = strtolower($padrao['mordomo_autonomia']);
    $padrao['mordomo_autonomia'] = in_array($a, ['observar', 'avisar', 'agir'], true) ? $a : 'avisar';
    return $padrao;
}

/** Hora atual está no intervalo "22-7" (atravessa a meia-noite)? */
function md_no_intervalo(string $faixa, ?int $hora = null): bool {
    if (!preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $faixa, $m)) return false;
    $h = $hora ?? (int)date('G');
    [$ini, $fim] = [(int)$m[1] % 24, (int)$m[2] % 24];
    return $ini <= $fim ? ($h >= $ini && $h < $fim) : ($h >= $ini || $h < $fim);
}

function md_obs(string $chave, string $tipo, string $sev, string $titulo, array $det = []): array {
    return ['chave' => mb_substr($chave, 0, 190, 'UTF-8'), 'tipo' => $tipo, 'severidade' => $sev, 'titulo' => mb_substr($titulo, 0, 255, 'UTF-8'), 'detalhes' => $det];
}

// ---------------------------------------------------------------------------
// Detectores
// ---------------------------------------------------------------------------
function md_detectores(PDO $pdo, array $cfg): array {
    $achados = [];
    $add = function (array $lista) use (&$achados) { foreach ($lista as $o) $achados[$o['chave']] = $o; };

    // Pedido de assistência (queda, SOS) ainda não confirmado.
    $add(cc_secao(function () use ($pdo) {
        $o = [];
        foreach ($pdo->query("SELECT id,tipo,severidade,mensagem,pessoa_ref,criado_em FROM assistencia_eventos
            WHERE confirmado=0 AND criado_em >= NOW() - INTERVAL 1 DAY")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o[] = md_obs('assistencia:' . $r['id'], 'assistencia', 'alta',
                'Pedido de assistência não confirmado: ' . cc_txt($r['tipo'], 20) . ($r['pessoa_ref'] ? ' (' . cc_txt($r['pessoa_ref'], 30) . ')' : '') . ' — ' . cc_txt($r['mensagem'], 120),
                ['id' => (int)$r['id'], 'desde' => $r['criado_em']]);
        }
        return $o;
    }));

    // Dispositivo que estava ativo nas últimas 24 h e parou de responder.
    $add(cc_secao(function () use ($pdo, $cfg) {
        $ign = array_filter(array_map('trim', explode(',', strtolower($cfg['mordomo_ignorar_tipos']))));
        $min = max(2, (int)$cfg['mordomo_offline_min']);
        $o = [];
        $st = $pdo->prepare("SELECT device_id,nome,tipo,localizacao,ultimo_heartbeat FROM dispositivos_cluster
            WHERE credential_revoked_at IS NULL AND ultimo_heartbeat >= NOW() - INTERVAL 1 DAY
              AND ultimo_heartbeat < NOW() - INTERVAL :m MINUTE");
        $st->bindValue(':m', $min, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (in_array(strtolower((string)$r['tipo']), $ign, true)) continue;
            $o[] = md_obs('offline:' . $r['device_id'], 'dispositivo_fora', 'media',
                cc_txt($r['nome'] ?: $r['device_id'], 40) . ($r['localizacao'] ? ' (' . cc_txt($r['localizacao'], 25) . ')' : '') . ' parou de responder; último contato ' . cc_tempo($r['ultimo_heartbeat']),
                ['device_id' => $r['device_id']]);
        }
        return $o;
    }));

    // Bateria baixa.
    $add(cc_secao(function () use ($pdo, $cfg) {
        $o = [];
        $st = $pdo->prepare("SELECT device_id,nome,battery_pct FROM dispositivos_cluster
            WHERE credential_revoked_at IS NULL AND battery_pct IS NOT NULL AND battery_pct < :b AND ultimo_heartbeat >= NOW() - INTERVAL 1 HOUR");
        $st->bindValue(':b', (int)$cfg['mordomo_bateria_min'], PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o[] = md_obs('bateria:' . $r['device_id'], 'bateria_baixa', (int)$r['battery_pct'] < 10 ? 'media' : 'baixa',
                'Bateria baixa em ' . cc_txt($r['nome'] ?: $r['device_id'], 40) . ': ' . (int)$r['battery_pct'] . '%', ['device_id' => $r['device_id']]);
        }
        return $o;
    }));

    // Temperatura fora da faixa (leituras dos últimos 30 min).
    $add(cc_secao(function () use ($pdo, $cfg) {
        $max = (float)str_replace(',', '.', $cfg['mordomo_temp_max']);
        $min = (float)str_replace(',', '.', $cfg['mordomo_temp_min']);
        $leituras = [];
        try {
            foreach ($pdo->query("SELECT s.iddevice, s.sensor_nome, s.valor_numerico v FROM sensores_telemetria s JOIN (
                    SELECT MAX(id) mid FROM sensores_telemetria WHERE data_hora >= NOW() - INTERVAL 30 MINUTE
                      AND (LOWER(sensor_nome) LIKE '%temp%' OR unidade IN ('°C','C','ºC')) GROUP BY iddevice, sensor_nome
                ) m ON m.mid=s.id")->fetchAll(PDO::FETCH_ASSOC) as $r) $leituras['sens:' . $r['iddevice'] . ':' . $r['sensor_nome']] = [$r['sensor_nome'], (float)$r['v']];
        } catch (Throwable $e) {}
        try {
            foreach ($pdo->query("SELECT i.id_dispositivo, COALESCE(d.nome,d.device_id) nome, i.temperatura_c v FROM iot_leituras i JOIN (
                    SELECT MAX(id) mid FROM iot_leituras WHERE data_hora >= NOW() - INTERVAL 30 MINUTE AND temperatura_c IS NOT NULL GROUP BY id_dispositivo
                ) m ON m.mid=i.id LEFT JOIN dispositivos_cluster d ON d.id=i.id_dispositivo")->fetchAll(PDO::FETCH_ASSOC) as $r) $leituras['iot:' . $r['id_dispositivo']] = [$r['nome'] ?: 'sensor', (float)$r['v']];
        } catch (Throwable $e) {}
        try {
            foreach ($pdo->query("SELECT e.device_id, e.temperature_c v FROM device_environment_readings e JOIN (
                    SELECT MAX(id) mid FROM device_environment_readings WHERE observed_at >= NOW() - INTERVAL 30 MINUTE AND temperature_c IS NOT NULL GROUP BY device_id
                ) m ON m.mid=e.id")->fetchAll(PDO::FETCH_ASSOC) as $r) $leituras['amb:' . $r['device_id']] = [$r['device_id'], (float)$r['v']];
        } catch (Throwable $e) {}
        $o = [];
        foreach ($leituras as $k => [$nome, $v]) {
            if ($v > $max || $v < $min) {
                $alta = $v > $max + 5 || $v < $min - 5;
                $o[] = md_obs('temperatura:' . $k, 'temperatura', $alta ? 'alta' : 'media',
                    'Temperatura ' . ($v > $max ? 'alta' : 'baixa') . ' em ' . cc_txt($nome, 40) . ': ' . number_format($v, 1, ',', '') . ' °C (faixa ' . $min . ' a ' . $max . ')',
                    ['valor' => $v]);
            }
        }
        return $o;
    }));

    // Irrigação/bomba ligada há muito tempo (cadastro clássico).
    $add(cc_secao(function () use ($pdo, $cfg) {
        $o = [];
        $st = $pdo->prepare("SELECT d.iddevice, d.devname, p.atualizado_em FROM devices d JOIN devpar p ON p.iddevice=d.iddevice AND p.devparname='dev1'
            WHERE p.devvalue='1' AND (LOWER(d.devname) LIKE '%irriga%' OR LOWER(d.devname) LIKE '%bomba%') AND p.atualizado_em < NOW() - INTERVAL :m MINUTE");
        $st->bindValue(':m', max(5, (int)$cfg['mordomo_irrigacao_max_min']), PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o[] = md_obs('irrigacao_longa:' . $r['iddevice'], 'irrigacao_longa', 'media',
                cc_txt($r['devname'], 40) . ' está ligada ' . cc_tempo($r['atualizado_em']) . ' (limite ' . (int)$cfg['mordomo_irrigacao_max_min'] . ' min)', ['iddevice' => (int)$r['iddevice']]);
        }
        return $o;
    }));

    // Luz acesa de madrugada (1h às 5h).
    $add(cc_secao(function () use ($pdo) {
        if (!md_no_intervalo('1-5')) return [];
        $o = [];
        foreach ($pdo->query("SELECT d.iddevice, d.devname FROM devices d JOIN devpar p ON p.iddevice=d.iddevice AND p.devparname='dev1'
            WHERE p.devvalue='1' AND (LOWER(d.devname) LIKE '%luz%' OR LOWER(d.devname) LIKE '%lampada%' OR LOWER(d.devname) LIKE '%lâmpada%')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o[] = md_obs('luz_madrugada:' . $r['iddevice'] . ':' . date('Y-m-d'), 'luz_madrugada', 'baixa', cc_txt($r['devname'], 40) . ' acesa de madrugada', ['iddevice' => (int)$r['iddevice']]);
        }
        return $o;
    }));

    // Movimento em câmera no horário de silêncio (últimos 15 min).
    $add(cc_secao(function () use ($pdo, $cfg) {
        if (!md_no_intervalo($cfg['mordomo_silencio'])) return [];
        $o = [];
        foreach ($pdo->query("SELECT nome_dispositivo, localizacao, COUNT(*) n, MAX(data_hora) ult, MAX(quantidade_faces) faces
            FROM camera_eventos WHERE movimento=1 AND data_hora >= NOW() - INTERVAL 15 MINUTE GROUP BY nome_dispositivo, localizacao")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $onde = cc_txt($r['nome_dispositivo'] ?: $r['localizacao'], 40);
            $o[] = md_obs('movimento:' . $onde . ':' . date('Y-m-d-H'), 'movimento_noite', (int)$r['faces'] > 0 ? 'alta' : 'media',
                'Movimento em ' . $onde . ' no horário de silêncio' . ((int)$r['faces'] > 0 ? ', com rosto detectado' : '') . ' (' . (int)$r['n'] . ' evento(s), o último ' . cc_tempo($r['ult']) . ')');
        }
        return $o;
    }));

    // Evento grave de segurança nos últimos 15 min.
    $add(cc_secao(function () use ($pdo) {
        $r = $pdo->query("SELECT COUNT(*) n, MAX(data_hora) ult, MAX(evento) ev FROM seguranca_logs
            WHERE severidade IN ('ALTO','CRITICO','CRÍTICO') AND data_hora >= NOW() - INTERVAL 15 MINUTE")->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['n'] === 0) return [];
        return [md_obs('seguranca:' . date('Y-m-d-H'), 'seguranca', 'alta', (int)$r['n'] . ' evento(s) grave(s) de segurança, o último ' . cc_tempo($r['ult']) . ': ' . cc_txt($r['ev'], 80))];
    }));

    return array_values($achados);
}

// ---------------------------------------------------------------------------
// Observações (memória do que já foi visto)
// ---------------------------------------------------------------------------
/** Grava os achados; devolve [novas, reabertas, abertas] e resolve o que sumiu. */
function md_sincronizar(PDO $pdo, array $achados): array {
    $chaves = array_column($achados, 'chave');
    $novas = [];
    $sel = $pdo->prepare("SELECT id,status FROM jarvis_mordomo_observacoes WHERE chave=:c");
    $ins = $pdo->prepare("INSERT INTO jarvis_mordomo_observacoes (chave,tipo,severidade,titulo,detalhes,status,primeira_vez,ultima_vez)
        VALUES (:c,:t,:s,:ti,:d,'ABERTA',NOW(),NOW())");
    $upd = $pdo->prepare("UPDATE jarvis_mordomo_observacoes SET titulo=:ti, severidade=:s, detalhes=:d, ultima_vez=NOW(),
        status='ABERTA', resolvida_em=NULL WHERE id=:id");
    foreach ($achados as $o) {
        $sel->execute([':c' => $o['chave']]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        $det = json_encode($o['detalhes'], JSON_UNESCAPED_UNICODE);
        if (!$row) {
            $ins->execute([':c' => $o['chave'], ':t' => $o['tipo'], ':s' => $o['severidade'], ':ti' => $o['titulo'], ':d' => $det]);
            $novas[] = $o + ['id' => (int)$pdo->lastInsertId(), 'reaberta' => false];
        } else {
            $upd->execute([':ti' => $o['titulo'], ':s' => $o['severidade'], ':d' => $det, ':id' => $row['id']]);
            if ($row['status'] !== 'ABERTA') $novas[] = $o + ['id' => (int)$row['id'], 'reaberta' => true];
        }
    }
    // O que não apareceu neste ciclo está resolvido.
    $abertas = $pdo->query("SELECT id,chave FROM jarvis_mordomo_observacoes WHERE status='ABERTA'")->fetchAll(PDO::FETCH_ASSOC);
    $res = $pdo->prepare("UPDATE jarvis_mordomo_observacoes SET status='RESOLVIDA', resolvida_em=NOW() WHERE id=:id");
    $resolvidas = 0;
    foreach ($abertas as $a) if (!in_array($a['chave'], $chaves, true)) { $res->execute([':id' => $a['id']]); $resolvidas++; }
    return ['novas' => $novas, 'resolvidas' => $resolvidas, 'abertas' => count($chaves)];
}

// ---------------------------------------------------------------------------
// Decisão
// ---------------------------------------------------------------------------
function md_pedido_ia(array $novas, string $autonomia): string {
    $itens = [];
    foreach ($novas as $i => $o) $itens[] = ($i + 1) . ') [' . $o['severidade'] . '] ' . $o['titulo'] . ($o['reaberta'] ? ' (voltou a acontecer)' : '');
    $regraAgir = $autonomia === 'agir'
        ? "Você PODE usar \"agir\" com uma destas ordens, no texto exato: \"ligue a luz da sala\", \"desligue a luz da sala\", \"ligue a irrigação\", \"desligue a irrigação\", \"ligue o relé N\", \"desligue o relé N\", \"ative a cena SLUG\". Só aja quando for claramente seguro e útil; na dúvida, avise."
        : "Autonomia atual: só avisar. NÃO use \"agir\"; se uma ordem ajudaria, coloque-a em \"sugestao\" para a família decidir.";
    return "[MORDOMO] Você é o mordomo desta casa. Use o ESTADO ATUAL DA CASA para entender a situação.\n" .
        "Observações detectadas agora:\n" . implode("\n", $itens) . "\n\n" .
        "Para cada observação, decida UMA ação: \"avisar\" (mensagem curta e útil para a família, em português, até 160 caracteres), " .
        "\"agir\" ou \"ignorar\" (com motivo). " . $regraAgir . "\n" .
        "Responda SOMENTE com JSON, sem texto fora dele: " .
        "{\"decisoes\":[{\"observacao\":1,\"acao\":\"avisar|agir|ignorar\",\"mensagem\":\"...\",\"ordem\":\"...\",\"sugestao\":\"...\",\"motivo\":\"...\"}]}";
}

function md_chamar_jarvis(array $payload, int $timeout = 60): array {
    $ch = curl_init(casa_url_interna('api/jarvis.php'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload + ['origem' => 'MORDOMO', 'skip_planner' => true], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . get_system_api_token()],
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $timeout,
    ]);
    $res = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    return ['ok' => $http >= 200 && $http < 300 && is_array($j), 'http' => $http, 'dados' => is_array($j) ? $j : [], 'erro' => $err ?: ($j['mensagem'] ?? null)];
}

/** Lê o JSON de decisões da resposta da IA; devolve [] se não der. */
function md_ler_decisoes(string $txt, int $n): array {
    $txt = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($txt));
    $i = strpos($txt, '{'); $f = strrpos($txt, '}');
    if ($i === false || $f === false) return [];
    $j = json_decode(substr($txt, $i, $f - $i + 1), true);
    if (!is_array($j) || !is_array($j['decisoes'] ?? null)) return [];
    $saida = [];
    foreach ($j['decisoes'] as $d) {
        $k = (int)($d['observacao'] ?? 0);
        if ($k < 1 || $k > $n || isset($saida[$k])) continue;
        $acao = strtolower(trim((string)($d['acao'] ?? '')));
        if (!in_array($acao, ['avisar', 'agir', 'ignorar'], true)) continue;
        $saida[$k] = [
            'acao' => $acao,
            'mensagem' => cc_txt($d['mensagem'] ?? '', 300),
            'ordem' => cc_txt($d['ordem'] ?? '', 120),
            'sugestao' => cc_txt($d['sugestao'] ?? '', 160),
            'motivo' => cc_txt($d['motivo'] ?? '', 300),
        ];
    }
    return $saida;
}

/** Decisão padrão quando a IA não responde: avisa o que for médio ou grave. */
function md_decisao_padrao(array $o): array {
    return MD_SEVERIDADES[$o['severidade']] >= 2
        ? ['acao' => 'avisar', 'mensagem' => $o['titulo'], 'ordem' => '', 'sugestao' => '', 'motivo' => 'IA indisponível: aviso automático']
        : ['acao' => 'ignorar', 'mensagem' => '', 'ordem' => '', 'sugestao' => '', 'motivo' => 'IA indisponível; severidade baixa'];
}

// ---------------------------------------------------------------------------
// Execução
// ---------------------------------------------------------------------------
/** Avisa a família pelos canais adequados à severidade. Devolve os canais usados. */
function md_avisar(PDO $pdo, string $mensagem, string $severidade, array $cfg): array {
    $canais = [];
    $mensagem = 'Mordomo: ' . $mensagem;
    $prio = $severidade === 'alta' ? 'alta' : 'normal';
    try {
        $pdo->prepare("INSERT INTO mobile_notificacoes (id_dispositivo,titulo,mensagem,prioridade) VALUES (NULL,'Mordomo JARVIS',:m,:p)")
            ->execute([':m' => mb_substr($mensagem, 0, 1000, 'UTF-8'), ':p' => $prio]);
        $canais[] = 'celular';
    } catch (Throwable $e) {}

    $silencio = md_no_intervalo($cfg['mordomo_silencio']);
    $urgente = $severidade === 'alta' || ($severidade === 'media' && !$silencio);
    if ($urgente) {
        try {
            if (!function_exists('agente_telegram_enviar')) require_once(__DIR__ . '/agente_externo.php');
            $GLOBALS['pdo'] = $GLOBALS['pdo'] ?? $pdo;
            if (agente_telegram_enviar($mensagem)) $canais[] = 'telegram';
        } catch (Throwable $e) {}
        // Fala na casa: tarefa na fila para o daemon que tem alto-falante.
        try {
            if (in_array('fala', ft_executores_ativos($pdo), true)) {
                $ctx = te_begin($pdo, 'Aviso do mordomo', 'MORDOMO', 'mordomo');
                te_complete($pdo, (int)$ctx['id_tarefa_raiz'], ['aviso' => $mensagem]);
                $pdo->prepare("INSERT INTO jarvis_tarefas (id_plano,correlation_id,ordem,titulo,tipo,executor,fila,payload,tarefa_pai_id,status,max_tentativas)
                    VALUES (:p,:c,2,'Falar aviso do mordomo','IMEDIATA','fala','geral',:pl,:pai,'PENDENTE',2)")
                    ->execute([':p' => $ctx['id_plano'], ':c' => $ctx['correlation_id'], ':pl' => json_encode(['texto' => $mensagem], JSON_UNESCAPED_UNICODE), ':pai' => $ctx['id_tarefa_raiz']]);
                $pdo->prepare("UPDATE jarvis_planos SET status='EM_EXECUCAO' WHERE id=:id")->execute([':id' => $ctx['id_plano']]);
                $canais[] = 'fala';
            }
        } catch (Throwable $e) {}
    }
    return $canais;
}

function md_registrar(PDO $pdo, ?int $obsId, array $d, string $origem, bool $executado, string $resultado): void {
    $pdo->prepare("INSERT INTO jarvis_mordomo_decisoes (observacao_id,acao,mensagem,ordem,motivo,origem,executado,resultado)
        VALUES (:o,:a,:m,:or,:mo,:org,:e,:r)")->execute([
        ':o' => $obsId, ':a' => $d['acao'], ':m' => $d['mensagem'] ?: null, ':or' => ($d['ordem'] ?: $d['sugestao']) ?: null,
        ':mo' => $d['motivo'] ?: null, ':org' => $origem, ':e' => $executado ? 1 : 0, ':r' => mb_substr($resultado, 0, 2000, 'UTF-8'),
    ]);
}

/** Um ciclo completo. $chamarIa pode ser trocada em testes. */
function md_ciclo(PDO $pdo, ?callable $chamarIa = null, ?callable $executarOrdem = null): array {
    $cfg = md_config($pdo);
    $chamarIa = $chamarIa ?? fn(string $pedido) => md_chamar_jarvis(['comando' => $pedido, 'sem_acoes' => true, 'ia_mode' => 'cloud_preferido'], 90);
    $executarOrdem = $executarOrdem ?? fn(string $ordem) => md_chamar_jarvis(['comando' => $ordem, 'somente_direto' => true], 30);

    $achados = md_detectores($pdo, $cfg);
    $sinc = md_sincronizar($pdo, $achados);
    $novas = $sinc['novas'];
    $resumo = ['autonomia' => $cfg['mordomo_autonomia'], 'achados' => count($achados), 'novas' => count($novas),
               'resolvidas' => $sinc['resolvidas'], 'decisoes' => [], 'lembretes' => 0];

    if ($novas && $cfg['mordomo_autonomia'] !== 'observar') {
        $r = $chamarIa(md_pedido_ia($novas, $cfg['mordomo_autonomia']));
        $decisoes = $r['ok'] ? md_ler_decisoes((string)($r['dados']['resposta'] ?? ''), count($novas)) : [];
        $origemDec = $decisoes ? 'ia' : 'padrao';
        foreach ($novas as $i => $o) {
            $d = $decisoes[$i + 1] ?? md_decisao_padrao($o);
            $executado = false; $resultado = '';
            if ($d['acao'] === 'agir') {
                if ($cfg['mordomo_autonomia'] === 'agir' && $d['ordem'] !== '') {
                    $x = $executarOrdem($d['ordem']);
                    $executado = $x['ok'] && ($x['dados']['status'] ?? '') === 'sucesso';
                    $resultado = $executado ? (string)($x['dados']['resposta'] ?? '') : ('Ordem recusada: ' . ($x['erro'] ?? ('HTTP ' . $x['http'])));
                    $msg = $executado
                        ? ($d['mensagem'] ?: ($o['titulo'] . ' — fiz: ' . $d['ordem'] . '.'))
                        : $o['titulo'] . ' — não consegui: ' . $d['ordem'] . '.';
                    $canais = md_avisar($pdo, $msg, $o['severidade'], $cfg);
                    $resultado .= ' | aviso: ' . implode(',', $canais);
                } else {
                    // Sem autonomia para agir: vira aviso com a sugestão.
                    $d['sugestao'] = $d['sugestao'] ?: $d['ordem'];
                    $d['acao'] = 'avisar';
                }
            }
            if ($d['acao'] === 'avisar') {
                $msg = ($d['mensagem'] ?: $o['titulo']) . ($d['sugestao'] ? ' Sugestão: ' . $d['sugestao'] . '.' : '');
                $canais = md_avisar($pdo, $msg, $o['severidade'], $cfg);
                $executado = (bool)$canais;
                $resultado = 'aviso: ' . (implode(',', $canais) ?: 'nenhum canal');
                $pdo->prepare("UPDATE jarvis_mordomo_observacoes SET ultimo_aviso=NOW(), avisos=avisos+1 WHERE id=:id")->execute([':id' => $o['id']]);
            }
            md_registrar($pdo, $o['id'], $d, $origemDec, $executado, $resultado);
            $pdo->prepare("UPDATE jarvis_mordomo_observacoes SET decidida_em=NOW() WHERE id=:id")->execute([':id' => $o['id']]);
            $resumo['decisoes'][] = ['observacao' => $o['titulo'], 'acao' => $d['acao'], 'ordem' => $d['ordem'], 'executado' => $executado, 'resultado' => $resultado];
        }
    }

    // Lembrete: caso grave que continua aberto e já foi avisado há mais de N minutos.
    if ($cfg['mordomo_autonomia'] !== 'observar') {
        $st = $pdo->prepare("SELECT id,titulo,severidade FROM jarvis_mordomo_observacoes WHERE status='ABERTA' AND severidade='alta'
            AND ultimo_aviso IS NOT NULL AND ultimo_aviso < NOW() - INTERVAL :m MINUTE");
        $st->bindValue(':m', max(5, (int)$cfg['mordomo_lembrete_min']), PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $canais = md_avisar($pdo, 'Lembrete: ' . $o['titulo'], 'alta', $cfg);
            $pdo->prepare("UPDATE jarvis_mordomo_observacoes SET ultimo_aviso=NOW(), avisos=avisos+1 WHERE id=:id")->execute([':id' => $o['id']]);
            md_registrar($pdo, (int)$o['id'], ['acao' => 'avisar', 'mensagem' => 'Lembrete: ' . $o['titulo'], 'ordem' => '', 'sugestao' => '', 'motivo' => 'caso grave ainda aberto'], 'lembrete', (bool)$canais, 'aviso: ' . implode(',', $canais));
            $resumo['lembretes']++;
        }
    }
    return $resumo;
}
