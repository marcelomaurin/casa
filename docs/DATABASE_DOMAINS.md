# Domínios de banco de dados

## Decisão arquitetural

O CASA possui dois tipos de armazenamento possíveis, mas eles não são intercambiáveis.

### MySQL/MariaDB — Control Plane

É o banco transacional oficial usado pelo site e pelas APIs públicas. Nele ficam:

- autenticação e usuários;
- configurações do sistema;
- Device Registry;
- API v1 e tokens;
- Command Bus e auditoria;
- planos, tarefas e `jarvis_acoes`;
- regras e cenas;
- telemetria operacional;
- mobile, watch, família e integrações públicas.

O schema oficial é controlado por `CASA_SCHEMA_VERSION` em `db.php`, pelo bootstrap `schema_mysql.sql` e por migrations incrementais.

**`jarvis_planos`, `jarvis_tarefas` e `jarvis_acoes` pertencem oficialmente ao MySQL/MariaDB.**

### PostgreSQL — serviços especializados

PostgreSQL pode ser utilizado por serviços locais/especializados quando houver necessidade técnica específica. Ele não deve duplicar identidade de devices, Command Bus, tarefas ou autenticação do Control Plane.

Um serviço PostgreSQL deve:

1. possuir schema e migration próprios;
2. documentar o proprietário do dado;
3. consumir o Control Plane por API/integração definida;
4. não escrever diretamente nas tabelas MySQL;
5. não redefinir tabelas canônicas do CASA.

## Estrutura

```text
site/var/www/html/api/schema_mysql.sql   # bootstrap oficial
site/var/www/html/api/migrations/        # migrations oficiais
database/mysql/                          # SQL manual/auxiliar MySQL
database/postgres/legacy/                # arquivo histórico, não executar
database/postgres/<servico>/             # futuro serviço PG isolado
```

## Regras

SQL sem engine identificável não deve ser adicionado em `database/`.

O CI verifica a separação para impedir que sintaxe PostgreSQL volte a aparecer em `database/mysql/` ou que arquivos SQL soltos reapareçam diretamente na raiz de `database/`.

Credenciais, senhas bootstrap e tokens nunca devem ser versionados em SQL.
