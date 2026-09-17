-- CASA/JARVIS - Motor de regras deterministico (sem IA)
SET NAMES utf8mb4;

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

-- Exemplo desabilitado: deve ser adaptado aos IDs reais antes de ativar.
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
