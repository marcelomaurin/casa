<?php
require __DIR__.'/../../site/var/www/html/api/computer_intent.php';
require __DIR__.'/../../site/var/www/html/api/task_engine.php';
$checks=0;
function intent_ok(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
foreach (['computador Bom dia','computador Frontier','Computer, boa noite!','Jarvis, obrigado','Qual a capital da França?',
    'Explique como ligar a luz da sala','Quando devo desligar a bomba?','Amanhã vai chover?',
    'O que acontece se desligar a irrigação?','Qual o status do plano 117?','O que é uma tarefa?',
    'Explique OAuth; depois explique TLS','Não ligue a luz da sala','Me explique o que é um daemon',
    '/cloud Qual o significado de Frontier?','Como criar uma tarefa para amanhã?'] as $text) {
    intent_ok(!computer_pedido_acao($text),'Conversa virou acao: '.$text);
    intent_ok(!computer_deve_planejar($text),'Conversa virou planejamento: '.$text);
}
foreach (['Ligue a luz da sala','Computador, pode ligar a luz da sala?', 'Por favor, desligue a irrigação',
    'Crie uma tarefa de backup','Corrija o daemon do Google Home','Reinicie o serviço','Entre em modo boa noite',
    'Anuncie bom dia no Google Home'] as $text) intent_ok(computer_pedido_acao($text),'Acao nao reconhecida: '.$text);
foreach (['Ligue a luz amanhã','Amanhã ligue a luz da sala','Às 18:30 desligue a bomba',
    'Quando detectar movimento, acenda a luz','Assim que chegar, avise pelo Telegram',
    'Ligue a luz e depois desligue a bomba','Agende um backup todo dia','/planejar Crie um relatório'] as $text)
    intent_ok(computer_deve_planejar($text),'Agendamento perdido: '.$text);
intent_ok(computer_saudacao('computador Bom dia')==='Bom dia! Como posso ajudar?','Saudacao do exemplo');
intent_ok(computer_saudacao('computador Frontier')===null,'Assunto ambiguo nao e saudacao inventada');
intent_ok(!computer_precisa_contexto_casa('computador Bom dia'),'Saudacao sem contexto operacional');
intent_ok(!computer_precisa_contexto_casa('computador Frontier'),'Assunto geral sem contexto operacional');
intent_ok(computer_consulta_tarefas('Quais tarefas estão pendentes?'),'Consulta a fila continua disponivel');

// O caminho conversacional inteiro do motor deve funcionar sem uma consulta SQL.
class IntentNoSqlPDO extends PDO {public function __construct() {} }
$pdo=new IntentNoSqlPDO();$ctx=te_conversation('WEB','computer','conversation-test');
$nested=te_context_from_input(te_public_context($ctx));
$child=te_begin($pdo,'Consultar fontes','AGENTE_INTERNET','web',$nested);
intent_ok(te_is_conversation($child) && $child['correlation_id']==='conversation-test','Subchamada preserva conversa');
intent_ok(te_add_subtask($pdo,$child,'Consultar IA','ia')===0,'Conversa nao insere subtarefa');
te_complete($pdo,0);te_finish($pdo,$child,'Resposta');te_fail_with_plan($pdo,$child,0,'Falha de teste');
intent_ok(te_list_tasks($pdo,$child)===[],'Conversa nao lista fila');
$response=te_attach_context(['resposta'=>'Bom dia','id_plano'=>117,'task_status'=>['stale'=>true]],$child,$pdo);
intent_ok(!isset($response['id_plano'],$response['task_context'],$response['task_status']) && $response['tipo_solicitacao']==='conversa','Resposta nao expõe plano ficticio');
echo "OK: $checks verificacoes de conversa e intencao\n";
