-- JARVIS RESIDENCIAL - LILYGO WATCH
-- Fila de notificacoes e configuracoes do cliente wearable

CREATE TABLE IF NOT EXISTS watch_notificacoes (
    id BIGSERIAL PRIMARY KEY,
    id_dispositivo BIGINT,
    titulo VARCHAR(120) NOT NULL DEFAULT 'JARVIS',
    mensagem TEXT NOT NULL,
    audio_url TEXT,
    prioridade VARCHAR(16) NOT NULL DEFAULT 'normal',
    lida BOOLEAN NOT NULL DEFAULT FALSE,
    entregue BOOLEAN NOT NULL DEFAULT FALSE,
    data_hora TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_entrega TIMESTAMP WITHOUT TIME ZONE,
    data_leitura TIMESTAMP WITHOUT TIME ZONE
);

CREATE INDEX IF NOT EXISTS idx_watch_notificacoes_pendentes
    ON watch_notificacoes (id_dispositivo, entregue, data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_watch_notificacoes_data
    ON watch_notificacoes (data_hora DESC);

COMMENT ON TABLE watch_notificacoes IS
'Fila de mensagens e audios enviados pelo JARVIS para relogios LILYGO Watch.';

-- Configuracao STT compatível com OpenAI / whisper.cpp server.
-- Ajuste os valores conforme o serviço utilizado.
INSERT INTO configuracoes_sistema (chave, valor)
VALUES
    ('watch_stt_url', 'http://127.0.0.1:8098/v1/audio/transcriptions'),
    ('watch_stt_model', 'whisper-1'),
    ('watch_stt_api_key', '')
ON CONFLICT (chave) DO NOTHING;
