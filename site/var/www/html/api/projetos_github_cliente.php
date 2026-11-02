<?php
// Cliente restrito para worker GitHub externo. Nunca acionado automaticamente pelo painel.
require_once __DIR__.'/projetos_github.php';
class CasaGithubNaoEncontrado extends RuntimeException {}
function casa_gh_http(string $method,string $path,?array $payload=null): array {
    if (!in_array($method,['GET','POST'],true) || !preg_match('~^/repos/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/(git/ref/heads/(main|master|casa/projeto-[1-9][0-9]*/tarefa-[1-9][0-9]*)|git/refs|pulls(?:/[1-9][0-9]*)?)$~D',$path,$matches))
        throw new InvalidArgumentException('Operacao GitHub nao permitida');
    casa_github_repo_validar($matches[1],casa_github_permitidos_ambiente());
    $token=(string)getenv('CASA_GITHUB_TOKEN');
    if ($token==='') throw new RuntimeException('CASA_GITHUB_TOKEN nao configurado');
    $ch=curl_init('https://api.github.com'.$path);
    $headers=['Accept: application/vnd.github+json','Authorization: Bearer '.$token,
        'X-GitHub-Api-Version: 2022-11-28','User-Agent: CASA-Projects'];
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0]);
    if ($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_SLASHES));
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $data=is_string($raw)?json_decode($raw,true):null;
    if ($status===404) throw new CasaGithubNaoEncontrado('Recurso GitHub nao encontrado');
    if ($status<200||$status>=300||!is_array($data))
        throw new RuntimeException('Falha na API GitHub (HTTP '.$status.')');
    return $data;
}
function casa_gh_criar_branch(array $project,array $task,string $base='master'): array {
    if (!in_array($base,['main','master'],true)) throw new InvalidArgumentException('Base invalida');
    $ctx=casa_github_preparar_tarefa($project,$task);
    $repo=$ctx['repositorio'];
    // Reexecucao segura: nao criar outra branch nem mover uma existente.
    try {
        $existing=casa_gh_http('GET','/repos/'.$repo.'/git/ref/heads/'.$ctx['branch']);
        if (($existing['ref']??'')==='refs/heads/'.$ctx['branch'])
            return ['ref'=>$existing['ref'],'ja_existia'=>true];
        throw new RuntimeException('Referencia existente inesperada');
    } catch (CasaGithubNaoEncontrado $e) {
        // Branch inexistente; continuar.
    }
    $ref=casa_gh_http('GET','/repos/'.$repo.'/git/ref/heads/'.$base);
    $sha=$ref['object']['sha']??null;
    if (!is_string($sha)||!preg_match('/^[a-f0-9]{40}$/D',$sha))
        throw new RuntimeException('SHA base invalido');
    return casa_gh_http('POST','/repos/'.$repo.'/git/refs',['ref'=>'refs/heads/'.$ctx['branch'],'sha'=>$sha]);
}
function casa_gh_pr_existente(array $project,array $task,string $base='master'): ?array {
    $ctx=casa_github_preparar_tarefa($project,$task);
    $prs=casa_gh_http('GET','/repos/'.$ctx['repositorio'].'/pulls');
    foreach ($prs as $pr) {
        if (($pr['head']['ref']??'')===$ctx['branch'] &&
            ($pr['base']['ref']??'')===$base &&
            ($pr['state']??'')==='open') return $pr;
    }
    return null;
}
function casa_gh_criar_pr_rascunho(array $project,array $task,string $base='master'): array {
    if (!in_array($base,['main','master'],true)) throw new InvalidArgumentException('Base invalida');
    $ctx=casa_github_preparar_tarefa($project,$task);
    // Nao duplicar PRs em repeticoes da tarefa.
    $existing=casa_gh_pr_existente($project,$task,$base);
    if ($existing!==null) return $existing+['ja_existia'=>true];
    return casa_gh_http('POST','/repos/'.$ctx['repositorio'].'/pulls',[
        'title'=>$ctx['titulo'],'head'=>$ctx['branch'],'base'=>$base,
        'body'=>$ctx['descricao']."\n\nCriterio de aceite: ".$ctx['criterio_aceite'],'draft'=>true]);
}
