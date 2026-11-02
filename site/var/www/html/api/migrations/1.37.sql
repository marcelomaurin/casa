-- Vinculo entre projetos aprovados e fila distribuida existente.
ALTER TABLE casa_projetos ADD COLUMN IF NOT EXISTS jarvis_plano_id BIGINT UNSIGNED NULL;
ALTER TABLE casa_projeto_tarefas ADD COLUMN IF NOT EXISTS jarvis_tarefa_id BIGINT UNSIGNED NULL;
CREATE INDEX IF NOT EXISTS idx_casa_projetos_plano ON casa_projetos(jarvis_plano_id);
CREATE INDEX IF NOT EXISTS idx_casa_projeto_tarefas_fila ON casa_projeto_tarefas(jarvis_tarefa_id);
