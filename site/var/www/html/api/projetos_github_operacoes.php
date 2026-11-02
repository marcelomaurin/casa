<?php
// Preparacao de requisicoes GitHub; sem efeitos remotos.
require_once __DIR__.'/projetos_github.php';
function casa_github_plano_branch(array $project,array $task,string $base='master'): array {
    if (!in_array($base,['main','master'],true)) throw new InvalidArgumentException('Base invalida');
    $context=casa_github_preparar_tarefa($project,$task);
    return ['method'=>'POST','path'=>'/repos/'.$context['repositorio'].'/git/refs',
        'body'=>['ref'=>'refs/heads/'.$context['branch']],
        'base'=>$base,'requer_sha_base'=>true,'executar_automaticamente'=>false];
}
function casa_github_plano_pr(array $project,array $task,string $base='master'): array {
    if (!in_array($base,['main','master'],true)) throw new InvalidArgumentException('Base invalida');
    $context=casa_github_preparar_tarefa($project,$task);
    return ['method'=>'POST','path'=>'/repos/'.$context['repositorio'].'/pulls',
        'body'=>['title'=>$context['titulo'],'head'=>$context['branch'],'base'=>$base,
            'body'=>$context['descricao'],'draft'=>true],
        'executar_automaticamente'=>false];
}
