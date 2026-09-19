-- ENGINE: MySQL/MariaDB
-- DOMAIN: CASA Control Plane
-- CASA/JARVIS - broadcast familiar, assistência e sinalização de chamadas
-- Pode ser executado manualmente em instalações existentes. A API também cria
-- estas tabelas automaticamente com CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS family_channels (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'familia',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_family_channels_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_presence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  canal_id BIGINT UNSIGNED NOT NULL,
  cliente VARCHAR(120) NOT NULL,
  dispositivo VARCHAR(120) NULL,
  plataforma VARCHAR(30) NOT NULL DEFAULT 'web',
  metadata JSON NULL,
  ultimo_ping TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_family_presence (canal_id, cliente, plataforma),
  KEY idx_family_presence_ping (canal_id, ultimo_ping),
  CONSTRAINT fk_family_presence_channel FOREIGN KEY (canal_id) REFERENCES family_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  canal_id BIGINT UNSIGNED NOT NULL,
  remetente VARCHAR(120) NOT NULL,
  origem VARCHAR(30) NOT NULL DEFAULT 'web',
  tipo VARCHAR(30) NOT NULL DEFAULT 'texto',
  mensagem LONGTEXT NULL,
  dados JSON NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_family_messages_channel_id (canal_id, id),
  CONSTRAINT fk_family_messages_channel FOREIGN KEY (canal_id) REFERENCES family_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_calls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  canal_id BIGINT UNSIGNED NOT NULL,
  iniciado_por VARCHAR(120) NOT NULL,
  modo VARCHAR(20) NOT NULL DEFAULT 'video',
  status VARCHAR(30) NOT NULL DEFAULT 'chamando',
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encerrado_em DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_family_calls_channel_status (canal_id, status, criado_em),
  CONSTRAINT fk_family_calls_channel FOREIGN KEY (canal_id) REFERENCES family_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_call_signals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  call_id BIGINT UNSIGNED NOT NULL,
  remetente VARCHAR(120) NOT NULL,
  destino VARCHAR(120) NULL,
  tipo VARCHAR(30) NOT NULL,
  payload LONGTEXT NOT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_family_signals_call_id (call_id, id),
  CONSTRAINT fk_family_signals_call FOREIGN KEY (call_id) REFERENCES family_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assistencia_eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pessoa_ref VARCHAR(120) NULL,
  dispositivo_ref VARCHAR(120) NULL,
  tipo VARCHAR(40) NOT NULL,
  severidade VARCHAR(20) NOT NULL DEFAULT 'info',
  mensagem VARCHAR(500) NULL,
  dados JSON NULL,
  confirmado TINYINT(1) NOT NULL DEFAULT 0,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmado_em DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_assistencia_tipo_data (tipo, criado_em),
  KEY idx_assistencia_confirmado_data (confirmado, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO family_channels (nome, slug, tipo)
VALUES ('Família CASA', 'familia', 'familia');
