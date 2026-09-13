-- Adicionar configuracoes para roteamento inteligente de multiplas IAs
INSERT INTO configuracoes_sistema (chave, valor)
VALUES 
  ('ia_routing_mode', 'auto'),
  ('ia_cloud_keywords', 'analise,pesquisa,programe,codigo,explique,calcule,redija,artigo,relatorio,python,sql,resolva,resuma,estude,arquitetura,desenvolva')
ON CONFLICT (chave) DO NOTHING;

SELECT chave, valor FROM configuracoes_sistema WHERE chave LIKE 'ia_%' OR chave LIKE 'runpod_%';
