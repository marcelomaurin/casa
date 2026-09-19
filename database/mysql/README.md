# MySQL / MariaDB

Engine oficial do CASA Control Plane.

## Autoridade de schema

A autoridade de instalação continua sendo:

```text
site/var/www/html/api/schema_mysql.sql
site/var/www/html/api/migrations/
```

Os arquivos deste diretório são scripts auxiliares ou snapshots manuais. Novas alterações estruturais de produção devem ser feitas como migrations versionadas no diretório da API, e não apenas aqui.

Domínios mantidos no MySQL incluem autenticação, usuários, Device Registry, Command Bus, planos/tarefas/ações, regras, cenas, mobile/watch, telemetria operacional e auditoria.
