CREATE TABLE IF NOT EXISTS device_desired_state (
  device_id VARCHAR(100) NOT NULL,
  desired_state JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (device_id),
  CONSTRAINT fk_device_desired_state_device FOREIGN KEY (device_id) REFERENCES dispositivos_cluster(device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
