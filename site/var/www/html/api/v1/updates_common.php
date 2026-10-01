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
