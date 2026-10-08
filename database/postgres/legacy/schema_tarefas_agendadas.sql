-- ENGINE: PostgreSQL
-- DOMAIN: LEGACY / archival only; not part of CASA Control Plane production schema
-- Tabela de Agendamento de Tarefas Automatizadas
CREATE TABLE IF NOT EXISTS tarefas_agendadas (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(100) NOT NULL,
    descricao TEXT,
    horario VARCHAR(10) NOT NULL, -- Ex: "07:00", "18:30" ou "*/15" (a cada 15 min)
    dias_semana VARCHAR(50) DEFAULT '*', -- Ex: "*", "1,2,3,4,5" (seg a sex), "0,6" (fim de semana)
    tipo_acao VARCHAR(30) NOT NULL, -- 'comando_jarvis', 'dispositivo_devpar', 'aviso_fala', 'script'
    payload TEXT NOT NULL, -- Ex: "Ligue a irrigacao da piscina", "iddevice=1&devparname=dev1&valor=1", "Bom dia senhor!"
    target_node VARCHAR(50) DEFAULT 'local', -- 'local', '192.168.2.6', '192.168.2.8'
    ativo BOOLEAN DEFAULT TRUE,
    ultima_execucao TIMESTAMP,
    proxima_execucao TIMESTAMP,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    modo_agendamento VARCHAR(20) DEFAULT 'RECORRENTE',
    executar_em TIMESTAMP,
    id_plano BIGINT,
    id_tarefa_plano BIGINT,
    executar_uma_vez BOOLEAN NOT NULL DEFAULT FALSE,
    cron_expr VARCHAR(100)
);

-- Inserir agendamentos padrao caso nao existam
INSERT INTO tarefas_agendadas (titulo, descricao, horario, dias_semana, tipo_acao, payload, target_node, ativo)
VALUES
('Irrigacao Matinal', 'Ativacao diaria da bomba de irrigacao do jardim', '06:30', '*', 'comando_jarvis', 'Ligue a irrigacao', 'local', TRUE),
('Desligar Irrigacao', 'Desliga a bomba de irrigacao apos ciclo matinal', '07:15', '*', 'comando_jarvis', 'Desligue a irrigacao', 'local', TRUE),
('Aviso Despertador Satelite', 'Saudacao do JARVIS e aviso de status no no do quarto', '07:00', '1,2,3,4,5', 'aviso_fala', 'Bom dia senhor. O sistema JARVIS esta online e todos os nos estao operacionais.', '192.168.2.6', TRUE),
('Apagar Luzes Noturnas', 'Garante o desligamento de lampadas apos meia-noite', '23:45', '*', 'comando_jarvis', 'Desligue a luz da sala', 'local', TRUE)
ON CONFLICT DO NOTHING;

-- Configuracao de token de seguranca da API
INSERT INTO configuracoes_sistema (chave, valor)
VALUES ('system_api_token', 'jarvis_secret_token_2026')
ON CONFLICT (chave) DO NOTHING;

GRANT ALL ON TABLE tarefas_agendadas TO casadb_user;
GRANT ALL ON SEQUENCE tarefas_agendadas_id_seq TO casadb_user;
