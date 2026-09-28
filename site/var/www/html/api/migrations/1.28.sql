-- CASA / COMPUTER - Migration 1.28
-- Reparo defensivo do nucleo de tarefas usado pelo JARVIS.
-- Garante que instalacoes parcialmente migradas tenham a estrutura minima atual.

CREATE TABLE IF NOT EXISTS jarvis_planos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  correlation_id VARCHAR(80) NULL,
  demanda_original LONGTEXT NOT NULL,
  origem VARCHAR(80) NOT NULL DEFAULT 'JARVIS',
  status VARCHAR(30) NOT NULL DEFAULT 'PLANEJADO',
  resumo TEXT NULL,
  dados_plano JSON NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  concluido_em DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_planos_status_data (status, criado_em),
  KEY idx_jarvis_planos_corr (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jarvis_tarefas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_plano BIGINT UNSIGNED NOT NULL,
  correlation_id VARCHAR(80) NULL,
  ordem INT NOT NULL DEFAULT 1,
  titulo VARCHAR(180) NOT NULL,
  descricao TEXT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'IMEDIATA',
  executor VARCHAR(60) NOT NULL DEFAULT 'jarvis',
  payload JSON NULL,
  depende_de BIGINT UNSIGNED NULL,
  tarefa_pai_id BIGINT UNSIGNED NULL,
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
  KEY idx_jarvis_tarefas_corr (correlation_id),
  CONSTRAINT fk_jt_plano FOREIGN KEY (id_plano) REFERENCES jarvis_planos(id) ON DELETE CASCADE,
  CONSTRAINT fk_jt_depende FOREIGN KEY (depende_de) REFERENCES jarvis_tarefas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE jarvis_planos
  ADD COLUMN IF NOT EXISTS correlation_id VARCHAR(80) NULL AFTER id;
ALTER TABLE jarvis_tarefas
  ADD COLUMN IF NOT EXISTS correlation_id VARCHAR(80) NULL AFTER id_plano;
ALTER TABLE jarvis_tarefas
  ADD COLUMN IF NOT EXISTS tarefa_pai_id BIGINT UNSIGNED NULL AFTER depende_de;

CREATE INDEX IF NOT EXISTS idx_jarvis_planos_corr ON jarvis_planos(correlation_id);
CREATE INDEX IF NOT EXISTS idx_jarvis_tarefas_corr ON jarvis_tarefas(correlation_id);

UPDATE jarvis_planos
SET correlation_id=CONCAT('plan_',id)
WHERE correlation_id IS NULL OR correlation_id='';

UPDATE jarvis_tarefas t
JOIN jarvis_planos p ON p.id=t.id_plano
SET t.correlation_id=p.correlation_id
WHERE t.correlation_id IS NULL OR t.correlation_id='';
