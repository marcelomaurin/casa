# Integração Casa — conflitos Android TV / Jarvis

Data: 2026-11-02

- Base: `integracao-casa-2026-11-02` (inclui PR #43 Jarvis/Mordomo).
- Origem: `fix/jarvis-tv-exibicao` (PR #44).
- GitHub recusou merge com HTTP 405: `Pull Request has merge conflicts`.
- `melhorias-casa` é idêntica à branch `fix/jarvis-tv-exibicao` e não deve ser integrada duas vezes.

## Arquivos alterados por ambas as linhas de desenvolvimento

1. `.github/tests/test_arm_nodes_ui.js`
2. `.github/workflows/integration-control-plane.yml`
3. `site/var/www/html/api/db.php`
4. `site/var/www/html/api/migrations/1.30.sql`
5. `site/var/www/html/lcars-site.css`
6. `site/var/www/html/lcars-site.js`

**Atenção:** esta lista representa sobreposição de arquivos, não necessariamente todos os blocos com conflito textual. É necessário executar um merge de três vias para obter os marcadores exatos e reconciliar as implementações.

## Critérios de resolução

- Preservar APIs de Mordomo, contexto e fila de tarefas introduzidas no PR #43.
- Manter migrações SQL idempotentes e evitar alterações destrutivas em banco existente.
- Conservar recursos novos de Android TV, menu LCARS e ESP32-CAM, sem restaurar classes Android removidas sem analisar dependências.
- Executar testes PHP, Python, JavaScript e compilação Android TV antes de integrar na `master`.
- Não forçar merge do PR #44 enquanto GitHub informar conflitos.

## Status

Auditoria de sobreposição concluída; resolução linha a linha e testes pendentes. `master` não alterada.
