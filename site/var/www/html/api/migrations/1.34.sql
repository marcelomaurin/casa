-- CASA/JARVIS - Migration 1.34
-- Fila de tarefas para daemons e agentes externos (api/v1/fila).
-- fila NULL = tarefa tratada pelo próprio site (comportamento antigo).
-- fila 'geral' = qualquer trabalhador que tenha o executor; outro nome = só aquele trabalhador.

ALTER TABLE jarvis_tarefas
    ADD COLUMN IF NOT EXISTS fila VARCHAR(80) NULL AFTER executor,
    ADD COLUMN IF NOT EXISTS reservado_por VARCHAR(120) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS reservado_ate DATETIME NULL AFTER reservado_por,
    ADD COLUMN IF NOT EXISTS tentativas INT NOT NULL DEFAULT 0 AFTER reservado_ate,
    ADD COLUMN IF NOT EXISTS max_tentativas INT NOT NULL DEFAULT 3 AFTER tentativas;

CREATE INDEX IF NOT EXISTS idx_jarvis_tarefas_fila ON jarvis_tarefas (fila, status, executar_em);
CREATE INDEX IF NOT EXISTS idx_jarvis_tarefas_pai ON jarvis_tarefas (tarefa_pai_id);
CREATE INDEX IF NOT EXISTS idx_jarvis_tarefas_dep ON jarvis_tarefas (depende_de);

CREATE TABLE IF NOT EXISTS jarvis_trabalhadores (
    nome VARCHAR(120) NOT NULL,
    executores JSON NULL,
    filas JSON NULL,
    cliente VARCHAR(160) NULL,
    host VARCHAR(160) NULL,
    versao VARCHAR(40) NULL,
    em_execucao INT NOT NULL DEFAULT 0,
    ultimo_contato DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (nome),
    KEY idx_trab_contato (ultimo_contato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
