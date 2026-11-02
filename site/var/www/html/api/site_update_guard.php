<?php
// Interrompe novas paginas PHP durante a troca; cron e recuperacao continuam disponiveis.
if (PHP_SAPI!=='cli' && is_file(dirname(__DIR__).'/.casa-site-update-maintenance')) {
    http_response_code(503);header('Retry-After: 60');header('Content-Type: text/plain; charset=utf-8');
    echo "Casa em atualizacao automatica. Aguarde alguns instantes.";exit;
}
