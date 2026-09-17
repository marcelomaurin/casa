-- CASA/JARVIS - Migration automatica 1.20
-- Executada por db.php somente quando param/VERSAO for diferente de 1.20.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS param (
    chave VARCHAR(120) NOT NULL,
    valor TEXT NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Control Plane / Device Registry
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
    ADD COLUMN IF NOT EXISTS config_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER health,
    ADD COLUMN IF NOT EXISTS metadata JSON NULL AFTER capabilities;

CREATE UNIQUE INDEX IF NOT EXISTS uk_dispositivos_token_hash ON dispositivos_cluster(device_token_hash);
CREATE INDEX IF NOT EXISTS idx_dispositivos_gateway ON dispositivos_cluster(gateway_device_id);
CREATE INDEX IF NOT EXISTS idx_dispositivos_health ON dispositivos_cluster(health, ultimo_heartbeat);

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

ALTER TABLE api_client_tokens
    ADD COLUMN IF NOT EXISTS device_id VARCHAR(120) NULL AFTER nome,
    ADD COLUMN IF NOT EXISTS token_prefix VARCHAR(24) NULL AFTER token_hash,
    ADD COLUMN IF NOT EXISTS revogado_em DATETIME NULL AFTER expira_em;

CREATE INDEX IF NOT EXISTS idx_api_tokens_device ON api_client_tokens(device_id, ativo);

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
    ADD COLUMN IF NOT EXISTS last_attempt_at DATETIME NULL AFTER entregue_em,
    ADD COLUMN IF NOT EXISTS iniciado_em DATETIME NULL AFTER ack_em;

CREATE UNIQUE INDEX IF NOT EXISTS uk_device_command_idempotency ON device_commands(device_id,idempotency_key);
CREATE INDEX IF NOT EXISTS idx_device_commands_lifecycle ON device_commands(device_id,lifecycle_status,criado_em);

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

-- Cenas e Rotinas
CREATE TABLE IF NOT EXISTS scenes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) NOT NULL,
    nome VARCHAR(160) NOT NULL,
    descricao TEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    stop_on_error TINYINT(1) NOT NULL DEFAULT 0,
    risk_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_scenes_slug (slug),
    KEY idx_scenes_ativo_nome (ativo,nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scene_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scene_id BIGINT UNSIGNED NOT NULL,
    ordem INT NOT NULL DEFAULT 10,
    device_id VARCHAR(120) NOT NULL,
    comando VARCHAR(120) NOT NULL,
    payload JSON NULL,
    prioridade VARCHAR(20) NOT NULL DEFAULT 'normal',
    required_capability VARCHAR(120) NULL,
    risk_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ttl_seconds INT UNSIGNED NOT NULL DEFAULT 300,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_scene_actions_scene (scene_id,enabled,ordem,id),
    KEY idx_scene_actions_device (device_id),
    CONSTRAINT fk_scene_actions_scene FOREIGN KEY (scene_id) REFERENCES scenes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scene_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scene_id BIGINT UNSIGNED NOT NULL,
    requested_by VARCHAR(160) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'QUEUED',
    correlation_id VARCHAR(80) NOT NULL,
    resumo JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciado_em DATETIME NULL,
    concluido_em DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_scene_runs_correlation (correlation_id),
    KEY idx_scene_runs_scene_data (scene_id,criado_em),
    KEY idx_scene_runs_status_data (status,criado_em),
    CONSTRAINT fk_scene_runs_scene FOREIGN KEY (scene_id) REFERENCES scenes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scene_run_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    scene_action_id BIGINT UNSIGNED NULL,
    command_id BIGINT UNSIGNED NULL,
    device_id VARCHAR(120) NOT NULL,
    comando VARCHAR(120) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'QUEUED',
    erro TEXT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_scene_run_actions_run (run_id,id),
    KEY idx_scene_run_actions_command (command_id),
    CONSTRAINT fk_scene_run_actions_run FOREIGN KEY (run_id) REFERENCES scene_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_scene_run_actions_action FOREIGN KEY (scene_action_id) REFERENCES scene_actions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO scenes(slug,nome,descricao,ativo,stop_on_error,risk_level)
VALUES('boa-noite','Boa noite','Cena noturna da residência',1,0,1)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),descricao=VALUES(descricao);

-- Motor de regras deterministico
CREATE TABLE IF NOT EXISTS automation_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) NOT NULL,
    nome VARCHAR(160) NOT NULL,
    descricao TEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    trigger_type VARCHAR(30) NOT NULL DEFAULT 'event',
    trigger_config JSON NOT NULL,
    conditions_json JSON NULL,
    cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    last_triggered_at DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_automation_rules_slug (slug),
    KEY idx_automation_rules_trigger (enabled,trigger_type),
    KEY idx_automation_rules_last (last_triggered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_rule_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id BIGINT UNSIGNED NOT NULL,
    ordem INT NOT NULL DEFAULT 10,
    device_id VARCHAR(120) NOT NULL,
    comando VARCHAR(120) NOT NULL,
    payload JSON NULL,
    prioridade VARCHAR(20) NOT NULL DEFAULT 'normal',
    required_capability VARCHAR(120) NULL,
    risk_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ttl_seconds INT UNSIGNED NOT NULL DEFAULT 300,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_rule_actions_rule (rule_id,enabled,ordem,id),
    CONSTRAINT fk_rule_actions_rule FOREIGN KEY (rule_id) REFERENCES automation_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_rule_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id BIGINT UNSIGNED NOT NULL,
    source_event_id BIGINT UNSIGNED NULL,
    correlation_id VARCHAR(80) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'QUEUED',
    details JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rule_run_corr (correlation_id),
    KEY idx_rule_runs_rule_data (rule_id,criado_em),
    KEY idx_rule_runs_event (source_event_id),
    CONSTRAINT fk_rule_runs_rule FOREIGN KEY (rule_id) REFERENCES automation_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_rule_run_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    rule_action_id BIGINT UNSIGNED NULL,
    command_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'QUEUED',
    erro TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_rule_run_actions_run (run_id,id),
    KEY idx_rule_run_actions_command (command_id),
    CONSTRAINT fk_rule_run_actions_run FOREIGN KEY (run_id) REFERENCES automation_rule_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_rule_run_actions_action FOREIGN KEY (rule_action_id) REFERENCES automation_rule_actions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO automation_rules(slug,nome,descricao,enabled,trigger_type,trigger_config,conditions_json,cooldown_seconds)
VALUES(
  'presenca-corredor-noturno',
  'Presença no corredor à noite',
  'Exemplo determinístico; configure device/action reais antes de habilitar.',
  0,
  'event',
  JSON_OBJECT('event_type','sensor.presence','device_id','sensor_corredor'),
  JSON_ARRAY(JSON_OBJECT('field','data.presence','op','eq','value',true)),
  30
)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),descricao=VALUES(descricao);
