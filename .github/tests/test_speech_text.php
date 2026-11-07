<?php
require_once __DIR__.'/../../site/var/www/html/api/fala_texto.php';
function speech_check($input, $expected) {
    $actual = casa_texto_para_fala($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "Falha no texto falado:\nENTRADA: $input\nESPERADO: $expected\nOBTIDO: $actual\n");
        exit(1);
    }
}
speech_check('A tarefa **Plano #153** não foi rodada.', 'A tarefa Plano número 153 não foi rodada.');
speech_check("## Diagnóstico\n- **Servidor:** offline\n- **Worker:** sem heartbeat", 'Diagnóstico. Servidor: offline. Worker: sem heartbeat.');
speech_check('O programa C# usa `worker.php`.', 'O programa C# usa worker.php.');
speech_check('[Detalhes](https://example.org) do plano #153.', 'Detalhes do plano número 153.');
speech_check("1. Verificar o daemon\n2. Reiniciar serviço", 'Item 1: Verificar o daemon. Item 2: Reiniciar serviço.');
speech_check("```python\nprint('hello')\n```\nTestado", 'Há um trecho de código na resposta escrita. Testado.');
speech_check('', '');
speech_check('Três equipamentos desconectados.', 'Três equipamentos desconectados.');
echo "Texto natural de voz em PHP: OK\n";
