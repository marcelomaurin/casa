-- CASA/JARVIS schema 1.27
-- Persistencia ambiental por mudanca + estado desejado dos devices.
-- Idempotente: seguro para executar novamente.

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

CREATE TABLE IF NOT EXISTS device_desired_state (
  device_id VARCHAR(100) NOT NULL,
  desired_state JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (device_id),
  CONSTRAINT fk_device_desired_state_device FOREIGN KEY (device_id)
    REFERENCES dispositivos_cluster(device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
