-- 1.39: localizacao compartilhada pelos celulares (opcional, ativada no app).
CREATE TABLE IF NOT EXISTS mobile_localizacoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(120) NOT NULL,
    cliente VARCHAR(160) NULL,
    latitude DECIMAL(9,6) NOT NULL,
    longitude DECIMAL(9,6) NOT NULL,
    precisao_m DECIMAL(8,1) NULL,
    altitude_m DECIMAL(8,1) NULL,
    velocidade_ms DECIMAL(7,2) NULL,
    bateria TINYINT UNSIGNED NULL,
    origem VARCHAR(20) NULL,
    capturado_em DATETIME NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mobile_loc_device_tempo (device_id, recebido_em),
    INDEX idx_mobile_loc_tempo (recebido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
