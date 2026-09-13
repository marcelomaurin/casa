-- JARVIS - historico do agente de pesquisa na internet
CREATE TABLE IF NOT EXISTS internet_pesquisas (
    id BIGSERIAL PRIMARY KEY,
    consulta TEXT NOT NULL,
    provedor VARCHAR(40),
    quantidade_resultados INTEGER NOT NULL DEFAULT 0,
    fontes JSONB,
    resposta_ia TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'OK',
    erro TEXT,
    data_hora TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_internet_pesquisas_data
    ON internet_pesquisas (data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_internet_pesquisas_status
    ON internet_pesquisas (status, data_hora DESC);

GRANT ALL ON TABLE internet_pesquisas TO casadb_user;
GRANT USAGE, SELECT ON SEQUENCE internet_pesquisas_id_seq TO casadb_user;
