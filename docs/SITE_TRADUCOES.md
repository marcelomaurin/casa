# Idiomas da interface web do Casa

O portal `/casa/index.php` usa um dicionário offline em `site/var/www/html/lcars-i18n.js`.

## Idiomas suportados

Português do Brasil (padrão: `pt-BR`), inglês (`en-US`), espanhol (`es-ES`), árabe (`ar-SA`), francês (`fr-FR`), alemão (`de-DE`), russo (`ru-RU`), japonês (`ja-JP`), chinês simplificado (`zh-CN`) e hindi (`hi-IN`).

O seletor **Idioma** fica disponível no topo do portal. A preferência é salva no armazenamento do navegador como `casa.ui.language` e a tela atual é recarregada. Um navegador novo, com preferência ausente ou inválida, sempre começa em **português do Brasil**; não há detecção automática da linguagem do sistema. O árabe muda `lang` e `dir=rtl` para leitura da direita para a esquerda, sem trocar a posição física dos controles.

## Cobertura da primeira etapa

- Navegação, grupos, seções, menu de módulos e os principais cartões do painel inicial.
- Telas **SISTEMA → Cluster ARM** e **SISTEMA → Orquestração de serviços**, incluindo ações de transferência/parada/reinício, estados, campos técnicos, títulos e mensagens comuns.
- Alguns rótulos compartilhados de outros módulos por meio de `moduleShell` e `actionBtn`.

Os conteúdos de usuários, endereços, nomes dos serviços, comandos, descrições vindas das APIs e textos de outras páginas PHP **não são traduzidos automaticamente**. A tradução dos formulários internos e demais páginas deve ser acrescentada ao dicionário, por etapa. Não se usa serviço externo para tradução e não são alterados identificadores de rotas, campos de banco, IDs de ações, canais da API ou códigos de serviços.

## Como ampliar o dicionário

Cada linha do conteúdo `raw` do script de tradução contém **10 colunas** separadas por `|`, na ordem `pt-BR|en-US|es-ES|ar-SA|fr-FR|de-DE|ru-RU|ja-JP|zh-CN|hi-IN`. A primeira coluna é a frase portuguesa original e a chave estável. Traduza texto visível com `uiT('texto pt-BR')` e escape o conteúdo ao criar HTML. Nunca passe nomes ou payloads controlados pelo usuário diretamente à tradução.

A função `CASAI18n.t(texto)` preserva o texto original quando não houver tradução cadastrada, evitando quebrar a interface. `CASAI18n.getLanguage()` retorna o idioma atual; `CASAI18n.setLanguage(tag)` altera a seleção.

## Testes

```bash
node .github/tests/test_site_i18n.js
node .github/tests/test_arm_nodes_ui.js
node .github/tests/test_orchestration_ui.js
```

O CI confere todos os idiomas, português padrão, fallback de idioma inválido, persistência, direção RTL, traduções essenciais e integração das telas do cluster.