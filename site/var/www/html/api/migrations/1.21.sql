-- CASA/JARVIS - Migration automatica 1.21
-- Cadastro de multiplas conexoes/modelos de IA com fallback por prioridade.

CREATE TABLE IF NOT EXISTS ia_modelos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(160) NOT NULL,
    provedor VARCHAR(50) NOT NULL DEFAULT 'openai_compatible',
    base_url VARCHAR(500) NOT NULL,
    api_key TEXT NULL,
    modelo VARCHAR(200) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    padrao TINYINT(1) NOT NULL DEFAULT 0,
    prioridade INT NOT NULL DEFAULT 100,
    classe_hardware ENUM('CPU','GPU_LOW','GPU_HIGH') NOT NULL DEFAULT 'CPU',
    nivel_capacidade ENUM('ESTUDANTE','ESTAGIARIO','PROFISSIONAL','PROFESSOR') NOT NULL DEFAULT 'ESTUDANTE',
    timeout_segundos INT UNSIGNED NOT NULL DEFAULT 45,
    max_tokens INT UNSIGNED NOT NULL DEFAULT 600,
    temperatura DECIMAL(4,2) NOT NULL DEFAULT 0.35,
    observacoes TEXT NULL,
    ultima_tentativa DATETIME NULL,
    ultimo_sucesso DATETIME NULL,
    ultimo_erro TEXT NULL,
    falhas_consecutivas INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ia_modelos_roteamento (ativo,padrao,prioridade),
    KEY idx_ia_modelos_classe (classe_hardware,nivel_capacidade,ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Somente um padrao deve ser escolhido pela aplicacao.
-- Migra configuracao OpenAI-compatible legada, se existir e estiver completa.
INSERT INTO ia_modelos
(nome,provedor,base_url,api_key,modelo,ativo,padrao,prioridade,classe_hardware,nivel_capacidade,observacoes)
SELECT
  'OpenAI-compatible legado',
  'openai_compatible',
  COALESCE((SELECT valor FROM configuracoes_sistema WHERE chave='openai_base_url' LIMIT 1),''),
  COALESCE((SELECT valor FROM configuracoes_sistema WHERE chave='openai_api_key' LIMIT 1),''),
  COALESCE((SELECT valor FROM configuracoes_sistema WHERE chave='openai_model' LIMIT 1),''),
  1,1,10,'GPU_HIGH','PROFISSIONAL','Migrado automaticamente da configuracao anterior'
WHERE COALESCE((SELECT valor FROM configuracoes_sistema WHERE chave='openai_base_url' LIMIT 1),'') <> ''
  AND COALESCE((SELECT valor FROM configuracoes_sistema WHERE chave='openai_model' LIMIT 1),'') <> ''
  AND NOT EXISTS (SELECT 1 FROM ia_modelos);

-- Se nao houver modelo migrado, registra o Ollama legado como CPU/estudante apenas se configurado.
INSERT INTO ia_modelos
(nome,provedor,base_url,api_key,modelo,ativo,padrao,prioridade,classe_hardware,nivel_capacidade,observacoes)
SELECT
  'Ollama local legado',
  'ollama',
  COALESCE((SELECT valor FROM configuracoes_sistema WHERE chave='local_ollama_url' LIMIT 1),'http://127.0.0.1:11434'),
  '',
  COALESCE((SELECT valor FROM configuracoes_sistema WHERE chave='local_model' LIMIT 1),'jarvis-local:latest'),
  1,1,20,'CPU','ESTUDANTE','Migrado automaticamente da configuracao anterior'
WHERE NOT EXISTS (SELECT 1 FROM ia_modelos);
