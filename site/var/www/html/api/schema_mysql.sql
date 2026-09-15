-- CASA/JARVIS - schema MySQL/MariaDB auto-instalavel
-- Executado automaticamente por api/db.php quando a estrutura estiver incompleta.
-- Usuario inicial da aplicacao: admin / admin123 (senha armazenada como hash bcrypt).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS configuracoes_sistema (
  chave VARCHAR(120) NOT NULL,
  valor LONGTEXT NULL,
  descricao VARCHAR(255) NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(100) NOT NULL,
  login VARCHAR(50) NOT NULL,
  senha VARCHAR(255) NOT NULL,
  email VARCHAR(120) NULL,
  perfil VARCHAR(30) NOT NULL DEFAULT 'admin',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_usuarios_login (login),
  UNIQUE KEY uk_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  iddevice BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  devname VARCHAR(80) NOT NULL,
  devdesc TEXT NULL,
  devtype INT NOT NULL DEFAULT 1,
  devcon VARCHAR(255) NULL,
  devstatus TINYINT(1) NOT NULL DEFAULT 1,
  ultima_comunicacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (iddevice),
  UNIQUE KEY uk_devices_devname (devname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devpar (
  idpar BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  iddevice BIGINT UNSIGNED NOT NULL,
  devparname VARCHAR(80) NOT NULL,
  devvalue VARCHAR(255) NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (idpar),
  UNIQUE KEY uk_devpar_device_name (iddevice, devparname),
  CONSTRAINT fk_devpar_device FOREIGN KEY (iddevice) REFERENCES devices(iddevice) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sensores_telemetria (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  iddevice BIGINT UNSIGNED NULL,
  sensor_nome VARCHAR(80) NOT NULL,
  valor_numerico DOUBLE NULL,
  unidade VARCHAR(30) NULL,
  raw_data LONGTEXT NULL,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sensores_device_data (iddevice, data_hora),
  KEY idx_sensores_nome_data (sensor_nome, data_hora),
  CONSTRAINT fk_sensores_device FOREIGN KEY (iddevice) REFERENCES devices(iddevice) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS falas (
  idfala BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mensagem TEXT NOT NULL,
  speaker VARCHAR(80) NOT NULL DEFAULT 'default',
  audio_path VARCHAR(500) NULL,
  status INT NOT NULL DEFAULT 0,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processado_em DATETIME NULL,
  PRIMARY KEY (idfala),
  KEY idx_falas_status (status, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS frases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  texto TEXT NOT NULL,
  autor VARCHAR(120) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS llm_conversas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_msg LONGTEXT NOT NULL,
  bot_msg LONGTEXT NOT NULL,
  contexto VARCHAR(80) NOT NULL DEFAULT 'geral',
  tokens_usados INT NOT NULL DEFAULT 0,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_llm_data (data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comandos_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  iddevice BIGINT UNSIGNED NULL,
  comando VARCHAR(255) NOT NULL,
  origem VARCHAR(80) NOT NULL DEFAULT 'web',
  resultado LONGTEXT NULL,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comandos_data (data_hora),
  KEY idx_comandos_origem_data (origem, data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arm_nodes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id VARCHAR(120) NULL,
  hostname VARCHAR(120) NOT NULL,
  ip_address VARCHAR(45) NULL,
  papel VARCHAR(100) NOT NULL DEFAULT 'No Distribuido',
  status VARCHAR(30) NOT NULL DEFAULT 'offline',
  cpu_info VARCHAR(255) NULL,
  ram_info VARCHAR(255) NULL,
  capabilities JSON NULL,
  ultimo_ping TIMESTAMP NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_arm_nodes_device_id (device_id),
  KEY idx_arm_nodes_status (status, ultimo_ping)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dispositivos_cluster (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id VARCHAR(120) NULL,
  nome VARCHAR(120) NOT NULL,
  tipo VARCHAR(50) NOT NULL,
  ip_address VARCHAR(45) NULL,
  mac_address VARCHAR(32) NULL,
  device_token VARCHAR(255) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'offline',
  ram_livre BIGINT NOT NULL DEFAULT 0,
  sinal_rssi INT NOT NULL DEFAULT 0,
  reles_status JSON NULL,
  capabilities JSON NULL,
  metadata JSON NULL,
  ultimo_heartbeat TIMESTAMP NULL,
  localizacao VARCHAR(120) NOT NULL DEFAULT 'Residencia',
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_dispositivos_token (device_token),
  UNIQUE KEY uk_dispositivos_device_id (device_id),
  KEY idx_dispositivos_tipo_status (tipo, status),
  KEY idx_dispositivos_heartbeat (ultimo_heartbeat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS iot_leituras (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_dispositivo BIGINT UNSIGNED NOT NULL,
  tipo_sensor VARCHAR(50) NOT NULL,
  temperatura_c DECIMAL(8,3) NULL,
  umidade_pct DECIMAL(8,3) NULL,
  rssi INT NULL,
  dados JSON NULL,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_iot_disp_data (id_dispositivo, data_hora),
  KEY idx_iot_tipo_data (tipo_sensor, data_hora),
  CONSTRAINT fk_iot_dispositivo FOREIGN KEY (id_dispositivo) REFERENCES dispositivos_cluster(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camera_eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_dispositivo BIGINT UNSIGNED NULL,
  nome_dispositivo VARCHAR(120) NULL,
  localizacao VARCHAR(180) NULL,
  arquivo VARCHAR(255) NOT NULL,
  movimento TINYINT(1) NOT NULL DEFAULT 0,
  quantidade_faces INT NOT NULL DEFAULT 0,
  confianca_max DECIMAL(8,6) NULL,
  status_processamento VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
  provedor VARCHAR(60) NULL,
  dados_analise JSON NULL,
  erro TEXT NULL,
  status_ia VARCHAR(30) NOT NULL DEFAULT 'NAO_ENVIADO',
  modelo_ia VARCHAR(120) NULL,
  resposta_ia JSON NULL,
  erro_ia TEXT NULL,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data_processamento DATETIME NULL,
  data_ia DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_camera_data (data_hora),
  KEY idx_camera_disp_data (id_dispositivo, data_hora),
  CONSTRAINT fk_camera_dispositivo FOREIGN KEY (id_dispositivo) REFERENCES dispositivos_cluster(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seguranca_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  origem_ip VARCHAR(45) NOT NULL,
  evento VARCHAR(80) NOT NULL,
  detalhes TEXT NULL,
  severidade VARCHAR(20) NOT NULL DEFAULT 'AVISO',
  bloqueado TINYINT(1) NOT NULL DEFAULT 0,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_seg_logs_ip_data (origem_ip, data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seguranca_ips_bloqueados (
  ip_address VARCHAR(45) NOT NULL,
  motivo TEXT NULL,
  tentativas_falhas INT NOT NULL DEFAULT 1,
  bloqueado_ate DATETIME NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ip_address),
  KEY idx_seg_bloqueio_ate (bloqueado_ate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agentes_externos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(120) NOT NULL,
  tipo VARCHAR(50) NOT NULL,
  configuracao JSON NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_disparo DATETIME NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_agente_nome_tipo (nome, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_client_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(120) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  scopes JSON NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  expira_em DATETIME NULL,
  ultimo_uso DATETIME NULL,
  ultimo_ip VARCHAR(45) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_api_token_hash (token_hash),
  KEY idx_api_tokens_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_v1_rate_limit (
  chave VARCHAR(128) NOT NULL,
  janela_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  contador INT NOT NULL DEFAULT 0,
  bloqueado_ate DATETIME NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_v1_security_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip VARCHAR(45) NULL,
  cliente VARCHAR(160) NULL,
  rota VARCHAR(220) NULL,
  metodo VARCHAR(12) NULL,
  evento VARCHAR(80) NOT NULL,
  severidade VARCHAR(20) NOT NULL DEFAULT 'INFO',
  detalhes JSON NULL,
  PRIMARY KEY (id),
  KEY idx_api_security_data (data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mobile_eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_dispositivo BIGINT UNSIGNED NULL,
  tipo VARCHAR(50) NOT NULL,
  descricao TEXT NULL,
  dados JSON NULL,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mobile_eventos_disp_data (id_dispositivo, data_hora),
  CONSTRAINT fk_mobile_evento_disp FOREIGN KEY (id_dispositivo) REFERENCES dispositivos_cluster(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mobile_notificacoes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_dispositivo BIGINT UNSIGNED NULL,
  titulo VARCHAR(120) NOT NULL DEFAULT 'JARVIS',
  mensagem TEXT NOT NULL,
  audio_url TEXT NULL,
  prioridade VARCHAR(20) NOT NULL DEFAULT 'normal',
  entregue TINYINT(1) NOT NULL DEFAULT 0,
  lida TINYINT(1) NOT NULL DEFAULT 0,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data_entrega DATETIME NULL,
  data_leitura DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_mobile_notif_pendentes (id_dispositivo, entregue, data_hora),
  CONSTRAINT fk_mobile_notif_disp FOREIGN KEY (id_dispositivo) REFERENCES dispositivos_cluster(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watch_notificacoes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_dispositivo BIGINT UNSIGNED NULL,
  titulo VARCHAR(120) NOT NULL DEFAULT 'JARVIS',
  mensagem TEXT NOT NULL,
  audio_url TEXT NULL,
  prioridade VARCHAR(20) NOT NULL DEFAULT 'normal',
  lida TINYINT(1) NOT NULL DEFAULT 0,
  entregue TINYINT(1) NOT NULL DEFAULT 0,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data_entrega DATETIME NULL,
  data_leitura DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_watch_pendentes (id_dispositivo, entregue, data_hora),
  CONSTRAINT fk_watch_disp FOREIGN KEY (id_dispositivo) REFERENCES dispositivos_cluster(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internet_pesquisas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  consulta TEXT NOT NULL,
  provedor VARCHAR(60) NULL,
  quantidade_resultados INT NOT NULL DEFAULT 0,
  fontes JSON NULL,
  resposta_ia LONGTEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'OK',
  erro TEXT NULL,
  data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_internet_data (data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jarvis_planos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  demanda_original LONGTEXT NOT NULL,
  origem VARCHAR(80) NOT NULL DEFAULT 'JARVIS',
  status VARCHAR(30) NOT NULL DEFAULT 'PLANEJADO',
  resumo TEXT NULL,
  dados_plano JSON NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  concluido_em DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_planos_status_data (status, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jarvis_tarefas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_plano BIGINT UNSIGNED NOT NULL,
  ordem INT NOT NULL DEFAULT 1,
  titulo VARCHAR(180) NOT NULL,
  descricao TEXT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'IMEDIATA',
  executor VARCHAR(60) NOT NULL DEFAULT 'jarvis',
  payload JSON NULL,
  depende_de BIGINT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
  executar_em DATETIME NULL,
  recorrencia VARCHAR(120) NULL,
  resultado JSON NULL,
  erro TEXT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  iniciado_em DATETIME NULL,
  concluido_em DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_jarvis_tarefas_plano (id_plano, ordem),
  KEY idx_jarvis_tarefas_status (status, executar_em),
  CONSTRAINT fk_jt_plano FOREIGN KEY (id_plano) REFERENCES jarvis_planos(id) ON DELETE CASCADE,
  CONSTRAINT fk_jt_depende FOREIGN KEY (depende_de) REFERENCES jarvis_tarefas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tarefas_agendadas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo VARCHAR(150) NOT NULL,
  descricao TEXT NULL,
  horario VARCHAR(20) NULL,
  dias_semana VARCHAR(80) NOT NULL DEFAULT '*',
  tipo_acao VARCHAR(50) NOT NULL,
  payload LONGTEXT NOT NULL,
  target_node VARCHAR(120) NOT NULL DEFAULT 'auto',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ultima_execucao DATETIME NULL,
  proxima_execucao DATETIME NULL,
  modo_agendamento VARCHAR(30) NOT NULL DEFAULT 'RECORRENTE',
  executar_em DATETIME NULL,
  id_plano BIGINT UNSIGNED NULL,
  id_tarefa_plano BIGINT UNSIGNED NULL,
  executar_uma_vez TINYINT(1) NOT NULL DEFAULT 0,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tarefas_ativo_proxima (ativo, proxima_execucao),
  CONSTRAINT fk_tarefa_plano FOREIGN KEY (id_plano) REFERENCES jarvis_planos(id) ON DELETE SET NULL,
  CONSTRAINT fk_tarefa_item FOREIGN KEY (id_tarefa_plano) REFERENCES jarvis_tarefas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracoes_sistema (chave, valor, descricao) VALUES
('site_base_url', 'https://casa.maurinsoft.com.br', 'URL publica central CASA/JARVIS'),
('ia_routing_mode', 'auto', 'Roteamento de IA'),
('watch_stt_model', 'whisper-1', 'Modelo STT do relogio')
ON DUPLICATE KEY UPDATE valor=VALUES(valor), descricao=VALUES(descricao);

INSERT INTO usuarios (nome, login, senha, email, perfil, ativo)
VALUES ('Administrador', 'admin', '$2y$12$gkPYafoiathQ0a5tY41oheBaM4bUOIShoAkBti3I33exPBiRVDGJ.', NULL, 'admin', 1)
ON DUPLICATE KEY UPDATE login=VALUES(login);

INSERT INTO frases (id, texto, autor) VALUES
(1, 'A imaginação é mais importante que o conhecimento.', 'Albert Einstein'),
(2, 'O mundo não está ameaçado pelas pessoas más, e sim por aquelas que permitem a maldade.', 'Albert Einstein'),
(3, 'Tente mover o mundo - o primeiro passo será mover a si mesmo.', 'Platão'),
(4, 'Os homens erram, os grandes homens confessam que erraram.', 'Voltaire'),
(5, 'Educai as crianças, para que não seja necessário punir os adultos.', 'Pitágoras')
ON DUPLICATE KEY UPDATE texto=VALUES(texto), autor=VALUES(autor);


CREATE TABLE IF NOT EXISTS device_pairing_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(64) NOT NULL,
  mac_address VARCHAR(30) NOT NULL,
  device_type VARCHAR(40) NOT NULL,
  model VARCHAR(80) NULL,
  firmware_version VARCHAR(30) NULL,
  capabilities JSON NULL,
  pairing_code VARCHAR(12) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  nome_atribuido VARCHAR(120) NULL,
  localizacao_atribuida VARCHAR(120) NULL,
  issued_device_id VARCHAR(120) NULL,
  issued_token VARCHAR(255) NULL,
  autorizado_por VARCHAR(120) NULL,
  solicitado_ip VARCHAR(45) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em DATETIME NOT NULL,
  autorizado_em DATETIME NULL,
  consumido_em DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pairing_req_id (request_id),
  KEY idx_pairing_mac (mac_address),
  KEY idx_pairing_status (status, expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
