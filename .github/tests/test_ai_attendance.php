<?php
require_once __DIR__ . '/../../site/var/www/html/api/v1/ai_attendance.php';

function ai_check($value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
function ai_error(callable $operation, int $status): void {
    try { $operation(); } catch (ApiV1AttendanceException $e) {
        ai_check($e->getCode() === $status, 'Codigo inesperado: ' . $e->getCode());
        return;
    }
    throw new RuntimeException('Operacao invalida foi aceita');
}

$payload = api_v1_attendance_payload(json_encode([
    'mensagem'=>'E na cozinha?', 'session_id'=>'sip-chamada-1', 'origem'=>'SIP',
    'historico_mensagens'=>[
        ['role'=>'user','content'=>'Qual a temperatura da sala?'],
        ['role'=>'assistant','content'=>'Vinte graus.'],
        ['role'=>'system','content'=>'nao encaminhar'],
    ],
    'task_context'=>['id_plano'=>12,'id_tarefa_raiz'=>13,'correlation_id'=>'plan-test-1'],
    'skip_planner'=>true, 'idioma'=>'pt-BR',
]), 'sip/atender', 'req-test-1');
ai_check($payload['comando']==='E na cozinha?' && $payload['origem']==='SIP', 'Mensagem nao encaminhada');
ai_check(count($payload['historico_mensagens'])===2, 'Historico foi descartado ou aceitou papel de sistema');
ai_check($payload['skip_planner'] && $payload['task_context']['id_plano']===12, 'Contexto de tarefa foi perdido');

$mock = static function ($url, $token, $sent) use ($payload) {
    ai_check($url==='http://fixture/jarvis' && $token==='fixture-system-token', 'Transporte incorreto');
    ai_check($sent===$payload, 'Estado da chamada alterado antes do modelo');
    return ['http'=>200,'body'=>json_encode([
        'status'=>'sucesso', 'resposta'=>'Na cozinha: **vinte e dois graus**.',
        'provedor'=>'Modelo local de teste', 'modelo_usado'=>['id'=>7,'modelo'=>'fixture','api_key'=>'nao expor'],
        'audio_url'=>'/audio/nao-tocar-na-casa.wav',
        'task_context'=>['id_plano'=>12,'correlation_id'=>'plan-test-1'],
    ])];
};
$result = api_v1_attendance_execute($payload,'http://fixture/jarvis','fixture-system-token',$mock);
ai_check($result['session_id']==='sip-chamada-1', 'Sessao nao retornada');
ai_check($result['resposta']==='Na cozinha: vinte e dois graus.', 'Texto falado contem Markdown');
ai_check($result['resposta_texto']==='Na cozinha: **vinte e dois graus**.', 'Resposta escrita foi perdida');
ai_check($result['audio_url']===null, 'Resposta SIP voltou com audio do servidor');
ai_check(!isset($result['modelo_usado']['api_key']), 'Credencial do modelo exposta');
ai_check($result['correlation_id']==='plan-test-1', 'Rastreabilidade foi perdida');

$other = api_v1_attendance_payload('{"mensagem":"Ola"}','ia/atender','req-test-2');
ai_check($other['historico_mensagens']===[] && $other['session_id']!==$payload['session_id'], 'Historico vazou entre requisicoes');
ai_check($other['origem']==='API_IA', 'Nova API nao identificou o canal');
ai_check(!array_key_exists('ia_mode',$other), 'Requisicao sobrescreveu o roteamento configurado no site');
$forced = api_v1_attendance_payload('{"mensagem":"teste","ia_mode":"local_only"}','ia/atender','req-local-test');
ai_check($forced['ia_mode']==='local_only', 'Override explicito de IA foi descartado');
$legacy = api_v1_attendance_payload('{"comando":"teste","origem":"DAEMON_TAREFAS","skip_planner":true}', 'comando','req-test-3');
ai_check($legacy['origem']==='DAEMON_TAREFAS' && $legacy['skip_planner'], 'Regressao do executor de tarefas');

foreach (['not-json','[]','{}','{"mensagem":[]}','{"mensagem":"oi","session_id":[]}',
    '{"mensagem":"oi","ia_mode":[]}','{"mensagem":"oi","historico_mensagens":"errado"}'] as $raw) {
    ai_error(fn()=>api_v1_attendance_payload($raw,'ia/atender','req-test-4'),400);
}
ai_error(fn()=>api_v1_attendance_payload(json_encode(['mensagem'=>str_repeat('á',4001)]),'ia/atender','req-test-5'),413);
ai_error(fn()=>api_v1_attendance_payload(str_repeat(' ',65537),'ia/atender','req-test-6'),413);
ai_error(fn()=>api_v1_attendance_execute($other,'http://fixture',''),503);
foreach ([
    [200,'not-json',502], [403,'{"mensagem":"negado"}',502],
    [503,'{"status":"erro"}',503], [200,'{"status":"erro","resposta":"falhou"}',502],
    [200,'{"status":"sucesso","resposta":"Sem modelo","provedor":"indisponivel"}',503],
] as [$http,$body,$expected]) {
    ai_error(fn()=>api_v1_attendance_execute($other,'http://fixture','fixture',
        static fn()=>['http'=>$http,'body'=>$body]), $expected);
}
foreach (['SIP','API_IA','GOOGLE_HOME_HARDWARE','google_home'] as $origin) {
    ai_check(casa_audio_no_cliente($origin), 'Origem tocaria audio duplicado: '.$origin);
}
ai_check(!casa_audio_no_cliente('JARVIS'), 'Voz residencial normal foi desabilitada');
echo "PASS: atendimento IA, historico isolado, texto falado, contexto de tarefas, limites e falhas explicitas\n";
