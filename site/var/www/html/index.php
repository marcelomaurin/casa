<?php
  // Interface Central JARVIS & Gestao Residencial
  ini_set('display_errors', 'Off');
  include_once(__DIR__ . '/casa/config.php');
  include_once(__DIR__ . '/casa/funcs.php');
  $pdo = get_db_pdo();

  // Contadores para o Dashboard
  $total_devices = $pdo->query("SELECT count(*) FROM devices")->fetchColumn();
  $total_sensores = $pdo->query("SELECT count(*) FROM sensores_telemetria")->fetchColumn();
  $total_nodes = $pdo->query("SELECT count(*) FROM arm_nodes")->fetchColumn();
  $total_conversas = $pdo->query("SELECT count(*) FROM llm_conversas")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>JARVIS — Sistema de Automação & IA Residencial</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  
  <!-- Fontes Google Modernas -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  
  <!-- Bootstrap & FontAwesome -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  
  <style>
    :root {
      --bg-main: #0a0e17;
      --bg-panel: rgba(16, 24, 40, 0.75);
      --bg-panel-hover: rgba(23, 34, 56, 0.85);
      --cyan: #00f2fe;
      --blue: #4facfe;
      --accent: #38bdf8;
      --text-main: #f1f5f9;
      --text-muted: #94a3b8;
      --border-color: rgba(56, 189, 248, 0.2);
      --border-glow: rgba(0, 242, 254, 0.4);
    }

    * { box-sizing: border-box; }
    body {
      background: radial-gradient(circle at 50% 10%, #111e38 0%, #070a12 100%);
      background-attachment: fixed;
      color: var(--text-main);
      font-family: 'Outfit', sans-serif;
      min-height: 100vh;
      margin: 0;
      padding-bottom: 50px;
    }

    /* Top Navigation HUD */
    .hud-navbar {
      background: rgba(10, 14, 23, 0.85);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border-color);
      padding: 12px 20px;
      position: sticky;
      top: 0;
      z-index: 1000;
    }
    .hud-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 22px;
      font-weight: 800;
      letter-spacing: 2px;
      background: linear-gradient(135deg, var(--cyan), var(--blue));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .hud-brand i { -webkit-text-fill-color: var(--cyan); }
    .hud-status-badge {
      font-size: 11px;
      font-family: 'JetBrains Mono', monospace;
      padding: 4px 10px;
      border-radius: 20px;
      background: rgba(16, 185, 129, 0.15);
      color: #34d399;
      border: 1px solid rgba(16, 185, 129, 0.3);
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .pulse-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 8px #10b981;
      animation: pulse 1.5s infinite;
    }
    @keyframes pulse {
      0% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(1.2); }
      100% { opacity: 1; transform: scale(1); }
    }

    /* Tabs HUD */
    .nav-tabs-hud {
      display: flex;
      gap: 8px;
      border-bottom: 1px solid var(--border-color);
      margin: 20px 0 25px 0;
      flex-wrap: wrap;
    }
    .nav-tabs-hud button {
      background: transparent;
      border: none;
      color: var(--text-muted);
      font-size: 14px;
      font-weight: 600;
      padding: 10px 18px;
      border-radius: 8px 8px 0 0;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: all 0.25s ease;
      border-bottom: 2px solid transparent;
    }
    .nav-tabs-hud button:hover {
      color: var(--cyan);
      background: rgba(56, 189, 248, 0.08);
    }
    .nav-tabs-hud button.active {
      color: var(--cyan);
      background: rgba(56, 189, 248, 0.12);
      border-bottom: 2px solid var(--cyan);
      text-shadow: 0 0 12px rgba(0, 242, 254, 0.5);
    }

    /* Cards Glassmorphism */
    .glass-card {
      background: var(--bg-panel);
      backdrop-filter: blur(14px);
      border: 1px solid var(--border-color);
      border-radius: 14px;
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
      transition: transform 0.2s ease, border-color 0.2s ease;
    }
    .glass-card:hover {
      border-color: var(--border-glow);
    }
    .glass-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      padding-bottom: 12px;
    }
    .glass-title {
      font-size: 18px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--cyan);
      letter-spacing: 0.5px;
    }

    /* JARVIS Center Arc Reactor */
    .jarvis-reactor {
      width: 140px;
      height: 140px;
      margin: 15px auto;
      border-radius: 50%;
      border: 2px dashed rgba(0, 242, 254, 0.5);
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 0 35px rgba(0, 242, 254, 0.25), inset 0 0 25px rgba(0, 242, 254, 0.25);
      animation: rotate-reactor 20s linear infinite;
      transition: all 0.3s ease;
    }
    .jarvis-reactor:hover, .jarvis-reactor.listening {
      box-shadow: 0 0 50px rgba(0, 242, 254, 0.6), inset 0 0 35px rgba(0, 242, 254, 0.6);
      border-color: #fff;
    }
    .jarvis-core {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: radial-gradient(circle, #fff 0%, var(--cyan) 60%, #0369a1 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #032030;
      font-size: 28px;
      box-shadow: 0 0 25px var(--cyan);
    }
    @keyframes rotate-reactor {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    /* Chat Area */
    .chat-window {
      height: 380px;
      overflow-y: auto;
      background: rgba(5, 10, 20, 0.65);
      border-radius: 10px;
      padding: 16px;
      border: 1px solid rgba(255, 255, 255, 0.05);
      margin-bottom: 15px;
    }
    .msg-bubble {
      margin-bottom: 14px;
      max-width: 85%;
      padding: 12px 18px;
      border-radius: 12px;
      font-size: 14px;
      line-height: 1.5;
    }
    .msg-user {
      float: right;
      background: linear-gradient(135deg, #0284c7, #0369a1);
      color: #fff;
      border-bottom-right-radius: 2px;
      clear: both;
    }
    .msg-jarvis {
      float: left;
      background: rgba(30, 41, 59, 0.85);
      border: 1px solid var(--border-color);
      color: #e2e8f0;
      border-bottom-left-radius: 2px;
      clear: both;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    /* Stat Badges */
    .stat-box {
      background: rgba(15, 23, 42, 0.6);
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 16px;
      text-align: center;
      transition: all 0.2s ease;
    }
    .stat-box:hover {
      background: rgba(30, 41, 59, 0.7);
      border-color: var(--cyan);
    }
    .stat-val {
      font-size: 28px;
      font-weight: 800;
      color: var(--cyan);
      font-family: 'JetBrains Mono', monospace;
    }
    .stat-lbl {
      font-size: 12px;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-top: 4px;
    }

    /* Tables in HUD */
    .hud-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 8px;
    }
    .hud-table th {
      color: var(--text-muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 10px 14px;
      border: none;
    }
    .hud-table tr td {
      background: rgba(20, 30, 48, 0.65);
      padding: 12px 14px;
      font-size: 14px;
      border-top: 1px solid rgba(255, 255, 255, 0.05);
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }
    .hud-table tr td:first-child { border-left: 1px solid rgba(255, 255, 255, 0.05); border-radius: 8px 0 0 8px; }
    .hud-table tr td:last-child { border-right: 1px solid rgba(255, 255, 255, 0.05); border-radius: 0 8px 8px 0; }
    .hud-table tr:hover td {
      background: rgba(30, 45, 75, 0.75);
      border-color: var(--border-color);
    }

    /* Form Controls */
    .hud-input {
      background: rgba(10, 15, 25, 0.8) !important;
      border: 1px solid var(--border-color) !important;
      color: #fff !important;
      border-radius: 8px !important;
      height: 44px;
      padding: 10px 14px;
    }
    .hud-input:focus {
      border-color: var(--cyan) !important;
      box-shadow: 0 0 10px rgba(0, 242, 254, 0.3) !important;
    }
    .hud-btn {
      background: linear-gradient(135deg, var(--cyan), var(--blue));
      color: #031525;
      font-weight: 700;
      border: none;
      border-radius: 8px;
      padding: 10px 20px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
    }
    .hud-btn:hover {
      box-shadow: 0 0 15px rgba(0, 242, 254, 0.5);
      transform: translateY(-1px);
      color: #031525;
    }
    .hud-btn-outline {
      background: transparent;
      border: 1px solid var(--border-color);
      color: var(--text-main);
    }
    .hud-btn-outline:hover {
      background: rgba(56, 189, 248, 0.15);
      color: var(--cyan);
      border-color: var(--cyan);
    }

    /* Modal HUD */
    .modal-content-hud {
      background: #0f172a;
      border: 1px solid var(--border-glow);
      border-radius: 14px;
      color: var(--text-main);
      box-shadow: 0 10px 40px rgba(0,0,0,0.8);
    }
    .modal-header-hud {
      border-bottom: 1px solid rgba(255,255,255,0.1);
      padding: 16px 20px;
    }
    .modal-footer-hud {
      border-top: 1px solid rgba(255,255,255,0.1);
      padding: 16px 20px;
    }
  </style>
</head>
<body>

<!-- TOP HUD NAV -->
<header class="hud-navbar">
  <div class="container-fluid">
    <div class="row" style="display: flex; align-items: center; justify-content: space-between;">
      <div class="col-xs-6" style="display: flex; align-items: center; gap: 15px;">
        <div class="hud-brand">
          <i class="fa-solid fa-atom"></i>
          <span>JARVIS</span>
        </div>
        <span class="hud-status-badge">
          <span class="pulse-dot"></span>
          <span>ONLINE • 192.168.2.12</span>
        </span>
      </div>
      <div class="col-xs-6 text-right" style="display: flex; align-items: center; justify-content: flex-end; gap: 15px;">
        <span class="text-muted" style="font-size: 13px;">
          <i class="fa-solid fa-microchip text-info"></i> ARM Quad-Core Cluster
        </span>
        <a href="/casa/index.php" class="btn btn-xs hud-btn-outline" style="border-radius: 6px;" title="Interface Clássica">
          <i class="fa-solid fa-table-cells"></i> Painel Antigo
        </a>
      </div>
    </div>
  </div>
</header>

<div class="container-fluid" style="max-width: 1400px; padding: 0 25px;">

  <!-- TABS DE NAVEGAÇÃO -->
  <div class="nav-tabs-hud">
    <button class="active" onclick="switchTab('tab-jarvis')">
      <i class="fa-solid fa-brain"></i> JARVIS Core & Voz
    </button>
    <button onclick="switchTab('tab-devices')">
      <i class="fa-solid fa-toggle-on"></i> Dispositivos & Relés
    </button>
    <button onclick="switchTab('tab-sensores')">
      <i class="fa-solid fa-gauge-high"></i> Sensores & Telemetria
    </button>
    <button onclick="switchTab('tab-nodes')">
      <i class="fa-solid fa-network-wired"></i> Cluster ARM (4 Nós)
    </button>
    <button onclick="switchTab('tab-frases')">
      <i class="fa-solid fa-quote-left"></i> Frases & Avisos
    </button>
    <button onclick="switchTab('tab-usuarios')">
      <i class="fa-solid fa-users"></i> Usuários & Segurança
    </button>
    <button onclick="switchTab('tab-config')">
      <i class="fa-solid fa-sliders"></i> Configurações (RunPod / IA)
    </button>
  </div>

  <!-- TAB 1: JARVIS CORE & VOZ -->
  <div id="tab-jarvis" class="tab-content-item">
    <div class="row">
      
      <!-- Coluna Esquerda: Reator e Comando por Voz -->
      <div class="col-md-5">
        <div class="glass-card text-center">
          <div class="glass-title text-center" style="justify-content: center;">
            <i class="fa-solid fa-wave-square"></i> Núcleo de Reconhecimento & Voz
          </div>
          
          <div class="jarvis-reactor" id="reactorBtn" onclick="toggleVoiceRecognition()" title="Clique para falar com o JARVIS">
            <div class="jarvis-core">
              <i class="fa-solid fa-microphone" id="micIcon"></i>
            </div>
          </div>
          <p id="voiceStatusText" style="font-size: 13px; color: var(--text-muted); margin-top: 10px;">
            Clique no reator ou use a caixa de texto abaixo para comandar
          </p>

          <div style="margin-top: 20px; text-align: left;">
            <label style="font-size: 12px; color: var(--text-muted);">Comando em Linguagem Natural:</label>
            <div class="input-group">
              <input type="text" id="comandoInput" class="form-control hud-input" placeholder="ex: Ligue a iluminação da sala..." onkeypress="if(event.keyCode==13) enviarComandoJarvis()">
              <span class="input-group-btn">
                <button class="hud-btn" onclick="enviarComandoJarvis()"><i class="fa-solid fa-paper-plane"></i></button>
              </span>
            </div>
          </div>

          <!-- Ações Rápidas -->
          <div style="margin-top: 20px; text-align: left;">
            <label style="font-size: 12px; color: var(--text-muted); display: block;">Comandos Rápidos de Automação:</label>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
              <button class="btn btn-xs hud-btn-outline" onclick="enviarComandoRapido('Ligue a luz da sala')"><i class="fa-solid fa-lightbulb text-warning"></i> Ligar Sala</button>
              <button class="btn btn-xs hud-btn-outline" onclick="enviarComandoRapido('Desligue a luz da sala')"><i class="fa-regular fa-lightbulb"></i> Desligar Sala</button>
              <button class="btn btn-xs hud-btn-outline" onclick="enviarComandoRapido('Ligue a irrigação da piscina')"><i class="fa-solid fa-droplet text-info"></i> Ligar Piscina</button>
              <button class="btn btn-xs hud-btn-outline" onclick="enviarComandoRapido('Desligue a irrigação da piscina')"><i class="fa-solid fa-ban text-danger"></i> Desligar Piscina</button>
              <button class="btn btn-xs hud-btn-outline" onclick="enviarComandoRapido('Qual o status geral da residência?')"><i class="fa-solid fa-shield-halved text-success"></i> Status Geral</button>
            </div>
          </div>

          <div style="margin-top: 20px; text-align: left;">
            <label style="font-size: 12px; color: var(--text-muted);">Voz do JARVIS (Padrão ou Clonada):</label>
            <select id="jarvisSpeakerSelect" class="form-control hud-input" onchange="atualizarVozJarvis(this.value)">
              <option value="padrao">Voz Neural Padrão (Faber pt-BR)</option>
            </select>
          </div>
        </div>

        <!-- Estatísticas Rápidas -->
        <div class="row">
          <div class="col-xs-6 col-sm-3" style="padding: 0 6px;">
            <div class="stat-box">
              <div class="stat-val"><?php echo $total_devices; ?></div>
              <div class="stat-lbl">Dispositivos</div>
            </div>
          </div>
          <div class="col-xs-6 col-sm-3" style="padding: 0 6px;">
            <div class="stat-box">
              <div class="stat-val"><?php echo $total_nodes; ?></div>
              <div class="stat-lbl">Nós ARM</div>
            </div>
          </div>
          <div class="col-xs-6 col-sm-3" style="padding: 0 6px;">
            <div class="stat-box">
              <div class="stat-val"><?php echo $total_sensores; ?></div>
              <div class="stat-lbl">Leituras</div>
            </div>
          </div>
          <div class="col-xs-6 col-sm-3" style="padding: 0 6px;">
            <div class="stat-box">
              <div class="stat-val"><?php echo $total_conversas; ?></div>
              <div class="stat-lbl">Interações</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Coluna Direita: Janela de Conversa e Diálogo -->
      <div class="col-md-7">
        <div class="glass-card">
          <div class="glass-header">
            <div class="glass-title">
              <i class="fa-solid fa-terminal"></i> Terminal Interativo JARVIS
            </div>
            <span id="provedorBadge" class="label label-primary" style="font-family: 'JetBrains Mono', monospace; font-size: 11px;">
              llama.cpp (Local)
            </span>
          </div>

          <div id="chatWindow" class="chat-window">
            <div class="msg-bubble msg-jarvis">
              <strong>JARVIS:</strong><br>
              Sistemas residenciais e nós operacionais, Senhor. Em que posso auxiliá-lo neste momento?
            </div>
          </div>

          <audio id="audioPlayback" style="display: none;"></audio>
        </div>
      </div>

    </div>
  </div>

  <!-- TAB 2: DISPOSITIVOS (CRUD) -->
  <div id="tab-devices" class="tab-content-item" style="display: none;">
    <div class="glass-card">
      <div class="glass-header">
        <div class="glass-title"><i class="fa-solid fa-toggle-on"></i> Gerenciamento de Dispositivos (devices)</div>
        <button class="hud-btn" onclick="abrirModalDevice()"><i class="fa-solid fa-plus"></i> Novo Dispositivo</button>
      </div>
      <div class="table-responsive">
        <table class="hud-table" id="tabelaDevices">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nome</th>
              <th>Descrição</th>
              <th>Tipo</th>
              <th>Endereço / IP</th>
              <th>Status</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- TAB 3: SENSORES & TELEMETRIA (CRUD) -->
  <div id="tab-sensores" class="tab-content-item" style="display: none;">
    <div class="glass-card">
      <div class="glass-header">
        <div class="glass-title"><i class="fa-solid fa-gauge-high"></i> Telemetria de Sensores (sensores_telemetria)</div>
        <div>
          <button class="hud-btn hud-btn-outline" onclick="injetarTelemetriaTeste()"><i class="fa-solid fa-bolt"></i> Simular Leitura</button>
          <button class="hud-btn" onclick="abrirModalSensor()"><i class="fa-solid fa-plus"></i> Nova Medição</button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="hud-table" id="tabelaSensores">
          <thead>
            <tr>
              <th>ID</th>
              <th>Sensor</th>
              <th>Valor</th>
              <th>Unidade</th>
              <th>Dispositivo</th>
              <th>Timestamp</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- TAB 4: CLUSTER ARM (4 NÓS) -->
  <div id="tab-nodes" class="tab-content-item" style="display: none;">
    <div class="glass-card">
      <div class="glass-header">
        <div class="glass-title"><i class="fa-solid fa-network-wired"></i> Cluster Residencial ARM (arm_nodes)</div>
        <div>
          <button class="hud-btn hud-btn-outline" onclick="pingNodes()"><i class="fa-solid fa-arrows-rotate"></i> Testar Ping Nós</button>
          <button class="hud-btn" onclick="abrirModalNode()"><i class="fa-solid fa-plus"></i> Adicionar Nó</button>
        </div>
      </div>
      <p class="text-muted" style="font-size: 13px;">Malha de processamento das 4 máquinas ARM na rede residencial.</p>
      <div class="table-responsive">
        <table class="hud-table" id="tabelaNodes">
          <thead>
            <tr>
              <th>Hostname</th>
              <th>Endereço IP</th>
              <th>Papel / Função</th>
              <th>CPU</th>
              <th>RAM</th>
              <th>Status</th>
              <th>Último Ping</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- TAB 5: FRASES & AVISOS (CRUD) -->
  <div id="tab-frases" class="tab-content-item" style="display: none;">
    <div class="glass-card">
      <div class="glass-header">
        <div class="glass-title"><i class="fa-solid fa-quote-left"></i> Frases e Avisos de Voz (frases)</div>
        <button class="hud-btn" onclick="abrirModalFrase()"><i class="fa-solid fa-plus"></i> Nova Frase</button>
      </div>
      <div class="table-responsive">
        <table class="hud-table" id="tabelaFrases">
          <thead>
            <tr>
              <th>ID</th>
              <th>Texto da Mensagem</th>
              <th>Autor / Categoria</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- TAB 6: USUÁRIOS (CRUD) -->
  <div id="tab-usuarios" class="tab-content-item" style="display: none;">
    <div class="glass-card">
      <div class="glass-header">
        <div class="glass-title"><i class="fa-solid fa-users"></i> Usuários e Credenciais (usuarios)</div>
        <button class="hud-btn" onclick="abrirModalUsuario()"><i class="fa-solid fa-plus"></i> Novo Usuário</button>
      </div>
      <div class="table-responsive">
        <table class="hud-table" id="tabelaUsuarios">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nome</th>
              <th>Login</th>
              <th>E-mail</th>
              <th>Perfil</th>
              <th>Status</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- TAB 7: CONFIGURAÇÕES RUNPOD / IA -->
  <div id="tab-config" class="tab-content-item" style="display: none;">
    <div class="glass-card">
      <div class="glass-header">
        <div class="glass-title"><i class="fa-solid fa-sliders"></i> Parâmetros do Sistema & RunPod.io GPU</div>
        <button class="hud-btn" onclick="salvarConfiguracoes()"><i class="fa-solid fa-floppy-disk"></i> Salvar Alterações</button>
      </div>
      <div class="row">
        <div class="col-md-6">
          <div class="form-group">
            <label>Provedor Ativo de Inteligência Artificial:</label>
            <select id="cfg_ia_provider" class="form-control hud-input">
              <option value="local">Local (llama.cpp no Raspberry Pi 4)</option>
              <option value="runpod">Nuvem GPU Serverless (RunPod.io)</option>
            </select>
          </div>
          <div class="form-group">
            <label>Chave de API RunPod.io (API Key):</label>
            <input type="password" id="cfg_runpod_api_key" class="form-control hud-input" placeholder="rpa_xxxxxxxxxxxxxxxxxxxxxxxxxx">
          </div>
          <div class="form-group">
            <label>ID do Endpoint Serverless RunPod (Endpoint ID):</label>
            <input type="text" id="cfg_runpod_endpoint_id" class="form-control hud-input" placeholder="vllm-xxxxxxxxxx">
          </div>
          <div class="form-group">
            <label>Modelo Nuvem (RunPod):</label>
            <input type="text" id="cfg_runpod_model" class="form-control hud-input" placeholder="meta-llama/Meta-Llama-3-8B-Instruct">
          </div>
        </div>

        <div class="col-md-6">
          <div class="form-group">
            <label>Modelo Local (llama.cpp):</label>
            <input type="text" id="cfg_local_model" class="form-control hud-input" readonly value="LiquidAI/lfm2.5-1.2b-instruct:q4_k_m">
          </div>
          <div class="form-group">
            <label>Palavra de Ativação por Voz (Wake Word):</label>
            <input type="text" id="cfg_jarvis_activation_word" class="form-control hud-input" placeholder="jarvis">
          </div>
          <div class="form-group">
            <label>Voz Padrão de Síntese:</label>
            <input type="text" id="cfg_jarvis_voice" class="form-control hud-input" placeholder="padrao">
          </div>
          <div class="alert alert-info" style="background: rgba(3, 105, 161, 0.2); border-color: rgba(56, 189, 248, 0.4); color: #bae6fd; font-size: 13px;">
            <i class="fa-solid fa-circle-info"></i> <strong>Fallback Inteligente:</strong> Caso o RunPod.io esteja indisponível ou sem saldo, o JARVIS automaticamente utiliza o motor local `llama.cpp` sem interrupção de serviço.
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- MODAL GENÉRICO DE CRUD -->
<div id="modalCrud" class="modal fade" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content modal-content-hud">
      <div class="modal-header modal-header-hud">
        <button type="button" class="close text-muted" data-dismiss="modal" style="color: #fff;">&times;</button>
        <h4 class="modal-title" id="modalCrudTitle" style="color: var(--cyan); font-weight: 700;">Título</h4>
      </div>
      <div class="modal-body" id="modalCrudBody" style="padding: 20px;">
        <!-- Campos Dinamicos -->
      </div>
      <div class="modal-footer modal-footer-hud">
        <button type="button" class="btn hud-btn-outline" data-dismiss="modal">Cancelar</button>
        <button type="button" class="hud-btn" id="btnSalvarModal" onclick="salvarModalCrud()"><i class="fa-solid fa-check"></i> Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- SCRIPTS JS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<script>
// Gerenciador de Abas
function switchTab(tabId) {
  $('.tab-content-item').hide();
  $('.nav-tabs-hud button').removeClass('active');
  $('#' + tabId).fadeIn(200);
  event.currentTarget.classList.add('active');

  if (tabId === 'tab-devices') carregarDevices();
  if (tabId === 'tab-sensores') carregarSensores();
  if (tabId === 'tab-nodes') carregarNodes();
  if (tabId === 'tab-frases') carregarFrases();
  if (tabId === 'tab-usuarios') carregarUsuarios();
  if (tabId === 'tab-config') carregarConfiguracoes();
}

// ----------------- JARVIS CORE & VOZ -----------------
var recognizing = false;
var recognition = null;

if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  recognition = new SpeechRecognition();
  recognition.lang = 'pt-BR';
  recognition.continuous = false;
  recognition.interimResults = false;

  recognition.onstart = function() {
    recognizing = true;
    $('#reactorBtn').addClass('listening');
    $('#micIcon').removeClass('fa-microphone').addClass('fa-ear-listen text-danger');
    $('#voiceStatusText').text('Ouvindo comando de voz...');
  };

  recognition.onresult = function(event) {
    var transcript = event.results[0][0].transcript;
    $('#comandoInput').val(transcript);
    enviarComandoJarvis();
  };

  recognition.onerror = function() {
    finalizarVoz();
  };

  recognition.onend = function() {
    finalizarVoz();
  };
}

function finalizarVoz() {
  recognizing = false;
  $('#reactorBtn').removeClass('listening');
  $('#micIcon').removeClass('fa-ear-listen text-danger').addClass('fa-microphone');
  $('#voiceStatusText').text('Clique no reator para falar');
}

function toggleVoiceRecognition() {
  if (!recognition) {
    alert('Reconhecimento de voz no navegador não suportado.');
    return;
  }
  if (recognizing) {
    recognition.stop();
  } else {
    recognition.start();
  }
}

function enviarComandoRapido(cmd) {
  $('#comandoInput').val(cmd);
  enviarComandoJarvis();
}

function enviarComandoJarvis() {
  var cmd = $('#comandoInput').val().trim();
  if (!cmd) return;

  $('#comandoInput').val('');
  $('#chatWindow').append('<div class="msg-bubble msg-user"><strong>Você:</strong><br>' + $('<div>').text(cmd).html() + '</div>');
  
  var waitId = 'wait_' + Date.now();
  $('#chatWindow').append('<div id="' + waitId + '" class="msg-bubble msg-jarvis"><i class="fa-solid fa-spinner fa-spin"></i> Processando comando...</div>');
  scrollChat();

  $.ajax({
    url: 'api/jarvis.php',
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify({ comando: cmd }),
    success: function(res) {
      $('#' + waitId).remove();
      if (res.status === 'sucesso') {
        $('#provedorBadge').text(res.provedor.toUpperCase());
        var msgHtml = '<div class="msg-bubble msg-jarvis"><strong>JARVIS:</strong><br>' + $('<div>').text(res.resposta).html();
        if (res.acao) {
          msgHtml += '<br><span class="badge" style="background: #10b981; margin-top: 6px;"><i class="fa-solid fa-check"></i> ' + res.acao + '</span>';
        }
        msgHtml += '</div>';
        $('#chatWindow').append(msgHtml);

        if (res.audio_url) {
          var audio = $('#audioPlayback')[0];
          audio.src = res.audio_url;
          audio.play();
        }
      } else {
        $('#chatWindow').append('<div class="msg-bubble msg-jarvis text-danger">Erro: ' + res.mensagem + '</div>');
      }
      scrollChat();
    },
    error: function() {
      $('#' + waitId).remove();
      $('#chatWindow').append('<div class="msg-bubble msg-jarvis text-danger">Falha na comunicação com o JARVIS.</div>');
      scrollChat();
    }
  });
}

function scrollChat() {
  var w = $('#chatWindow');
  w.scrollTop(w.prop("scrollHeight"));
}

function carregarVozesJarvis() {
  $.getJSON('/casa/ws/proxy_tts.php?action=vozes', function(data) {
    if (data.vozes) {
      var s = $('#jarvisSpeakerSelect');
      s.empty();
      data.vozes.forEach(function(v) {
        var label = (v === 'padrao') ? 'Voz Neural Padrão (pt-BR)' : 'Voz Clonada: ' + v.toUpperCase();
        s.append($('<option>', { value: v, text: label }));
      });
    }
  });
}

// ----------------- CRUD DEVICES -----------------
function carregarDevices() {
  $.getJSON('api/crud.php?tabela=devices&acao=listar', function(res) {
    var tbody = $('#tabelaDevices tbody');
    tbody.empty();
    if (res.dados) {
      res.dados.forEach(function(d) {
        var statusBadge = d.devstatus ? '<span class="label label-success">Online</span>' : '<span class="label label-danger">Offline</span>';
        var tr = '<tr>' +
          '<td>' + d.iddevice + '</td>' +
          '<td><strong>' + d.devname + '</strong></td>' +
          '<td>' + (d.devdesc || '') + '</td>' +
          '<td>Tipo ' + d.devtype + '</td>' +
          '<td><code>' + (d.devcon || '') + '</code></td>' +
          '<td>' + statusBadge + '</td>' +
          '<td>' +
            '<button class="btn btn-xs hud-btn-outline" onclick="editarDevice(' + JSON.stringify(d).replace(/"/g, '&quot;') + ')"><i class="fa-solid fa-pen"></i></button> ' +
            '<button class="btn btn-xs btn-danger" onclick="excluirItem(\'devices\', ' + d.iddevice + ', carregarDevices)"><i class="fa-solid fa-trash"></i></button>' +
          '</td>' +
        '</tr>';
        tbody.append(tr);
      });
    }
  });
}

function abrirModalDevice() {
  $('#modalCrudTitle').text('Novo Dispositivo');
  $('#modalCrudBody').html(
    '<input type="hidden" id="f_iddevice" value="">' +
    '<div class="form-group"><label>Nome do Dispositivo:</label><input class="form-control hud-input" id="f_devname" placeholder="ex: sala, piscina"></div>' +
    '<div class="form-group"><label>Descrição:</label><input class="form-control hud-input" id="f_devdesc" placeholder="Controlador da Iluminação"></div>' +
    '<div class="form-group"><label>Tipo (1=ESP8266, 2=Relé, 3=Sensor):</label><input type="number" class="form-control hud-input" id="f_devtype" value="1"></div>' +
    '<div class="form-group"><label>Endereço IP / Conexão:</label><input class="form-control hud-input" id="f_devcon" placeholder="192.168.2.210"></div>' +
    '<div class="form-group"><label>Status Inicial:</label><select class="form-control hud-input" id="f_devstatus"><option value="true">Ativo / Online</option><option value="false">Inativo</option></select></div>'
  );
  $('#btnSalvarModal').attr('onclick', 'salvarDevice()');
  $('#modalCrud').modal('show');
}

function editarDevice(d) {
  abrirModalDevice();
  $('#modalCrudTitle').text('Editar Dispositivo #' + d.iddevice);
  $('#f_iddevice').val(d.iddevice);
  $('#f_devname').val(d.devname);
  $('#f_devdesc').val(d.devdesc);
  $('#f_devtype').val(d.devtype);
  $('#f_devcon').val(d.devcon);
  $('#f_devstatus').val(d.devstatus ? 'true' : 'false');
}

function salvarDevice() {
  var id = $('#f_iddevice').val();
  var payload = {
    devname: $('#f_devname').val(),
    devdesc: $('#f_devdesc').val(),
    devtype: parseInt($('#f_devtype').val()),
    devcon: $('#f_devcon').val(),
    devstatus: $('#f_devstatus').val() === 'true'
  };
  var acao = id ? 'atualizar' : 'criar';
  if (id) payload.iddevice = parseInt(id);

  $.ajax({
    url: 'api/crud.php?tabela=devices&acao=' + acao,
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify(payload),
    success: function() {
      $('#modalCrud').modal('hide');
      carregarDevices();
    }
  });
}

// ----------------- CRUD SENSORES -----------------
function carregarSensores() {
  $.getJSON('api/crud.php?tabela=sensores_telemetria&acao=listar&limite=50', function(res) {
    var tbody = $('#tabelaSensores tbody');
    tbody.empty();
    if (res.dados) {
      res.dados.forEach(function(s) {
        var tr = '<tr>' +
          '<td>' + s.id + '</td>' +
          '<td><i class="fa-solid fa-microchip text-info"></i> <strong>' + s.sensor_nome + '</strong></td>' +
          '<td style="font-family: monospace; font-size: 15px; color: var(--cyan);">' + s.valor_numerico + '</td>' +
          '<td>' + (s.unidade || '') + '</td>' +
          '<td>Dispositivo #' + (s.iddevice || '—') + '</td>' +
          '<td style="font-size: 12px; color: var(--text-muted);">' + s.data_hora + '</td>' +
          '<td>' +
            '<button class="btn btn-xs btn-danger" onclick="excluirItem(\'sensores_telemetria\', ' + s.id + ', carregarSensores)"><i class="fa-solid fa-trash"></i></button>' +
          '</td>' +
        '</tr>';
        tbody.append(tr);
      });
    }
  });
}

function injetarTelemetriaTeste() {
  var valores = [24.5, 25.1, 26.0, 78.4, 12.8];
  var val = valores[Math.floor(Math.random() * valores.length)];
  $.ajax({
    url: 'api/crud.php?tabela=sensores_telemetria&acao=criar',
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify({
      iddevice: 1,
      sensor_nome: 'Temperatura_Sala',
      valor_numerico: val,
      unidade: '°C',
      raw_data: 'teste_auto'
    }),
    success: function() {
      carregarSensores();
    }
  });
}

function abrirModalSensor() {
  $('#modalCrudTitle').text('Nova Medição Manual');
  $('#modalCrudBody').html(
    '<div class="form-group"><label>Nome do Sensor:</label><input class="form-control hud-input" id="f_sensor_nome" placeholder="Temperatura_Piscina"></div>' +
    '<div class="form-group"><label>Valor Numérico:</label><input type="number" step="0.01" class="form-control hud-input" id="f_valor_numerico" placeholder="28.5"></div>' +
    '<div class="form-group"><label>Unidade:</label><input class="form-control hud-input" id="f_unidade" placeholder="°C, %, RPM"></div>' +
    '<div class="form-group"><label>ID Dispositivo:</label><input type="number" class="form-control hud-input" id="f_sensor_iddevice" value="1"></div>'
  );
  $('#btnSalvarModal').attr('onclick', 'salvarSensor()');
  $('#modalCrud').modal('show');
}

function salvarSensor() {
  var payload = {
    sensor_nome: $('#f_sensor_nome').val(),
    valor_numerico: parseFloat($('#f_valor_numerico').val()),
    unidade: $('#f_unidade').val(),
    iddevice: parseInt($('#f_sensor_iddevice').val())
  };
  $.ajax({
    url: 'api/crud.php?tabela=sensores_telemetria&acao=criar',
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify(payload),
    success: function() {
      $('#modalCrud').modal('hide');
      carregarSensores();
    }
  });
}

// ----------------- CRUD CLUSTER ARM -----------------
function carregarNodes() {
  $.getJSON('api/crud.php?tabela=arm_nodes&acao=listar', function(res) {
    var tbody = $('#tabelaNodes tbody');
    tbody.empty();
    if (res.dados) {
      res.dados.forEach(function(n) {
        var stBadge = (n.status === 'online') ? '<span class="label label-success">Online</span>' : '<span class="label label-warning">' + n.status + '</span>';
        var tr = '<tr>' +
          '<td><strong>' + n.hostname + '</strong></td>' +
          '<td><code>' + n.ip_address + '</code></td>' +
          '<td>' + n.papel + '</td>' +
          '<td><small>' + (n.cpu_info || 'ARM') + '</small></td>' +
          '<td><small>' + (n.ram_info || '') + '</small></td>' +
          '<td>' + stBadge + '</td>' +
          '<td style="font-size: 11px; color: var(--text-muted);">' + n.ultimo_ping + '</td>' +
          '<td>' +
            '<button class="btn btn-xs hud-btn-outline" onclick="editarNode(' + JSON.stringify(n).replace(/"/g, '&quot;') + ')"><i class="fa-solid fa-pen"></i></button> ' +
            '<button class="btn btn-xs btn-danger" onclick="excluirItem(\'arm_nodes\', ' + n.id + ', carregarNodes)"><i class="fa-solid fa-trash"></i></button>' +
          '</td>' +
        '</tr>';
        tbody.append(tr);
      });
    }
  });
}

function pingNodes() {
  $.getJSON('api/crud.php?tabela=arm_nodes&acao=ping_nodes', function() {
    carregarNodes();
  });
}

function abrirModalNode() {
  $('#modalCrudTitle').text('Adicionar Nó ARM ao Cluster');
  $('#modalCrudBody').html(
    '<input type="hidden" id="f_node_id" value="">' +
    '<div class="form-group"><label>Hostname:</label><input class="form-control hud-input" id="f_node_host" placeholder="rpi-node-05"></div>' +
    '<div class="form-group"><label>Endereço IP:</label><input class="form-control hud-input" id="f_node_ip" placeholder="192.168.2.16"></div>' +
    '<div class="form-group"><label>Papel / Responsabilidade:</label><input class="form-control hud-input" id="f_node_papel" placeholder="Visão Computacional / Câmeras"></div>' +
    '<div class="form-group"><label>Informações de CPU:</label><input class="form-control hud-input" id="f_node_cpu" placeholder="ARM Cortex-A72 4-cores"></div>' +
    '<div class="form-group"><label>Informações de RAM:</label><input class="form-control hud-input" id="f_node_ram" placeholder="4GB"></div>'
  );
  $('#btnSalvarModal').attr('onclick', 'salvarNode()');
  $('#modalCrud').modal('show');
}

function editarNode(n) {
  abrirModalNode();
  $('#modalCrudTitle').text('Editar Nó ARM #' + n.id);
  $('#f_node_id').val(n.id);
  $('#f_node_host').val(n.hostname);
  $('#f_node_ip').val(n.ip_address);
  $('#f_node_papel').val(n.papel);
  $('#f_node_cpu').val(n.cpu_info);
  $('#f_node_ram').val(n.ram_info);
}

function salvarNode() {
  var id = $('#f_node_id').val();
  var payload = {
    hostname: $('#f_node_host').val(),
    ip_address: $('#f_node_ip').val(),
    papel: $('#f_node_papel').val(),
    cpu_info: $('#f_node_cpu').val(),
    ram_info: $('#f_node_ram').val()
  };
  var acao = id ? 'atualizar' : 'criar';
  if (id) payload.id = parseInt(id);

  $.ajax({
    url: 'api/crud.php?tabela=arm_nodes&acao=' + acao,
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify(payload),
    success: function() {
      $('#modalCrud').modal('hide');
      carregarNodes();
    }
  });
}

// ----------------- CRUD FRASES -----------------
function carregarFrases() {
  $.getJSON('api/crud.php?tabela=frases&acao=listar', function(res) {
    var tbody = $('#tabelaFrases tbody');
    tbody.empty();
    if (res.dados) {
      res.dados.forEach(function(f) {
        var tr = '<tr>' +
          '<td>' + f.id + '</td>' +
          '<td><strong>"' + f.texto + '"</strong></td>' +
          '<td>' + (f.autor || '—') + '</td>' +
          '<td>' +
            '<button class="btn btn-xs hud-btn-outline" onclick="falarFraseTexto(\'' + encodeURIComponent(f.texto) + '\')"><i class="fa-solid fa-volume-high text-info"></i> Falar</button> ' +
            '<button class="btn btn-xs btn-danger" onclick="excluirItem(\'frases\', ' + f.id + ', carregarFrases)"><i class="fa-solid fa-trash"></i></button>' +
          '</td>' +
        '</tr>';
        tbody.append(tr);
      });
    }
  });
}

function falarFraseTexto(encTxt) {
  var txt = decodeURIComponent(encTxt);
  $.ajax({
    url: 'casa/ws/proxy_tts.php?action=falar',
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify({ texto: txt, speaker: 'padrao', reproduzir: true })
  });
}

function abrirModalFrase() {
  $('#modalCrudTitle').text('Nova Frase para Síntese');
  $('#modalCrudBody').html(
    '<div class="form-group"><label>Texto da Frase:</label><textarea class="form-control hud-input" id="f_frase_texto" rows="3"></textarea></div>' +
    '<div class="form-group"><label>Autor / Origem:</label><input class="form-control hud-input" id="f_frase_autor" placeholder="Segurança / Alerta"></div>'
  );
  $('#btnSalvarModal').attr('onclick', 'salvarFrase()');
  $('#modalCrud').modal('show');
}

function salvarFrase() {
  var payload = {
    texto: $('#f_frase_texto').val(),
    autor: $('#f_frase_autor').val()
  };
  $.ajax({
    url: 'api/crud.php?tabela=frases&acao=criar',
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify(payload),
    success: function() {
      $('#modalCrud').modal('hide');
      carregarFrases();
    }
  });
}

// ----------------- CRUD USUÁRIOS -----------------
function carregarUsuarios() {
  $.getJSON('api/crud.php?tabela=usuarios&acao=listar', function(res) {
    var tbody = $('#tabelaUsuarios tbody');
    tbody.empty();
    if (res.dados) {
      res.dados.forEach(function(u) {
        var tr = '<tr>' +
          '<td>' + u.id + '</td>' +
          '<td><strong>' + u.nome + '</strong></td>' +
          '<td><code>' + u.login + '</code></td>' +
          '<td>' + (u.email || '—') + '</td>' +
          '<td><span class="label label-info">' + u.perfil + '</span></td>' +
          '<td>' + (u.ativo ? '<span class="label label-success">Ativo</span>' : '<span class="label label-default">Inativo</span>') + '</td>' +
          '<td>' +
            '<button class="btn btn-xs btn-danger" onclick="excluirItem(\'usuarios\', ' + u.id + ', carregarUsuarios)"><i class="fa-solid fa-trash"></i></button>' +
          '</td>' +
        '</tr>';
        tbody.append(tr);
      });
    }
  });
}

function abrirModalUsuario() {
  $('#modalCrudTitle').text('Novo Usuário');
  $('#modalCrudBody').html(
    '<div class="form-group"><label>Nome Completo:</label><input class="form-control hud-input" id="f_user_nome"></div>' +
    '<div class="form-group"><label>Login:</label><input class="form-control hud-input" id="f_user_login"></div>' +
    '<div class="form-group"><label>Senha:</label><input type="password" class="form-control hud-input" id="f_user_senha"></div>' +
    '<div class="form-group"><label>E-mail:</label><input type="email" class="form-control hud-input" id="f_user_email"></div>' +
    '<div class="form-group"><label>Perfil:</label><select class="form-control hud-input" id="f_user_perfil"><option value="admin">Administrador</option><option value="operador">Operador Residencial</option></select></div>'
  );
  $('#btnSalvarModal').attr('onclick', 'salvarUsuario()');
  $('#modalCrud').modal('show');
}

function salvarUsuario() {
  var payload = {
    nome: $('#f_user_nome').val(),
    login: $('#f_user_login').val(),
    senha: $('#f_user_senha').val(),
    email: $('#f_user_email').val(),
    perfil: $('#f_user_perfil').val(),
    ativo: true
  };
  $.ajax({
    url: 'api/crud.php?tabela=usuarios&acao=criar',
    type: 'POST',
    contentType: 'application/json',
    data: JSON.stringify(payload),
    success: function() {
      $('#modalCrud').modal('hide');
      carregarUsuarios();
    }
  });
}

// ----------------- CONFIGURAÇÕES / RUNPOD -----------------
function carregarConfiguracoes() {
  $.getJSON('api/crud.php?tabela=configuracoes_sistema&acao=listar', function(res) {
    if (res.dados) {
      res.dados.forEach(function(c) {
        if ($('#cfg_' + c.chave).length) {
          $('#cfg_' + c.chave).val(c.valor);
        }
      });
    }
  });
}

function salvarConfiguracoes() {
  var chaves = ['ia_provider', 'runpod_api_key', 'runpod_endpoint_id', 'runpod_model', 'local_model', 'jarvis_activation_word', 'jarvis_voice'];
  var requests = [];

  chaves.forEach(function(k) {
    var val = $('#cfg_' + k).val();
    requests.push($.ajax({
      url: 'api/crud.php?tabela=configuracoes_sistema&acao=atualizar',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ chave: k, valor: val })
    }));
  });

  $.when.apply($, requests).done(function() {
    alert('Configurações atualizadas com sucesso no banco de dados!');
  });
}

// Utilitario de Exclusao Generico
function excluirItem(tabela, id, callback) {
  if (!confirm('Deseja realmente excluir este registro?')) return;
  $.ajax({
    url: 'api/crud.php?tabela=' + tabela + '&acao=excluir&id=' + id,
    type: 'POST',
    success: function() {
      if (callback) callback();
    }
  });
}

$(document).ready(function() {
  carregarVozesJarvis();
});
</script>
</body>
</html>
