-- CASA/JARVIS - Migration 1.32
-- Segurança: data do bloqueio, última falha e origem dos eventos (painel, API, hardware).

ALTER TABLE seguranca_ips_bloqueados
    ADD COLUMN IF NOT EXISTS bloqueado_em DATETIME NULL AFTER bloqueado_ate,
    ADD COLUMN IF NOT EXISTS ultima_falha DATETIME NULL AFTER bloqueado_em,
    ADD COLUMN IF NOT EXISTS origem VARCHAR(40) NULL AFTER ultima_falha;

-- Bloqueios já existentes: melhor estimativa da data do bloqueio é 1 h antes do fim.
UPDATE seguranca_ips_bloqueados
SET bloqueado_em = DATE_SUB(bloqueado_ate, INTERVAL 1 HOUR)
WHERE bloqueado_ate IS NOT NULL AND bloqueado_em IS NULL;

UPDATE seguranca_ips_bloqueados SET ultima_falha = atualizado_em WHERE ultima_falha IS NULL;

ALTER TABLE seguranca_logs
    ADD COLUMN IF NOT EXISTS origem VARCHAR(40) NULL AFTER bloqueado,
    ADD COLUMN IF NOT EXISTS usuario VARCHAR(120) NULL AFTER origem;

CREATE INDEX IF NOT EXISTS idx_seg_logs_data ON seguranca_logs (data_hora);
CREATE INDEX IF NOT EXISTS idx_seg_logs_sev ON seguranca_logs (severidade, data_hora);
