-- JARVIS - Planejamento e decomposicao de demandas

CREATE TABLE IF NOT EXISTS jarvis_planos (
    id BIGSERIAL PRIMARY KEY,
    demanda_original TEXT NOT NULL,
    origem VARCHAR(50) DEFAULT 'JARVIS',
    status VARCHAR(20) NOT NULL DEFAULT 'PLANEJADO',
    resumo TEXT,
    dados_plano JSONB,
    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em TIMESTAMP WITHOUT TIME ZONE
);

CREATE TABLE IF NOT EXISTS jarvis_tarefas (
    id BIGSERIAL PRIMARY KEY,
    id_plano BIGINT NOT NULL REFERENCES jarvis_planos(id) ON DELETE CASCADE,
    ordem INTEGER NOT NULL DEFAULT 1,
    titulo VARCHAR(180) NOT NULL,
    descricao TEXT,
    tipo VARCHAR(30) NOT NULL DEFAULT 'IMEDIATA', -- IMEDIATA | AGENDADA | CONDICIONAL
    executor VARCHAR(40) NOT NULL DEFAULT 'jarvis', -- jarvis | web | dispositivo | fala | script
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    depende_de BIGINT REFERENCES jarvis_tarefas(id) ON DELETE SET NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE', -- PENDENTE | AGENDADA | EXECUTANDO | CONCLUIDA | ERRO | AGUARDANDO
    executar_em TIMESTAMP WITHOUT TIME ZONE,
    recorrencia VARCHAR(120),
    resultado JSONB,
    erro TEXT,
    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciado_em TIMESTAMP WITHOUT TIME ZONE,
    concluido_em TIMESTAMP WITHOUT TIME ZONE
);

CREATE INDEX IF NOT EXISTS idx_jarvis_tarefas_plano ON jarvis_tarefas(id_plano, ordem);
CREATE INDEX IF NOT EXISTS idx_jarvis_tarefas_status ON jarvis_tarefas(status, executar_em);
CREATE INDEX IF NOT EXISTS idx_jarvis_tarefas_execucao ON jarvis_tarefas(executar_em) WHERE status IN ('PENDENTE','AGENDADA');

-- Extensoes do scheduler legado para tarefas unicas e rastreabilidade do plano
ALTER TABLE tarefas_agendadas ADD COLUMN IF NOT EXISTS modo_agendamento VARCHAR(20) DEFAULT 'RECORRENTE';
ALTER TABLE tarefas_agendadas ADD COLUMN IF NOT EXISTS executar_em TIMESTAMP WITHOUT TIME ZONE;
ALTER TABLE tarefas_agendadas ADD COLUMN IF NOT EXISTS id_plano BIGINT REFERENCES jarvis_planos(id) ON DELETE SET NULL;
ALTER TABLE tarefas_agendadas ADD COLUMN IF NOT EXISTS id_tarefa_plano BIGINT REFERENCES jarvis_tarefas(id) ON DELETE SET NULL;
ALTER TABLE tarefas_agendadas ADD COLUMN IF NOT EXISTS executar_uma_vez BOOLEAN NOT NULL DEFAULT FALSE;

GRANT ALL ON TABLE jarvis_planos, jarvis_tarefas TO casadb_user;
GRANT ALL ON SEQUENCE jarvis_planos_id_seq, jarvis_tarefas_id_seq TO casadb_user;
