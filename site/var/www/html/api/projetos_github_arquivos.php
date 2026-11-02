<?php
// Escrita de arquivos pequenos em branch de tarefa, chamada explicitamente por worker autorizado.
require_once __DIR__.'/projetos_github_cliente.php';
function casa_gh_validar_caminho(string $path): string {
    if (strlen($path)>240 || !preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D',$path))
        throw new InvalidArgumentException('Caminho de arquivo invalido');
    foreach (explode('/',$path) as $segmento)
        if ($segmento==='.' || $segmento==='..' || str_starts_with($segmento,'.'))
            throw new InvalidArgumentException('Arquivo oculto ou caminho relativo proibido');
    if (preg_match('~(^|/)(vendor|node_modules|secrets|credentials)(/|$)~i',$path))
        throw new InvalidArgumentException('Diretorio protegido');
    if (!preg_match('/\.(php|pas|pp|py|js|ts|css|html|md|json|sql|sh|yml|yaml|txt)$/iD',$path))
        throw new InvalidArgumentException('Extensao nao autorizada');
    return $path;
}
function casa_gh_gravar_arquivo(array $project,array $task,string $path,string $content,string $message): array {
    if (getenv('CASA_GITHUB_WRITE_ENABLED')!=='1')
        throw new RuntimeException('Escrita GitHub desabilitada');
    $ctx=casa_github_preparar_tarefa($project,$task);
    $path=casa_gh_validar_caminho($path);
    if (strlen($content)>100000 || trim($message)==='' || strlen($message)>180)
        throw new InvalidArgumentException('Conteudo ou mensagem invalida');
    $repo=$ctx['repositorio'];$branch=$ctx['branch'];
    $url='https://api.github.com/repos/'.$repo.'/contents/'.implode('/',array_map('rawurlencode',explode('/',$path)));
    $token=(string)getenv('CASA_GITHUB_TOKEN');
    if ($token==='') throw new RuntimeException('Token nao configurado');
    $headers=['Accept: application/vnd.github+json','Authorization: Bearer '.$token,
        'X-GitHub-Api-Version: 2022-11-28','User-Agent: CASA-Projects'];
    $request=static function(string $method,string $url,?array $payload)use($headers):array {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,
            CURLOPT_FOLLOWLOCATION=>false]);
        if ($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_SLASHES));
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        $json=is_string($raw)?json_decode($raw,true):null;
        if ($status!==404 && ($status<200 || $status>=300 || !is_array($json)))
            throw new RuntimeException('GitHub HTTP '.$status);
        return ['status'=>$status,'data'=>$json];
    };
    // Leitura na branch da tarefa; jamais editar main/master.
    $current=$request('GET',$url.'?ref='.rawurlencode($branch),null);
    $payload=['message'=>$message,'content'=>base64_encode($content),'branch'=>$branch];
    if ($current['status']===200) {
        $sha=$current['data']['sha']??'';
        if (!is_string($sha)||!preg_match('/^[a-f0-9]{40}$/D',$sha))
            throw new RuntimeException('SHA do arquivo invalido');
        $payload['sha']=$sha;
    }
    $result=$request('PUT',$url,$payload);
    return ['path'=>$path,'branch'=>$branch,'commit_sha'=>$result['data']['commit']['sha']??null];
}
