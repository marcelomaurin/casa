-- Versoes de integradores: cadastrar uma release NAO libera sua instalacao.
CREATE TABLE IF NOT EXISTS cluster_update_releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(60) NOT NULL,
    manifest LONGTEXT NOT NULL,
    manifest_sha256 CHAR(64) NOT NULL,
    created_by VARCHAR(160) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME NULL,
    UNIQUE KEY uk_cluster_release_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cluster_update_channels (
    channel VARCHAR(40) NOT NULL PRIMARY KEY,
    release_id BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cluster_channel_release FOREIGN KEY (release_id)
        REFERENCES cluster_update_releases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cluster_update_channels(channel, release_id) VALUES ('stable', NULL);

CREATE TABLE IF NOT EXISTS cluster_update_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(120) NOT NULL,
    release_id BIGINT UNSIGNED NOT NULL,
    component VARCHAR(80) NOT NULL,
    status VARCHAR(40) NOT NULL,
    installed_version VARCHAR(60) NULL,
    details LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cluster_report (device_id, release_id, component),
    CONSTRAINT fk_cluster_report_release FOREIGN KEY (release_id)
        REFERENCES cluster_update_releases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
