<?php
// Cron por acessos, semelhante ao WP-Cron: uma verificacao a cada cinco minutos.
// Nenhuma instalacao acontece antes da habilitacao pelo administrador.
if (PHP_SAPI!=='cli' && !defined('CASA_SITE_UPDATE_HOOK_REGISTERED')) {
    define('CASA_SITE_UPDATE_HOOK_REGISTERED',true);
    require_once __DIR__.'/site_self_update.php';
    $casaUpdateRoot=dirname(__DIR__);
    $casaUpdateState=CasaSiteUpdater::statePath($casaUpdateRoot);
    $casaUpdateSettings=$casaUpdateState.'/settings.json';
    if (@is_file($casaUpdateSettings) && @filesize($casaUpdateSettings)<10000) {
        $casaUpdateConfig=json_decode((string)@file_get_contents($casaUpdateSettings),true);
        if (($casaUpdateConfig['enabled']??false)===true && !is_link($casaUpdateState.'/web-tick.lock')) {
            $casaTick=@fopen($casaUpdateState.'/web-tick.lock','c+');
            if ($casaTick!==false) {
                if (flock($casaTick,LOCK_EX|LOCK_NB)) {
                    $casaLast=(int)stream_get_contents($casaTick,30);
                    if (time()-$casaLast>=300 || is_file($casaUpdateRoot.'/.casa-site-update-maintenance')) {
                        ftruncate($casaTick,0);rewind($casaTick);fwrite($casaTick,(string)time());fflush($casaTick);
                        register_shutdown_function(static function()use($casaUpdateRoot,$casaUpdateState):void {
                            if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
                            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                            ignore_user_abort(true);@set_time_limit(180);
                            try { (new CasaSiteUpdater($casaUpdateRoot,$casaUpdateState))->run(); }
                            catch (Throwable $e) { error_log('Casa auto-update: '.$e->getMessage()); }
                        });
                    }
                    flock($casaTick,LOCK_UN);
                }
                fclose($casaTick);
            }
        }
    }
}
