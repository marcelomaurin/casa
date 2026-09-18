-- CASA / COMPUTER - Migration 1.24
-- Hierarquia generica de tarefas para qualquer solicitacao de IA.
ALTER TABLE jarvis_tarefas
    ADD COLUMN IF NOT EXISTS tarefa_pai_id BIGINT UNSIGNED NULL AFTER depende_de;
