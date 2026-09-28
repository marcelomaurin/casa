-- CASA/JARVIS - Historico ambiental por mudanca
-- Uma nova linha deve ser inserida somente quando temperatura ou umidade
-- diferirem da ultima leitura persistida para o mesmo device.

CREATE TABLE IF NOT EXISTS device_environment_readings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id VARCHAR(120) NOT NULL,
  temperature_c DECIMAL(6,2) NOT NULL,
  humidity_pct DECIMAL(6,2) NOT NULL,
  sensor_type VARCHAR(30) NULL,
  observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_environment_device_time (device_id, observed_at),
  KEY idx_environment_time (observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
