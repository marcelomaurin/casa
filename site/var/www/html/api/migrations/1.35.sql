-- CASA/JARVIS - Migration 1.35
-- Mordomo: o que foi observado na casa e o que foi decidido.

CREATE TABLE IF NOT EXISTS jarvis_mordomo_observacoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chave VARCHAR(190) NOT NULL,
    tipo VARCHAR(60) NOT NULL,
    severidade VARCHAR(10) NOT NULL DEFAULT 'media',
    titulo VARCHAR(255) NOT NULL,
    detalhes JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ABERTA',
    primeira_vez DATETIME NOT NULL,
    ultima_vez DATETIME NOT NULL,
    resolvida_em DATETIME NULL,
    decidida_em DATETIME NULL,
    ultimo_aviso DATETIME NULL,
    avisos INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mordomo_chave (chave),
    KEY idx_mordomo_status (status, severidade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jarvis_mordomo_decisoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    observacao_id BIGINT UNSIGNED NULL,
    acao VARCHAR(20) NOT NULL,
    mensagem TEXT NULL,
    ordem VARCHAR(255) NULL,
    motivo TEXT NULL,
    origem VARCHAR(20) NOT NULL DEFAULT 'ia',
    executado TINYINT(1) NOT NULL DEFAULT 0,
    resultado TEXT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mordomo_dec_data (criado_em),
    KEY idx_mordomo_dec_obs (observacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
