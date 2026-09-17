-- CASA/JARVIS - Control Plane seguro para todos os devices
-- Migration incremental. Nao remove campos legados para manter compatibilidade.

SET NAMES utf8mb4;

-- Identidade/autenticacao moderna do device. device_token legado permanece durante migracao.
ALTER TABLE dispositivos_cluster
    ADD COLUMN IF NOT EXISTS device_token_hash CHAR(64) NULL AFTER device_token,
    ADD COLUMN IF NOT EXISTS credential_version INT NOT NULL DEFAULT 1 AFTER device_token_hash,
    ADD COLUMN IF NOT EXISTS credential_revoked_at DATETIME NULL AFTER credential_version,
    ADD COLUMN IF NOT EXISTS protocol_version VARCHAR(30) NULL AFTER credential_revoked_at,
    ADD COLUMN IF NOT EXISTS firmware_version VARCHAR(60) NULL AFTER protocol_version,
    ADD COLUMN IF NOT EXISTS manufacturer VARCHAR(80) NULL AFTER firmware_version,
    ADD COLUMN IF NOT EXISTS model VARCHAR(100) NULL AFTER manufacturer,
    ADD COLUMN IF NOT EXISTS transport VARCHAR(30) NULL AFTER model,
    ADD COLUMN IF NOT EXISTS local_ip VARCHAR(45) NULL AFTER transport,
    ADD COLUMN IF NOT EXISTS observed_ip VARCHAR(45) NULL AFTER local_ip,
    ADD COLUMN IF NOT EXISTS gateway_device_id VARCHAR(120) NULL AFTER observed_ip,
    ADD COLUMN IF NOT EXISTS battery_pct INT NULL AFTER gateway_device_id,
    ADD COLUMN IF NOT EXISTS health VARCHAR(30) NOT NULL DEFAULT 'unknown' AFTER battery_pct,
    ADD COLUMN IF NOT EXISTS config_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER health;

CREATE UNIQUE INDEX IF NOT EXISTS uk_dispositivos_token_hash ON dispositivos_cluster(device_token_hash);
CREATE INDEX IF NOT EXISTS idx_dispositivos_gateway ON dispositivos_cluster(gateway_device_id);
CREATE INDEX IF NOT EXISTS idx_dispositivos_health ON dispositivos_cluster(health, ultimo_heartbeat);

-- Registry normalizado de capacidades. O JSON dispositivos_cluster.capabilities permanece
-- como cache/compatibilidade para firmwares e clientes antigos.
CREATE TABLE IF NOT EXISTS device_capabilities (
    device_id VARCHAR(120) NOT NULL,
    capability VARCHAR(120) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    risk_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    config JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (device_id, capability),
    KEY idx_device_capability_name (capability, enabled),
    KEY idx_device_capability_risk (risk_level, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Token API vinculado explicitamente ao device quando aplicavel.
ALTER TABLE api_client_tokens
    ADD COLUMN IF NOT EXISTS device_id VARCHAR(120) NULL AFTER nome,
    ADD COLUMN IF NOT EXISTS token_prefix VARCHAR(24) NULL AFTER token_hash,
    ADD COLUMN IF NOT EXISTS revogado_em DATETIME NULL AFTER expira_em;
CREATE INDEX IF NOT EXISTS idx_api_tokens_device ON api_client_tokens(device_id, ativo);

-- Fila central CASA -> device.
-- `status` e mantido por compatibilidade com clientes legados.
-- `lifecycle_status` representa o ciclo canonico: QUEUED/SENT/ACKNOWLEDGED/EXECUTING/DONE/FAILED/EXPIRED.
CREATE TABLE IF NOT EXISTS device_commands (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id VARCHAR(120) NOT NULL,
    comando VARCHAR(120) NOT NULL,
    payload JSON NULL,
    prioridade VARCHAR(20) NOT NULL DEFAULT 'normal',
    correlation_id VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(120) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    lifecycle_status VARCHAR(30) NOT NULL DEFAULT 'QUEUED',
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_retries INT UNSIGNED NOT NULL DEFAULT 3,
    requested_by VARCHAR(160) NULL,
    risk_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NULL,
    entregue_em DATETIME NULL,
    last_attempt_at DATETIME NULL,
    ack_em DATETIME NULL,
    iniciado_em DATETIME NULL,
    concluido_em DATETIME NULL,
    resultado JSON NULL,
    erro TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_device_command_correlation (correlation_id),
    UNIQUE KEY uk_device_command_idempotency (device_id,idempotency_key),
    KEY idx_device_commands_poll (device_id,status,prioridade,criado_em),
    KEY idx_device_commands_lifecycle (device_id,lifecycle_status,criado_em),
    KEY idx_device_commands_expira (expira_em,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE device_commands
    ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(120) NULL AFTER correlation_id,
    ADD COLUMN IF NOT EXISTS lifecycle_status VARCHAR(30) NOT NULL DEFAULT 'QUEUED' AFTER status,
    ADD COLUMN IF NOT EXISTS retry_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER lifecycle_status,
    ADD COLUMN IF NOT EXISTS max_retries INT UNSIGNED NOT NULL DEFAULT 3 AFTER retry_count,
    ADD COLUMN IF NOT EXISTS requested_by VARCHAR(160) NULL AFTER max_retries,
    ADD COLUMN IF NOT EXISTS risk_level TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER requested_by,
    ADD COLUMN IF NOT EXISTS last_attempt_at DATETIME NULL AFTER entregue_em;

CREATE UNIQUE INDEX IF NOT EXISTS uk_device_command_idempotency ON device_commands(device_id,idempotency_key);
CREATE INDEX IF NOT EXISTS idx_device_commands_lifecycle ON device_commands(device_id,lifecycle_status,criado_em);

-- Sincroniza o ciclo canonico com registros criados antes desta migration.
UPDATE device_commands
SET lifecycle_status = CASE LOWER(status)
    WHEN 'pending' THEN 'QUEUED'
    WHEN 'ack' THEN 'ACKNOWLEDGED'
    WHEN 'executing' THEN 'EXECUTING'
    WHEN 'success' THEN 'DONE'
    WHEN 'error' THEN 'FAILED'
    WHEN 'expired' THEN 'EXPIRED'
    ELSE lifecycle_status
END;

-- Event bus device -> CASA.
CREATE TABLE IF NOT EXISTS device_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id VARCHAR(120) NOT NULL,
    tipo VARCHAR(120) NOT NULL,
    prioridade VARCHAR(20) NOT NULL DEFAULT 'normal',
    correlation_id VARCHAR(80) NULL,
    dados JSON NULL,
    observado_ip VARCHAR(45) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado TINYINT(1) NOT NULL DEFAULT 0,
    processado_em DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_device_events_device (device_id,criado_em),
    KEY idx_device_events_processado (processado,prioridade,criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historico leve de heartbeat para diagnostico; estado atual continua em dispositivos_cluster.
CREATE TABLE IF NOT EXISTS device_heartbeats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id VARCHAR(120) NOT NULL,
    transport VARCHAR(30) NULL,
    local_ip VARCHAR(45) NULL,
    observed_ip VARCHAR(45) NULL,
    gateway_device_id VARCHAR(120) NULL,
    rssi INT NULL,
    battery_pct INT NULL,
    uptime_sec BIGINT UNSIGNED NULL,
    health VARCHAR(30) NOT NULL DEFAULT 'ok',
    firmware_version VARCHAR(60) NULL,
    protocol_version VARCHAR(30) NULL,
    dados JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_device_hb_device (device_id,criado_em),
    KEY idx_device_hb_health (health,criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
