-- ENGINE: PostgreSQL
-- DOMAIN: LEGACY / archival only; not part of CASA Control Plane production schema
-- SECURITY: credentials and bootstrap users must be provisioned outside SQL/version control.
-- Inicializacao historica do Banco de Dados casadb no PostgreSQL
DO $$
BEGIN
   IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'casadb_user') THEN
      CREATE ROLE casadb_user WITH LOGIN;
   ELSE
      -- Password intentionally not managed in this file.
   END IF;
END
$$;

SELECT 'CREATE DATABASE casadb OWNER casadb_user'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'casadb')\gexec

GRANT ALL PRIVILEGES ON DATABASE casadb TO casadb_user;


-- 1. Tabela de Usuarios
CREATE TABLE IF NOT EXISTS usuarios (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    login VARCHAR(50) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    perfil VARCHAR(20) DEFAULT 'admin',
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tabela de Dispositivos (compativel com o projeto original e expandida)
CREATE TABLE IF NOT EXISTS devices (
    iddevice SERIAL PRIMARY KEY,
    devname VARCHAR(50) NOT NULL,
    devdesc TEXT,
    devtype INT DEFAULT 1,
    devcon VARCHAR(255),
    devstatus BOOLEAN DEFAULT TRUE,
    ultima_comunicacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Parametros e Variaveis dos Dispositivos
CREATE TABLE IF NOT EXISTS devpar (
    idpar SERIAL PRIMARY KEY,
    iddevice INT REFERENCES devices(iddevice) ON DELETE CASCADE,
    devparname VARCHAR(50) NOT NULL,
    devvalue VARCHAR(255),
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Telemetria e Captura de Leituras dos Sensores da Residencia
CREATE TABLE IF NOT EXISTS sensores_telemetria (
    id BIGSERIAL PRIMARY KEY,
    iddevice INT REFERENCES devices(iddevice) ON DELETE SET NULL,
    sensor_nome VARCHAR(50) NOT NULL,
    valor_numerico DOUBLE PRECISION,
    unidade VARCHAR(20),
    raw_data TEXT,
    data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. Fila de Falas / Sintetizacao de Voz
CREATE TABLE IF NOT EXISTS falas (
    idfala SERIAL PRIMARY KEY,
    mensagem TEXT NOT NULL,
    speaker VARCHAR(50) DEFAULT 'default',
    audio_path VARCHAR(255),
    status INT DEFAULT 0, -- 0: pendente, 1: reproduzido/processado, 2: erro
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processado_em TIMESTAMP
);

-- 6. Frases pre-cadastradas
CREATE TABLE IF NOT EXISTS frases (
    id SERIAL PRIMARY KEY,
    texto TEXT NOT NULL,
    autor VARCHAR(100),
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. Historico de Conversas com a LLM (llama.cpp)
CREATE TABLE IF NOT EXISTS llm_conversas (
    id SERIAL PRIMARY KEY,
    user_msg TEXT NOT NULL,
    bot_msg TEXT NOT NULL,
    contexto VARCHAR(50) DEFAULT 'geral',
    tokens_usados INT DEFAULT 0,
    data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. Log de Acoes e Comandos
CREATE TABLE IF NOT EXISTS comandos_log (
    id BIGSERIAL PRIMARY KEY,
    iddevice INT,
    comando VARCHAR(100) NOT NULL,
    origem VARCHAR(50) DEFAULT 'web',
    resultado TEXT,
    data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Inserir dados iniciais caso nao existam
-- Bootstrap de usuario removido. Crie usuarios pela aplicacao ou por segredo externo.

INSERT INTO devices (devname, devdesc, devtype, devcon, devstatus)
SELECT 'sala', 'Controlador Principal da Sala (ESP8266 + Nextion)', 1, '192.168.2.210', TRUE
WHERE NOT EXISTS (SELECT 1 FROM devices WHERE devname = 'sala');

INSERT INTO devices (devname, devdesc, devtype, devcon, devstatus)
SELECT 'piscina', 'Controlador Piscina (Reles Bomba e Iluminacao)', 2, '192.168.2.211', TRUE
WHERE NOT EXISTS (SELECT 1 FROM devices WHERE devname = 'piscina');

INSERT INTO frases (id, texto, autor) VALUES
(1, 'A imaginação é mais importante que o conhecimento.', 'Albert Einstein'),
(2, 'O mundo não está ameaçado pelas pessoas más, e sim por aquelas que permitem a maldade.', 'Albert Einstein'),
(3, 'Tente mover o mundo - o primeiro passo será mover a si mesmo.', 'Platão'),
(4, 'Os homens erram, os grandes homens confessam que erraram.', 'Voltaire'),
(5, 'Educai as crianças, para que não seja necessário punir os adultos.', 'Pitágoras')
ON CONFLICT (id) DO NOTHING;

GRANT ALL ON ALL TABLES IN SCHEMA public TO casadb_user;
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO casadb_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO casadb_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO casadb_user;
