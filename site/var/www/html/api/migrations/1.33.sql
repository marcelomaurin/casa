-- CASA/JARVIS - Migration 1.33
-- Histórico do COMPUTER separado por usuário do painel.
-- Conversas antigas (sem usuário) continuam no banco, mas não aparecem mais no chat de ninguém.

ALTER TABLE llm_conversas
    ADD COLUMN IF NOT EXISTS usuario VARCHAR(120) NULL AFTER contexto;

CREATE INDEX IF NOT EXISTS idx_llm_usuario ON llm_conversas (usuario, id);
