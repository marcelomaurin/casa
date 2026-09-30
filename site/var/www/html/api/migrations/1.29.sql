-- CASA / COMPUTER - Migration 1.29
-- Adiciona suporte a armazenamento de token em api_client_tokens para geracao de QR Code e pareamento.

ALTER TABLE api_client_tokens
  ADD COLUMN IF NOT EXISTS token VARCHAR(255) NULL AFTER device_id;
