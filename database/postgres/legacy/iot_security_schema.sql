-- ENGINE: PostgreSQL
-- DOMAIN: LEGACY / archival only; not part of CASA Control Plane production schema
-- Migração para Cluster IoT, Segurança Anti-Intrusão e Agentes Externos
CREATE TABLE IF NOT EXISTS dispositivos_cluster (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(64) NOT NULL,
    tipo VARCHAR(32) NOT NULL, -- 'esp32_cam', 'esp32_voice', 'arduino_ethernet', 'esp8266', 'arm_node'
    ip_address VARCHAR(45),
    mac_address VARCHAR(20),
    device_token VARCHAR(64) NOT NULL UNIQUE,
    status VARCHAR(20) DEFAULT 'offline',
    ram_livre INT DEFAULT 0, -- Bytes ou KB
    sinal_rssi INT DEFAULT 0, -- dBm
    reles_status JSONB DEFAULT '{}', -- Estado dos reles conectados {"rele1": 0, "rele2": 0}
    ultimo_heartbeat TIMESTAMP,
    localizacao VARCHAR(64) DEFAULT 'Residência',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS seguranca_logs (
    id SERIAL PRIMARY KEY,
    origem_ip VARCHAR(45) NOT NULL,
    evento VARCHAR(64) NOT NULL, -- 'TOKEN_INVALIDO', 'BLOQUEIO_IP', 'LOGIN_FALHA', 'RATE_LIMIT', 'INTRUSAO_DETECTADA'
    detalhes TEXT,
    severidade VARCHAR(20) DEFAULT 'AVISO', -- 'INFO', 'AVISO', 'CRITICO'
    bloqueado BOOLEAN DEFAULT false,
    data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS seguranca_ips_bloqueados (
    ip_address VARCHAR(45) PRIMARY KEY,
    motivo TEXT,
    tentativas_falhas INT DEFAULT 1,
    bloqueado_ate TIMESTAMP,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS agentes_externos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(64) NOT NULL,
    tipo VARCHAR(32) NOT NULL, -- 'telegram_bot', 'webhook', 'clima_api'
    configuracao JSONB NOT NULL,
    ativo BOOLEAN DEFAULT true,
    ultimo_disparo TIMESTAMP,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed de Dispositivos Iniciais Padrão para Fácil Associação
INSERT INTO dispositivos_cluster (nome, tipo, ip_address, device_token, status, localizacao, reles_status)
VALUES 
('ESP32-CAM Entrada', 'esp32_cam', '192.168.2.50', 'token_espcam_portao_2026', 'online', 'Portão Principal', '{"flash": 0, "stream_fps": 15}'),
('ESP32 Voice Sala (Google Home)', 'esp32_voice', '192.168.2.51', 'token_espvoice_sala_2026', 'online', 'Sala de Estar', '{"volume": 80, "mic_ativo": true}'),
('Arduino Ethernet Relés Piscina', 'arduino_ethernet', '192.168.2.52', 'token_ard_reles_2026', 'online', 'Área Externa / Piscina', '{"rele1": 0, "rele2": 0, "rele3": 0, "rele4": 0}')
ON CONFLICT (device_token) DO NOTHING;

-- Seed de Configuração de Agentes Externos
INSERT INTO agentes_externos (nome, tipo, configuracao, ativo)
VALUES
('Telegram Bot Notificador', 'telegram_bot', '{"bot_token": "SEU_TELEGRAM_BOT_TOKEN", "chat_id": "SEU_CHAT_ID", "alertar_invasao": true, "alertar_sensores": true}', false),
('Webhook Automação Externa', 'webhook', '{"url": "https://api.meuservico.com/webhook", "metodo": "POST", "headers": {"Content-Type": "application/json"}}', false),
('Previsão do Tempo (OpenWeather)', 'clima_api', '{"cidade": "Sao Paulo", "api_key": "free_tier", "atualizar_intervalo_min": 60}', true)
ON CONFLICT DO NOTHING;
