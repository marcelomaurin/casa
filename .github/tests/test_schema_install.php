<?php
putenv('JARVIS_DB_HOST=127.0.0.1');
putenv('JARVIS_DB_PORT=3306');
putenv('JARVIS_DB_NAME=casa_test');
putenv('JARVIS_DB_USER=root');
putenv('JARVIS_DB_PASS=root');
putenv('JARVIS_AUTO_INIT_DB=1');

require __DIR__ . '/../../site/var/www/html/api/db.php';

$pdo = get_db_pdo();
$version = casa_schema_version($pdo);
if ($version !== CASA_SCHEMA_VERSION) {
    fwrite(STDERR, "VERSAO esperada ".CASA_SCHEMA_VERSION.", recebida: {$version}\n");
    exit(2);
}

$missing = casa_missing_tables($pdo);
if ($missing) {
    fwrite(STDERR, 'Tabelas ausentes: ' . implode(',', $missing) . "\n");
    exit(3);
}

$before = (string)$pdo->query("SELECT atualizado_em FROM param WHERE chave='VERSAO'")->fetchColumn();
sleep(1);
ensure_database_schema($pdo);
$after = (string)$pdo->query("SELECT atualizado_em FROM param WHERE chave='VERSAO'")->fetchColumn();
if ($before !== $after) {
    fwrite(STDERR, "Instalador executou novamente apesar de VERSAO=".CASA_SCHEMA_VERSION."\n");
    exit(4);
}

$required = ['device_commands','device_command_audit','scenes','automation_rules','jarvis_acoes'];
foreach ($required as $table) {
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t");
    $s->execute([':t'=>$table]);
    if ((int)$s->fetchColumn() !== 1) {
        fwrite(STDERR, "Tabela obrigatoria ausente: {$table}\n");
        exit(5);
    }
}

echo "Schema CASA instalado e validado em VERSAO=".CASA_SCHEMA_VERSION."\n";
