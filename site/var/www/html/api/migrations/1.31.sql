-- CASA / JARVIS - Migração 1.31: Câmeras IP, CFTV/DVR e Metadados de Streaming
ALTER TABLE dispositivos_cluster
  ADD COLUMN IF NOT EXISTS metadata JSON NULL,
  ADD COLUMN IF NOT EXISTS capabilities JSON NULL;
