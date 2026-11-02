<?php
// Validacao offline do manifesto de alteracoes. Sem acesso a banco ou rede.
require_once __DIR__.'/projetos_github_arquivos.php';
function casa_gh_validar_alteracoes($changes): array {
    if (!is_array($changes) || !array_is_list($changes) || count($changes)<1 || count($changes)>5)
        throw new InvalidArgumentException('Informe de 1 a 5 alteracoes');
    $seen=[];
    foreach ($changes as $change) {
        if (!is_array($change) || !isset($change['path'],$change['content']) ||
            !is_string($change['path']) || !is_string($change['content']) ||
            strlen($change['content'])>100000)
            throw new InvalidArgumentException('Alteracao invalida');
        $path=casa_gh_validar_caminho($change['path']);
        if (isset($seen[strtolower($path)]))
            throw new InvalidArgumentException('Caminho duplicado');
        if (strtolower(pathinfo($path,PATHINFO_EXTENSION))==='php') {
            if (!str_starts_with(ltrim($change['content']), '<?php'))
                throw new InvalidArgumentException('Arquivo PHP deve iniciar com <?php');
            try {
                token_get_all($change['content'],TOKEN_PARSE);
            } catch (ParseError $e) {
                throw new InvalidArgumentException('Erro de sintaxe PHP em '.$path,0,$e);
            }
        }
        $seen[strtolower($path)]=true;
    }
    return $changes;
}
