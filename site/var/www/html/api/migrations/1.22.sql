-- CASA/COMPUTER - Migration automatica 1.22
-- Agendamentos cron e identificação explícita do executor.

ALTER TABLE tarefas_agendadas
    ADD COLUMN IF NOT EXISTS cron_expr VARCHAR(100) NULL AFTER dias_semana,
    ADD COLUMN IF NOT EXISTS executor_tipo VARCHAR(30) NOT NULL DEFAULT 'ia' AFTER cron_expr;

UPDATE tarefas_agendadas
SET executor_tipo = CASE
    WHEN tipo_acao='aviso_fala' THEN 'fala'
    WHEN tipo_acao='dispositivo_devpar' THEN 'equipamento'
    ELSE 'ia'
END
WHERE executor_tipo IS NULL OR executor_tipo='';
