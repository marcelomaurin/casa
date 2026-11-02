<?php
if (PHP_SAPI!=='cli') { http_response_code(403);exit(1); }
require_once __DIR__.'/../api/db.php';
require_once __DIR__.'/github_queue_runtime.php';
$stop=false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM,SIGINT] as $signal) pcntl_signal($signal,static function()use(&$stop):void { $stop=true; });
}
while (!$stop) {
    try {
        $result=casa_gh_fila_uma(get_db_pdo(),(string)getenv('CASA_GITHUB_WORKER_NAME'));
        if ($result) echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $e) { fwrite(STDERR,'Fila GitHub: '.$e->getMessage()."\n"); }
    if (!$stop) sleep(5);
}
