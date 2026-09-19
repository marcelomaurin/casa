-- ENGINE: PostgreSQL
-- DOMAIN: LEGACY / archival only; not part of CASA Control Plane production schema
-- JARVIS - hardening da API externa v1

CREATE TABLE IF NOT EXISTS api_client_tokens (
    id BIGSERIAL PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    scopes TEXT[] NOT NULL DEFAULT ARRAY['status.read'],
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    expira_em TIMESTAMP NULL,
    ultimo_uso TIMESTAMP NULL,
    ultimo_ip INET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_api_client_tokens_ativo
    ON api_client_tokens (ativo);

CREATE TABLE IF NOT EXISTS api_v1_rate_limit (
    chave VARCHAR(128) PRIMARY KEY,
    janela_inicio TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    contador INTEGER NOT NULL DEFAULT 0,
    bloqueado_ate TIMESTAMP NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_v1_security_log (
    id BIGSERIAL PRIMARY KEY,
    data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip INET NULL,
    cliente VARCHAR(160) NULL,
    rota VARCHAR(220) NULL,
    metodo VARCHAR(12) NULL,
    evento VARCHAR(80) NOT NULL,
    severidade VARCHAR(20) NOT NULL DEFAULT 'INFO',
    detalhes JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_api_v1_security_log_data
    ON api_v1_security_log (data_hora DESC);

-- Exemplo para criar token do Android sem salvar o segredo em texto puro:
-- 1) gere um segredo aleatório fora do banco;
-- 2) salve somente SHA-256 abaixo:
-- INSERT INTO api_client_tokens(nome, token_hash, scopes)
-- VALUES (
--   'JARVIS Android Marcelo',
--   encode(digest('SEU_TOKEN_ALEATORIO', 'sha256'), 'hex'),
--   ARRAY['status.read','jarvis.command','mobile.read','mobile.write','sensors.read','devices.read']
-- );
-- Requer extensão pgcrypto para digest(); alternativamente calcule SHA-256 na aplicação.
