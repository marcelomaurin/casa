-- CASA/JARVIS - complemento da migration 1.20: auditoria de comandos
SET NAMES utf8mb4;

ALTER TABLE device_commands
    ADD COLUMN IF NOT EXISTS authorized_client VARCHAR(160) NULL AFTER requested_by,
    ADD COLUMN IF NOT EXISTS confirmed_by VARCHAR(160) NULL AFTER authorized_client,
    ADD COLUMN IF NOT EXISTS confirmed_at DATETIME NULL AFTER confirmed_by;

CREATE TABLE IF NOT EXISTS device_command_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    command_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(120) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    lifecycle_status VARCHAR(30) NULL,
    actor VARCHAR(160) NULL,
    details JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_command_audit_command (command_id,id),
    KEY idx_command_audit_device (device_id,criado_em),
    KEY idx_command_audit_event (event_type,criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_device_commands_before_insert_audit;
CREATE TRIGGER trg_device_commands_before_insert_audit
BEFORE INSERT ON device_commands
FOR EACH ROW
BEGIN
    IF NEW.authorized_client IS NULL OR NEW.authorized_client = '' THEN
        SET NEW.authorized_client = NEW.requested_by;
    END IF;
    IF NEW.risk_level >= 3 AND (NEW.confirmed_by IS NULL OR NEW.confirmed_by = '') THEN
        SET NEW.confirmed_by = NEW.requested_by;
        SET NEW.confirmed_at = NOW();
    END IF;
END;

DROP TRIGGER IF EXISTS trg_device_commands_after_insert_audit;
CREATE TRIGGER trg_device_commands_after_insert_audit
AFTER INSERT ON device_commands
FOR EACH ROW
INSERT INTO device_command_audit(command_id,device_id,event_type,lifecycle_status,actor,details)
VALUES(
    NEW.id,
    NEW.device_id,
    'CREATED',
    NEW.lifecycle_status,
    NEW.requested_by,
    JSON_OBJECT(
        'risk_level', NEW.risk_level,
        'authorized_client', NEW.authorized_client,
        'confirmed_by', NEW.confirmed_by,
        'confirmed_at', NEW.confirmed_at,
        'correlation_id', NEW.correlation_id
    )
);

DROP TRIGGER IF EXISTS trg_device_commands_after_update_audit;
CREATE TRIGGER trg_device_commands_after_update_audit
AFTER UPDATE ON device_commands
FOR EACH ROW
BEGIN
    IF NOT (OLD.lifecycle_status <=> NEW.lifecycle_status) THEN
        INSERT INTO device_command_audit(command_id,device_id,event_type,lifecycle_status,actor,details)
        VALUES(
            NEW.id,
            NEW.device_id,
            'LIFECYCLE',
            NEW.lifecycle_status,
            CASE
                WHEN NEW.lifecycle_status IN ('ACKNOWLEDGED','EXECUTING','DONE','FAILED') THEN NEW.device_id
                ELSE NEW.requested_by
            END,
            JSON_OBJECT(
                'previous_status', OLD.lifecycle_status,
                'new_status', NEW.lifecycle_status,
                'retry_count', NEW.retry_count,
                'error', NEW.erro
            )
        );
    END IF;
END;
