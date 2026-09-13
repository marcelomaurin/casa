<?php
  ini_set('display_errors', 'On');
  include "sessao.php";
  include "config.php";
  include "funcs.php";

  $pdo = get_db_pdo();
  // Busca histórico de conversas do PostgreSQL
  $stmt = $pdo->query("SELECT * FROM llm_conversas ORDER BY id DESC LIMIT 15");
  $conversas = array_reverse($stmt->fetchAll());
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Assistente IA Residencial (LLM llama.cpp) - Casa Inteligente</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <style>
    body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .navbar-casa { background: #1a252f; border: none; border-radius: 0; }
    .navbar-casa .navbar-brand, .navbar-casa .navbar-nav>li>a { color: #ecf0f1 !important; font-weight: 600; }
    .navbar-casa .navbar-nav>li>a:hover, .navbar-casa .navbar-nav>.active>a { background: #34495e !important; }
    .card-box { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
    .chat-container { height: 450px; overflow-y: auto; padding: 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 15px; }
    .chat-bubble { max-width: 80%; padding: 12px 16px; border-radius: 12px; margin-bottom: 12px; clear: both; font-size: 14px; line-height: 1.5; }
    .bubble-user { float: right; background: #3498db; color: #fff; border-bottom-right-radius: 2px; }
    .bubble-bot { float: left; background: #ffffff; color: #2c3e50; border: 1px solid #dcdde1; border-bottom-left-radius: 2px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .btn-listen { font-size: 11px; padding: 2px 8px; margin-top: 6px; display: inline-block; color: #2980b9; cursor: pointer; text-decoration: none; }
    .btn-listen:hover { text-decoration: underline; }
    .quick-question { cursor: pointer; margin-right: 5px; margin-bottom: 5px; display: inline-block; }
  </style>
</head>
<body>

<nav class="navbar navbar-inverse navbar-casa">
  <div class="container-fluid">
    <div class="navbar-header">
      <a class="navbar-brand" href="index.php"><i class="fa fa-home"></i> Casa Inteligente</a>
    </div>
    <ul class="nav navbar-nav">
      <li><a href="index.php"><i class="fa fa-dashboard"></i> Painel Principal</a></li>
      <li><a href="sensores.php"><i class="fa fa-tachometer"></i> Sensores</a></li>
      <li><a href="voz.php"><i class="fa fa-microphone"></i> Síntese & Clonagem de Voz</a></li>
      <li class="active"><a href="ia.php"><i class="fa fa-microchip"></i> Assistente IA (LLM)</a></li>
      <li><a href="cameras.php"><i class="fa fa-video-camera"></i> Câmeras</a></li>
    </ul>
    <ul class="nav navbar-nav navbar-right">
      <li><a href="index.php"><i class="fa fa-user"></i> <?php echo isset($_SESSION["pusuario"]) ? htmlspecialchars($_SESSION["pusuario"]) : "admin"; ?></a></li>
    </ul>
  </div>
</nav>

<div class="container">
  <div class="row">
    <div class="col-md-9">
      <div class="card-box">
        <h4><i class="fa fa-comments"></i> Assistente Residencial Inteligente</h4>
        <p class="text-muted">Modelo de Linguagem rodando localmente via <strong>llama.cpp</strong> no Raspberry Pi. Todas as conversas são arquivadas na tabela <code>llm_conversas</code> do PostgreSQL.</p>
        
        <div id="chatBox" class="chat-container">
          <div class="chat-bubble bubble-bot">
            <strong><i class="fa fa-robot"></i> Assistente da Casa:</strong><br>
            Olá! Sou o assistente de inteligência artificial da Casa Inteligente. Como posso te ajudar hoje?
          </div>

          <?php foreach($conversas as $c): ?>
            <div class="chat-bubble bubble-user">
              <strong>Você:</strong><br>
              <?php echo nl2br(htmlspecialchars($c['user_msg'])); ?>
            </div>
            <div class="chat-bubble bubble-bot">
              <strong><i class="fa fa-robot"></i> Assistente:</strong><br>
              <?php echo nl2br(htmlspecialchars($c['bot_msg'])); ?>
              <br>
              <a class="btn-listen" onclick="falarTexto('<?php echo addslashes(str_replace(["\r", "\n"], ' ', $c['bot_msg'])); ?>')">
                <i class="fa fa-volume-up"></i> Ouvir resposta
              </a>
            </div>
          <?php endforeach; ?>
        </div>

        <div>
          <div style="margin-bottom: 10px;">
            <small class="text-muted">Sugestões rápidas:</small><br>
            <span class="label label-info quick-question" onclick="enviarPergunta('Qual o status atual dos controladores da casa?')">Status dos controladores</span>
            <span class="label label-info quick-question" onclick="enviarPergunta('Quais procedimentos de segurança devo seguir?')">Procedimentos de segurança</span>
            <span class="label label-info quick-question" onclick="enviarPergunta('Como funciona o acionamento da iluminação e piscina?')">Automação residencial</span>
          </div>
          
          <div class="input-group">
            <input type="text" id="perguntaInput" class="form-control input-lg" placeholder="Pergunte algo para a inteligência da casa..." onkeypress="if(event.keyCode==13) dispararPergunta()">
            <span class="input-group-btn">
              <button id="btnEnviar" class="btn btn-primary btn-lg" onclick="dispararPergunta()"><i class="fa fa-paper-plane"></i> Enviar</button>
            </span>
          </div>
        </div>

      </div>
    </div>

    <div class="col-md-3">
      <div class="card-box">
        <h5><i class="fa fa-server"></i> Informações do Sistema</h5>
        <ul class="list-unstyled" style="font-size: 13px; line-height: 2;">
          <li><strong>Motor LLM:</strong> llama.cpp</li>
          <li><strong>Dispositivo:</strong> Raspberry Pi 4 (ARM64)</li>
          <li><strong>Banco de Dados:</strong> PostgreSQL 15 (casadb)</li>
          <li><strong>Sintetizador:</strong> Piper Neural TTS</li>
        </ul>
      </div>

      <div class="card-box">
        <h5><i class="fa fa-lightbulb-o"></i> Dica</h5>
        <p style="font-size: 12px;" class="text-muted">Você pode pedir para a IA ler qualquer resposta em voz alta com a voz padrão ou qualquer perfil de voz clonado!</p>
      </div>
    </div>
  </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
<script>
function scrollChat() {
  var d = $('#chatBox');
  d.scrollTop(d.prop("scrollHeight"));
}

function falarTexto(texto) {
  $.ajax({
    url: 'ws/proxy_tts.php?action=falar',
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify({ texto: texto, speaker: 'padrao', reproduzir: true })
  });
}

function enviarPergunta(texto) {
  $('#perguntaInput').val(texto);
  dispararPergunta();
}

function dispararPergunta() {
  var pergunta = $('#perguntaInput').val().trim();
  if (!pergunta) return;

  $('#perguntaInput').val('');
  $('#btnEnviar').prop('disabled', true);

  // Adiciona bolha do usuário
  $('#chatBox').append('<div class="chat-bubble bubble-user"><strong>Você:</strong><br>' + $('<div>').text(pergunta).html() + '</div>');
  
  // Bolha de espera
  var waitingId = 'wait_' + Date.now();
  $('#chatBox').append('<div id="' + waitingId + '" class="chat-bubble bubble-bot"><i class="fa fa-spinner fa-spin"></i> <em>A IA está pensando...</em></div>');
  scrollChat();

  $.ajax({
    url: 'ws/chat_ia.php',
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify({ pergunta: pergunta }),
    success: function(res) {
      $('#' + waitingId).remove();
      $('#btnEnviar').prop('disabled', false);
      if (res.status === 'sucesso') {
        var respHtml = '<div class="chat-bubble bubble-bot"><strong><i class="fa fa-robot"></i> Assistente:</strong><br>' 
          + $('<div>').text(res.resposta).html() 
          + '<br><a class="btn-listen" onclick="falarTexto(' + JSON.stringify(res.resposta) + ')"><i class="fa fa-volume-up"></i> Ouvir resposta</a></div>';
        $('#chatBox').append(respHtml);
      } else {
        $('#chatBox').append('<div class="chat-bubble bubble-bot text-danger"><i class="fa fa-times"></i> Erro: ' + (res.mensagem || 'Falha ao processar') + '</div>');
      }
      scrollChat();
    },
    error: function() {
      $('#' + waitingId).remove();
      $('#btnEnviar').prop('disabled', false);
      $('#chatBox').append('<div class="chat-bubble bubble-bot text-danger"><i class="fa fa-times"></i> Erro de comunicação com a IA.</div>');
      scrollChat();
    }
  });
}

$(document).ready(function() {
  scrollChat();
});
</script>
</body>
</html>
