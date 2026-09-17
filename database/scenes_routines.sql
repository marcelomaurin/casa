-- CASA/JARVIS - Cenas e Rotinas
-- Migration incremental para MySQL/MariaDB.

SET NAMES utf8mb4;

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

-- Cena inicial de exemplo. As actions ficam a cargo do cadastro real de devices.
INSERT INTO scenes(slug,nome,descricao,ativo,stop_on_error,risk_level)
VALUES('boa-noite','Boa noite','Cena noturna da residência',1,0,1)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),descricao=VALUES(descricao);
