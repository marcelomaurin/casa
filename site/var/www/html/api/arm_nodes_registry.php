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

function arm_cluster_service_catalog(): array {
    return [
        [
            'id' => 'casa-cluster-site',
            'label' => 'Portal Web do Cluster (:8080)',
            'category' => 'base',
            'protected' => true,
            'exclusive' => false,
            'default_ip' => 'all',
            'default_owner' => 'Todos os nós (Protegido)',
            'port' => 8080,
            'entrypoint' => '/opt/casa-cluster-site/server.py',
            'purpose' => 'Interface web local e API REST de telemetria em tempo real (CPU, RAM, temperatura, processos) e controle remoto dos daemons deste servidor.',
            'impact_when_stopped' => 'SERVIÇO PROTEGIDO: Não pode ser desativado pois deixaria o nó sem painel web e sem API local de gerenciamento.',
        ],
        [
            'id' => 'casa-node-agent',
            'label' => 'Agente ARM do Control Plane (:8098)',
            'category' => 'base',
            'protected' => false,
            'exclusive' => false,
            'default_ip' => 'all',
            'default_owner' => 'Todos os nós (Base)',
            'port' => 8098,
            'entrypoint' => '/opt/casa-node-agent/casa-node-agent.py',
            'purpose' => 'Daemon cliente do Control Plane CASA/1.0: envia heartbeat a cada 20s para o site principal, reporta telemetria/serviços e executa comandos remotos de GPIO, Relés, Serial/RS485 e controle de daemons.',
            'impact_when_stopped' => 'O servidor passará a aparecer como OFFLINE no site principal da casa e deixará de receber comandos da nuvem.',
        ],
        [
            'id' => 'ssh',
            'label' => 'Servidor OpenSSH (:22)',
            'category' => 'base',
            'protected' => false,
            'exclusive' => false,
            'default_ip' => 'all',
            'default_owner' => 'Todos os nós (Base)',
            'port' => 22,
            'entrypoint' => '/usr/sbin/sshd',
            'purpose' => 'Acesso seguro de terminal remoto (SSH) para administração Linux, deploy de código e comunicação com o controlador da malha SSH.',
            'impact_when_stopped' => 'Bloqueia conexões de terminal SSH externas para manutenção direta neste servidor.',
        ],
        [
            'id' => 'casa-tts',
            'label' => 'Síntese Neural de Voz TTS (:8097)',
            'category' => 'cluster',
            'protected' => false,
            'exclusive' => true,
            'default_ip' => '192.168.2.13',
            'default_owner' => 'Raspberry Pi 4B (.13)',
            'port' => 8097,
            'entrypoint' => '/home/mmm/servicos/tts/server_tts.py',
            'purpose' => 'Motor de síntese de voz neural (Piper/eSpeak/Coqui) que converte textos e respostas do Jarvis em áudio falado nas caixas de som e nos dispositivos da casa.',
            'impact_when_stopped' => 'A casa e o assistente Jarvis ficam mudos (avisos falados, confirmações de comandos e alertas sonoros deixam de funcionar).',
        ],
        [
            'id' => 'jarvis-web-agent',
            'label' => 'Agente de Pesquisa Web IA (:8092)',
            'category' => 'cluster',
            'protected' => false,
            'exclusive' => true,
            'default_ip' => '192.168.2.13',
            'default_owner' => 'Raspberry Pi 4B (.13)',
            'port' => 8092,
            'entrypoint' => '/opt/jarvis-web-agent/web_agent.py',
            'purpose' => 'Daemon de busca na internet, raspagem de páginas, consulta de notícias, previsão do tempo e alimentação de contexto externo atualizado para a IA Jarvis.',
            'impact_when_stopped' => 'O Jarvis perde a capacidade de consultar informações em tempo real na internet (notícias, cotações, clima e pesquisas web).',
        ],
        [
            'id' => 'espcam_daemon',
            'label' => 'Visão Computacional & ESP32-CAM (:8088)',
            'category' => 'cluster',
            'protected' => false,
            'exclusive' => true,
            'default_ip' => '192.168.2.13',
            'default_owner' => 'Raspberry Pi 4B (.13)',
            'port' => 8088,
            'entrypoint' => '/home/mmm/servicos/espcam/espcam_daemon.py',
            'purpose' => 'Captura quadros das câmeras ESP32-CAM e RTSP da residência, executa detecção de presença/movimento e encaminha imagens para análise visual por IA.',
            'impact_when_stopped' => 'Interrompe o monitoramento automático das câmeras ESP32-CAM, detecção visual de presença e captura de fotos de segurança.',
        ],
        [
            'id' => 'casa-ssh-agent',
            'label' => 'Controlador SSH da Malha (:8095)',
            'category' => 'cluster',
            'protected' => false,
            'exclusive' => true,
            'default_ip' => '192.168.2.13',
            'default_owner' => 'Raspberry Pi 4B (.13)',
            'port' => 8095,
            'entrypoint' => '/opt/casa/ssh-agent/casa-ssh-agent.py',
            'purpose' => 'Gateway central da malha SSH: permite que o site principal e as ferramentas de administração executem diagnósticos e comandos remotos autenticados em todos os nós ARM da LAN.',
            'impact_when_stopped' => 'Desativa a ponte central de gerenciamento SSH entre os servidores do cluster.',
        ],
        [
            'id' => 'casa-scheduler',
            'label' => 'Agendador Residencial (Scheduler)',
            'category' => 'cluster',
            'protected' => false,
            'exclusive' => true,
            'default_ip' => '192.168.2.16',
            'default_owner' => 'Raspberry Pi 3B (.16)',
            'port' => null,
            'entrypoint' => '/opt/casa-scheduler/casa-scheduler.py',
            'purpose' => 'Motor cronológico da residência: avalia regras agendadas (horários de ligar/desligar luzes, relés, cenas automáticas, avisos matinais e rotinas periódicas) e dispara as ações no horário exato.',
            'impact_when_stopped' => 'Nenhuma rotina agendada por horário (despertador, acionamento programado de relés/iluminação ou cenas automáticas) será disparada.',
        ],
        [
            'id' => 'casa-tunnel',
            'label' => 'Conectividade & Túnel Nuvem',
            'category' => 'cluster',
            'protected' => false,
            'exclusive' => true,
            'default_ip' => '192.168.2.16',
            'default_owner' => 'Raspberry Pi 3B (.16)',
            'port' => null,
            'entrypoint' => '/opt/casa-tunnel/casa-tunnel.py',
            'purpose' => 'Mantém o túnel reverso e a ponte de conectividade segura entre a rede interna da residência (LAN 192.168.2.x) e o servidor em nuvem (maurinsoft.com.br).',
            'impact_when_stopped' => 'A nuvem perde acesso direto por túnel aos serviços internos da rede local da casa.',
        ],
        [
            'id' => 'casa-runpod-agent',
            'label' => 'Agente RunPod & GPU Remota (:8096)',
            'category' => 'cluster',
            'protected' => false,
            'exclusive' => true,
            'default_ip' => '192.168.2.16',
            'default_owner' => 'Raspberry Pi 3B (.16)',
            'port' => 8096,
            'entrypoint' => '/opt/casa/servicos/runpod-agent/casa-runpod-agent.py',
            'purpose' => 'Gerencia sob demanda instâncias de GPU na nuvem (RunPod) para tarefas pesadas de IA (modelos LLM grandes, visão avançada e treinamento), desligando pods ociosos para economizar custos.',
            'impact_when_stopped' => 'Desativa o provisionamento automático e o monitoramento de GPUs remotas no RunPod.',
        ],
        [
            'id' => 'mariadb',
            'label' => 'Banco MariaDB / MySQL (maurinsoftdb)',
            'category' => 'database',
            'protected' => false,
            'exclusive' => true,
            'default_ip' => '192.168.2.15',
            'default_owner' => 'Cubieboard DB (.15)',
            'port' => 3306,
            'entrypoint' => '/usr/sbin/mariadbd',
            'purpose' => 'Servidor de banco de dados relacional que hospeda o banco central da casa (maurinsoftdb: dispositivos, sensores, agendamentos, usuários e logs). Deve rodar exclusivamente no servidor de banco.',
            'impact_when_stopped' => 'Desativado nos nós de aplicação (.13, .14, .16) para economizar RAM. Se parado no servidor de banco (.15), interrompe as consultas SQL locais.',
        ],
    ];
}

function arm_node_default_profile(string $ip): array {
    $profiles = [
        '192.168.2.13' => [
            'role' => 'Nó de IA, Voz Neural TTS, Visão ESPCam & Controlador SSH',
            'assigned_services' => ['casa-cluster-site', 'casa-node-agent', 'ssh', 'casa-tts', 'jarvis-web-agent', 'espcam_daemon', 'casa-ssh-agent'],
        ],
        '192.168.2.16' => [
            'role' => 'Nó de Automação (Scheduler), Túnel Nuvem & RunPod GPU',
            'assigned_services' => ['casa-cluster-site', 'casa-node-agent', 'ssh', 'casa-scheduler', 'casa-tunnel', 'casa-runpod-agent'],
        ],
        '192.168.2.14' => [
            'role' => 'Nó Gateway de Hardware, Serial, RS485 & Sensores',
            'assigned_services' => ['casa-cluster-site', 'casa-node-agent', 'ssh'],
        ],
        '192.168.2.15' => [
            'role' => 'Nó de Banco de Dados Central (maurinsoftdb)',
            'assigned_services' => ['casa-cluster-site', 'casa-node-agent', 'ssh', 'mariadb'],
        ],
    ];
    return $profiles[$ip] ?? [
        'role' => 'Nó do Cluster ARM',
        'assigned_services' => ['casa-cluster-site', 'casa-node-agent', 'ssh'],
    ];
}

function arm_node_normalize(array $row, bool $legacy, int $now): array {
    $meta = arm_node_json($row['metadata'] ?? null);
    $lastSeen = $row['last_seen_epoch'] ?? null;
    $age = is_numeric($lastSeen) ? max(0, $now - (int)$lastSeen) : null;
    $status = strtolower((string)($row['status'] ?? ''));
    $revoked = !empty($row['credential_revoked_at']) || $status === 'revoked';
    $online = !$revoked && $age !== null && $age <= 40 && !in_array($status, ['offline', 'error', 'erro'], true);
    $version = (string)($meta['version'] ?? $meta['commit'] ?? $row['firmware_version'] ?? '');
    $commit = (string)($meta['commit'] ?? $meta['git_commit'] ?? '');
    $remoteCommit = (string)($meta['remote_commit'] ?? '');
    $branch = (string)($meta['branch'] ?? 'master');
    $lastCheck = (string)($meta['last_check'] ?? '');
    $deployedAt = (string)($meta['deployed_at'] ?? $meta['last_update'] ?? '');

    $upToDate = null;
    if (isset($meta['up_to_date'])) {
        $upToDate = (bool)$meta['up_to_date'];
    } elseif ($commit !== '' && $remoteCommit !== '') {
        $upToDate = (substr($commit, 0, 7) === substr($remoteCommit, 0, 7));
    }
    if ($commit !== '' && !str_contains($version, substr($commit, 0, 7))) {
        $version = ($version !== '' && $version !== '1.1.0' ? $version . ' · ' : '') . '#' . substr($commit, 0, 7);
    }
    // Sem commit informado pelo nó a versão é desconhecida: não inventar um commit.
    if ($version === '1.1.0' && $commit === '') $version = '';

    $ipAddr = (string)($row['local_ip'] ?? $row['ip_address'] ?? '');
    $defProfile = arm_node_default_profile($ipAddr);

    $rawRole = trim((string)($meta['node_role'] ?? $row['papel'] ?? $row['model'] ?? ''));
    if ($rawRole === '' || in_array(strtolower($rawRole), ['arm-agent', 'linux-arm', 'agente linux arm'], true)) {
        $rawRole = $defProfile['role'];
    }

    $services = is_array($meta['services'] ?? null) ? $meta['services'] : [];
    $assigned = is_array($meta['assigned_services'] ?? null) && !empty($meta['assigned_services'])
        ? array_values(array_unique(array_map('strval', $meta['assigned_services'])))
        : $defProfile['assigned_services'];
    if (!in_array('casa-cluster-site', $assigned, true)) {
        array_unshift($assigned, 'casa-cluster-site');
    }

    $catalog = arm_cluster_service_catalog();
    $servicesDetail = [];
    foreach ($catalog as $svcItem) {
        $sid = $svcItem['id'];
        $isAssigned = in_array($sid, $assigned, true);
        $st = (string)($services[$sid] ?? ($sid === 'casa-cluster-site' && $online ? 'active' : ($isAssigned && $online ? 'active' : 'inactive')));
        if ($isAssigned || $st === 'active') {
            $servicesDetail[] = [
                'id' => $sid,
                'label' => $svcItem['label'],
                'category' => $svcItem['category'],
                'protected' => (bool)$svcItem['protected'],
                'exclusive' => (bool)$svcItem['exclusive'],
                'assigned' => $isAssigned,
                'state' => $st,
                'active' => ($st === 'active'),
                'purpose' => (string)($svcItem['purpose'] ?? ''),
                'impact_when_stopped' => (string)($svcItem['impact_when_stopped'] ?? ''),
                'entrypoint' => (string)($svcItem['entrypoint'] ?? ''),
                'port' => $svcItem['port'] ?? null,
            ];
        }
    }

    return [
        'id' => ($legacy ? 'legacy:' : 'device:').$row['id'],
        'device_id' => (string)($row['device_id'] ?? ''),
        'nome' => (string)($row['nome'] ?? $meta['hostname'] ?? $row['hostname'] ?? $row['device_id'] ?? 'Agente ARM'),
        'hostname' => (string)($meta['hostname'] ?? $row['hostname'] ?? ''),
        'ip_address' => $ipAddr,
        'papel' => $rawRole,
        'platform' => (string)($meta['platform'] ?? 'Linux ARM'),
        'version' => $version,
        'commit' => $commit,
        'remote_commit' => $remoteCommit,
        'up_to_date' => $upToDate,
        'branch' => $branch,
        'last_check' => $lastCheck,
        'deployed_at' => $deployedAt,
        'cpu' => arm_node_metric($meta['cpu'] ?? $row['cpu_info'] ?? null, 'cpu'),
        'ram' => arm_node_metric($meta['ram'] ?? $row['ram_info'] ?? null, 'ram'),
        'capabilities' => arm_node_caps($row['capabilities'] ?? null),
        'services' => $services,
        'assigned_services' => $assigned,
        'services_detail' => $servicesDetail,
        'last_seen' => $row[$legacy ? 'ultimo_ping' : 'ultimo_heartbeat'] ?? null,
        'age_seconds' => $age,
        'online' => $online,
        'status' => $revoked ? 'revoked' : ($online ? 'online' : ($age === null ? 'registered' : 'offline')),
        'source' => $legacy ? 'arm_nodes' : 'dispositivos_cluster',
    ];
}

function arm_cluster_orchestration_summary(array $nodes): array {
    $catalog = arm_cluster_service_catalog();
    $result = [];
    foreach ($catalog as $svc) {
        if (empty($svc['exclusive'])) continue;
        $sid = $svc['id'];
        $assignedNode = null;
        $activeNodes = [];
        foreach ($nodes as $n) {
            $nAssigned = $n['assigned_services'] ?? [];
            $nServices = $n['services'] ?? [];
            $st = $nServices[$sid] ?? ($n['online'] && in_array($sid, $nAssigned, true) ? 'active' : 'inactive');
            if (in_array($sid, $nAssigned, true) && $assignedNode === null) {
                $assignedNode = $n;
            }
            if ($st === 'active') {
                $activeNodes[] = [
                    'device_id' => $n['device_id'],
                    'nome' => $n['nome'],
                    'ip_address' => $n['ip_address'],
                ];
            }
        }
        $ownerDevice = $assignedNode['device_id'] ?? ($activeNodes[0]['device_id'] ?? '');
        $ownerName = $assignedNode['nome'] ?? ($activeNodes[0]['nome'] ?? $svc['default_owner']);
        $ownerIp = $assignedNode['ip_address'] ?? ($activeNodes[0]['ip_address'] ?? $svc['default_ip']);
        $isActive = !empty($activeNodes) || ($assignedNode && !empty($assignedNode['online']) && (($assignedNode['services'][$sid] ?? 'active') === 'active'));
        $result[] = [
            'id' => $sid,
            'label' => $svc['label'],
            'category' => $svc['category'],
            'protected' => (bool)$svc['protected'],
            'exclusive' => (bool)$svc['exclusive'],
            'purpose' => (string)($svc['purpose'] ?? ''),
            'impact_when_stopped' => (string)($svc['impact_when_stopped'] ?? ''),
            'entrypoint' => (string)($svc['entrypoint'] ?? ''),
            'port' => $svc['port'] ?? null,
            'owner_device_id' => $ownerDevice,
            'owner_name' => $ownerName,
            'owner_ip' => $ownerIp,
            'active' => $isActive,
            'state' => $isActive ? 'active' : 'inactive',
            'active_nodes' => $activeNodes,
            'redundant' => count($activeNodes) > 1,
        ];
    }
    return $result;
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
