-- CASA / COMPUTER - Migration 1.25
-- Unifica Task Engine -> Action -> Command Bus.

CREATE TABLE IF NOT EXISTS jarvis_acoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_plano BIGINT UNSIGNED NOT NULL,
    id_tarefa BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(40) NOT NULL DEFAULT 'DEVICE_COMMAND',
    device_id VARCHAR(120) NOT NULL,
    comando VARCHAR(120) NOT NULL,
    payload JSON NULL,
    required_capability VARCHAR(120) NULL,
    prioridade VARCHAR(20) NOT NULL DEFAULT 'normal',
    risk_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ttl_seconds INT UNSIGNED NOT NULL DEFAULT 300,
    correlation_id VARCHAR(80) NOT NULL,
    command_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'PLANNED',
    resultado JSON NULL,
    erro TEXT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciado_em DATETIME NULL,
    concluido_em DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_jarvis_acoes_corr (correlation_id),
    UNIQUE KEY uk_jarvis_acoes_command (command_id),
    KEY idx_jarvis_acoes_plano (id_plano,id),
    KEY idx_jarvis_acoes_tarefa (id_tarefa,id),
    KEY idx_jarvis_acoes_status (status,criado_em),
    CONSTRAINT fk_jarvis_acoes_plano FOREIGN KEY (id_plano) REFERENCES jarvis_planos(id) ON DELETE CASCADE,
    CONSTRAINT fk_jarvis_acoes_tarefa FOREIGN KEY (id_tarefa) REFERENCES jarvis_tarefas(id) ON DELETE CASCADE,
    CONSTRAINT fk_jarvis_acoes_command FOREIGN KEY (command_id) REFERENCES device_commands(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
