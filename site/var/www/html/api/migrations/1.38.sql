-- 1.38: tokens de dispositivos antigos passam a ter resumo SHA-256.
-- A autenticacao compara o resumo; o texto puro so vale para cadastros sem resumo.
UPDATE dispositivos_cluster SET device_token_hash = SHA2(device_token, 256) WHERE device_token_hash IS NULL AND device_token IS NOT NULL AND device_token <> '' AND device_token NOT LIKE 'HASHED:%';
