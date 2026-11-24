<?php
require_once __DIR__ . '/../../site/var/www/html/api/v1/command_conversation.php';
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$first = api_v1_command_payload(['origem'=>'SIP', 'session_id'=>'sip-1', 'historico_mensagens'=>[
    ['role'=>'user','content'=>'Qual a temperatura?'],
    ['role'=>'assistant','content'=>'Vinte graus.'],
    ['role'=>'system','content'=>'nao encaminhar'],
]], 'E na sala?', 'auto', 'turn-2');
check(count($first['historico_mensagens']) === 2, 'Historico valido nao foi preservado');
check($first['historico_mensagens'][0]['role'] === 'user', 'Ordem das mensagens mudou');
check($first['origem'] === 'SIP' && $first['session_id'] === 'sip-1', 'Identidade SIP perdida');
$second = api_v1_command_payload(['session_id'=>'sip-2'], 'Ola', 'auto', 'turn-3');
check($second['historico_mensagens'] === [], 'Historico vazou entre chamadas');
$many = array_fill(0, 100, ['role'=>'user','content'=>str_repeat('a', 4000)]);
$limited = api_v1_command_payload(['historico_mensagens'=>$many], 'Ola', 'auto', 'turn-4');
check(count($limited['historico_mensagens']) === 8, 'Limite total do historico nao aplicado');
$malformed = api_v1_command_payload(['session_id'=>[], 'historico_mensagens'=>[
    ['role'=>'user','content'=>[]], ['role'=>'assistant','content'=>str_repeat('a',16001)],
]], 'Ola', 'auto', 'turn-5');
check(!isset($malformed['session_id']) && $malformed['historico_mensagens'] === [], 'Payload invalido aceito');
foreach ([[false,200], ['not-json',200], ['{"status":"erro","resposta":"falhou"}',200],
    ['{"resposta":""}',200], ['{"resposta":"negado"}',403], ['{"resposta":{}}',200]] as $case) {
    $failed = false;
    try { api_v1_command_response($case[0], $case[1]); }
    catch (RuntimeException $e) { $failed = true; }
    check($failed, 'Falha do JARVIS foi tratada como resposta');
}
check(api_v1_command_response('{"resposta":"Tudo bem."}',200)['resposta'] === 'Tudo bem.', 'Resposta valida recusada');
echo "PASS: historico SIP isolado, papeis, limites e falhas explicitas do JARVIS\n";
