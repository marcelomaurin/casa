-- CASA / JARVIS - Migração 1.31: Câmeras IP, CFTV/DVR e Metadados de Streaming
ALTER TABLE dispositivos_cluster
  ADD COLUMN IF NOT EXISTS metadata JSON NULL,
  ADD COLUMN IF NOT EXISTS capabilities JSON NULL;

CREATE TABLE IF NOT EXISTS redes_wifi_casa (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ssid VARCHAR(64) NOT NULL,
  senha VARCHAR(64) NOT NULL,
  prioridade INT NOT NULL DEFAULT 1,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_redes_wifi_ssid (ssid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camera_wifi_survey (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id VARCHAR(120) NOT NULL,
  ip_address VARCHAR(45) NULL,
  wifi_conectado VARCHAR(64) NULL,
  rssi_conectado INT NULL,
  redes_detectadas JSON NOT NULL,
  coletado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cws_device (device_id, coletado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

