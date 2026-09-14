-- CASA/JARVIS - integração site <-> celular <-> relógio
-- MySQL/MariaDB

CREATE TABLE IF NOT EXISTS watch_telemetria (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente VARCHAR(120) NOT NULL,
  bateria_pct INT NULL,
  passos BIGINT UNSIGNED NULL,
  rssi_ble INT NULL,
  rssi_wifi INT NULL,
  wifi_ssid VARCHAR(120) NULL,
  transporte VARCHAR(20) NULL,
  minutos_sem_movimento INT NULL,
  modo_energia VARCHAR(20) NULL,
  alerta_ativo TINYINT(1) NOT NULL DEFAULT 0,
  dados JSON NULL,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_watch_tel_cliente_data (cliente, data_hora),
  KEY idx_watch_tel_data (data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scopes sugeridos para tokens individuais:
-- Celular: ["mobile.read","mobile.write","family.read","family.write","jarvis.command","status.read"]
-- Relógio: ["watch.read","watch.write","family.read","family.write","jarvis.command","status.read"]
--
-- Nunca reutilizar token mestre da aplicação no APK ou no firmware.
