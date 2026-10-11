<?php
/**
 * CASA / JARVIS - Servico de Monitoramento de Clusters e Nos ARM
 *
 * Arquitetura de "Padrao Invertido" (Heartbeat Push / Dead Man's Snitch):
 *  - O no/cluster envia heartbeat a cada 20 segundos.
 *  - Se em 40 segundos o servidor nao receber comunicacao do no, ele caiu.
 *
 * Este servico em background roda continuamente ou via cron/systemd e:
 *  1. Verifica a diferenca de tempo entre o ultimo heartbeat/ping e NOW().
 *  2. Atualiza a coluna `status` de 'online' para 'offline' imediatamente se > 40s.
 *  3. Registra logs de eventos e alertas quando um no do cluster cai ou se restabelece.
 *
 * Modos de uso:
 *  - Daemon continuo: php casa-cluster-monitor.php --daemon
 *  - Ciclo unico (cron): php casa-cluster-monitor.php --once
 */

declare(strict_types=1);

// Localiza api/db.php
$baseDir = dirname(__DIR__, 3);
$dbPath = $baseDir . '/site/var/www/html/api/db.php';
if (!file_exists($dbPath)) {
    // Tenta caminho relativo alternativo
    $dbPath = __DIR__ . '/../../../site/var/www/html/api/db.php';
}

if (!file_exists($dbPath)) {
    fwrite(STDERR, "[Cluster Monitor ERRO] Nao foi possivel localizar db.php em: {$dbPath}\n");
    exit(1);
}

require_once $dbPath;

$options = getopt('', ['daemon', 'once', 'timeout::', 'interval::', 'verbose']);
$isDaemon = isset($options['daemon']) || !isset($options['once']);
$timeoutSec = isset($options['timeout']) ? max(10, (int)$options['timeout']) : 40;
$intervalSec = isset($options['interval']) ? max(1, (int)$options['interval']) : 5;
$verbose = isset($options['verbose']);

function monitor_log(string $msg): void {
    $now = date('Y-m-d H:i:s');
    echo "[{$now}] {$msg}\n";
}

function registrar_evento_no(PDO $pdo, string $tipoEvento, string $deviceId, string $descricao): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO device_events (device_id, event_type, priority, payload, data_hora) 
            VALUES (:d, :t, :p, :j, NOW())");
        $stmt->execute([
            ':d' => $deviceId,
            ':t' => $tipoEvento,
            ':p' => ($tipoEvento === 'NODE_OFFLINE' ? 'high' : 'normal'),
            ':j' => json_encode(['mensagem' => $descricao, 'timestamp' => time()], JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Throwable $e) {
        // Tabela de eventos pode nao estar disponivel em esquemas minimos
    }

    try {
        $stmt2 = $pdo->prepare("INSERT INTO comandos_log (origem, comando, resposta, data_hora) 
            VALUES ('CLUSTER_MONITOR', :cmd, :resp, NOW())");
        $stmt2->execute([
            ':cmd' => "{$tipoEvento}: {$deviceId}",
            ':resp' => $descricao
        ]);
    } catch (Throwable $e) {}
}

function run_monitor_cycle(PDO $pdo, int $timeoutSec, bool $verbose): array {
    $now = (int)$pdo->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();
    $caidos = 0;
    $online = 0;

    // 1. Verificar na tabela dispositivos_cluster
    try {
        $sql = "SELECT id, device_id, nome, status, ultimo_heartbeat, UNIX_TIMESTAMP(ultimo_heartbeat) AS last_seen 
                FROM dispositivos_cluster 
                WHERE (tipo = 'linux-arm' OR tipo = 'arm-agent' OR capabilities LIKE '%arm-agent%' OR capabilities LIKE '%linux-arm%')
                  AND credential_revoked_at IS NULL";
        $stmt = $pdo->query($sql);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($devices as $d) {
            $lastSeen = $d['last_seen'] !== null ? (int)$d['last_seen'] : null;
            $diff = $lastSeen !== null ? ($now - $lastSeen) : null;
            $currStatus = strtolower((string)$d['status']);
            $devId = (string)($d['device_id'] ?: 'device:' . $d['id']);
            $nome = (string)$d['nome'];

            // Se excedeu 40s ou nunca enviou heartbeat
            if ($diff === null || $diff > $timeoutSec) {
                if ($currStatus === 'online') {
                    // O no acabou de cair!
                    $upd = $pdo->prepare("UPDATE dispositivos_cluster SET status = 'offline' WHERE id = :id");
                    $upd->execute([':id' => $d['id']]);
                    $secStr = $diff !== null ? "{$diff}s" : "sem registro";
                    monitor_log("[ALERTA] Nó '{$nome}' ({$devId}) CAIU! Sem heartbeat há {$secStr} (limite: {$timeoutSec}s).");
                    registrar_evento_no($pdo, 'NODE_OFFLINE', $devId, "Cluster nó {$nome} parou de responder (offline após {$secStr})");
                    $caidos++;
                }
            } else {
                // Heartbeat dentro da janela de 40s
                if ($currStatus !== 'online') {
                    $upd = $pdo->prepare("UPDATE dispositivos_cluster SET status = 'online' WHERE id = :id");
                    $upd->execute([':id' => $d['id']]);
                    monitor_log("[RECUPERADO] Nó '{$nome}' ({$devId}) está ONLINE (heartbeat recente há {$diff}s).");
                    registrar_evento_no($pdo, 'NODE_ONLINE', $devId, "Cluster nó {$nome} restabeleceu contato (online)");
                }
                $online++;
            }
        }
    } catch (Throwable $e) {
        monitor_log("[ERRO dispositivos_cluster] " . $e->getMessage());
    }

    // 2. Verificar na tabela legada arm_nodes
    try {
        $sqlArm = "SELECT id, device_id, hostname, status, ultimo_ping, UNIX_TIMESTAMP(ultimo_ping) AS last_seen 
                   FROM arm_nodes";
        $stmtArm = $pdo->query($sqlArm);
        $armRows = $stmtArm->fetchAll(PDO::FETCH_ASSOC);

        foreach ($armRows as $a) {
            $lastSeen = $a['last_seen'] !== null ? (int)$a['last_seen'] : null;
            $diff = $lastSeen !== null ? ($now - $lastSeen) : null;
            $currStatus = strtolower((string)$a['status']);
            $devId = (string)($a['device_id'] ?: $a['hostname'] ?: 'arm:' . $a['id']);

            if ($diff === null || $diff > $timeoutSec) {
                if ($currStatus === 'online') {
                    $upd = $pdo->prepare("UPDATE arm_nodes SET status = 'offline' WHERE id = :id");
                    $upd->execute([':id' => $a['id']]);
                }
            } else {
                if ($currStatus !== 'online') {
                    $upd = $pdo->prepare("UPDATE arm_nodes SET status = 'online' WHERE id = :id");
                    $upd->execute([':id' => $a['id']]);
                }
            }
        }
    } catch (Throwable $e) {
        // Tabela arm_nodes pode nao existir em alguns bancos
    }

    if ($verbose) {
        monitor_log("[CICLO] Monitoramento concluído. Online: {$online}. Caídos neste ciclo: {$caidos}.");
    }

    return ['online' => $online, 'caidos' => $caidos];
}

// Inicializacao
monitor_log("Iniciando CASA Cluster Monitor (Regra Invertida: Heartbeat 20s / Timeout Queda {$timeoutSec}s)...");

do {
    try {
        $pdo = get_db_pdo();
        run_monitor_cycle($pdo, $timeoutSec, $verbose);
    } catch (Throwable $e) {
        monitor_log("[FALHA DB] " . $e->getMessage() . ". Tentando novamente em {$intervalSec}s...");
    }

    if ($isDaemon) {
        sleep($intervalSec);
    }
} while ($isDaemon);

monitor_log("Ciclo unico finalizado.");
