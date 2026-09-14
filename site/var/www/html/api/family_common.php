<?php
// CASA/JARVIS - núcleo compartilhado de assistência familiar, presença e broadcast.

function family_ensure_schema(PDO $pdo): void {
    $sql = [
        "CREATE TABLE IF NOT EXISTS family_channels (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nome VARCHAR(120) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            tipo VARCHAR(30) NOT NULL DEFAULT 'familia',
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uk_family_channels_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS family_presence (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canal_id BIGINT UNSIGNED NOT NULL,
            cliente VARCHAR(120) NOT NULL,
            dispositivo VARCHAR(120) NULL,
            plataforma VARCHAR(30) NOT NULL DEFAULT 'web',
            metadata JSON NULL,
            ultimo_ping TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_family_presence (canal_id, cliente, plataforma),
            KEY idx_family_presence_ping (canal_id, ultimo_ping),
            CONSTRAINT fk_family_presence_channel FOREIGN KEY (canal_id) REFERENCES family_channels(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS family_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canal_id BIGINT UNSIGNED NOT NULL,
            remetente VARCHAR(120) NOT NULL,
            origem VARCHAR(30) NOT NULL DEFAULT 'web',
            tipo VARCHAR(30) NOT NULL DEFAULT 'texto',
            mensagem LONGTEXT NULL,
            dados JSON NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_family_messages_channel_id (canal_id, id),
            CONSTRAINT fk_family_messages_channel FOREIGN KEY (canal_id) REFERENCES family_channels(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS family_calls (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canal_id BIGINT UNSIGNED NOT NULL,
            iniciado_por VARCHAR(120) NOT NULL,
            modo VARCHAR(20) NOT NULL DEFAULT 'video',
            status VARCHAR(30) NOT NULL DEFAULT 'chamando',
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            encerrado_em DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_family_calls_channel_status (canal_id, status, criado_em),
            CONSTRAINT fk_family_calls_channel FOREIGN KEY (canal_id) REFERENCES family_channels(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS family_call_signals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            call_id BIGINT UNSIGNED NOT NULL,
            remetente VARCHAR(120) NOT NULL,
            destino VARCHAR(120) NULL,
            tipo VARCHAR(30) NOT NULL,
            payload LONGTEXT NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_family_signals_call_id (call_id, id),
            CONSTRAINT fk_family_signals_call FOREIGN KEY (call_id) REFERENCES family_calls(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS assistencia_eventos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pessoa_ref VARCHAR(120) NULL,
            dispositivo_ref VARCHAR(120) NULL,
            tipo VARCHAR(40) NOT NULL,
            severidade VARCHAR(20) NOT NULL DEFAULT 'info',
            mensagem VARCHAR(500) NULL,
            dados JSON NULL,
            confirmado TINYINT(1) NOT NULL DEFAULT 0,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmado_em DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_assistencia_tipo_data (tipo, criado_em),
            KEY idx_assistencia_confirmado_data (confirmado, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($sql as $q) $pdo->exec($q);
    $pdo->exec("INSERT IGNORE INTO family_channels (nome, slug, tipo) VALUES ('Família CASA', 'familia', 'familia')");
}

function family_channel(PDO $pdo, string $slug = 'familia'): array {
    $stmt = $pdo->prepare('SELECT * FROM family_channels WHERE slug=:s AND ativo=1 LIMIT 1');
    $stmt->execute([':s'=>$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Canal familiar não encontrado');
    return $row;
}

function family_presence(PDO $pdo, int $channelId, string $client, string $platform, ?string $device, array $metadata=[]): void {
    $stmt = $pdo->prepare("INSERT INTO family_presence(canal_id,cliente,dispositivo,plataforma,metadata,ultimo_ping)
        VALUES(:c,:n,:d,:p,:m,NOW())
        ON DUPLICATE KEY UPDATE dispositivo=VALUES(dispositivo), metadata=VALUES(metadata), ultimo_ping=NOW()");
    $stmt->execute([
        ':c'=>$channelId, ':n'=>substr($client,0,120), ':d'=>$device ? substr($device,0,120) : null,
        ':p'=>substr($platform,0,30), ':m'=>json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ]);
}

function family_send(PDO $pdo, int $channelId, string $sender, string $origin, string $type, ?string $message, array $data=[]): int {
    $stmt = $pdo->prepare("INSERT INTO family_messages(canal_id,remetente,origem,tipo,mensagem,dados)
        VALUES(:c,:r,:o,:t,:m,:d)");
    $stmt->execute([
        ':c'=>$channelId, ':r'=>substr($sender,0,120), ':o'=>substr($origin,0,30), ':t'=>substr($type,0,30),
        ':m'=>$message, ':d'=>json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ]);
    return (int)$pdo->lastInsertId();
}

function family_alert(PDO $pdo, string $type, string $severity, string $message, array $data=[], ?string $person=null, ?string $device=null): int {
    $stmt = $pdo->prepare("INSERT INTO assistencia_eventos(pessoa_ref,dispositivo_ref,tipo,severidade,mensagem,dados)
        VALUES(:p,:d,:t,:s,:m,:j)");
    $stmt->execute([
        ':p'=>$person, ':d'=>$device, ':t'=>substr($type,0,40), ':s'=>substr($severity,0,20), ':m'=>substr($message,0,500),
        ':j'=>json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ]);
    $id = (int)$pdo->lastInsertId();
    $priority = in_array($severity, ['critica','alta'], true) ? $severity : 'normal';
    try {
        $n = $pdo->prepare("INSERT INTO mobile_notificacoes(id_dispositivo,titulo,mensagem,prioridade) VALUES(NULL,'Assistência JARVIS',:m,:p)");
        $n->execute([':m'=>$message, ':p'=>$priority]);
    } catch (Throwable $e) {}
    $channel = family_channel($pdo, 'familia');
    family_send($pdo, (int)$channel['id'], 'JARVIS', 'sistema', 'alerta', $message, array_merge($data, ['event_id'=>$id,'severity'=>$severity,'type'=>$type]));
    return $id;
}
