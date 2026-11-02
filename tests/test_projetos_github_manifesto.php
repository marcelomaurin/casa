<?php
// Executar: php tests/test_projetos_github_manifesto.php
require_once __DIR__.'/../site/var/www/html/api/projetos_github_manifesto.php';
$pass=0;
function ok(bool $value,string $name):void {
    global $pass;
    if (!$value) throw new RuntimeException('Falhou: '.$name);
    $pass++;
}
function rejeita($value,string $name):void {
    try { casa_gh_validar_alteracoes($value); }
    catch (InvalidArgumentException $e) { ok(true,$name); return; }
    throw new RuntimeException('Aceitou entrada invalida: '.$name);
}
ok(count(casa_gh_validar_alteracoes([['path'=>'src/app.php','content'=>'<?php echo 1;']]))===1,'arquivo valido');
rejeita([],'lista vazia');
rejeita(array_fill(0,6,['path'=>'src/a.php','content'=>'x']),'mais de cinco');
rejeita([['path'=>'../secret.php','content'=>'x']],'path traversal');
rejeita([['path'=>'.github/workflows/a.yml','content'=>'x']],'arquivo oculto');
rejeita([['path'=>'vendor/lib.php','content'=>'x']],'vendor');
rejeita([['path'=>'src/a.exe','content'=>'x']],'extensao');
rejeita([['path'=>'src/a.php','content'=>'x'],['path'=>'SRC/A.php','content'=>'y']],'duplicado');
rejeita([['path'=>'src/a.php','content'=>str_repeat('x',100001)]],'conteudo grande');
rejeita([['path'=>'src/b.php','content'=>'<?php function x( {']],'sintaxe PHP invalida');
rejeita([['path'=>'src/b.php','content'=>'echo 1;']],'PHP sem abertura');
ok(count(casa_gh_validar_alteracoes([['path'=>'src/b.php','content'=>'<?php function x(): int { return 1; }']]))===1,'PHP valido');
echo "OK: {$pass} verificacoes\n";
