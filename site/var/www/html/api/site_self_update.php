<?php
// Atualizador por HTTPS, independente do banco e de credenciais SSH/Git.
// O executor publico e somente CLI; nao existe rota HTTP que instale codigo.
final class CasaSiteUpdater {
    public const REPO='marcelomaurin/casa';
    public const PREFIX='site/var/www/html/';
    private string $root;
    private string $state;
    private $request;
    private $health;

    public static function statePath(string $root): string {
        $root=rtrim(str_replace('\\','/',realpath($root)?:$root),'/');
        return getenv('CASA_SITE_UPDATE_STATE_DIR')?:dirname($root,2).'/.casa-site-update-'.substr(hash('sha256',$root),0,12);
    }

    public function __construct(string $root,?string $state=null,?callable $request=null,?callable $health=null) {
        $resolved=realpath($root);
        if ($resolved===false || !is_dir($resolved)) throw new RuntimeException('Raiz do site inexistente');
        $this->root=rtrim(str_replace('\\','/',$resolved),'/');
        $state=$state??self::statePath($this->root);
        if (!is_dir($state) && !mkdir($state,0700,true)) throw new RuntimeException('Diretorio privado de atualizacao indisponivel');
        $this->state=rtrim(str_replace('\\','/',(string)realpath($state)),'/');
        if ($this->state==='' || self::inside($this->state,$this->root)) throw new RuntimeException('Estado e backups devem ficar fora da raiz publica');
        $this->request=$request??[self::class,'download'];
        $this->health=$health;
    }

    private static function inside(string $path,string $base): bool {
        if (PHP_OS_FAMILY==='Windows') { $path=strtolower($path);$base=strtolower($base); }
        return $path===$base || str_starts_with($path,$base.'/');
    }

    public static function allowed(string $path): bool {
        if ($path==='' || strlen($path)>240 || str_contains($path,'\\') || str_starts_with($path,'/')) return false;
        foreach (explode('/',$path) as $part) {
            if ($part==='.htaccess') continue;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D',$part) || str_ends_with($part,'.')) return false;
        }
        if (preg_match('~(^|/)(uploads?|data|cache|tmp|logs?|audios?|sessions|secrets|credentials)(/|$)~i',$path)) return false;
        if (preg_match('~(^|/)(config\.local\.php|[^/]+\.secret\.php|[^/]+\.(env|ini|cfg|key|pem|db|sqlite|log))$~i',$path)) return false;
        if (preg_match('~(^|/)(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|/|$)~i',$path)) return false;
        return basename($path)==='.htaccess' || (bool)preg_match('/\.(php|js|css|html|json|sql|md|txt|svg|ico|png|jpg|jpeg|webp|gif|woff2?|ttf|apk)$/iD',$path);
    }

    private function target(string $path): string {
        if (!self::allowed($path)) throw new RuntimeException('Destino de atualizacao protegido ou invalido');
        $current=$this->root;
        foreach (explode('/',$path) as $part) {
            $current.='/'.$part;
            if (is_link($current)) throw new RuntimeException('Link encontrado no destino do site');
        }
        $parent=dirname($current);
        while (!file_exists($parent)) $parent=dirname($parent);
        $real=realpath($parent);
        if ($real===false || !self::inside(str_replace('\\','/',$real),$this->root)) throw new RuntimeException('Destino fora do site');
        if (file_exists($current) && !is_file($current)) throw new RuntimeException('Destino nao e arquivo regular');
        return $current;
    }

    public static function blob(string $raw): string { return sha1('blob '.strlen($raw)."\0".$raw); }

    private static function download(string $url,int $limit): string {
        $parts=parse_url($url);
        if (($parts['scheme']??'')!=='https' || !in_array($parts['host']??'',['api.github.com','raw.githubusercontent.com'],true) ||
            isset($parts['user']) || isset($parts['pass'])) throw new RuntimeException('Fonte de atualizacao invalida');
        if (!function_exists('curl_init')) throw new RuntimeException('Extensao PHP curl necessaria');
        $raw='';$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['User-Agent: Casa-Site-Updater','Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28'],
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw,$limit):int {
                if (strlen($raw)+strlen($chunk)>$limit) return 0;
                $raw.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if ($ok===false || $code!==200) throw new RuntimeException('Download GitHub indisponivel (HTTP '.$code.')');
        return $raw;
    }

    private function api(string $route): array {
        $raw=($this->request)('https://api.github.com/repos/'.self::REPO.$route,4000000);
        $value=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Resposta GitHub invalida');
        return $value;
    }

    private static function sha($value): string {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{40}$/D',$value)) throw new RuntimeException('Commit ou hash Git invalido');
        return $value;
    }

    private function json(string $name,array $default=[]): array {
        $file=$this->state.'/'.$name;
        if (!is_file($file)) return $default;
        if (is_link($file) || filesize($file)>4000000) throw new RuntimeException('Estado de atualizacao invalido');
        $data=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Estado invalido');
        return $data;
    }

    private static function write(string $file,string $raw,int $mode=0644): void {
        if (is_link($file)) throw new RuntimeException('Destino nao pode ser link');
        if (!is_dir(dirname($file)) && !mkdir(dirname($file),0755,true)) throw new RuntimeException('Nao foi possivel preparar destino');
        $tmp=$file.'.casa-'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw)) throw new RuntimeException('Falha ao gravar arquivo temporario');
        chmod($tmp,$mode);
        if (!rename($tmp,$file)) { @unlink($tmp);throw new RuntimeException('Falha ao substituir arquivo'); }
        if (function_exists('opcache_invalidate') && str_ends_with($file,'.php')) @opcache_invalidate($file,true);
    }

    private function save(string $name,array $data): void {
        self::write($this->state.'/'.$name,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),0600);
    }

    public function enable(bool $enabled): array {
        $config=['enabled'=>$enabled,'repo'=>self::REPO,'branch'=>'master','source'=>self::PREFIX];
        $this->save('settings.json',$config);return $config;
    }

    public function status(): array {
        return ['config'=>$this->json('settings.json',['enabled'=>false]),'installed'=>$this->json('installed.json'),
            'last_check'=>$this->json('status.json'),'state_dir'=>$this->state,'web_root'=>$this->root];
    }

    private function report(string $phase,array $extra=[]): array {
        $result=['status'=>$phase,'checked_at'=>gmdate('c')]+$extra;
        $this->save('status.json',$result);return $result;
    }

    private function ci(string $sha): bool {
        $data=$this->api('/actions/runs?head_sha='.$sha.'&event=push&per_page=100');
        $required=['.github/workflows/integration-control-plane.yml'=>null,'.github/workflows/site-auto-update.yml'=>null];
        foreach ($data['workflow_runs']??[] as $run) {
            $path=$run['path']??'';
            if (array_key_exists($path,$required) && $required[$path]===null && ($run['head_sha']??'')===$sha && ($run['head_branch']??'')==='master')
                $required[$path]=($run['status']??'')==='completed' && ($run['conclusion']??'')==='success';
        }
        return !in_array(null,$required,true) && !in_array(false,$required,true);
    }

    private function recover(): void {
        $journal=$this->json('transaction.json');
        if (!$journal) return;
        if (($journal['root']??'')!==$this->root || !preg_match('/^[a-f0-9]{40}-[a-f0-9]{12}$/D',$journal['backup']??''))
            throw new RuntimeException('Journal nao corresponde a instalacao');
        $backup=$this->state.'/backups/'.$journal['backup'];
        foreach (array_reverse($journal['operations']) as $op) {
            $target=$this->target($op['path']);
            if ($op['existed']) {
                if (!is_file($backup.'/'.$op['path']) || is_link($backup.'/'.$op['path'])) throw new RuntimeException('Backup de recuperacao ausente');
                if (is_file($target)) {
                    $current=self::blob((string)file_get_contents($target));
                    if ($current!==$op['before_sha'] && $current!==$op['after_sha']) throw new RuntimeException('Arquivo alterado durante recuperacao');
                }
                $old=(string)file_get_contents($backup.'/'.$op['path']);
                if (self::blob($old)!==$op['before_sha']) throw new RuntimeException('Backup de recuperacao invalido');
                self::write($target,$old);
            } elseif (is_file($target)) {
                if (self::blob((string)file_get_contents($target))!==$op['after_sha']) throw new RuntimeException('Arquivo novo alterado durante recuperacao');
                if (!unlink($target)) throw new RuntimeException('Falha ao recuperar arquivo novo');
            }
        }
        $this->save('installed.json',$journal['previous']);
        unlink($this->state.'/transaction.json');
        if (is_file($this->root.'/.casa-site-update-maintenance')) unlink($this->root.'/.casa-site-update-maintenance');
    }

    public function run(bool $checkOnly=false): array {
        $lock=fopen($this->state.'/update.lock','c');
        if ($lock===false) throw new RuntimeException('Lock de atualizacao indisponivel');
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock);return ['status'=>'ocupado']; }
        try {
            $this->recover();
            if (!$checkOnly && !($this->json('settings.json')['enabled']??false)) return $this->report('desabilitado');
            $commits=$this->api('/commits?sha=master&path=site%2Fvar%2Fwww%2Fhtml&per_page=1');
            $sha=self::sha($commits[0]['sha']??null);
            $installed=$this->json('installed.json',['files'=>[]]);
            if (($installed['commit']??'')===$sha) return $this->report('atualizado',['commit'=>$sha]);
            if (!$this->ci($sha)) return $this->report('aguardando_testes',['available_commit'=>$sha]);
            $treeSha=self::sha($commits[0]['commit']['tree']['sha']??null);
            $tree=$this->api('/git/trees/'.$treeSha.'?recursive=1');
            if (!empty($tree['truncated'])) throw new RuntimeException('Arvore GitHub incompleta');
            $files=[];$seen=[];$bytes=0;
            foreach ($tree['tree']??[] as $entry) {
                if (!str_starts_with($entry['path']??'',self::PREFIX)) continue;
                $path=substr($entry['path'],strlen(self::PREFIX));
                if (($entry['type']??'')!=='blob' || !self::allowed($path)) continue;
                if (!in_array($entry['mode']??'',['100644','100755'],true)) throw new RuntimeException('Link ou modo nao suportado no pacote');
                if (isset($seen[strtolower($path)])) throw new RuntimeException('Colisao de nomes no pacote');
                $size=$entry['size']??null;
                if (!is_int($size) || $size<0 || $size>16000000) throw new RuntimeException('Arquivo de atualizacao excessivo');
                $files[$path]=['sha'=>self::sha($entry['sha']??null),'size'=>$size];$seen[strtolower($path)]=true;$bytes+=$size;
            }
            if (!isset($files['index.php'],$files['api/db.php']) || count($files)>600 || $bytes>80000000) throw new RuntimeException('Pacote de site incompleto ou excessivo');
            $operations=[];
            foreach ($files as $path=>$meta) {
                $target=$this->target($path);$exists=is_file($target);
                $before=$exists?self::blob((string)file_get_contents($target)):null;
                if ($before===$meta['sha']) continue;
                if (isset($installed['files'][$path]) && $before!==null && $before!==$installed['files'][$path]['sha'])
                    throw new RuntimeException('Alteracao local em arquivo gerenciado: '.$path);
                $operations[]=['path'=>$path,'existed'=>$exists,'before_sha'=>$before,'after_sha'=>$meta['sha']];
            }
            foreach ($installed['files']??[] as $path=>$meta) {
                if (isset($files[$path]) || !self::allowed($path)) continue;
                $target=$this->target($path);
                if (is_file($target)) {
                    if (self::blob((string)file_get_contents($target))!==$meta['sha']) throw new RuntimeException('Arquivo removido possui alteracao local: '.$path);
                    $operations[]=['path'=>$path,'existed'=>true,'before_sha'=>$meta['sha'],'after_sha'=>null];
                }
            }
            // Instalar o guard antes dos arquivos que passam a inclui-lo.
            $priority=static function(array $op):int {
                if ($op['after_sha']===null) return 50;
                if (in_array($op['path'],['api/site_update_guard.php','api/site_update_hook.php'],true)) return 0;
                if (in_array($op['path'],['api/db.php','index.php'],true)) return 1;
                return 2;
            };
            usort($operations,static fn($a,$b)=>$priority($a)<=>$priority($b));
            if ($checkOnly) return $this->report('verificado',['available_commit'=>$sha,'changed_files'=>count($operations)]);
            // Baixar e validar tudo antes de alterar um unico arquivo do site.
            if (!is_dir($this->state.'/cache')) mkdir($this->state.'/cache',0700);
            foreach ($operations as $op) {
                if ($op['after_sha']===null) continue;
                $cache=$this->state.'/cache/'.$op['after_sha'];$meta=$files[$op['path']];
                if (!is_file($cache) || filesize($cache)!==$meta['size'] || self::blob((string)file_get_contents($cache))!==$meta['sha']) {
                    $raw=($this->request)('https://raw.githubusercontent.com/'.self::REPO.'/'.$sha.'/'.self::PREFIX.$op['path'],16000000);
                    if (strlen($raw)!==$meta['size'] || self::blob($raw)!==$meta['sha']) throw new RuntimeException('Hash ou tamanho incorreto: '.$op['path']);
                    self::write($cache,$raw,0600);
                }
                if (strtolower(pathinfo($op['path'],PATHINFO_EXTENSION))==='php') {
                    try { token_get_all((string)file_get_contents($cache),TOKEN_PARSE); }
                    catch (ParseError $e) { throw new RuntimeException('Sintaxe PHP invalida no pacote: '.$op['path']); }
                }
            }
            $backup=$sha.'-'.bin2hex(random_bytes(6));$dir=$this->state.'/backups/'.$backup;
            if (!mkdir($dir,0700,true)) throw new RuntimeException('Nao foi possivel preparar backups');
            foreach ($operations as $op) if ($op['existed']) self::write($dir.'/'.$op['path'],(string)file_get_contents($this->target($op['path'])),0600);
            $this->save('transaction.json',['root'=>$this->root,'backup'=>$backup,'previous'=>$installed,'operations'=>$operations]);
            self::write($this->root.'/.casa-site-update-maintenance',gmdate('c'),0644);
            foreach ($operations as $op) {
                $target=$this->target($op['path']);
                if ($op['after_sha']===null) { if (!unlink($target)) throw new RuntimeException('Falha ao remover arquivo obsoleto'); }
                else self::write($target,(string)file_get_contents($this->state.'/cache/'.$op['after_sha']));
            }
            if ($this->health!==null && ($this->health)($this->root)!==true) throw new RuntimeException('Verificacao do site falhou; restaurando versao anterior');
            $this->save('installed.json',['commit'=>$sha,'installed_at'=>gmdate('c'),'files'=>$files]);
            unlink($this->state.'/transaction.json');unlink($this->root.'/.casa-site-update-maintenance');
            return $this->report('atualizado',['commit'=>$sha,'changed_files'=>count($operations),'backup'=>$backup]);
        } catch (Throwable $e) {
            try { $this->recover(); } catch (Throwable $recovery) {
                $this->report('recuperacao_pendente',['error'=>$recovery->getMessage()]);throw $recovery;
            }
            $this->report('erro',['error'=>$e->getMessage()]);throw $e;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
}
