<?php
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require_once __DIR__.'/../api/site_self_update.php';
try {
    $state=getenv('CASA_SITE_UPDATE_STATE_DIR')?:null;
    $updater=new CasaSiteUpdater(dirname(__DIR__),$state);
    if (in_array('--enable',$argv,true)) $result=$updater->enable(true);
    elseif (in_array('--disable',$argv,true)) $result=$updater->enable(false);
    elseif (in_array('--status',$argv,true)) $result=$updater->status();
    else $result=$updater->run(in_array('--check',$argv,true));
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR,'Atualizacao Casa: '.$e->getMessage()."\n");exit(1); }
