-- ENGINE: MySQL/MariaDB
-- DOMAIN: CASA Control Plane
-- CASA/JARVIS - Protocolo de Solicitacao e Passagem de Chaves via Celular
-- Permite que dispositivos novos solicitem pareamento e o celular autorize com chave individual.

-- Compativel com MySQL / MariaDB (Hostinger)
CREATE TABLE IF NOT EXISTS device_pairing_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id VARCHAR(64) NOT NULL,
    mac_address VARCHAR(30) NOT NULL,
    device_type VARCHAR(40) NOT NULL,
    model VARCHAR(80) NULL,
    firmware_version VARCHAR(30) NULL,
    capabilities JSON NULL,
    pairing_code VARCHAR(12) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    nome_atribuido VARCHAR(120) NULL,
    localizacao_atribuida VARCHAR(120) NULL,
    issued_device_id VARCHAR(120) NULL,
    issued_token VARCHAR(255) NULL,
    autorizado_por VARCHAR(120) NULL,
    solicitado_ip VARCHAR(45) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    autorizado_em DATETIME NULL,
    consumido_em DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pairing_req_id (request_id),
    KEY idx_pairing_mac (mac_address),
    KEY idx_pairing_status (status, expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
