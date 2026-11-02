<?php
// CASA/JARVIS - Fila de tarefas para daemons e agentes externos.
//
// Ciclo de uma tarefa na fila (jarvis_tarefas com fila preenchida):
//   PENDENTE / AGENDADA --reservar--> EXECUTANDO --concluir--> CONCLUIDA
//                                         |--falhar (com nova tentativa)--> PENDENTE (com espera)
//                                         |--falhar (esgotou) ------------> ERRO (dependentes também)
//                                         |--liberar---------------------> PENDENTE
//                                         |--dividir---------------------> AGUARDANDO_PARTES
//                                         '--prazo da reserva vence------> PENDENTE ou ERRO
//   AGUARDANDO_PARTES --todas as partes terminam--> CONCLUIDA (ou ERRO se alguma parte falhou)
//
// Uma tarefa só é entregue quando a tarefa de que depende está CONCLUIDA e a hora
// de execução (executar_em) já chegou. O plano é fechado quando nada mais está em andamento.

const FT_FINAIS = ['CONCLUIDA', 'ERRO', 'CANCELADA'];
const FT_EM_ANDAMENTO = ['PENDENTE', 'AGENDADA', 'EXECUTANDO', 'AGUARDANDO', 'AGUARDANDO_PARTES'];
const FT_MAX_PARTES = 20;
const FT_RESERVA_MIN = 30;
const FT_RESERVA_MAX = 3600;
// Fila reservada: tarefas que o próprio site executa (ex.: dispositivo que depende de tarefa da fila).
const FT_FILA_SERVIDOR = 'servidor';

class FilaErro extends RuntimeException {
    public int $http;
    public function __construct(string $mensagem, int $http = 400) { parent::__construct($mensagem); $this->http = $http; }
}

function ft_nome_valido(string $nome): bool {
    return (bool)preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:\-]{0,79}$/', $nome);
}

function ft_lista_nomes($valor, string $campo): array {
    if (is_string($valor)) $valor = array_map('trim', explode(',', $valor));
    if (!is_array($valor)) return [];
    $saida = [];
    foreach ($valor as $v) {
        $v = strtolower(trim((string)$v));
        if ($v === '') continue;
        if (!ft_nome_valido($v)) throw new FilaErro("Nome inválido em {$campo}: {$v}");
        $saida[$v] = true;
    }
    return array_keys($saida);
}

function ft_json($v): ?string {
    if ($v === null) return null;
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function ft_decode($v) {
    if ($v === null || $v === '') return null;
    $d = json_decode((string)$v, true);
    return json_last_error() === JSON_ERROR_NONE ? $d : $v;
}

/** Registra ou atualiza o trabalhador (daemon/agente) e seu último contato. */
function ft_registrar_trabalhador(PDO $pdo, string $nome, array $executores, array $filas, ?string $cliente, array $info = []): void {
    $em = $pdo->prepare("SELECT COUNT(*) FROM jarvis_tarefas WHERE status='EXECUTANDO' AND reservado_por=:n");
    $em->execute([':n' => $nome]);
    $pdo->prepare("INSERT INTO jarvis_trabalhadores (nome,executores,filas,cliente,host,versao,em_execucao,ultimo_contato)
        VALUES (:n,:e,:f,:c,:h,:v,:x,NOW())
        ON DUPLICATE KEY UPDATE executores=VALUES(executores),filas=VALUES(filas),cliente=VALUES(cliente),
            host=VALUES(host),versao=VALUES(versao),em_execucao=VALUES(em_execucao),ultimo_contato=NOW()")
        ->execute([
            ':n' => $nome, ':e' => ft_json(array_values($executores)), ':f' => ft_json(array_values($filas)),
            ':c' => $cliente !== null ? mb_substr($cliente, 0, 160, 'UTF-8') : null,
            ':h' => isset($info['host']) ? mb_substr((string)$info['host'], 0, 160, 'UTF-8') : null,
            ':v' => isset($info['versao']) ? mb_substr((string)$info['versao'], 0, 40, 'UTF-8') : null,
            ':x' => (int)$em->fetchColumn(),
        ]);
}

/** Trabalhadores com contato recente: [nome => ['executores'=>[], 'filas'=>[]]]. */
function ft_trabalhadores_ativos(PDO $pdo, int $segundos = 300): array {
    try {
        $st = $pdo->prepare("SELECT nome,executores,filas FROM jarvis_trabalhadores WHERE ultimo_contato >= DATE_SUB(NOW(), INTERVAL :s SECOND)");
        $st->bindValue(':s', $segundos, PDO::PARAM_INT);
        $st->execute();
    } catch (Throwable $e) { return []; }
    $saida = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $saida[$r['nome']] = [
            'executores' => array_values((array)(ft_decode($r['executores']) ?: [])),
            'filas' => array_values((array)(ft_decode($r['filas']) ?: [])),
        ];
    }
    return $saida;
}

/** Executores oferecidos por algum trabalhador ativo. */
function ft_executores_ativos(PDO $pdo, int $segundos = 300): array {
    $ex = [];
    foreach (ft_trabalhadores_ativos($pdo, $segundos) as $t) foreach ($t['executores'] as $e) $ex[$e] = true;
    return array_keys($ex);
}

/** Reservas vencidas voltam para a fila (ou viram ERRO se esgotaram as tentativas). */
function ft_recuperar_expiradas(PDO $pdo): int {
    $st = $pdo->query("SELECT id, tentativas, max_tentativas FROM jarvis_tarefas
        WHERE fila IS NOT NULL AND status='EXECUTANDO' AND reservado_ate IS NOT NULL AND reservado_ate < NOW()");
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['tentativas'] >= (int)$r['max_tentativas']) {
            ft_marcar_erro($pdo, (int)$r['id'], 'Prazo da reserva venceu e as tentativas acabaram');
        } else {
            $pdo->prepare("UPDATE jarvis_tarefas SET status='PENDENTE', reservado_por=NULL, reservado_ate=NULL,
                erro='Prazo da reserva venceu; tarefa devolvida à fila' WHERE id=:id AND status='EXECUTANDO'")
                ->execute([':id' => $r['id']]);
        }
        $n++;
    }
    return $n;
}

/**
 * Reserva até $limite tarefas para o trabalhador. A reserva é feita tarefa a tarefa com
 * "UPDATE ... WHERE status IN (...)": se dois trabalhadores disputam a mesma, só um vence.
 */
function ft_reservar(PDO $pdo, string $trabalhador, array $executores, array $filas, int $limite, int $prazo): array {
    if (!$executores) throw new FilaErro('Informe ao menos um executor');
    if (strtolower($trabalhador) === FT_FILA_SERVIDOR || in_array(FT_FILA_SERVIDOR, $filas, true)) {
        throw new FilaErro("A fila '" . FT_FILA_SERVIDOR . "' é reservada ao site");
    }
    $limite = max(1, min(20, $limite));
    $prazo = max(FT_RESERVA_MIN, min(FT_RESERVA_MAX, $prazo));
    ft_recuperar_expiradas($pdo);
    ft_fechar_planos($pdo);

    $filasAceitas = array_values(array_unique(array_merge(['geral', strtolower($trabalhador)], $filas)));
    $phE = implode(',', array_fill(0, count($executores), '?'));
    $phF = implode(',', array_fill(0, count($filasAceitas), '?'));
    $sql = "SELECT t.id FROM jarvis_tarefas t
            LEFT JOIN jarvis_tarefas d ON d.id = t.depende_de
            WHERE t.fila IN ({$phF}) AND t.executor IN ({$phE})
              AND t.status IN ('PENDENTE','AGENDADA')
              AND (t.executar_em IS NULL OR t.executar_em <= NOW())
              AND (t.depende_de IS NULL OR d.status = 'CONCLUIDA')
            ORDER BY COALESCE(t.executar_em, t.criado_em), t.id
            LIMIT " . ($limite * 3);
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($filasAceitas, $executores));
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);

    $reservadas = [];
    $up = $pdo->prepare("UPDATE jarvis_tarefas SET status='EXECUTANDO', reservado_por=:w,
            reservado_ate=DATE_ADD(NOW(), INTERVAL :p SECOND), tentativas=tentativas+1,
            iniciado_em=COALESCE(iniciado_em,NOW()), erro=NULL
        WHERE id=:id AND status IN ('PENDENTE','AGENDADA')");
    foreach ($ids as $id) {
        if (count($reservadas) >= $limite) break;
        $up->bindValue(':w', $trabalhador);
        $up->bindValue(':p', $prazo, PDO::PARAM_INT);
        $up->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $up->execute();
        if ($up->rowCount() === 1) $reservadas[] = ft_tarefa_para_trabalhador($pdo, (int)$id);
    }
    return $reservadas;
}

/** Dados entregues ao trabalhador, incluindo o resultado da tarefa anterior (entrada). */
function ft_tarefa_para_trabalhador(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT t.*, d.resultado AS resultado_dependencia, d.titulo AS titulo_dependencia
        FROM jarvis_tarefas t LEFT JOIN jarvis_tarefas d ON d.id=t.depende_de WHERE t.id=:id");
    $st->execute([':id' => $id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new FilaErro('Tarefa não encontrada', 404);
    return [
        'id' => (int)$t['id'],
        'id_plano' => (int)$t['id_plano'],
        'correlation_id' => $t['correlation_id'] ?? null,
        'titulo' => $t['titulo'],
        'descricao' => $t['descricao'],
        'tipo' => $t['tipo'],
        'executor' => $t['executor'],
        'fila' => $t['fila'],
        'payload' => ft_decode($t['payload']) ?? new stdClass(),
        'tentativa' => (int)$t['tentativas'],
        'max_tentativas' => (int)$t['max_tentativas'],
        'reservado_ate' => $t['reservado_ate'],
        'tarefa_pai_id' => $t['tarefa_pai_id'] !== null ? (int)$t['tarefa_pai_id'] : null,
        'entrada' => $t['depende_de'] !== null ? [
            'tarefa_id' => (int)$t['depende_de'],
            'titulo' => $t['titulo_dependencia'],
            'resultado' => ft_decode($t['resultado_dependencia']),
        ] : null,
        'task_context' => [
            'id_plano' => (int)$t['id_plano'],
            'id_tarefa_raiz' => $t['tarefa_pai_id'] !== null ? (int)$t['tarefa_pai_id'] : (int)$t['id'],
            'current_task_id' => (int)$t['id'],
            'correlation_id' => $t['correlation_id'] ?? null,
        ],
    ];
}

/** Garante que a tarefa está reservada por este trabalhador. */
function ft_exigir_reserva(PDO $pdo, int $id, string $trabalhador): array {
    $st = $pdo->prepare("SELECT * FROM jarvis_tarefas WHERE id=:id");
    $st->execute([':id' => $id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t || $t['fila'] === null) throw new FilaErro('Tarefa não encontrada na fila', 404);
    if ($t['status'] !== 'EXECUTANDO' || $t['reservado_por'] !== $trabalhador) {
        throw new FilaErro('Tarefa não está reservada por este trabalhador (status ' . $t['status'] . ')', 409);
    }
    return $t;
}

function ft_renovar(PDO $pdo, int $id, string $trabalhador, int $prazo): string {
    ft_exigir_reserva($pdo, $id, $trabalhador);
    $prazo = max(FT_RESERVA_MIN, min(FT_RESERVA_MAX, $prazo));
    $st = $pdo->prepare("UPDATE jarvis_tarefas SET reservado_ate=DATE_ADD(NOW(), INTERVAL :p SECOND) WHERE id=:id");
    $st->bindValue(':p', $prazo, PDO::PARAM_INT);
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->execute();
    $q = $pdo->prepare("SELECT reservado_ate FROM jarvis_tarefas WHERE id=:id");
    $q->execute([':id' => $id]);
    return (string)$q->fetchColumn();
}

function ft_concluir(PDO $pdo, int $id, string $trabalhador, $resultado): void {
    $t = ft_exigir_reserva($pdo, $id, $trabalhador);
    $pdo->prepare("UPDATE jarvis_tarefas SET status='CONCLUIDA', resultado=:r, erro=NULL, concluido_em=NOW(),
        reservado_por=NULL, reservado_ate=NULL WHERE id=:id")
        ->execute([':r' => ft_json($resultado), ':id' => $id]);
    ft_apos_final($pdo, $t, true);
}

/**
 * Falha: volta para a fila com espera crescente (30 s, 60 s, 120 s... até 15 min) enquanto
 * houver tentativas; depois vira ERRO e derruba quem dependia dela.
 */
function ft_falhar(PDO $pdo, int $id, string $trabalhador, string $erro, bool $repetir, $resultado = null): string {
    $t = ft_exigir_reserva($pdo, $id, $trabalhador);
    $erro = mb_substr($erro !== '' ? $erro : 'Falha sem descrição', 0, 4000, 'UTF-8');
    if ($repetir && (int)$t['tentativas'] < (int)$t['max_tentativas']) {
        $espera = min(900, 30 * (2 ** max(0, (int)$t['tentativas'] - 1)));
        $st = $pdo->prepare("UPDATE jarvis_tarefas SET status='PENDENTE', erro=:e, resultado=:r,
            executar_em=DATE_ADD(NOW(), INTERVAL :s SECOND), reservado_por=NULL, reservado_ate=NULL WHERE id=:id");
        $st->bindValue(':e', $erro);
        $st->bindValue(':r', ft_json($resultado));
        $st->bindValue(':s', $espera, PDO::PARAM_INT);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
        return 'PENDENTE';
    }
    ft_marcar_erro($pdo, $id, $erro, $resultado);
    return 'ERRO';
}

/** Devolve a tarefa à fila sem gastar tentativa (ex.: daemon desligando). */
function ft_liberar(PDO $pdo, int $id, string $trabalhador): void {
    ft_exigir_reserva($pdo, $id, $trabalhador);
    $pdo->prepare("UPDATE jarvis_tarefas SET status='PENDENTE', reservado_por=NULL, reservado_ate=NULL,
        tentativas=GREATEST(tentativas-1,0) WHERE id=:id")->execute([':id' => $id]);
}

/**
 * Divide a tarefa em partes. Cada parte vira uma tarefa filha (mesmo plano), que pode ir
 * para outra fila ou executor e depender de uma parte anterior ("depende_da_parte", 1 = primeira).
 * A tarefa original espera em AGUARDANDO_PARTES e conclui quando todas as partes terminarem.
 */
function ft_dividir(PDO $pdo, int $id, string $trabalhador, array $partes): array {
    $t = ft_exigir_reserva($pdo, $id, $trabalhador);
    if (!$partes) throw new FilaErro('Nenhuma parte informada');
    if (count($partes) > FT_MAX_PARTES) throw new FilaErro('Máximo de ' . FT_MAX_PARTES . ' partes');

    $normal = [];
    foreach (array_values($partes) as $i => $p) {
        if (!is_array($p)) throw new FilaErro('Parte ' . ($i + 1) . ' inválida');
        $executor = strtolower(trim((string)($p['executor'] ?? $t['executor'])));
        if (!ft_nome_valido($executor)) throw new FilaErro('Executor inválido na parte ' . ($i + 1));
        $fila = strtolower(trim((string)($p['fila'] ?? $t['fila'])));
        if (!ft_nome_valido($fila)) throw new FilaErro('Fila inválida na parte ' . ($i + 1));
        $dep = $p['depende_da_parte'] ?? null;
        if ($dep !== null && (!is_int($dep) && !ctype_digit((string)$dep) || (int)$dep < 1 || (int)$dep > $i)) {
            throw new FilaErro('depende_da_parte da parte ' . ($i + 1) . ' deve apontar para uma parte anterior');
        }
        $normal[] = [
            'titulo' => mb_substr(trim((string)($p['titulo'] ?? ('Parte ' . ($i + 1)))), 0, 180, 'UTF-8') ?: 'Parte ' . ($i + 1),
            'descricao' => isset($p['descricao']) ? (string)$p['descricao'] : null,
            'executor' => $executor, 'fila' => $fila,
            'payload' => $p['payload'] ?? new stdClass(),
            'dep' => $dep !== null ? (int)$dep : null,
            'max' => max(1, min(10, (int)($p['max_tentativas'] ?? $t['max_tentativas']))),
        ];
    }

    $iniciou = !$pdo->inTransaction();
    if ($iniciou) $pdo->beginTransaction();
    try {
        $base = (int)$pdo->query("SELECT COALESCE(MAX(ordem),0) FROM jarvis_tarefas WHERE id_plano=" . (int)$t['id_plano'])->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO jarvis_tarefas (id_plano,correlation_id,ordem,titulo,descricao,tipo,executor,fila,payload,
                depende_de,tarefa_pai_id,status,max_tentativas)
            VALUES (:p,:c,:o,:t,:d,'IMEDIATA',:e,:f,:pl,:dep,:pai,'PENDENTE',:m)");
        $ids = [];
        foreach ($normal as $i => $p) {
            $ins->execute([
                ':p' => $t['id_plano'], ':c' => $t['correlation_id'], ':o' => $base + $i + 1,
                ':t' => $p['titulo'], ':d' => $p['descricao'], ':e' => $p['executor'], ':f' => $p['fila'],
                ':pl' => ft_json($p['payload']), ':dep' => $p['dep'] !== null ? $ids[$p['dep'] - 1] : null,
                ':pai' => $id, ':m' => $p['max'],
            ]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE jarvis_tarefas SET status='AGUARDANDO_PARTES', reservado_por=NULL, reservado_ate=NULL WHERE id=:id")
            ->execute([':id' => $id]);
        if ($iniciou) $pdo->commit();
    } catch (Throwable $e) {
        if ($iniciou && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $ids;
}

/** Marca ERRO e derruba em cascata quem dependia desta tarefa. */
function ft_marcar_erro(PDO $pdo, int $id, string $erro, $resultado = null): void {
    $st = $pdo->prepare("SELECT * FROM jarvis_tarefas WHERE id=:id");
    $st->execute([':id' => $id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return;
    $pdo->prepare("UPDATE jarvis_tarefas SET status='ERRO', erro=:e, resultado=COALESCE(:r,resultado), concluido_em=NOW(),
        reservado_por=NULL, reservado_ate=NULL WHERE id=:id")
        ->execute([':e' => mb_substr($erro, 0, 4000, 'UTF-8'), ':r' => ft_json($resultado), ':id' => $id]);

    $dep = $pdo->prepare("SELECT id FROM jarvis_tarefas WHERE depende_de=:id AND status IN ('PENDENTE','AGENDADA','AGUARDANDO')");
    $dep->execute([':id' => $id]);
    foreach ($dep->fetchAll(PDO::FETCH_COLUMN) as $d) {
        ft_marcar_erro($pdo, (int)$d, "Dependência #{$id} falhou: " . mb_substr($erro, 0, 300, 'UTF-8'));
    }
    ft_apos_final($pdo, $t, false);
}

/** Depois que uma tarefa termina: fecha a tarefa-mãe dividida e, se for o caso, o plano. */
function ft_apos_final(PDO $pdo, array $t, bool $concluida): void {
    if ($concluida) ft_disparar_servidor($pdo, (int)$t['id']);
    $pai = $t['tarefa_pai_id'] !== null ? (int)$t['tarefa_pai_id'] : 0;
    if ($pai > 0) {
        $st = $pdo->prepare("SELECT status FROM jarvis_tarefas WHERE id=:id");
        $st->execute([':id' => $pai]);
        if ($st->fetchColumn() === 'AGUARDANDO_PARTES') {
            $q = $pdo->prepare("SELECT id,titulo,status,resultado,erro FROM jarvis_tarefas WHERE tarefa_pai_id=:p ORDER BY ordem,id");
            $q->execute([':p' => $pai]);
            $filhas = $q->fetchAll(PDO::FETCH_ASSOC);
            $abertas = array_filter($filhas, fn($f) => !in_array($f['status'], FT_FINAIS, true));
            if (!$abertas) {
                $partes = array_map(fn($f) => [
                    'tarefa_id' => (int)$f['id'], 'titulo' => $f['titulo'], 'status' => $f['status'],
                    'resultado' => ft_decode($f['resultado']), 'erro' => $f['erro'],
                ], $filhas);
                $falhas = array_filter($filhas, fn($f) => $f['status'] !== 'CONCLUIDA');
                if ($falhas) {
                    ft_marcar_erro($pdo, $pai, count($falhas) . ' parte(s) falharam', ['partes' => $partes]);
                } else {
                    $pdo->prepare("UPDATE jarvis_tarefas SET status='CONCLUIDA', resultado=:r, erro=NULL, concluido_em=NOW() WHERE id=:id")
                        ->execute([':r' => ft_json(['partes' => $partes]), ':id' => $pai]);
                    $st = $pdo->prepare("SELECT * FROM jarvis_tarefas WHERE id=:id");
                    $st->execute([':id' => $pai]);
                    if ($row = $st->fetch(PDO::FETCH_ASSOC)) ft_apos_final($pdo, $row, true);
                }
                return; // ft_marcar_erro / chamada recursiva já atualizam o plano
            }
        }
    }
    ft_atualizar_plano($pdo, (int)$t['id_plano']);
}

/** Fecha o plano quando não resta nenhuma tarefa da fila em andamento. */
function ft_atualizar_plano(PDO $pdo, int $idPlano): void {
    if ($idPlano <= 0) return;
    $ph = implode(',', array_fill(0, count(FT_EM_ANDAMENTO), '?'));
    $st = $pdo->prepare("SELECT
            SUM(fila IS NOT NULL) AS da_fila,
            SUM(status IN ({$ph})) AS abertas,
            SUM(fila IS NOT NULL AND status='ERRO') AS erros
        FROM jarvis_tarefas WHERE id_plano=?");
    $st->execute(array_merge(FT_EM_ANDAMENTO, [$idPlano]));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ((int)$r['da_fila'] === 0 || (int)$r['abertas'] > 0) return;
    $status = (int)$r['erros'] > 0 ? 'ERRO' : 'CONCLUIDO';
    $pdo->prepare("UPDATE jarvis_planos SET status=:s, concluido_em=COALESCE(concluido_em,NOW()) WHERE id=:id AND status<>:s2")
        ->execute([':s' => $status, ':s2' => $status, ':id' => $idPlano]);
}

/** Tarefas da fila 'servidor' que dependiam desta: o site executa agora (acionamento de dispositivo). */
function ft_disparar_servidor(PDO $pdo, int $idConcluida): void {
    $st = $pdo->prepare("SELECT * FROM jarvis_tarefas WHERE depende_de=:id AND fila=:f AND status='PENDENTE'");
    $st->execute([':id' => $idConcluida, ':f' => FT_FILA_SERVIDOR]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $id = (int)$t['id'];
        if ($t['executor'] !== 'dispositivo' || !function_exists('te_device_action')) {
            ft_marcar_erro($pdo, $id, 'Executor sem tratamento no site: ' . $t['executor']);
            continue;
        }
        $payload = ft_decode($t['payload']);
        $ctx = [
            'id_plano' => (int)$t['id_plano'], 'id_tarefa_raiz' => (int)($t['tarefa_pai_id'] ?? $id),
            'current_task_id' => $id, 'correlation_id' => (string)($t['correlation_id'] ?? ''),
            'origem' => 'FILA', 'modulo' => 'fila',
        ];
        try {
            $pdo->prepare("UPDATE jarvis_tarefas SET status='EXECUTANDO', iniciado_em=NOW() WHERE id=:id")->execute([':id' => $id]);
            $fila = te_device_action($pdo, $ctx, $id, is_array($payload) ? $payload : [], is_array($payload) && !empty($payload['confirm']));
            $pdo->prepare("UPDATE jarvis_tarefas SET status='AGUARDANDO', resultado=:r WHERE id=:id")
                ->execute([':r' => ft_json(['queued' => true, 'dados' => $fila]), ':id' => $id]);
        } catch (Throwable $e) {
            ft_marcar_erro($pdo, $id, 'Falha ao acionar dispositivo: ' . $e->getMessage());
        }
    }
}

/** Chamado pelo motor de tarefas quando uma tarefa termina fora da fila (ex.: comando de dispositivo confirmado). */
function ft_tarefa_terminou(PDO $pdo, int $id, bool $concluida): void {
    $st = $pdo->prepare("SELECT * FROM jarvis_tarefas WHERE id=:id");
    $st->execute([':id' => $id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return;
    if ($concluida) ft_apos_final($pdo, $t, true);
    else ft_marcar_erro($pdo, $id, (string)($t['erro'] ?? 'Falha'));
}

/** Fecha planos que têm tarefas de fila e nada mais em andamento (rede de segurança). */
function ft_fechar_planos(PDO $pdo): void {
    try {
        $ids = $pdo->query("SELECT DISTINCT id_plano FROM jarvis_tarefas t
            JOIN jarvis_planos p ON p.id=t.id_plano
            WHERE t.fila IS NOT NULL AND p.status NOT IN ('CONCLUIDO','ERRO')
            LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $p) ft_atualizar_plano($pdo, (int)$p);
    } catch (Throwable $e) {}
}
