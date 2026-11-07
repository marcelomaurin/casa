<?php
/**
 * Converte texto formatado de IA em linguagem adequada para sintese de voz.
 * Preserva a resposta escrita, numeros e nomes; so remove marcadores de exibicao.
 */
function casa_texto_para_fala(string $texto): string {
    $texto = str_replace(["\r\n", "\r"], "\n", trim($texto));
    if ($texto === '') return '';

    // Codigos longos nao devem ser ditados como pontuacao e comandos de maquina.
    $texto = preg_replace('/```[^\n]*\n.*?```/us', "\nHá um trecho de código na resposta escrita.\n", $texto);
    $texto = preg_replace('/^\h*[-*_]{3,}\h*$/mu', '', $texto);

    // Links e imagens Markdown: falar a descricao, nao a URL ou sua sintaxe.
    $texto = preg_replace('/!\[([^\]]*)\]\([^)]+\)/u', '$1', $texto);
    $texto = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $texto);

    // Cabecalhos, citacoes e marcadores de listas.
    $texto = preg_replace('/^\h{0,3}#{1,6}\h+/mu', '', $texto);
    $texto = preg_replace('/^\h{0,3}>\h?/mu', '', $texto);
    $texto = preg_replace('/^\h*[-*+]\h+/mu', '', $texto);
    $texto = preg_replace('/^\h*(\d{1,3})[.)]\h+/mu', 'Item $1: ', $texto);

    // '#153' deve soar como 'numero 153', sem alterar nomes como C#.
    $texto = preg_replace('/(?<![\p{L}\p{N}])#\h*(\d+)\b/u', 'número $1', $texto);

    // Negrito, italico, destaque, codigo inline e tachado.
    $texto = preg_replace('/\*\*([^\n]*?)\*\*/u', '$1', $texto);
    $texto = preg_replace('/__([^\n]*?)__/u', '$1', $texto);
    $texto = preg_replace('/~~([^\n]*?)~~/u', '$1', $texto);
    $texto = preg_replace('/(?<!\w)\*([^*\n]+)\*(?!\w)/u', '$1', $texto);
    $texto = preg_replace('/(?<!\w)_([^_\n]+)_(?!\w)/u', '$1', $texto);
    $texto = preg_replace('/`([^`\n]+)`/u', '$1', $texto);

    // Separadores de coluna de tabelas simples; sem falar "barra vertical".
    $texto = preg_replace('/\h*\|\h*/u', ', ', $texto);
    $texto = preg_replace('/\h+/u', ' ', $texto);

    $linhas = preg_split('/\n+/u', $texto);
    $fala = [];
    foreach ($linhas as $linha) {
        $linha = trim((string)$linha, " \t,");
        if ($linha === '') continue;
        if (!preg_match('/[.!?;:]$/u', $linha)) $linha .= '.';
        $fala[] = $linha;
    }
    return trim(implode(' ', $fala));
}
