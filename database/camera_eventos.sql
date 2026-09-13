-- JARVIS RESIDENCIAL
-- Eventos da ESP32-CAM e resultados de deteccao facial

CREATE TABLE IF NOT EXISTS camera_eventos (
    id BIGSERIAL PRIMARY KEY,
    id_dispositivo BIGINT,
    nome_dispositivo VARCHAR(120),
    localizacao VARCHAR(180),
    arquivo VARCHAR(255) NOT NULL,
    movimento BOOLEAN NOT NULL DEFAULT FALSE,
    quantidade_faces INTEGER NOT NULL DEFAULT 0,
    confianca_max NUMERIC(8,6),
    status_processamento VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
    provedor VARCHAR(60),
    dados_analise JSONB,
    erro TEXT,
    data_hora TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_processamento TIMESTAMP WITHOUT TIME ZONE
);

CREATE INDEX IF NOT EXISTS idx_camera_eventos_data_hora
    ON camera_eventos (data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_camera_eventos_dispositivo
    ON camera_eventos (id_dispositivo, data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_camera_eventos_faces
    ON camera_eventos (quantidade_faces, data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_camera_eventos_status
    ON camera_eventos (status_processamento, data_hora DESC);

COMMENT ON TABLE camera_eventos IS
'Eventos recebidos das ESP32-CAM e resultado da deteccao facial via Google Cloud Vision.';

COMMENT ON COLUMN camera_eventos.dados_analise IS
'JSON retornado pelo processa_imagem.py. Contem bounding boxes, confianca e atributos faciais; nao contem identidade da pessoa.';
