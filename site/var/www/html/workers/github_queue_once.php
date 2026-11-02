<?php
// Executa uma etapa de uma tarefa; cron/systemd pode chamar repetidamente.
if (PHP_SAPI!=='cli') { http_response_code(403);exit(1); }
require_once __DIR__.'/../api/db.php';
require_once __DIR__.'/github_queue_runtime.php';
try {
    $result=casa_gh_fila_uma(get_db_pdo(),(string)getenv('CASA_GITHUB_WORKER_NAME'));
    echo json_encode($result??['status'=>'fila_vazia'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR,'Fila GitHub: '.$e->getMessage()."\n");exit(1);
}
