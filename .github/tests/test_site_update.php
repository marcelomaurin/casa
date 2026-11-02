<?php
require_once __DIR__.'/../../site/var/www/html/api/site_self_update.php';
$checks=0;
function su_ok(bool $v,string $name):void { global $checks;if (!$v) throw new RuntimeException($name);$checks++; }
function su_fail(callable $f,string $name):void { try { $f(); } catch (Throwable $e) { su_ok(true,$name);return; }throw new RuntimeException('Aceitou: '.$name); }
function su_dir(string $path):void { if (!is_dir($path)) mkdir($path,0700,true); }
function su_put(string $path,string $raw):void { su_dir(dirname($path));file_put_contents($path,$raw); }
final class SiteFixture {
    public string $root;public string $state;public string $sha;public array $files;public bool $ci=true;public bool $badHash=false;public array $requests=[];
    public function __construct() {
        $base=sys_get_temp_dir().'/casa-site-test-'.bin2hex(random_bytes(6));
        $this->root=$base.'/web';$this->state=$base.'/private';su_dir($this->root);
        $this->sha=str_repeat('a',40);
        $this->files=['index.php'=>'<?php echo "novo";','api/db.php'=>'<?php function db_test() {}','assets/app.js'=>'const v=1;',
            'api/config.local.php'=>'<?php return ["db_pass"=>"nunca_instalar"];','uploads/foto.jpg'=>'nao_substituir'];
        su_put($this->root.'/index.php','<?php echo "antigo";');
        su_put($this->root.'/api/config.local.php','<?php return ["db_pass"=>"privado"];');
        su_put($this->root.'/uploads/foto.jpg','foto_local');su_put($this->root.'/extra-local.txt','preservar');
    }
    public function request(string $url,int $limit):string {
        $this->requests[]=$url;
        if (str_contains($url,'/commits?')) return json_encode([['sha'=>$this->sha,'commit'=>['tree'=>['sha'=>str_repeat('b',40)]]]]);
        if (str_contains($url,'/actions/runs?')) {
            $runs=[];
            foreach (['integration-control-plane.yml','site-auto-update.yml'] as $file)
                $runs[]=['path'=>'.github/workflows/'.$file,'head_sha'=>$this->sha,'head_branch'=>'master','status'=>'completed','conclusion'=>$this->ci?'success':'failure'];
            return json_encode(['workflow_runs'=>$runs]);
        }
        if (str_contains($url,'/git/trees/')) {
            $tree=[];
            foreach ($this->files as $path=>$raw) $tree[]=['path'=>CasaSiteUpdater::PREFIX.$path,'type'=>'blob','mode'=>'100644','size'=>strlen($raw),'sha'=>CasaSiteUpdater::blob($raw)];
            return json_encode(['tree'=>$tree,'truncated'=>false]);
        }
        $prefix='https://raw.githubusercontent.com/'.CasaSiteUpdater::REPO.'/'.$this->sha.'/'.CasaSiteUpdater::PREFIX;
        if (!str_starts_with($url,$prefix)) throw new RuntimeException('URL inesperada: '.$url);
        $path=substr($url,strlen($prefix));
        return $this->badHash?'conteudo adulterado':$this->files[$path];
    }
    public function updater(?callable $health=null):CasaSiteUpdater { return new CasaSiteUpdater($this->root,$this->state,[$this,'request'],$health); }
}

$f=new SiteFixture();$u=$f->updater();
su_ok($u->run()['status']==='desabilitado','nao atualizar antes de habilitar');
$u->enable(true);$r=$u->run();
su_ok($r['status']==='atualizado','primeira instalacao');
su_ok(file_get_contents($f->root.'/index.php')===$f->files['index.php'],'codigo instalado');
su_ok(str_contains(file_get_contents($f->root.'/api/config.local.php'),'privado'),'configuracao privada preservada');
su_ok(file_get_contents($f->root.'/uploads/foto.jpg')==='foto_local','upload preservado');
su_ok(file_get_contents($f->root.'/extra-local.txt')==='preservar','arquivo desconhecido preservado');
su_ok(!is_file($f->root.'/.casa-site-update-maintenance'),'manutencao encerrada');
su_ok(!is_file($f->state.'/transaction.json'),'journal finalizado');
$count=count($f->requests);su_ok($u->run()['status']==='atualizado' && count($f->requests)===$count+1,'sem downloads repetidos');
$f->sha=str_repeat('c',40);unset($f->files['assets/app.js']);$f->files['novo.php']='<?php echo 2;';
$u->run();su_ok(!is_file($f->root.'/assets/app.js'),'remove somente arquivo gerenciado obsoleto');
su_ok(is_file($f->root.'/novo.php'),'novo arquivo instalado');
su_put($f->root.'/index.php','<?php echo "edicao local";');$f->sha=str_repeat('d',40);$f->files['index.php']='<?php echo "outra versao";';
su_fail(fn()=>$u->run(),'nao sobrepor edicao local');
su_ok(str_contains(file_get_contents($f->root.'/index.php'),'edicao local'),'edicao local mantida');

$f=new SiteFixture();$u=$f->updater();$u->enable(true);$f->ci=false;
su_ok($u->run()['status']==='aguardando_testes','CI falho bloqueia atualizacao');
su_ok(str_contains(file_get_contents($f->root.'/index.php'),'antigo'),'CI falho nao altera site');
$f->ci=true;$f->badHash=true;su_fail(fn()=>$u->run(),'hash adulterado');
su_ok(str_contains(file_get_contents($f->root.'/index.php'),'antigo'),'download invalido nao altera site');
$f->badHash=false;$f->files['api/db.php']='<?php function x( {';su_fail(fn()=>$u->run(),'PHP quebrado');
su_ok(str_contains(file_get_contents($f->root.'/index.php'),'antigo'),'PHP invalido recusado antes de escrever');

$f=new SiteFixture();$u=$f->updater(static fn()=>false);$u->enable(true);
su_fail(fn()=>$u->run(),'rollback por falha da verificacao');
su_ok(str_contains(file_get_contents($f->root.'/index.php'),'antigo'),'rollback restaura codigo anterior');
su_ok(!file_exists($f->root.'/assets/app.js'),'rollback remove apenas arquivos novos da transacao');
su_ok(!file_exists($f->root.'/.casa-site-update-maintenance'),'rollback libera manutencao');
su_ok(str_contains(file_get_contents($f->root.'/api/config.local.php'),'privado'),'rollback preserva credenciais');

// Simular interrupcao depois de alterar um arquivo e antes de finalizar o journal.
$f=new SiteFixture();$u=$f->updater();$u->enable(true);
$old=file_get_contents($f->root.'/index.php');$new='<?php echo "parcial";';$backup=str_repeat('e',40).'-'.str_repeat('f',12);
su_put($f->state.'/backups/'.$backup.'/index.php',$old);su_put($f->root.'/index.php',$new);
su_put($f->root.'/.casa-site-update-maintenance','atualizando');
su_put($f->state.'/transaction.json',json_encode(['root'=>str_replace('\\','/',realpath($f->root)),'backup'=>$backup,'previous'=>['files'=>[]],
    'operations'=>[['path'=>'index.php','existed'=>true,'before_sha'=>CasaSiteUpdater::blob($old),'after_sha'=>CasaSiteUpdater::blob($new)]]]));
$u->run(true);su_ok(file_get_contents($f->root.'/index.php')===$old,'recupera interrupcao antes da nova verificacao');
su_ok(!is_file($f->root.'/.casa-site-update-maintenance'),'recuperacao libera site');
$lock=fopen($f->state.'/update.lock','c');flock($lock,LOCK_EX);
su_ok($u->run()['status']==='ocupado','exclusao mutua de atualizadores');flock($lock,LOCK_UN);fclose($lock);

foreach (['../fora.php','/absoluto.php','api/config.local.php','.env','uploads/a.php','log/a.php','api/a.secret.php','NUL.php','a\\b.php'] as $path)
    su_ok(!CasaSiteUpdater::allowed($path),'caminho protegido '.$path);
su_ok(CasaSiteUpdater::allowed('api/v1/.htaccess'),'htaccess do gateway gerenciado');
$f=new SiteFixture();su_fail(fn()=>new CasaSiteUpdater($f->root,$f->root.'/estado'),'backups fora da raiz publica');
if (PHP_OS_FAMILY!=='Windows') {
    $f=new SiteFixture();$u=$f->updater();$u->enable(true);su_dir($f->root.'/api');
    unlink($f->root.'/api/config.local.php');rmdir($f->root.'/api');symlink(dirname($f->root),$f->root.'/api');
    su_fail(fn()=>$u->run(),'nao seguir symlink no destino');
}
// O navegador nao pode executar o instalador; durante a troca recebe 503.
$f=new SiteFixture();
su_put($f->root.'/api/site_update_guard.php',(string)file_get_contents(__DIR__.'/../../site/var/www/html/api/site_update_guard.php'));
su_put($f->root.'/workers/site_update.php',(string)file_get_contents(__DIR__.'/../../site/var/www/html/workers/site_update.php'));
su_put($f->root.'/index.php','<?php require __DIR__."/api/site_update_guard.php"; echo "disponivel";');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
if (!$socket) throw new RuntimeException('Porta de teste indisponivel');
$address=stream_socket_get_name($socket,false);fclose($socket);
$log=dirname($f->root).'/http-test.log';
$proc=proc_open([PHP_BINARY,'-S',$address,'-t',$f->root],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);
if (!is_resource($proc)) throw new RuntimeException('Servidor de teste indisponivel');
fclose($pipes[0]);
try {
    $ready=false;
    for ($i=0;$i<30;$i++) {
        $probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1);
        if ($probe) { fclose($probe);$ready=true;break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Servidor de teste nao iniciou');
    $context=stream_context_create(['http'=>['ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
    file_get_contents('http://'.$address.'/workers/site_update.php',false,$context);
    su_ok(str_contains($http_response_header[0]??'','403'),'instalador recusa HTTP');
    su_put($f->root.'/.casa-site-update-maintenance','troca em andamento');
    $body=file_get_contents('http://'.$address.'/',false,$context);
    su_ok(str_contains($http_response_header[0]??'','503') && str_contains($body,'atualizacao'),'guard de manutencao responde 503');
    unlink($f->root.'/.casa-site-update-maintenance');
    $body=file_get_contents('http://'.$address.'/',false,$context);
    su_ok(str_contains($http_response_header[0]??'','200') && $body==='disponivel','site volta depois da troca');
} finally { proc_terminate($proc);proc_close($proc); }
echo "OK: {$checks} verificacoes de atualizacao e recuperacao\n";
