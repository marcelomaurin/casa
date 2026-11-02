<?php
require_once __DIR__.'/../site/var/www/html/api/projetos_github_fluxo.php';
putenv('CASA_GITHUB_REPOS_PERMITIDOS=example/casa');
putenv('CASA_GITHUB_REQUIRED_CHECKS=integration');
$passed=0;
function check(bool $v,string $label):void { global $passed;if (!$v) throw new RuntimeException($label);$passed++; }
function fails(callable $f,string $label):void { try { $f(); } catch (RuntimeException|InvalidArgumentException $e) { check(true,$label);return; }throw new RuntimeException('Aceitou: '.$label); }
class GithubFake {
    public string $parent;public string $head;public string $tree;public string $commit;
    public array $state=[];public array $calls=[];public ?array $pr=null;public array $checks=[];public array $reviews=[];
    public bool $crashPatch=false;public bool $crashPr=false;public bool $lease=true;public bool $changedDuringRead=false;
    public int $generated=0;public array $changes=[['path'=>'src/app.php','content'=>'<?php echo 2;']];
    public array $entries=[];
    public function __construct() {
        $this->parent=str_repeat('a',40);$this->head=$this->parent;$this->tree=str_repeat('b',40);$this->commit=str_repeat('c',40);
        $this->entries=[['path'=>'src/app.php','type'=>'blob','mode'=>'100644','sha'=>str_repeat('d',40),'size'=>13]];
    }
    public function api(string $m,string $r,?array $p=null):array {
        check(casa_gh_rota_fluxo($m,$r),'rota autorizada: '.$m.' '.$r);$this->calls[]=[$m,$r,$p];
        if ($r==='') return ['default_branch'=>'master'];
        if (str_starts_with($r,'/pulls?')) return $this->pr?[$this->pr]:[];
        if ($m==='GET' && str_starts_with($r,'/git/ref/')) return ['object'=>['sha'=>$this->head]];
        if ($m==='GET' && str_starts_with($r,'/git/commits/')) return ['tree'=>['sha'=>$this->tree]];
        if ($m==='GET' && str_starts_with($r,'/git/trees/')) return ['tree'=>$this->entries,'truncated'=>false];
        if ($m==='GET' && str_starts_with($r,'/git/blobs/')) return ['encoding'=>'base64','content'=>base64_encode('<?php echo 1;')];
        if ($m==='POST' && $r==='/git/blobs') return ['sha'=>str_repeat('e',40)];
        if ($m==='POST' && $r==='/git/trees') return ['sha'=>str_repeat('f',40)];
        if ($m==='POST' && $r==='/git/commits') { check($p['parents']===[$this->parent],'commit atomico no pai esperado');return ['sha'=>$this->commit]; }
        if ($m==='PATCH') {
            check($p['force']===false,'sem force push');$this->head=$p['sha'];
            if ($this->crashPatch) { $this->crashPatch=false;throw new RuntimeException('Interrupcao apos PATCH'); }
            return ['object'=>['sha'=>$this->head]];
        }
        if ($m==='POST' && $r==='/pulls') {
            check($p['draft']===true,'PR sempre draft');
            $this->pr=['number'=>9,'html_url'=>'https://github.com/example/casa/pull/9','state'=>'open','draft'=>true,
                'head'=>['sha'=>$this->head,'ref'=>'casa/projeto-1/tarefa-2','repo'=>['full_name'=>'example/casa']],
                'base'=>['ref'=>'master'],'user'=>['login'=>'worker'],'merged'=>false];
            if ($this->crashPr) { $this->crashPr=false;throw new RuntimeException('Interrupcao apos PR'); }
            return $this->pr;
        }
        if ($m==='GET' && $r==='/pulls/9') {
            if ($this->changedDuringRead) { $this->pr['head']['sha']=str_repeat('9',40);$this->changedDuringRead=false; }
            return $this->pr;
        }
        if (str_contains($r,'/check-runs?')) return ['check_runs'=>$this->checks];
        if (str_contains($r,'/status?')) return ['statuses'=>[]];
        if (str_contains($r,'/reviews?')) return $this->reviews;
        throw new RuntimeException('Fake sem rota '.$m.' '.$r);
    }
    public function advance():array {
        return casa_gh_fluxo_avancar(['id'=>1,'repositorio'=>'example/casa'],
            ['id'=>2,'projeto_id'=>1,'titulo'=>'Corrigir src/app.php','descricao'=>'Corrigir app','criterio_aceite'=>'Retornar 2'],$this->state,
            ['api'=>[$this,'api'],'salvar'=>function($s){$this->state=$s;},
                'reserva'=>function(){if (!$this->lease) throw new RuntimeException('Lease perdido');},
                'gerar'=>function($ctx){$this->generated++;check(isset($ctx['fontes']['src/app.php']),'IA recebe codigo real');return $this->changes;}]);
    }
    public function approve():void {
        $this->checks=[['name'=>'integration','head_sha'=>$this->head,'status'=>'completed','conclusion'=>'success']];
        $this->reviews=[['state'=>'APPROVED','commit_id'=>$this->head,'user'=>['type'=>'User','login'=>'maintainer'],'author_association'=>'OWNER']];
    }
}
if (defined('CASA_GITHUB_FIXTURE_ONLY')) return;
$f=new GithubFake();$f->advance();check($f->state['etapa']==='publicar','geracao salva antes da escrita');
$f->crashPatch=true;fails(fn()=>$f->advance(),'interrupcao apos mover branch');
check(isset($f->state['commit_sha']),'commit salvo antes de mover branch');
$f->advance();check($f->state['etapa']==='abrir_pr','retoma publicacao sem novo commit');
check(count(array_filter($f->calls,fn($c)=>$c[0]==='POST'&&$c[1]==='/git/commits'))===1,'commit unico');
$f->crashPr=true;fails(fn()=>$f->advance(),'interrupcao apos PR');$f->advance();
check(count(array_filter($f->calls,fn($c)=>$c[0]==='POST'&&$c[1]==='/pulls'))===1,'PR unico');
$f->advance();check($f->state['status']==='aguardando_testes','sem testes nao conclui');
$f->approve();$f->reviews=[];$f->advance();check($f->state['status']==='aguardando_revisao_humana','CI nao substitui humano');
$f->approve();$f->advance();check($f->state['status']==='aguardando_merge_humano','aprovacao nao faz merge');
check($f->pr['draft']===true && !$f->pr['merged'],'draft preservado');
$f->reviews[0]['commit_id']=str_repeat('0',40);$f->advance();check(!$f->state['evidencias']['aprovacao_humana'],'aprovacao antiga rejeitada');
$f->approve();$f->reviews[0]['user']['type']='Bot';$f->advance();check(!$f->state['evidencias']['aprovacao_humana'],'bot rejeitado');
$f->approve();$f->reviews[]=['state'=>'CHANGES_REQUESTED','commit_id'=>$f->head,'user'=>['type'=>'User','login'=>'reviewer2'],'author_association'=>'COLLABORATOR'];
$f->advance();check($f->state['status']==='alteracoes_solicitadas','pedido de alteracao bloqueia');
$f->approve();$f->checks[0]['conclusion']='failure';$f->advance();check(!$f->state['evidencias']['testes_aprovados'],'teste falho bloqueia');
$f->approve();$f->checks[0]['head_sha']=str_repeat('0',40);$f->advance();check(!$f->state['evidencias']['testes_aprovados'],'check antigo rejeitado');
$f->approve();$f->pr['merged']=true;$f->pr['merged_by']=['type'=>'User','login'=>'maintainer'];$f->pr['state']='closed';
$f->advance();check($f->state['etapa']==='concluido','conclui apenas depois de merge humano aprovado');
$f->advance();check($f->state['etapa']==='concluido','retoma entre checkpoint e conclusao SQL');
check($f->generated===1,'IA gerada uma vez');
$g=new GithubFake();$g->advance();$g->head=str_repeat('1',40);fails(fn()=>$g->advance(),'branch concorrente nao sobrescrita');
$g=new GithubFake();$g->lease=false;fails(fn()=>$g->advance(),'lease obrigatorio');check(!$g->calls,'sem rede apos perda de lease');
$g=new GithubFake();$g->changes=[['path'=>'src/app.php','content'=>'<?php function ( {']];fails(fn()=>$g->advance(),'PHP invalido nao publicado');
check(!array_filter($g->calls,fn($c)=>$c[0]!=='GET'),'sem efeitos antes da validacao neste snapshot');
$g=new GithubFake();$g->entries[]=['path'=>'src/other.php','type'=>'blob','mode'=>'100644','sha'=>str_repeat('8',40),'size'=>10];
$g->changes=[['path'=>'src/other.php','content'=>'<?php echo 3;']];fails(fn()=>$g->advance(),'arquivo nao lido nao sobrescrito');
$g=new GithubFake();$g->entries[]=['path'=>'src/link','type'=>'blob','mode'=>'120000','sha'=>str_repeat('8',40),'size'=>10];
$g->changes=[['path'=>'src/link/x.php','content'=>'<?php echo 3;']];fails(fn()=>$g->advance(),'ancestral symlink rejeitado');
foreach ([['PUT','/pulls/9/merge'],['POST','/pulls/9/reviews'],['PATCH','/git/refs/heads/master'],['PATCH','/pulls/9'],['POST','/actions/workflows/1/dispatches']] as [$m,$r])
    check(!casa_gh_rota_fluxo($m,$r),'rota proibida '.$r);
foreach (['src/config.local.php','secrets.json','NUL.php','src/x./a.php'] as $p)
    fails(fn()=>casa_gh_validar_caminho($p),'caminho protegido '.$p);
echo "OK: {$passed} verificacoes do fluxo\n";
