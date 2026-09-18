-- CASA / COMPUTER - Migration 1.24
-- Hierarquia generica de tarefas para qualquer solicitacao de IA.

ALTER TABLE jarvis_tarefas
    ADD COLUMN IF NOT EXISTS tarefa_pai_id BIGINT UNSIGNED NULL AFTER depende_de;

CREATE INDEX IF NOT EXISTS idx_jarvis_tarefas_pai
    ON jarvis_tarefas(tarefa_pai_id,ordem);

-- A FK pode já existir em instalações atualizadas manualmente.
SET @fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'jarvis_tarefas'
    AND CONSTRAINT_NAME = 'fk_jt_pai'
);
SET @sql = IF(@fk_exists=0,
  'ALTER TABLE jarvis_tarefas ADD CONSTRAINT fk_jt_pai FOREIGN KEY (tarefa_pai_id) REFERENCES jarvis_tarefas(id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
