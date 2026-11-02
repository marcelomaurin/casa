<?php
// Instalador independente para uma instalacao Casa existente na Hostinger/PHP.
// Autorizacao vem da sessao administrativa ja existente, nunca da URL.
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if (empty($_SESSION['auth_user']) || !in_array(strtolower((string)($_SESSION['auth_perfil']??'')),['admin','administrador'],true)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Atualizar Casa</title><p>Entre no Casa com uma conta administradora e abra novamente este arquivo.</p><a href="login.php">Entrar no Casa</a>';
    exit;
}
if (empty($_SESSION['casa_update_csrf'])) $_SESSION['casa_update_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['casa_update_csrf'];$message='';$error='';$status=null;

function casaInstallerFetch(string $url,int $limit=4000000): string {
    if (!function_exists('curl_init')) throw new RuntimeException('Habilite a extensao curl do PHP na hospedagem.');
    $parts=parse_url($url);
    if (($parts['scheme']??'')!=='https' || !in_array($parts['host']??'',['api.github.com','raw.githubusercontent.com'],true)) throw new RuntimeException('Fonte invalida');
    $raw='';$ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['User-Agent: Casa-Site-Installer','Accept: application/vnd.github+json'],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw,$limit):int {
            if (strlen($raw)+strlen($chunk)>$limit) return 0;
            $raw.=$chunk;return strlen($chunk);
        }]);
    $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if ($ok===false || $code!==200) throw new RuntimeException('GitHub indisponivel (HTTP '.$code.'). Tente novamente mais tarde.');
    return $raw;
}

function casaInstallerBootstrap(string $root,?callable $fetch=null): void {
    $base='https://api.github.com/repos/marcelomaurin/casa';
    $fetch=$fetch??'casaInstallerFetch';
    $get=static fn($route)=>json_decode($fetch($base.$route,4000000),true,64,JSON_THROW_ON_ERROR);
    $commits=$get('/commits?sha=master&path=site%2Fvar%2Fwww%2Fhtml&per_page=1');
    $sha=$commits[0]['sha']??'';$treeSha=$commits[0]['commit']['tree']['sha']??'';
    if (!preg_match('/^[a-f0-9]{40}$/D',$sha) || !preg_match('/^[a-f0-9]{40}$/D',$treeSha)) throw new RuntimeException('Versao GitHub invalida');
    $runs=$get('/actions/runs?head_sha='.$sha.'&event=push&per_page=100');
    $required=['.github/workflows/integration-control-plane.yml'=>null,'.github/workflows/site-auto-update.yml'=>null];
    foreach ($runs['workflow_runs']??[] as $run) {
        $path=$run['path']??'';
        if (array_key_exists($path,$required) && $required[$path]===null && ($run['head_sha']??'')===$sha && ($run['head_branch']??'')==='master')
            $required[$path]=($run['status']??'')==='completed' && ($run['conclusion']??'')==='success';
    }
    if (in_array(null,$required,true) || in_array(false,$required,true)) throw new RuntimeException('A nova versao ainda nao terminou os testes. Tente novamente depois.');
    $tree=$get('/git/trees/'.$treeSha.'?recursive=1');
    if (!empty($tree['truncated'])) throw new RuntimeException('Pacote incompleto');
    $wanted=['api/site_self_update.php'=>null,'api/site_update_guard.php'=>null,'api/site_update_hook.php'=>null,'workers/site_update.php'=>null];
    foreach ($tree['tree']??[] as $entry) {
        $prefix='site/var/www/html/';
        if (!str_starts_with($entry['path']??'',$prefix)) continue;
        $path=substr($entry['path'],strlen($prefix));
        if (array_key_exists($path,$wanted) && ($entry['type']??'')==='blob' && ($entry['mode']??'')==='100644') $wanted[$path]=$entry;
    }
    $downloaded=[];
    foreach ($wanted as $path=>$meta) {
        if (!$meta || !is_int($meta['size']??null) || $meta['size']>150000) throw new RuntimeException('Componente do atualizador ausente ou invalido');
        $raw=$fetch('https://raw.githubusercontent.com/marcelomaurin/casa/'.$sha.'/site/var/www/html/'.$path,150000);
        if (strlen($raw)!==$meta['size'] || sha1('blob '.strlen($raw)."\0".$raw)!==($meta['sha']??'')) throw new RuntimeException('Falha na verificacao de '.$path);
        token_get_all($raw,TOKEN_PARSE);$downloaded[$path]=$raw;
    }
    // Todos os componentes foram verificados antes de escrever qualquer um.
    foreach ($downloaded as $path=>$raw) {
        $target=$root.'/'.$path;
        if (is_link(dirname($target)) || is_link($target)) throw new RuntimeException('Destino nao pode ser link');
        if (!is_dir(dirname($target)) && !mkdir(dirname($target),0755,true)) throw new RuntimeException('Sem permissao para preparar componentes');
        $tmp=$target.'.casa-install-'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw)) throw new RuntimeException('Sem permissao para instalar atualizador');
        chmod($tmp,0644);
        if (!rename($tmp,$target)) throw new RuntimeException('Nao foi possivel instalar componente');
        if (function_exists('opcache_invalidate')) @opcache_invalidate($target,true);
    }
}

try {
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        if (!hash_equals($csrf,(string)($_POST['csrf']??''))) { http_response_code(403);throw new RuntimeException('Sessao expirada. Atualize a pagina.'); }
        $action=(string)($_POST['action']??'');
        if (!in_array($action,['install','check','update','disable'],true)) throw new RuntimeException('Acao invalida');
        // Liberar a sessao evita bloquear o restante do painel durante o download.
        session_write_close();@set_time_limit(240);ignore_user_abort(true);
        if (!is_file(__DIR__.'/api/site_self_update.php')) {
            if ($action!=='install') throw new RuntimeException('Instale o atualizador primeiro');
            casaInstallerBootstrap(__DIR__);
        }
        require_once __DIR__.'/api/site_self_update.php';
        $updater=new CasaSiteUpdater(__DIR__);
        if ($action==='disable') { $updater->enable(false);$message='Atualizacao automatica pausada.'; }
        elseif ($action==='check') { $r=$updater->run(true);$message='Verificacao: '.$r['status']; }
        else { $updater->enable(true);$r=$updater->run();$message='Resultado: '.$r['status']; }
        $status=$updater->status();
    } elseif (is_file(__DIR__.'/api/site_self_update.php')) {
        require_once __DIR__.'/api/site_self_update.php';$status=(new CasaSiteUpdater(__DIR__))->status();
    }
} catch (Throwable $e) { $error=$e->getMessage(); }
function casaInstallerEscape($text):string { return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8'); }
?>
<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Atualizacao do Casa</title>
<style>body{font:16px system-ui;max-width:760px;margin:40px auto;padding:20px;background:#101b28;color:#eef4fa}section{background:#1b2c40;padding:24px;border-radius:12px;margin:20px 0}button{padding:12px 18px;margin:6px;border:0;border-radius:6px;cursor:pointer}a{color:#8ed5ff}.error{color:#ffb5b5}.ok{color:#a8edbd}code{overflow-wrap:anywhere}</style>
<h1>Atualizacao do Casa</h1><p>O proprio site busca a versao testada no GitHub. Configuracoes e dados locais sao preservados.</p>
<?php if($error):?><p class="error"><?=casaInstallerEscape($error)?></p><?php endif;?>
<?php if($message):?><p class="ok"><?=casaInstallerEscape($message)?></p><?php endif;?>
<section><p>Atualizacao automatica: <strong><?=($status['config']['enabled']??false)?'habilitada':'nao habilitada'?></strong></p>
<p>Versao instalada: <code><?=casaInstallerEscape($status['installed']['commit']??'ainda nao registrada')?></code></p>
<p>Ultima verificacao: <?=casaInstallerEscape($status['last_check']['checked_at']??'nenhuma')?> · <?=casaInstallerEscape($status['last_check']['status']??'aguardando instalacao')?></p>
<form method="post"><input type="hidden" name="csrf" value="<?=casaInstallerEscape($csrf)?>">
<button name="action" value="install">Instalar e ativar atualizacao automatica</button>
<?php if($status):?><button name="action" value="check">Verificar agora</button><button name="action" value="update">Atualizar agora</button><button name="action" value="disable">Pausar atualizacao automatica</button><?php endif;?>
</form></section>
<p>Depois de ativada, a verificacao ocorre em segundo plano, a cada cinco minutos, quando ha acessos ao site. Na Hostinger, um cron pode manter a verificacao mesmo sem visitantes.</p>
<p>Cron opcional: PHP a cada cinco minutos executando <code><?=casaInstallerEscape(__DIR__.'/workers/site_update.php')?></code>.</p>
<p>Se aparecer "aguardando_testes", a versao atual fica no ar ate os testes terminarem. Esta atualizacao nao faz merge de Pull Requests.</p>
<a href="index.php">Voltar ao Casa</a></html>
