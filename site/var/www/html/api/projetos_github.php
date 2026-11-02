<?php
/**
 * CASA: utilitarios seguros para futura integracao de agentes ao GitHub.
 * Nao executa comandos, nao grava arquivos e nao abre PR automaticamente.
 */
function casa_github_repo_validar(string $repo, array $permitidos): string {
    $repo=trim($repo);
    if (!preg_match('/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/D',$repo) ||
        str_contains($repo,'..') || str_starts_with($repo,'.') || str_contains($repo,'/.'))
        throw new InvalidArgumentException('Repositorio GitHub invalido.');
    if (!in_array(strtolower($repo),array_map('strtolower',$permitidos),true))
        throw new DomainException('Repositorio nao autorizado para agentes.');
    return $repo;
}
function casa_github_branch_tarefa(int $projetoId, int $tarefaId): string {
    if ($projetoId<1 || $tarefaId<1) throw new InvalidArgumentException('IDs invalidos.');
    return 'casa/projeto-'.$projetoId.'/tarefa-'.$tarefaId;
}
function casa_github_permitidos_ambiente(): array {
    $raw=trim((string)getenv('CASA_GITHUB_REPOS_PERMITIDOS'));
    if ($raw==='') return []; // bloqueio seguro por padrao
    return array_values(array_filter(array_map('trim',explode(',',$raw)),static fn($x)=>$x!==''));
}
function casa_github_preparar_tarefa(array $project,array $task): array {
    $repo=casa_github_repo_validar((string)($project['repositorio']??''),casa_github_permitidos_ambiente());
    $projetoId=(int)($project['id']??0);$tarefaId=(int)($task['id']??0);
    if ($projetoId<1 || $tarefaId<1 || (int)($task['projeto_id']??0)!==$projetoId)
        throw new InvalidArgumentException('Tarefa nao pertence ao projeto.');
    return [
        'repositorio'=>$repo,
        'branch'=>casa_github_branch_tarefa($projetoId,$tarefaId),
        'titulo'=>mb_substr((string)($task['titulo']??''),0,200),
        'descricao'=>mb_substr((string)($task['descricao']??''),0,10000),
        'criterio_aceite'=>mb_substr((string)($task['criterio_aceite']??''),0,10000),
        'aprovacao_humana_obrigatoria'=>true,
        'publicacao_automatica'=>false
    ];
}
