# CASA / JARVIS — Adaptive LCARS UI

## Objetivo

Transformar a linguagem LCARS em um sistema operacional de interface para uso residencial real, sem limitar o projeto a um tema visual fixo. A interface deve reorganizar menus, informação e ações conforme a tarefa e pode receber instruções estruturadas da IA.

A IA **não gera HTML, CSS ou JavaScript**. Ela escolhe apenas layouts e componentes permitidos pelo framework.

## Princípios de projeto

1. **A moldura navega**: barras laterais e superiores são áreas funcionais, não decoração.
2. **O miolo trabalha**: o centro da tela apresenta o item, operação ou conjunto de dados selecionado.
3. **Poucas opções por grupo**: a navegação lateral privilegia grupos pequenos e previsíveis.
4. **Menu grande vira colunas**: quando um grupo possui muitos itens, o conteúdo é dividido em 2–4 colunas temáticas.
5. **Estado sempre visível**: online/offline, alertas e condições críticas permanecem nas bordas.
6. **Ações físicas são explícitas**: comandos de risco não devem parecer simples links de navegação.
7. **Responsivo por composição**: desktop usa três zonas; tablet reduz colunas; celular empilha os blocos.
8. **IA descreve intenção visual, framework decide geometria**.

## Grupos CASA sugeridos

### SEGURANÇA
- Câmeras
- Alarmes
- Portas e portões
- Fechaduras
- Sensores
- Acessos
- Eventos

### OPERAÇÕES
- Rotinas
- Ambientes
- Manutenção
- Ações rápidas
- Cenários
- Agendamentos
- Histórico

### DISPOSITIVOS
- Celular
- Watch
- TVs
- ESP32 / ESP8266
- Câmeras
- Sensores
- Gateways / Bridges

### AUTOMAÇÃO
- Regras
- Gatilhos
- Condições
- Ações
- Agendamentos
- Command Bus
- Event Bus

### IA & VOZ
- Conversa JARVIS
- Reconhecimento de voz
- TTS
- RunPod
- Agentes
- Planejador
- Pesquisa web

### FAMÍLIA
- Pessoas
- Presença
- Dispositivos pessoais
- Preferências
- Notificações

### SISTEMA
- Estado geral
- Rede
- Servidor
- Banco de dados
- Logs
- Atualizações
- Diagnóstico
- Configurações

## Layouts permitidos

### `focus`
1–3 itens ou detalhe de um objeto. Ex.: uma porta, uma câmera, um dispositivo.

### `menu-grid`
Menus extensos. O framework distribui seções em até quatro colunas, semelhante aos consoles LCARS de biblioteca/listagem.

### `dashboard`
4–11 objetos operacionais. Ex.: dispositivos, ambientes, câmeras.

### `telemetry`
Alta densidade de métricas. Ex.: CPU, RAM, RSSI, temperaturas, latências.

### `alert`
Urgência alta/crítica. Reduz a densidade da tela e aumenta foco, contexto e ações.

### `form`
Edição/configuração. Campos ficam no miolo; navegação e estado permanecem estáveis.

### `chat`
Conversação com JARVIS. O histórico ocupa o centro; estado do sistema e grupos continuam acessíveis.

### `auto`
O framework escolhe:

- alerta crítico/alto → `alert`
- mensagens → `chat`
- campos → `form`
- 6+ métricas → `telemetry`
- 2+ seções ou 12+ itens → `menu-grid`
- 4–11 itens → `dashboard`
- caso contrário → `focus`

## Contrato para IA

Endpoint:

`GET /casa/api/ui_layout.php`

Retorna layouts, componentes, regras e exemplo.

`POST /casa/api/ui_layout.php`

Recebe uma especificação e devolve uma versão normalizada e segura.

Exemplo:

```json
{
  "layout": "auto",
  "kind": "menu",
  "group": "SEGURANÇA",
  "title": "SEGURANÇA",
  "sections": [
    {
      "title": "PERÍMETRO",
      "items": [
        {"id": "portao", "label": "Portão principal"},
        {"id": "garagem", "label": "Garagem"}
      ]
    },
    {
      "title": "MONITORAMENTO",
      "items": [
        {"id": "cameras", "label": "Câmeras"},
        {"id": "sensores", "label": "Sensores"}
      ]
    }
  ]
}
```

A resposta contém `layout` e `spec` normalizado. O navegador renderiza com `CASALcars.render()`.

## Arquivos

- `site/var/www/html/lcars-framework.css` — tokens, grid, componentes e responsividade.
- `site/var/www/html/lcars-framework.js` — seleção automática, validação cliente e renderização.
- `site/var/www/html/api/ui_layout.php` — contrato seguro para IA/aplicativos.
- `site/var/www/html/ui_adaptativa.php` — laboratório com exemplos reais do CASA.

## Segurança

O contrato recusa a ideia de UI arbitrária gerada pelo modelo. Não há campos para `raw_html`, `raw_css` ou JavaScript. Eventos são retornados como ações simbólicas (`select`, `navigate`, `execute`, etc.) para que o código do CASA decida o que realmente pode ser executado.

Para ações físicas, a UI deve continuar passando por política/autorização e Command Bus; mudar o layout nunca concede permissão operacional.

## Referências estudadas

A arquitetura foi inspirada no padrão geral encontrado em implementações modernas de LCARS: grids responsivos, barras/elbows, tokens de tema, painéis, navegação, breadcrumbs, indicadores e componentes especializados. O código deste framework é próprio do CASA e não incorpora código de bibliotecas externas.
