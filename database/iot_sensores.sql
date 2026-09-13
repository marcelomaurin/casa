-- JARVIS RESIDENCIAL - TELEMETRIA DE SENSORES IOT

CREATE TABLE IF NOT EXISTS iot_leituras (
    id BIGSERIAL PRIMARY KEY,
    id_dispositivo BIGINT NOT NULL,
    tipo_sensor VARCHAR(40) NOT NULL,
    temperatura_c NUMERIC(8,3),
    umidade_pct NUMERIC(8,3),
    rssi INTEGER,
    dados JSONB,
    data_hora TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_iot_leituras_disp_data
    ON iot_leituras (id_dispositivo, data_hora DESC);

CREATE INDEX IF NOT EXISTS idx_iot_leituras_tipo_data
    ON iot_leituras (tipo_sensor, data_hora DESC);

COMMENT ON TABLE iot_leituras IS
'Historico de telemetria dos sensores IoT conectados ao JARVIS.';
