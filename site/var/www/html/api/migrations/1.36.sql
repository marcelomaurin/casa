-- CASA: modulo de projetos e agentes, MySQL/MariaDB
CREATE TABLE IF NOT EXISTS casa_projetos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 nome VARCHAR(180) NOT NULL,
 objetivo TEXT NOT NULL,
 repositorio VARCHAR(240) NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'planejamento',
 criado_por VARCHAR(120) NOT NULL,
 criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_projetos_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS casa_projeto_agentes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 projeto_id BIGINT UNSIGNED NOT NULL,
 nome VARCHAR(120) NOT NULL,
 especialidade VARCHAR(80) NOT NULL,
 executor VARCHAR(80) NOT NULL DEFAULT 'jarvis',
 status VARCHAR(24) NOT NULL DEFAULT 'disponivel',
 criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_agentes_projeto (projeto_id),
 CONSTRAINT fk_casa_agentes_projeto FOREIGN KEY (projeto_id) REFERENCES casa_projetos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS casa_projeto_tarefas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 projeto_id BIGINT UNSIGNED NOT NULL,
 agente_id BIGINT UNSIGNED NULL,
 depende_de BIGINT UNSIGNED NULL,
 titulo VARCHAR(200) NOT NULL,
 descricao TEXT NOT NULL,
 criterio_aceite TEXT NULL,
 prioridade TINYINT UNSIGNED NOT NULL DEFAULT 3,
 status VARCHAR(24) NOT NULL DEFAULT 'pendente',
 criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_tarefas_projeto_status (projeto_id,status),
 CONSTRAINT fk_casa_tarefas_projeto FOREIGN KEY (projeto_id) REFERENCES casa_projetos(id) ON DELETE CASCADE,
 CONSTRAINT fk_casa_tarefas_agente FOREIGN KEY (agente_id) REFERENCES casa_projeto_agentes(id) ON DELETE SET NULL,
 CONSTRAINT fk_casa_tarefas_dependencia FOREIGN KEY (depende_de) REFERENCES casa_projeto_tarefas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS casa_projeto_execucoes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 tarefa_id BIGINT UNSIGNED NOT NULL,
 agente_id BIGINT UNSIGNED NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'aguardando',
 resultado LONGTEXT NULL,
 erro TEXT NULL,
 iniciado_em DATETIME NULL,
 concluido_em DATETIME NULL,
 criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_execucoes_tarefa (tarefa_id),
 CONSTRAINT fk_casa_exec_tarefa FOREIGN KEY (tarefa_id) REFERENCES casa_projeto_tarefas(id) ON DELETE CASCADE,
 CONSTRAINT fk_casa_exec_agente FOREIGN KEY (agente_id) REFERENCES casa_projeto_agentes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
