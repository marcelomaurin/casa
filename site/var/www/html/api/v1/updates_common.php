<?php
// Release imutavel e fonte fixa. A API nunca recebe comandos de shell ou destinos locais.
function updates_catalog(): array {
    return [
        'arm-agent' => 'clusters/comunicacao/arm-agent/',
        'scheduler' => 'clusters/automacao/casa-scheduler/',
        'google-home' => 'clusters/voz/google-home-agent/',
        'tts' => 'clusters/voz/tts/',
        'espcam' => 'clusters/visao/espcam/',
        'avatar' => 'clusters/visao/raspberrypi-avatar-agent/meta-casa-avatar/recipes-casa/casa-avatar/files/',
        'web-agent' => 'clusters/pesquisa/web-agent/',
        'tunnel' => 'clusters/infraestrutura/casa-tunnel/',
        'runpod' => 'clusters/infraestrutura/runpod-agent/',
        'cluster-site' => 'clusters/site/',
        'ssh-agent' => 'clusters/infraestrutura/casa-ssh-agent/',
        'update-agent' => 'clusters/infraestrutura/casa-cluster-update/',
    ];
}

function updates_safe_file(string $path): bool {
    if ($path === '' || strlen($path) > 240 || $path[0] === '/' || strpos($path, '\\') !== false) return false;
    foreach (explode('/', $path) as $part) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $part)) return false;
        if (preg_match('/\.(cfg|env|ini|onnx|wav|db|sqlite|json)$/i', $part)) return false;
    }
    return (bool)preg_match('/\.(py|html|css|js|sh)$/D', $path);
}

function updates_validate_manifest(array $manifest): array {
    if (($manifest['schema'] ?? null) !== 1) throw new InvalidArgumentException('schema deve ser 1');
    $version = (string)($manifest['version'] ?? '');
    if (!preg_match('/^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})$/D', $version)) {
        throw new InvalidArgumentException('version deve usar MAJOR.MINOR.PATCH');
    }
    $commit = (string)($manifest['source_commit'] ?? '');
    if (!preg_match('/^[a-f0-9]{40}$/D', $commit)) throw new InvalidArgumentException('source_commit deve ser um SHA Git fixo');
    $components = $manifest['components'] ?? null;
    if (!is_array($components) || !$components || count($components) > count(updates_catalog())) throw new InvalidArgumentException('components invalidos');
    $catalog = updates_catalog();
    $clean = [];
    $total = 0;
    foreach ($components as $id => $component) {
        if (!isset($catalog[$id]) || !is_array($component)) throw new InvalidArgumentException('Integrador desconhecido');
        $files = $component['files'] ?? null;
        if (!is_array($files) || !$files || count($files) > 32) throw new InvalidArgumentException('Lista de arquivos invalida');
        $targets = [];
        $cleanFiles = [];
        foreach ($files as $file) {
            if (!is_array($file)) throw new InvalidArgumentException('Arquivo invalido');
            $source = (string)($file['source'] ?? '');
            $target = (string)($file['target'] ?? '');
            $hash = (string)($file['sha256'] ?? '');
            $size = $file['size'] ?? null;
            if (!updates_safe_file($source) || !updates_safe_file($target) || strpos($source, $catalog[$id]) !== 0) {
                throw new InvalidArgumentException('Arquivo fora do integrador ou arquivo de configuracao/dados');
            }
            if (isset($targets[$target]) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !is_int($size) || $size < 1 || $size > 5242880) {
                throw new InvalidArgumentException('Hash, tamanho ou destino invalido');
            }
            $targets[$target] = true;
            $total += $size;
            $cleanFiles[] = ['source'=>$source, 'target'=>$target, 'sha256'=>$hash, 'size'=>$size];
        }
        usort($cleanFiles, fn($a, $b) => strcmp($a['target'], $b['target']));
        $clean[$id] = ['files'=>$cleanFiles];
    }
    if ($total > 52428800) throw new InvalidArgumentException('Release excede 50 MB');
    ksort($clean);
    return ['schema'=>1, 'version'=>$version, 'source_commit'=>$commit, 'components'=>$clean];
}

function updates_manifest_json(array $manifest): string {
    return json_encode(updates_validate_manifest($manifest), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function updates_register(PDO $pdo, array $manifest, string $actor): array {
    $json = updates_manifest_json($manifest);
    $version = $manifest['version'];
    $hash = hash('sha256', $json);
    try {
        $s = $pdo->prepare('INSERT INTO cluster_update_releases(version,manifest,manifest_sha256,created_by) VALUES(:v,:m,:h,:a)');
        $s->execute([':v'=>$version, ':m'=>$json, ':h'=>$hash, ':a'=>substr($actor,0,160)]);
        return ['id'=>(int)$pdo->lastInsertId(), 'version'=>$version, 'released'=>false];
    } catch (PDOException $e) {
        $s = $pdo->prepare('SELECT id,manifest_sha256,released_at FROM cluster_update_releases WHERE version=:v');
        $s->execute([':v'=>$version]);
        $old = $s->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw $e;
        if (!hash_equals($old['manifest_sha256'], $hash)) throw new DomainException('Versao ja cadastrada com outro conteudo');
        return ['id'=>(int)$old['id'], 'version'=>$version, 'released'=>$old['released_at'] !== null];
    }
}

function updates_activate(PDO $pdo, string $version): array {
    $pdo->beginTransaction();
    try {
        // Serializa liberacoes concorrentes; registrar nunca altera este ponteiro.
        $current = $pdo->query("SELECT release_id FROM cluster_update_channels WHERE channel='stable' FOR UPDATE")->fetchColumn();
        $s = $pdo->prepare('SELECT id,version FROM cluster_update_releases WHERE version=:v');
        $s->execute([':v'=>$version]);
        $release = $s->fetch(PDO::FETCH_ASSOC);
        if (!$release) throw new DomainException('Versao nao cadastrada');
        if ($current && (int)$current !== (int)$release['id']) {
            $old = $pdo->prepare('SELECT version FROM cluster_update_releases WHERE id=:id');
            $old->execute([':id'=>$current]);
            if (version_compare($release['version'], $old->fetchColumn(), '<=')) throw new DomainException('A nova versao deve ser maior que a liberada');
        }
        $pdo->prepare('UPDATE cluster_update_releases SET released_at=COALESCE(released_at,NOW()) WHERE id=:id')->execute([':id'=>$release['id']]);
        $pdo->prepare("UPDATE cluster_update_channels SET release_id=:id WHERE channel='stable'")->execute([':id'=>$release['id']]);
        $pdo->commit();
        return ['id'=>(int)$release['id'], 'version'=>$release['version'], 'released'=>true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function updates_current(PDO $pdo): ?array {
    $row = $pdo->query("SELECT r.id,r.manifest,r.manifest_sha256,r.released_at FROM cluster_update_channels c JOIN cluster_update_releases r ON r.id=c.release_id WHERE c.channel='stable' AND r.released_at IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return ['id'=>(int)$row['id'], 'manifest'=>json_decode($row['manifest'],true,512,JSON_THROW_ON_ERROR), 'manifest_sha256'=>$row['manifest_sha256'], 'released_at'=>$row['released_at']];
}

// ---------------------------------------------------------------------------
// Atualização contínua a partir do Git (casa-cluster-update 2.x).
// Cada nó acompanha a branch (master) direto do GitHub e informa aqui o commit
// instalado. O painel (Segurança › Atualizações) consulta o estado e pode pedir
// uma atualização forçada, entregue ao nó na próxima consulta de política.
// ---------------------------------------------------------------------------

const UPDATES_REPO_SLUG = 'marcelomaurin/casa';

function updates_nodes_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS cluster_update_nodes (
        device_id VARCHAR(120) NOT NULL,
        hostname VARCHAR(120) NULL,
        branch VARCHAR(100) NULL,
        commit_sha CHAR(40) NULL,
        remote_commit CHAR(40) NULL,
        result VARCHAR(30) NULL,
        error VARCHAR(300) NULL,
        updater_version VARCHAR(30) NULL,
        components LONGTEXT NULL,
        last_check DATETIME NULL,
        last_update DATETIME NULL,
        last_report_at DATETIME NULL,
        force_seq INT UNSIGNED NOT NULL DEFAULT 0,
        force_handled INT UNSIGNED NOT NULL DEFAULT 0,
        force_requested_by VARCHAR(120) NULL,
        force_requested_at DATETIME NULL,
        PRIMARY KEY (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function updates_branch(PDO $pdo): string {
    try {
        $v = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave='cluster_update_branch' LIMIT 1")->fetchColumn();
        if (is_string($v) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/D', $v)) return $v;
    } catch (Throwable $e) {}
    return 'master';
}

/** Nós que podem consultar a política: atualizador, agente ARM ou nó Linux ARM. */
function updates_device_allowed(array $dev): bool {
    $caps = $dev['capabilities'] ?? [];
    return in_array('update-agent', $caps, true) || in_array('arm-agent', $caps, true) || ($dev['tipo'] ?? '') === 'linux-arm';
}

function updates_policy(PDO $pdo, string $deviceId): array {
    updates_nodes_ensure_schema($pdo);
    $s = $pdo->prepare('SELECT force_seq FROM cluster_update_nodes WHERE device_id=:d');
    $s->execute([':d' => $deviceId]);
    return ['mode' => 'git', 'repo' => 'https://github.com/' . UPDATES_REPO_SLUG . '.git', 'branch' => updates_branch($pdo), 'force_seq' => (int)($s->fetchColumn() ?: 0)];
}

function updates_sha_or_null($v): ?string {
    return is_string($v) && preg_match('/^[a-f0-9]{40}$/D', $v) ? $v : null;
}

/** ISO 8601 do nó -> epoch; gravado com FROM_UNIXTIME para usar o mesmo fuso de NOW() do MySQL. */
function updates_iso_to_epoch($v): ?int {
    if (!is_string($v) || $v === '') return null;
    $t = strtotime($v);
    return $t ?: null;
}

function updates_node_report(PDO $pdo, string $deviceId, array $in): void {
    updates_nodes_ensure_schema($pdo);
    $components = [];
    foreach ((array)($in['components'] ?? []) as $name => $c) {
        if (!isset(updates_catalog()[$name]) || !is_array($c)) continue;
        $status = (string)($c['status'] ?? '');
        if (!in_array($status, ['updated', 'unchanged', 'failed', 'not_installed'], true)) continue;
        $components[$name] = ['status' => $status, 'commit' => updates_sha_or_null($c['commit'] ?? null),
            'files_changed' => max(0, (int)($c['files_changed'] ?? 0)), 'updated_at' => substr((string)($c['updated_at'] ?? ''), 0, 25)];
    }
    $result = (string)($in['result'] ?? '');
    if (!in_array($result, ['updated', 'up-to-date', 'failed'], true)) $result = 'unknown';
    $branch = (string)($in['branch'] ?? '');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/D', $branch)) $branch = null;
    $s = $pdo->prepare("INSERT INTO cluster_update_nodes(device_id,hostname,branch,commit_sha,remote_commit,result,error,updater_version,components,last_check,last_update,last_report_at,force_handled)
        VALUES(:d,:h,:b,:c,:r,:res,:e,:v,:comp,FROM_UNIXTIME(:lc),FROM_UNIXTIME(:lu),NOW(),:fh)
        ON DUPLICATE KEY UPDATE hostname=VALUES(hostname),branch=VALUES(branch),commit_sha=VALUES(commit_sha),remote_commit=VALUES(remote_commit),
            result=VALUES(result),error=VALUES(error),updater_version=VALUES(updater_version),components=VALUES(components),
            last_check=VALUES(last_check),last_update=COALESCE(VALUES(last_update),last_update),last_report_at=NOW(),
            force_handled=GREATEST(force_handled,VALUES(force_handled))");
    $s->execute([':d' => $deviceId, ':h' => substr((string)($in['hostname'] ?? ''), 0, 120) ?: null, ':b' => $branch,
        ':c' => updates_sha_or_null($in['commit'] ?? null), ':r' => updates_sha_or_null($in['remote_commit'] ?? null), ':res' => $result,
        ':e' => substr((string)($in['error'] ?? ''), 0, 300) ?: null, ':v' => substr((string)($in['updater_version'] ?? ''), 0, 30) ?: null,
        ':comp' => json_encode($components, JSON_UNESCAPED_SLASHES), ':lc' => updates_iso_to_epoch($in['last_check'] ?? null),
        ':lu' => updates_iso_to_epoch($in['last_update'] ?? null), ':fh' => max(0, (int)($in['force_handled'] ?? 0))]);
}

/** Pede atualização forçada a um nó ou a todos. Retorna quantos nós foram marcados. */
function updates_request_force(PDO $pdo, array $deviceIds, string $actor): int {
    updates_nodes_ensure_schema($pdo);
    $s = $pdo->prepare("INSERT INTO cluster_update_nodes(device_id,force_seq,force_requested_by,force_requested_at) VALUES(:d,1,:a,NOW())
        ON DUPLICATE KEY UPDATE force_seq=force_seq+1,force_requested_by=VALUES(force_requested_by),force_requested_at=NOW()");
    $n = 0;
    foreach (array_unique($deviceIds) as $id) {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9._:-]{1,120}$/D', $id)) continue;
        $s->execute([':d' => $id, ':a' => substr($actor, 0, 120)]);
        $n++;
    }
    return $n;
}

/** Último commit da branch no GitHub, com cache de 60 s (e fallback no que os nós viram). */
function updates_latest_commit(PDO $pdo, string $branch, bool $refresh = false): ?array {
    $key = 'CLUSTER_UPDATE_HEAD_' . substr(preg_replace('/[^A-Za-z0-9]/', '_', $branch), 0, 80);
    $cached = null;
    try {
        $s = $pdo->prepare('SELECT valor,UNIX_TIMESTAMP(atualizado_em) t FROM param WHERE chave=:k');
        $s->execute([':k' => $key]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) { $cached = json_decode((string)$row['valor'], true); if ($cached && !$refresh && time() - (int)$row['t'] < 60) return $cached; }
    } catch (Throwable $e) {}
    $fresh = null;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.github.com/repos/' . UPDATES_REPO_SLUG . '/commits/' . rawurlencode($branch));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: casa-cluster-update-panel']]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = $code === 200 ? json_decode((string)$raw, true) : null;
        if (is_array($j) && updates_sha_or_null($j['sha'] ?? null)) {
            $fresh = ['sha' => $j['sha'], 'message' => mb_substr(strtok((string)($j['commit']['message'] ?? ''), "\n"), 0, 160),
                'date' => (string)($j['commit']['committer']['date'] ?? ''), 'author' => mb_substr((string)($j['commit']['author']['name'] ?? ''), 0, 80), 'source' => 'github'];
        }
    }
    if (!$fresh) {
        // GitHub indisponível a partir do servidor: usa o commit remoto mais recente informado pelos nós.
        try {
            updates_nodes_ensure_schema($pdo);
            $s = $pdo->prepare('SELECT remote_commit FROM cluster_update_nodes WHERE remote_commit IS NOT NULL AND branch=:b ORDER BY last_check DESC LIMIT 1');
            $s->execute([':b' => $branch]);
            $sha = $s->fetchColumn();
            if ($sha) $fresh = ['sha' => $sha, 'message' => '', 'date' => '', 'author' => '', 'source' => 'nodes'];
        } catch (Throwable $e) {}
    }
    if ($fresh) {
        try { $pdo->prepare('INSERT INTO param(chave,valor) VALUES(:k,:v) ON DUPLICATE KEY UPDATE valor=VALUES(valor),atualizado_em=NOW()')->execute([':k' => $key, ':v' => json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]); } catch (Throwable $e) {}
        return $fresh;
    }
    return $cached;
}

/** Visão consolidada para o painel. */
function updates_overview(PDO $pdo, bool $refresh = false): array {
    updates_nodes_ensure_schema($pdo);
    $branch = updates_branch($pdo);
    $latest = updates_latest_commit($pdo, $branch, $refresh);
    $nodes = [];
    $rows = $pdo->query("SELECT device_id,nome,tipo,status,local_ip,ip_address,capabilities,metadata,ultimo_heartbeat,credential_revoked_at FROM dispositivos_cluster WHERE device_id IS NOT NULL AND device_id<>'' AND credential_revoked_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $caps = json_decode((string)$r['capabilities'], true);
        $caps = is_array($caps) ? $caps : [];
        if ($r['tipo'] !== 'linux-arm' && !in_array('arm-agent', $caps, true) && !in_array('update-agent', $caps, true)) continue;
        $nodes[$r['device_id']] = ['device_id' => $r['device_id'], 'nome' => $r['nome'] ?: $r['device_id'], 'ip' => $r['local_ip'] ?: $r['ip_address'],
            'heartbeat' => $r['ultimo_heartbeat']];
    }
    $byId = [];
    foreach ($pdo->query('SELECT *,TIMESTAMPDIFF(SECOND,last_report_at,NOW()) AS idade FROM cluster_update_nodes')->fetchAll(PDO::FETCH_ASSOC) as $u) $byId[$u['device_id']] = $u;
    foreach ($byId as $id => $u) if (!isset($nodes[$id]) && $u['last_report_at']) $nodes[$id] = ['device_id' => $id, 'nome' => $u['hostname'] ?: $id, 'ip' => null, 'heartbeat' => null];
    $out = [];
    foreach ($nodes as $id => $n) {
        $u = $byId[$id] ?? null;
        $commit = $u['commit_sha'] ?? null;
        $pending = $u && (int)$u['force_seq'] > (int)$u['force_handled'];
        if (!$u || !$u['last_report_at']) $state = 'sem_atualizador';
        elseif ((int)$u['idade'] > 900) $state = 'sem_contato';
        elseif ($u['result'] === 'failed') $state = 'falhou';
        elseif ($latest && $commit === $latest['sha']) $state = 'atualizado';
        elseif ($commit) $state = 'desatualizado';
        else $state = 'desconhecido';
        $comps = $u ? json_decode((string)$u['components'], true) : [];
        $out[] = $n + [
            'estado' => $state, 'commit' => $commit, 'remote_commit' => $u['remote_commit'] ?? null, 'branch' => $u['branch'] ?? null,
            'resultado' => $u['result'] ?? null, 'erro' => $u['error'] ?? null, 'updater_version' => $u['updater_version'] ?? null,
            'ultima_verificacao' => $u['last_check'] ?? null, 'ultima_atualizacao' => $u['last_update'] ?? null, 'ultimo_relatorio' => $u['last_report_at'] ?? null,
            'componentes' => is_array($comps) ? $comps : [], 'forcar_pendente' => (bool)$pending,
            'forcar_pedido_por' => $u['force_requested_by'] ?? null, 'forcar_pedido_em' => $u['force_requested_at'] ?? null,
        ];
    }
    usort($out, fn($a, $b) => strcmp((string)$a['nome'], (string)$b['nome']));
    return ['branch' => $branch, 'latest' => $latest, 'nodes' => $out, 'server_time' => date('c')];
}
