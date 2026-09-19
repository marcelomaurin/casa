-- CASA / JARVIS - Migration 1.26
-- Correlation ID ponta a ponta para observabilidade.

ALTER TABLE jarvis_planos
    ADD COLUMN IF NOT EXISTS correlation_id VARCHAR(80) NULL AFTER id;
CREATE INDEX IF NOT EXISTS idx_jarvis_planos_corr ON jarvis_planos(correlation_id);

ALTER TABLE jarvis_tarefas
    ADD COLUMN IF NOT EXISTS correlation_id VARCHAR(80) NULL AFTER id_plano;
CREATE INDEX IF NOT EXISTS idx_jarvis_tarefas_corr ON jarvis_tarefas(correlation_id);

ALTER TABLE telemetria_operacional
    ADD COLUMN IF NOT EXISTS correlation_id VARCHAR(80) NULL AFTER id;
CREATE INDEX IF NOT EXISTS idx_telemetria_corr ON telemetria_operacional(correlation_id,data_hora);

ALTER TABLE api_v1_security_log
    ADD COLUMN IF NOT EXISTS correlation_id VARCHAR(80) NULL AFTER id;
CREATE INDEX IF NOT EXISTS idx_api_security_corr ON api_v1_security_log(correlation_id,data_hora);

ALTER TABLE device_command_audit
    ADD COLUMN IF NOT EXISTS correlation_id VARCHAR(80) NULL AFTER command_id;
CREATE INDEX IF NOT EXISTS idx_command_audit_corr ON device_command_audit(correlation_id,id);

-- correlation_id identifica a solicitacao raiz e pode aparecer em varias acoes/comandos.
ALTER TABLE jarvis_acoes DROP INDEX IF EXISTS uk_jarvis_acoes_corr;
CREATE INDEX IF NOT EXISTS idx_jarvis_acoes_corr ON jarvis_acoes(correlation_id,id);

ALTER TABLE device_commands DROP INDEX IF EXISTS uk_device_command_correlation;
CREATE INDEX IF NOT EXISTS idx_device_commands_corr ON device_commands(correlation_id,id);

UPDATE jarvis_planos
SET correlation_id=CONCAT('plan_',id)
WHERE correlation_id IS NULL OR correlation_id='';

UPDATE jarvis_tarefas t
JOIN jarvis_planos p ON p.id=t.id_plano
SET t.correlation_id=p.correlation_id
WHERE t.correlation_id IS NULL OR t.correlation_id='';

UPDATE jarvis_acoes a
JOIN jarvis_planos p ON p.id=a.id_plano
SET a.correlation_id=p.correlation_id
WHERE p.correlation_id IS NOT NULL;

UPDATE device_commands c
JOIN jarvis_acoes a ON a.command_id=c.id
SET c.correlation_id=a.correlation_id
WHERE a.correlation_id IS NOT NULL;

UPDATE device_command_audit a
JOIN device_commands c ON c.id=a.command_id
SET a.correlation_id=c.correlation_id
WHERE a.correlation_id IS NULL OR a.correlation_id='';


DROP TRIGGER IF EXISTS trg_device_commands_after_insert_audit;
CREATE TRIGGER trg_device_commands_after_insert_audit
AFTER INSERT ON device_commands
FOR EACH ROW
INSERT INTO device_command_audit(command_id,correlation_id,device_id,event_type,lifecycle_status,actor,details)
VALUES(NEW.id,NEW.correlation_id,NEW.device_id,'CREATED',NEW.lifecycle_status,NEW.requested_by,
JSON_OBJECT('risk_level',NEW.risk_level,'authorized_client',NEW.authorized_client,'confirmed_by',NEW.confirmed_by,'confirmed_at',NEW.confirmed_at));

DROP TRIGGER IF EXISTS trg_device_commands_after_update_audit;
CREATE TRIGGER trg_device_commands_after_update_audit
AFTER UPDATE ON device_commands
FOR EACH ROW
INSERT INTO device_command_audit(command_id,correlation_id,device_id,event_type,lifecycle_status,actor,details)
SELECT NEW.id,NEW.correlation_id,NEW.device_id,'LIFECYCLE',NEW.lifecycle_status,
       CASE WHEN NEW.lifecycle_status IN ('ACKNOWLEDGED','EXECUTING','DONE','FAILED') THEN NEW.device_id ELSE NEW.requested_by END,
       JSON_OBJECT('previous_status',OLD.lifecycle_status,'new_status',NEW.lifecycle_status,'retry_count',NEW.retry_count,'error',NEW.erro)
WHERE NOT (OLD.lifecycle_status <=> NEW.lifecycle_status);
