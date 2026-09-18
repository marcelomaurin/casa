-- CASA / COMPUTER - Migration 1.23
-- Auditoria operacional consolidada.

CREATE TABLE IF NOT EXISTS telemetria_operacional (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalizado_em DATETIME NULL,
    origem VARCHAR(120) NULL,
    canal VARCHAR(60) NULL,
    ip_cliente VARCHAR(45) NULL,
    operacao VARCHAR(120) NULL,
    solicitacao LONGTEXT NULL,
    resposta_ia LONGTEXT NULL,
    acao_executada LONGTEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'RECEBIDO',
    modelo VARCHAR(200) NULL,
    duracao_ms INT UNSIGNED NULL,
    detalhes JSON NULL,
    PRIMARY KEY (id),
    KEY idx_telemetria_data (data_hora),
    KEY idx_telemetria_origem_data (origem,data_hora),
    KEY idx_telemetria_status_data (status,data_hora),
    KEY idx_telemetria_ip_data (ip_cliente,data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
