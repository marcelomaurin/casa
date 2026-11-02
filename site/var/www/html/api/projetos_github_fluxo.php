<?php
// Maquina de estados retomavel. Nunca aprova, promove ou mescla um PR.
require_once __DIR__.'/projetos_github_ia.php';

function casa_gh_rota_fluxo(string $method,string $route): bool {
    $sha='[a-f0-9]{40}';
    $branch='casa/projeto-[1-9][0-9]*/tarefa-[1-9][0-9]*';
    $page='per_page=100&page=(?:[1-9]|10)';
    $routes=[
        'GET'=>'~^(?:|/git/ref/heads/(?:main|master|'.$branch.')|/git/commits/'.$sha.'|/git/trees/'.$sha.'\?recursive=1|/git/blobs/'.$sha.'|/pulls\?state=all&'.$page.'|/pulls/[1-9][0-9]*(?:/reviews\?'.$page.')?|/commits/'.$sha.'/(?:check-runs\?filter=latest&'.$page.'|status\?'.$page.'))$~D',
        'POST'=>'~^/git/(?:refs|blobs|trees|commits)$|^/pulls$~D',
        'PATCH'=>'~^/git/refs/heads/'.$branch.'$~D',
    ];
    return isset($routes[$method]) && (bool)preg_match($routes[$method],$route);
}

function casa_gh_fluxo_http(string $repo,string $method,string $route,?array $payload=null): array {
    casa_github_repo_validar($repo,casa_github_permitidos_ambiente());
    if (!casa_gh_rota_fluxo($method,$route)) throw new DomainException('Rota GitHub proibida');
    if ($method!=='GET') {
        if (getenv('CASA_GITHUB_WRITE_ENABLED')!=='1') throw new RuntimeException('Escrita GitHub desabilitada');
        if ($route==='/git/refs' && !preg_match('~^refs/heads/casa/projeto-[1-9][0-9]*/tarefa-[1-9][0-9]*$~D',$payload['ref']??''))
            throw new DomainException('Branch protegida');
        if ($method==='PATCH' && ($payload['force']??null)!==false) throw new DomainException('Force push proibido');
        if ($route==='/pulls' && (($payload['draft']??null)!==true || !preg_match('~^casa/projeto-[1-9][0-9]*/tarefa-[1-9][0-9]*$~D',$payload['head']??'')))
            throw new DomainException('Somente PR de tarefa em rascunho');
    }
    $token=(string)getenv('CASA_GITHUB_TOKEN');
    if ($token==='') throw new RuntimeException('CASA_GITHUB_TOKEN nao configurado');
    $ch=curl_init('https://api.github.com/repos/'.$repo.$route);
    $raw='';
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','Content-Type: application/json',
            'Authorization: Bearer '.$token,'X-GitHub-Api-Version: 2022-11-28','User-Agent: CASA-Projects'],
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw):int {
            if (strlen($raw)+strlen($chunk)>4000000) return 0;
            $raw.=$chunk;return strlen($chunk);
        }]);
    if ($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if ($status===404) throw new CasaGithubNaoEncontrado('Recurso GitHub nao encontrado');
    $data=json_decode($raw,true);
    if ($ok===false || $status<200 || $status>=300 || !is_array($data))
        throw new RuntimeException('Falha GitHub HTTP '.$status);
    return $data;
}

function casa_gh_sha($sha): string {
    if (!is_string($sha) || !preg_match('/^[a-f0-9]{40}$/D',$sha)) throw new RuntimeException('SHA GitHub invalido');
    return $sha;
}

function casa_gh_paginar(callable $api,string $route,string $key=''): array {
    $all=[];
    for ($page=1;$page<=10;$page++) {
        $r=$api('GET',$route.'per_page=100&page='.$page);
        $items=$key===''?$r:($r[$key]??null);
        if (!is_array($items) || !array_is_list($items)) throw new RuntimeException('Pagina GitHub invalida');
        $all=array_merge($all,$items);
        if (count($items)<100) return $all;
    }
    throw new RuntimeException('Limite de paginacao GitHub atingido');
}

function casa_gh_buscar_pr(callable $api,array $ctx,string $base): ?array {
    foreach (casa_gh_paginar($api,'/pulls?state=all&') as $pr) {
        if (($pr['head']['ref']??'')===$ctx['branch'] &&
            strcasecmp($pr['head']['repo']['full_name']??'',$ctx['repositorio'])===0 &&
            ($pr['base']['ref']??'')===$base) return $pr;
    }
    return null;
}

// Snapshot imutavel, limitado, sem symlinks, submodulos ou arquivos privados.
function casa_gh_contexto_fonte(callable $api,string $head,array $task): array {
    $commit=$api('GET','/git/commits/'.casa_gh_sha($head));
    $treeSha=casa_gh_sha($commit['tree']['sha']??null);
    $tree=$api('GET','/git/trees/'.$treeSha.'?recursive=1');
    if (!empty($tree['truncated']) || count($tree['tree']??[])>12000) throw new RuntimeException('Arvore excede limite de contexto');
    $files=[];$candidates=[];$all=[];
    $words=preg_split('/[^a-z0-9_]+/i',strtolower(implode(' ',[$task['titulo'],$task['descricao'],$task['criterio_aceite']??''])));
    foreach ($tree['tree']??[] as $entry) {
        $all[$entry['path']]=$entry;
        if (($entry['type']??'')!=='blob' || ($entry['mode']??'')!=='100644' || ($entry['size']??100001)>100000) continue;
        try { $path=casa_gh_validar_caminho($entry['path']); } catch (InvalidArgumentException $e) { continue; }
        $files[$path]=$entry;
        $score=str_contains(strtolower(implode(' ',[$task['titulo'],$task['descricao']])),strtolower($path))?100:0;
        foreach (array_unique($words) as $word) if (strlen($word)>3 && str_contains(strtolower($path),$word)) $score++;
        if (strtolower($path)==='readme.md') $score++;
        if ($score>0) $candidates[$path]=$score;
    }
    arsort($candidates);$sources=[];$bytes=0;
    foreach (array_keys($candidates) as $path) {
        if (count($sources)>=8) break;
        if ($bytes+($files[$path]['size']??100001)>180000) continue;
        $blob=$api('GET','/git/blobs/'.casa_gh_sha($files[$path]['sha']));
        $content=base64_decode(str_replace(["\n","\r"],'',$blob['content']??''),true);
        if (($blob['encoding']??'')!=='base64' || !is_string($content) || strlen($content)>100000 || str_contains($content,"\0"))
            throw new RuntimeException('Arquivo fonte invalido');
        $bytes+=strlen($content);$sources[$path]=$content;
    }
    if (!$sources) throw new RuntimeException('Sem arquivos relevantes; especifique caminhos na descricao da tarefa');
    return ['head_sha'=>$head,'tree_sha'=>$treeSha,'arquivos'=>$sources,'caminhos'=>array_slice(array_keys($files),0,2000),'entradas'=>$all];
}

function casa_gh_evidencias(array $pr,array $checks,array $statuses,array $reviews): array {
    $head=casa_gh_sha($pr['head']['sha']??null);
    $tests=count($checks)+count($statuses)>0;
    $names=[];
    foreach ($checks as $check) {
        $names[]=$check['name']??'';
        if (($check['head_sha']??'')!==$head || ($check['status']??'')!=='completed' || ($check['conclusion']??'')!=='success') $tests=false;
    }
    foreach ($statuses as $status) {
        $names[]=$status['context']??'';
        if (($status['state']??'')!=='success') $tests=false;
    }
    // Exigir checks nomeados pelo operador evita sucesso com suite ausente ou apenas lint.
    $required=array_filter(array_map('trim',explode(',',(string)getenv('CASA_GITHUB_REQUIRED_CHECKS'))));
    if (!$required || array_diff($required,$names)) $tests=false;
    $latest=[];
    foreach ($reviews as $review) {
        if (!in_array($review['state']??'',['APPROVED','CHANGES_REQUESTED','DISMISSED'],true)) continue;
        $login=strtolower($review['user']['login']??'');
        if ($login!=='' && ($review['user']['type']??'')==='User' &&
            !str_ends_with($login,'[bot]') && $login!==strtolower($pr['user']['login']??'') &&
            in_array($review['author_association']??'',['OWNER','MEMBER','COLLABORATOR'],true)) $latest[$login]=$review;
    }
    $approved=false;$changes=false;
    foreach ($latest as $review) {
        if (($review['state']??'')==='CHANGES_REQUESTED') $changes=true;
        if (($review['state']??'')==='APPROVED' && ($review['commit_id']??'')===$head) $approved=true;
    }
    return ['head_sha'=>$head,'testes_aprovados'=>$tests,'checks'=>$names,
        'aprovacao_humana'=>$approved&&!$changes,'alteracoes_solicitadas'=>$changes];
}

// Os adaptadores permitem testar efeitos, falhas e retomadas sem credenciais reais.
function casa_gh_fluxo_avancar(array $project,array $task,array $state,array $services): array {
    $ctx=casa_github_preparar_tarefa($project,$task);
    $api=$services['api'];$save=$services['salvar'];$lease=$services['reserva'];
    $lease();
    if (!$state) {
        $repo=$api('GET','');$base=$repo['default_branch']??'';
        if (!in_array($base,['main','master'],true)) throw new RuntimeException('Base deve ser main ou master');
        $state=['versao'=>1,'etapa'=>'gerar','base'=>$base,'branch'=>$ctx['branch'],'repositorio'=>$ctx['repositorio'],
            'projeto_tarefa_id'=>$task['id'],'aprovacao_humana_obrigatoria'=>true,'merge_automatico'=>false];
        $save($state);
    }
    if (($state['branch']??'')!==$ctx['branch'] || ($state['repositorio']??'')!==$ctx['repositorio'] ||
        !in_array($state['base']??'',['main','master'],true)) throw new RuntimeException('Checkpoint nao corresponde a tarefa');
    $base=$state['base'];$stage=$state['etapa'];
    if ($stage==='concluido') $stage='acompanhar'; // Retomar entre checkpoint e conclusao SQL.
    if ($stage==='erro') return $state;
    if ($stage==='gerar') {
        // Recuperar PR criado antes de uma interrupcao sem gerar/reescrever codigo.
        $existing=casa_gh_buscar_pr($api,$ctx,$base);
        if ($existing!==null) {
            $state['pr_numero']=(int)$existing['number'];$state['pr_url']=$existing['html_url'];$state['etapa']='acompanhar';
        } else {
            try { $ref=$api('GET','/git/ref/heads/'.$ctx['branch']); }
            catch (CasaGithubNaoEncontrado $e) {
                $ref=$api('GET','/git/ref/heads/'.$base);$lease();
                $ref=$api('POST','/git/refs',['ref'=>'refs/heads/'.$ctx['branch'],'sha'=>casa_gh_sha($ref['object']['sha']??null)]);
            }
            $head=casa_gh_sha($ref['object']['sha']??null);
            $source=casa_gh_contexto_fonte($api,$head,$task);
            $changes=casa_gh_validar_alteracoes(($services['gerar'])($ctx+['fontes'=>$source['arquivos'],'caminhos'=>$source['caminhos'],'head_sha'=>$head]));
            foreach ($changes as $change) {
                $entry=$source['entradas'][$change['path']]??null;
                // Nao substituir arquivos existentes que nao foram lidos pela IA.
                if ($entry!==null && !array_key_exists($change['path'],$source['arquivos'])) throw new RuntimeException('IA alterou arquivo fora do contexto: '.$change['path']);
                foreach ($source['entradas'] as $existingPath=>$unused) {
                    if (strcasecmp($existingPath,$change['path'])===0 && $existingPath!==$change['path'])
                        throw new RuntimeException('Colisao de caminho entre sistemas operacionais');
                }
                $segments=explode('/',$change['path']);array_pop($segments);$parent='';
                foreach ($segments as $segment) {
                    $parent=($parent===''?'':$parent.'/').$segment;
                    if (isset($source['entradas'][$parent]) && ($source['entradas'][$parent]['type']??'')!=='tree')
                        throw new RuntimeException('Diretorio de destino nao e uma arvore regular');
                }
            }
            $state['manifesto']=$changes;$state['parent_sha']=$head;$state['tree_sha']=$source['tree_sha'];
            $state['validacao']=['manifesto'=>'aprovado','sintaxe_php'=>'aprovada','testes_integracao'=>'pendentes_no_CI'];
            $state['etapa']='publicar';
        }
    } elseif ($stage==='publicar') {
        $lease();
        $current=$api('GET','/git/ref/heads/'.$ctx['branch']);$head=casa_gh_sha($current['object']['sha']??null);
        $changes=casa_gh_validar_alteracoes($state['manifesto']??null);
        if (!isset($state['commit_sha'])) {
            if ($head!==$state['parent_sha']) throw new RuntimeException('Branch mudou; revisao humana necessaria');
            $tree=[];
            foreach ($changes as $change) {
                $lease();$blob=$api('POST','/git/blobs',['encoding'=>'base64','content'=>base64_encode($change['content'])]);
                $tree[]=['path'=>$change['path'],'mode'=>'100644','type'=>'blob','sha'=>casa_gh_sha($blob['sha']??null)];
            }
            $lease();$newTree=$api('POST','/git/trees',['base_tree'=>$state['tree_sha'],'tree'=>$tree]);
            if (($newTree['sha']??'')===$state['tree_sha']) throw new RuntimeException('IA nao produziu alteracoes');
            $lease();$commit=$api('POST','/git/commits',['message'=>'CASA tarefa '.$task['id'].': '.$ctx['titulo'],
                'tree'=>casa_gh_sha($newTree['sha']??null),'parents'=>[$head]]);
            $state['commit_sha']=casa_gh_sha($commit['sha']??null);$save($state);
        }
        // Checkpoint anterior ao PATCH permite retomar depois do efeito remoto.
        if ($head!==$state['commit_sha']) {
            if ($head!==$state['parent_sha']) throw new RuntimeException('Branch mudou; publicacao cancelada');
            $lease();$api('PATCH','/git/refs/heads/'.$ctx['branch'],['sha'=>$state['commit_sha'],'force'=>false]);
        }
        unset($state['manifesto']);$state['etapa']='abrir_pr';
    } elseif ($stage==='abrir_pr') {
        $pr=casa_gh_buscar_pr($api,$ctx,$base);
        if ($pr===null) {
            $lease();$pr=$api('POST','/pulls',['title'=>$ctx['titulo'],'head'=>$ctx['branch'],'base'=>$base,'draft'=>true,
                'body'=>$ctx['descricao']."\n\nCriterio de aceite: ".$ctx['criterio_aceite'].
                "\n\nValidacao local: manifesto e sintaxe PHP. Testes de integracao aguardam CI.\nRevisao humana obrigatoria; este agente nunca aprova ou faz merge."]);
        }
        $state['pr_numero']=(int)$pr['number'];$state['pr_url']=$pr['html_url'];$state['etapa']='acompanhar';
    } elseif ($stage==='acompanhar') {
        $number=(int)($state['pr_numero']??0);
        if ($number<1) throw new RuntimeException('PR invalido');
        $pr=$api('GET','/pulls/'.$number);
        if (($pr['head']['ref']??'')!==$ctx['branch'] || strcasecmp($pr['head']['repo']['full_name']??'',$ctx['repositorio'])!==0 ||
            ($pr['base']['ref']??'')!==$base) throw new RuntimeException('PR fora do escopo');
        $head=casa_gh_sha($pr['head']['sha']??null);
        $checks=casa_gh_paginar($api,'/commits/'.$head.'/check-runs?filter=latest&','check_runs');
        $statuses=casa_gh_paginar($api,'/commits/'.$head.'/status?','statuses');
        $reviews=casa_gh_paginar($api,'/pulls/'.$number.'/reviews?');
        $evidence=casa_gh_evidencias($pr,$checks,$statuses,$reviews);
        // Revalidar HEAD apos as consultas para nao aceitar evidencias de uma versao antiga.
        $fresh=$api('GET','/pulls/'.$number);
        if (($fresh['head']['sha']??'')!==$head) throw new RuntimeException('HEAD mudou durante acompanhamento');
        $state['evidencias']=$evidence;
        if (!empty($fresh['merged'])) {
            $human=($fresh['merged_by']['type']??'')==='User' && !str_ends_with(strtolower($fresh['merged_by']['login']??''),'[bot]');
            if (!$human || !$evidence['aprovacao_humana'] || !$evidence['testes_aprovados']) {
                $state['etapa']='erro';$state['status']='merge_sem_evidencias_obrigatorias';
            } else { $state['etapa']='concluido';$state['status']='integrado_por_humano'; }
        } elseif (($fresh['state']??'')==='closed') {
            $state['etapa']='erro';$state['status']='pr_fechado_sem_merge';
        } else {
            $state['status']=$evidence['alteracoes_solicitadas']?'alteracoes_solicitadas':
                (!$evidence['testes_aprovados']?'aguardando_testes':(!$evidence['aprovacao_humana']?'aguardando_revisao_humana':'aguardando_merge_humano'));
        }
    } else throw new RuntimeException('Etapa desconhecida');
    $lease();$save($state);return $state;
}
