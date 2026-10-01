<?php
// Read-only projection of current agents and legacy ARM nodes. No credentials.
function arm_node_json($value): array {
    if (is_array($value)) return $value;
    $decoded = is_string($value) ? json_decode($value, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function arm_node_caps($value): array {
    $caps = [];
    foreach (arm_node_json($value) as $key => $val) {
        if (is_array($val)) {
            if (($val['enabled'] ?? true) && is_string($val['name'] ?? null)) $caps[] = $val['name'];
        } elseif (is_int($key) && is_string($val)) {
            $caps[] = $val;
        } elseif (is_string($key) && $val) {
            $caps[] = $key;
        }
    }
    return array_values(array_unique($caps));
}

function arm_node_is_agent(array $row): bool {
    $meta = arm_node_json($row['metadata'] ?? null);
    $caps = array_map('strtolower', arm_node_caps($row['capabilities'] ?? null));
    if (array_intersect($caps, ['arm-agent', 'linux-arm', 'linux-arm-agent', 'raspberry-pi', 'cluster_arm'])) return true;
    $markers = [$row['tipo'] ?? '', $row['manufacturer'] ?? '', $meta['platform'] ?? '', $meta['architecture'] ?? '', $meta['arch'] ?? ''];
    foreach ($markers as $marker) {
        if (is_string($marker) && preg_match('/(?:linux[-_ ]?arm|arm[-_ ]?(?:agent|node|cluster)|cluster[-_ ]?arm|aarch64|arm64|armv[5-9]|raspberry[ _-]?pi|orange[ _-]?pi|cubie[a-z0-9_-]*)/i', $marker)) return true;
    }
    return false;
}

function arm_node_metric($value, string $kind): string {
    if (is_scalar($value)) {
        $decoded = json_decode((string)$value, true);
        if (!is_array($decoded)) return (string)$value;
        $value = $decoded;
    }
    if (!is_array($value)) return '';
    $parts = [];
    if ($kind === 'cpu') {
        if (is_numeric($value['cores'] ?? null)) $parts[] = $value['cores'].' núcleos';
        if (is_numeric($value['load_1m'] ?? null)) $parts[] = 'carga '.$value['load_1m'];
        if (is_numeric($value['temp_c'] ?? null)) $parts[] = $value['temp_c'].' °C';
    } else {
        if (is_numeric($value['used_mb'] ?? null)) $parts[] = $value['used_mb'].' MB usados';
        if (is_numeric($value['total_mb'] ?? null)) $parts[] = $value['total_mb'].' MB total';
    }
    return implode(' / ', $parts);
}

function arm_node_normalize(array $row, bool $legacy, int $now): array {
    $meta = arm_node_json($row['metadata'] ?? null);
    $lastSeen = $row['last_seen_epoch'] ?? null;
    $age = is_numeric($lastSeen) ? max(0, $now - (int)$lastSeen) : null;
    $status = strtolower((string)($row['status'] ?? ''));
    $revoked = !empty($row['credential_revoked_at']) || $status === 'revoked';
    $online = !$revoked && $age !== null && $age <= 120 && !in_array($status, ['offline', 'error', 'erro'], true);
    $version = (string)($meta['version'] ?? $meta['commit'] ?? $row['firmware_version'] ?? '');
    $commit = (string)($meta['commit'] ?? $meta['git_commit'] ?? '');
    $deployedAt = (string)($meta['deployed_at'] ?? $meta['last_update'] ?? '');
    if ($commit !== '' && !str_contains($version, substr($commit, 0, 7))) {
        $version = ($version !== '' && $version !== '1.1.0' ? $version . ' · ' : '') . '#' . substr($commit, 0, 7);
    }
    // Sem commit informado pelo nó a versão é desconhecida: não inventar um commit.
    if ($version === '1.1.0' && $commit === '') $version = '';

    return [
        'id' => ($legacy ? 'legacy:' : 'device:').$row['id'],
        'device_id' => (string)($row['device_id'] ?? ''),
        'nome' => (string)($row['nome'] ?? $meta['hostname'] ?? $row['hostname'] ?? $row['device_id'] ?? 'Agente ARM'),
        'hostname' => (string)($meta['hostname'] ?? $row['hostname'] ?? ''),
        'ip_address' => (string)($row['local_ip'] ?? $row['ip_address'] ?? ''),
        'papel' => (string)($row['papel'] ?? $row['model'] ?? $row['tipo'] ?? 'Agente Linux ARM'),
        'platform' => (string)($meta['platform'] ?? 'Linux ARM'),
        'version' => $version,
        'commit' => $commit,
        'deployed_at' => $deployedAt,
        'cpu' => arm_node_metric($meta['cpu'] ?? $row['cpu_info'] ?? null, 'cpu'),
        'ram' => arm_node_metric($meta['ram'] ?? $row['ram_info'] ?? null, 'ram'),
        'capabilities' => arm_node_caps($row['capabilities'] ?? null),
        'last_seen' => $row[$legacy ? 'ultimo_ping' : 'ultimo_heartbeat'] ?? null,
        'age_seconds' => $age,
        'online' => $online,
        'status' => $revoked ? 'revoked' : ($online ? 'online' : ($age === null ? 'registered' : 'offline')),
        'source' => $legacy ? 'arm_nodes' : 'dispositivos_cluster',
    ];
}

function arm_nodes_merge(array $legacy, array $devices, int $now): array {
    $nodes = [];
    foreach ($legacy as $row) {
        $id = trim((string)($row['device_id'] ?? ''));
        $key = $id !== '' ? 'id:'.$id : 'legacy:'.$row['id'];
        $nodes[$key] = arm_node_normalize($row, true, $now);
    }
    foreach ($devices as $row) {
        $id = trim((string)($row['device_id'] ?? ''));
        $key = $id !== '' ? 'id:'.$id : 'device:'.$row['id'];
        if (!isset($nodes[$key]) && !arm_node_is_agent($row)) continue;
        $nodes[$key] = arm_node_normalize($row, false, $now);
    }
    $nodes = array_values($nodes);
    usort($nodes, function ($a, $b) {
        return ((int)$b['online'] <=> (int)$a['online']) ?: strcasecmp($a['nome'], $b['nome']) ?: strcmp($a['id'], $b['id']);
    });
    return $nodes;
}

function arm_nodes_list(PDO $pdo): array {
    $now = (int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    $legacy = [];
    try {
        $legacy = $pdo->query('SELECT id,device_id,hostname,ip_address,papel,status,cpu_info,ram_info,capabilities,ultimo_ping,UNIX_TIMESTAMP(ultimo_ping) AS last_seen_epoch FROM arm_nodes')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $legacy = [];
    }
    $devices = [];
    try {
        $devices = $pdo->query('SELECT id,device_id,nome,tipo,manufacturer,model,status,local_ip,ip_address,firmware_version,capabilities,metadata,ultimo_heartbeat,credential_revoked_at,UNIX_TIMESTAMP(ultimo_heartbeat) AS last_seen_epoch FROM dispositivos_cluster')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $devices = [];
    }
    return arm_nodes_merge($legacy, $devices, $now);
}
