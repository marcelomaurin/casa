-- JARVIS RESIDENCIAL
-- Eventos da ESP32-CAM, detecção facial e análise visual da IA

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
    status_ia VARCHAR(20) NOT NULL DEFAULT 'NAO_ENVIADO',
    modelo_ia VARCHAR(120),
    resposta_ia JSONB,
    erro_ia TEXT,
    data_hora TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_processamento TIMESTAMP WITHOUT TIME ZONE,
    data_ia TIMESTAMP WITHOUT TIME ZONE
);

-- Permite aplicar este arquivo também em instalações onde a tabela já existia.
ALTER TABLE camera_eventos ADD COLUMN IF NOT EXISTS status_ia VARCHAR(20) NOT NULL DEFAULT 'NAO_ENVIADO';
ALTER TABLE camera_eventos ADD COLUMN IF NOT EXISTS modelo_ia VARCHAR(120);
ALTER TABLE camera_eventos ADD COLUMN IF NOT EXISTS resposta_ia JSONB;
ALTER TABLE camera_eventos ADD COLUMN IF NOT EXISTS erro_ia TEXT;
ALTER TABLE camera_eventos ADD COLUMN IF NOT EXISTS data_ia TIMESTAMP WITHOUT TIME ZONE;

CREATE INDEX IF NOT EXISTS idx_camera_eventos_data_hora
    ON camera_eventos (data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_camera_eventos_dispositivo
    ON camera_eventos (id_dispositivo, data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_camera_eventos_faces
    ON camera_eventos (quantidade_faces, data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_camera_eventos_status
    ON camera_eventos (status_processamento, data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_camera_eventos_status_ia
    ON camera_eventos (status_ia, data_hora DESC);

COMMENT ON TABLE camera_eventos IS
'Eventos recebidos das ESP32-CAM, resultado da deteccao facial via Google Cloud Vision e analise de cena por IA visual.';

COMMENT ON COLUMN camera_eventos.dados_analise IS
'JSON retornado pelo processa_imagem.py. Contem bounding boxes e confianca; nao contem identidade da pessoa.';

COMMENT ON COLUMN camera_eventos.resposta_ia IS
'Analise da cena retornada pelo modelo multimodal do JARVIS quando pelo menos um rosto e detectado.';
