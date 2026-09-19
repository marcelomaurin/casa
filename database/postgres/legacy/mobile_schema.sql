-- ENGINE: PostgreSQL
-- DOMAIN: LEGACY / archival only; not part of CASA Control Plane production schema
-- JARVIS RESIDENCIAL - APLICATIVO ANDROID

CREATE TABLE IF NOT EXISTS mobile_eventos (
    id BIGSERIAL PRIMARY KEY,
    id_dispositivo BIGINT,
    tipo VARCHAR(40) NOT NULL,
    descricao TEXT,
    dados JSONB,
    data_hora TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_mobile_eventos_disp_data
    ON mobile_eventos (id_dispositivo, data_hora DESC);

CREATE TABLE IF NOT EXISTS mobile_notificacoes (
    id BIGSERIAL PRIMARY KEY,
    id_dispositivo BIGINT,
    titulo VARCHAR(120) NOT NULL DEFAULT 'JARVIS',
    mensagem TEXT NOT NULL,
    audio_url TEXT,
    prioridade VARCHAR(16) NOT NULL DEFAULT 'normal',
    entregue BOOLEAN NOT NULL DEFAULT FALSE,
    lida BOOLEAN NOT NULL DEFAULT FALSE,
    data_hora TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_entrega TIMESTAMP WITHOUT TIME ZONE,
    data_leitura TIMESTAMP WITHOUT TIME ZONE
);

CREATE INDEX IF NOT EXISTS idx_mobile_notificacoes_pendentes
    ON mobile_notificacoes (id_dispositivo, entregue, data_hora ASC);
