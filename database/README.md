# Bancos de dados do CASA

A partir da consolidação arquitetural, os bancos têm responsabilidades explícitas.

## Produção / Control Plane

**MySQL/MariaDB é o banco oficial do Control Plane.**

O instalador autoritativo está em:

```text
site/var/www/html/api/schema_mysql.sql
site/var/www/html/api/migrations/
```

Scripts auxiliares/manuais MySQL ficam em `database/mysql/`.

## PostgreSQL

PostgreSQL não faz parte do schema do Control Plane público.

Scripts históricos PostgreSQL foram movidos para:

```text
database/postgres/legacy/
```

Eles são apenas referência de versões anteriores e não devem ser executados pelo instalador CASA atual.

Serviços especializados que realmente precisarem de PostgreSQL devem possuir schema próprio dentro de um diretório do serviço ou em `database/postgres/<servico>/`, sem recriar tabelas do Control Plane.

Consulte `docs/DATABASE_DOMAINS.md`.
