<?php
// Conversar e consultar informacoes nao equivale a ordenar uma execucao.
function computer_texto_intencao(string $text): string {
    $text=mb_strtolower(trim($text),'UTF-8');
    $text=strtr($text,['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
    $text=preg_replace('/\s+/u',' ',$text);
    $text=preg_replace('~^/(cloud|local|runpod)\b\s*~u','',$text);
    $text=preg_replace('/^(computador|computer|jarvis)\b[\s,:!.]*/u','',$text);
    return trim($text);
}

function computer_pedido_acao(string $text): bool {
    $text=computer_texto_intencao($text);
    if (preg_match('~^/planejar\b~u',$text)) return true;
    $text=preg_replace('/^por favor[,\s]*/u','',$text);
    $text=preg_replace('/^(voce\s+)?(pode|poderia|consegue|conseguiria)\s+(por favor\s+)?/u','',$text);
    $text=preg_replace('/^(quero que|preciso que|gostaria que|peco que|me ajude a)\s+/u','',$text);
    $text=preg_replace('/^(amanha|hoje|depois de amanha)(\s+as?\s+\d{1,2}(?::\d{2}|h)?)?[,\s]+/u','',$text);
    $text=preg_replace('/^(as?\s+\d{1,2}(?::\d{2}|h)?|daqui a \d+ (minutos?|horas?|segundos?))[,\s]+/u','',$text);
    if (preg_match('/^(quando|assim que|caso)\b.+\b(ligue|desligue|acenda|apague|ative|desative|acione|abra|feche|inicie|pare|envie|avise|notifique)\s+.+/u',$text)) return true;
    // Perguntas, exemplos, negacoes e hipoteses ficam na conversa por padrao.
    if (preg_match('/^(nao\b|como\b|quando\b|por que\b|porque\b|qual\b|quais\b|quanto\b|o que\b|sera\b|se\b|explique\b|me explique\b)/u',$text)) return false;
    if (preg_match('/^(ligue|ligar|liga|desligue|desligar|desliga|acenda|acender|apague|apagar|ative|ativar|desative|desativar|acione|acionar|abra|abrir|feche|fechar|inicie|iniciar|pare|parar|reinicie|reiniciar|execute|executar|rode|rodar|instale|instalar|desinstale|atualize|atualizar|sincronize|publique|publicar|corrija|corrigir|conserte|implemente|implementar|desenvolva|desenvolver|entre|entrar)\b/u',$text)) return true;
    if (preg_match('/^(agende|agendar|programe|programar|lembre|lembre-me|avise|avise-me|notifique|envie|enviar|mande)\b/u',$text)) return true;
    if (preg_match('/^(crie|criar|gere|gerar|adicione|adicionar|remova|remover|exclua|excluir|cancele|cancelar|altere|alterar|modifique|modificar)\s+(um[as]?\s+|o[as]?\s+)?(plano|tarefa|agendamento|automacao|regra|cena|projeto|arquivo|script|codigo|programa|repositorio|pull request|pr)\b/u',$text)) return true;
    return (bool)preg_match('/^(diga|fale|anuncie|reproduza)\b.+\b(google home|nest|alto.falante|caixa de som)\b/u',$text);
}

function computer_deve_planejar(string $text): bool {
    $text=computer_texto_intencao($text);
    if (!computer_pedido_acao($text)) return false;
    if (preg_match('~^/planejar\b~u',$text)) return true;
    return (bool)preg_match('/\b(amanha|daqui a|[aà]s \d{1,2}(:\d{2})?|todo dia|toda semana|todo mes|semanalmente|diariamente|e depois|depois disso|em seguida|logo depois|apos isso|quando|assim que|caso aconteca)\b|;/u',$text);
}

function computer_saudacao(string $text): ?string {
    $text=trim(computer_texto_intencao($text)," \t\n\r\0\x0B!.,?");
    if (preg_match('/^(bom dia|boa tarde|boa noite)( pra voce| para voce| tudo bem)?$/u',$text,$m)) return ucfirst($m[1]).'! Como posso ajudar?';
    if (preg_match('/^(oi|ola|oie|e ai|tudo bem)$/u',$text)) return 'Olá! Como posso ajudar?';
    if (preg_match('/^(obrigado|obrigada|muito obrigado|muito obrigada|valeu)$/u',$text)) return 'De nada! Estou à disposição.';
    return null;
}

function computer_consulta_tarefas(string $text): bool {
    return (bool)preg_match('/\b(plano|planos|tarefas?|fila|execucoes|agendamentos?)\b/u',computer_texto_intencao($text));
}

function computer_precisa_contexto_casa(string $text): bool {
    $text=computer_texto_intencao($text);
    return computer_pedido_acao($text) || computer_consulta_tarefas($text)
        || (bool)preg_match('/\b(casa|sala|quarto|cozinha|luzes?|reles?|sensores?|umidade|irrigacao|piscina|alarme|portao|cluster|dispositivos?|mordomo|cameras?)\b|status do sistema/u',$text);
}
