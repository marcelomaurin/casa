<?php
  ini_set('display_errors', 'On');
  include "sessao.php";
  include "config.php";
  include "funcs.php";

  $pdo = get_db_pdo();
  // Busca frases pré-cadastradas
  $stmt_frases = $pdo->query("SELECT * FROM frases ORDER BY id ASC");
  $frases = $stmt_frases->fetchAll();

  // Busca histórico recente de falas
  $stmt_historico = $pdo->query("SELECT * FROM falas ORDER BY idfala DESC LIMIT 10");
  $historico = $stmt_historico->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Síntese e Clonagem de Voz - Casa Inteligente</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <style>
    body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .navbar-casa { background: #1a252f; border: none; border-radius: 0; }
    .navbar-casa .navbar-brand, .navbar-casa .navbar-nav>li>a { color: #ecf0f1 !important; font-weight: 600; }
    .navbar-casa .navbar-nav>li>a:hover, .navbar-casa .navbar-nav>.active>a { background: #34495e !important; }
    .card-box { background: #fff; border-radius: 8px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
    .card-header-title { font-size: 19px; font-weight: 700; color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-bottom: 20px; }
    .badge-speaker { background: #3498db; color: #fff; font-size: 12px; padding: 4px 8px; border-radius: 4px; }
    .btn-action { font-weight: bold; border-radius: 4px; }
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
      <li class="active"><a href="voz.php"><i class="fa fa-microphone"></i> Síntese & Clonagem de Voz</a></li>
      <li><a href="ia.php"><i class="fa fa-microchip"></i> Assistente IA (LLM)</a></li>
      <li><a href="cameras.php"><i class="fa fa-video-camera"></i> Câmeras</a></li>
    </ul>
    <ul class="nav navbar-nav navbar-right">
      <li><a href="index.php"><i class="fa fa-user"></i> <?php echo isset($_SESSION["pusuario"]) ? htmlspecialchars($_SESSION["pusuario"]) : "admin"; ?></a></li>
    </ul>
  </div>
</nav>

<div class="container">
  <div class="row">
    
    <!-- Coluna Esquerda: Sintetizar Voz -->
    <div class="col-md-7">
      <div class="card-box">
        <div class="card-header-title"><i class="fa fa-volume-up"></i> Sintetizador de Voz Residencial</div>
        
        <div class="form-group">
          <label>Voz Selecionada (Padrão ou Voz Clonada):</label>
          <select id="selVoz" class="form-control" style="font-size: 15px;">
            <option value="padrao">Voz Neural Padrão (Português BR)</option>
          </select>
        </div>

        <div class="form-group">
          <label>Texto a ser reproduzido:</label>
          <textarea id="txtMensagem" class="form-control" rows="4" placeholder="Digite o que a casa deve falar..."></textarea>
        </div>

        <div class="checkbox">
          <label><input type="checkbox" id="chkReproduzirLocal" checked> Tocar nos alto-falantes da casa (mplayer)</label>
        </div>

        <div style="margin-top: 15px;">
          <button id="btnFalar" class="btn btn-primary btn-action" onclick="executarFala()"><i class="fa fa-bullhorn"></i> Falar Agora</button>
          <span id="falaStatus" style="margin-left: 15px; font-weight: bold;"></span>
        </div>

        <div id="audioPlayerContainer" style="margin-top: 20px; display: none;">
          <label>Áudio Gerado:</label>
          <audio id="audioPlayer" controls style="width: 100%;"></audio>
        </div>

        <hr>
        <h4><i class="fa fa-bookmark"></i> Frases Rápidas Cadastradas</h4>
        <div class="list-group" style="max-height: 200px; overflow-y: auto;">
          <?php foreach($frases as $f): ?>
            <a href="javascript:void(0)" class="list-group-item" onclick="preencherFrase('<?php echo addslashes($f['texto']); ?>')">
              <strong>"<?php echo htmlspecialchars($f['texto']); ?>"</strong> 
              <small class="text-muted">— <?php echo htmlspecialchars($f['autor']); ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Coluna Direita: Clonagem de Voz -->
    <div class="col-md-5">
      <div class="card-box">
        <div class="card-header-title"><i class="fa fa-clone"></i> Clonar Nova Voz</div>
        <p class="text-muted">Envie um áudio de 3 a 15 segundos da pessoa falando (formato .wav ou .mp3). O sistema extrairá os parâmetros acústicos e criará o perfil de clone.</p>
        
        <form id="formClone" enctype="multipart/form-data">
          <div class="form-group">
            <label>Nome da Voz (ex: marcelo, maria, assistente):</label>
            <input type="text" id="cloneNome" class="form-control" placeholder="marcelo" required>
          </div>

          <div class="form-group">
            <label>Amostra de Voz (.wav, .mp3, .ogg):</label>
            <input type="file" id="cloneArquivo" class="form-control" accept="audio/*" required>
          </div>

          <button type="button" class="btn btn-success btn-action" onclick="enviarClonagem()"><i class="fa fa-upload"></i> Processar e Clonar Voz</button>
          <div id="cloneStatus" style="margin-top: 10px; font-weight: bold;"></div>
        </form>
      </div>

      <div class="card-box">
        <div class="card-header-title"><i class="fa fa-history"></i> Histórico Recente (PostgreSQL)</div>
        <ul class="list-group">
          <?php foreach($historico as $h): ?>
            <li class="list-group-item">
              <span class="badge-speaker"><?php echo htmlspecialchars($h['speaker']); ?></span>
              <span style="font-size: 13px;"><?php echo htmlspecialchars($h['mensagem']); ?></span>
              <div class="text-muted" style="font-size: 11px; margin-top: 3px;"><?php echo $h['processado_em']; ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

  </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script>
function carregarVozes() {
  $.getJSON('ws/proxy_tts.php?action=vozes', function(data) {
    if (data.vozes) {
      var select = $('#selVoz');
      select.empty();
      data.vozes.forEach(function(v) {
        var label = (v === 'padrao') ? 'Voz Neural Padrão (pt-BR)' : 'Voz Clonada: ' + v.toUpperCase();
        select.append($('<option>', { value: v, text: label }));
      });
    }
  });
}

function preencherFrase(txt) {
  $('#txtMensagem').val(txt);
}

function executarFala() {
  var texto = $('#txtMensagem').val().trim();
  var speaker = $('#selVoz').val();
  var reprod = $('#chkReproduzirLocal').is(':checked');

  if (!texto) {
    alert('Por favor, digite uma mensagem para falar.');
    return;
  }

  $('#btnFalar').prop('disabled', true);
  $('#falaStatus').html('<i class="fa fa-spinner fa-spin"></i> Sintetizando com ' + speaker + '...');

  $.ajax({
    url: 'ws/proxy_tts.php?action=falar',
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify({ texto: texto, speaker: speaker, reproduzir: reprod }),
    success: function(res) {
      $('#btnFalar').prop('disabled', false);
      if (res.status === 'ok') {
        $('#falaStatus').html('<span class="text-success"><i class="fa fa-check"></i> Fala concluída com sucesso!</span>');
        if (res.audio_url) {
          var audio = $('#audioPlayer')[0];
          var file = res.audio_url.split('/').pop();
          $('#audioPlayer').attr('src', 'ws/proxy_tts.php?action=audio&file=' + file);
          $('#audioPlayerContainer').slideDown();
          audio.play();
        }
      } else {
        $('#falaStatus').html('<span class="text-danger"><i class="fa fa-times"></i> ' + (res.detail || 'Erro na síntese') + '</span>');
      }
    },
    error: function(err) {
      $('#btnFalar').prop('disabled', false);
      $('#falaStatus').html('<span class="text-danger"><i class="fa fa-times"></i> Erro ao conectar no servidor de voz</span>');
    }
  });
}

function enviarClonagem() {
  var nome = $('#cloneNome').val().trim();
  var fileInput = $('#cloneArquivo')[0];

  if (!nome || fileInput.files.length === 0) {
    alert('Informe o nome da voz e selecione um arquivo de áudio.');
    return;
  }

  var formData = new FormData();
  formData.append('nome', nome);
  formData.append('amostra', fileInput.files[0]);

  $('#cloneStatus').html('<i class="fa fa-spinner fa-spin"></i> Processando amostra e gerando perfil acústico...');

  $.ajax({
    url: 'ws/proxy_tts.php?action=clonar',
    type: 'POST',
    data: formData,
    processData: false,
    contentType: false,
    success: function(res) {
      if (res.status === 'sucesso') {
        $('#cloneStatus').html('<span class="text-success"><i class="fa fa-check"></i> ' + res.mensagem + '</span>');
        carregarVozes();
        $('#cloneNome').val('');
        $('#cloneArquivo').val('');
      } else {
        $('#cloneStatus').html('<span class="text-danger">' + (res.detail || 'Erro na clonagem') + '</span>');
      }
    },
    error: function() {
      $('#cloneStatus').html('<span class="text-danger">Erro de comunicação com o serviço de clonagem.</span>');
    }
  });
}

$(document).ready(function() {
  carregarVozes();
});
</script>
</body>
</html>
