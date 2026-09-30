<?php
// CASA • JARVIS Residential AI Platform - Mobile Web App / PWA
// Aplicação mobile com leitor de QR Code integrado para ganho de acesso instantâneo à residência
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>CASA Mobile • JARVIS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="theme-color" content="#ff9900">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="manifest" href="/casa/manifest.json">
  <link rel="icon" type="image/x-icon" href="/casa/favicon.ico">
  <script src="/casa/jsqr.min.js"></script>
  <style>
    :root {
      --ja-bg: #03080e;
      --ja-surface: #07121b;
      --ja-card: #0a1b28;
      --ja-orange: #ff9900;
      --ja-amber: #f2a000;
      --ja-blue: #3f82ad;
      --ja-cyan: #00e5ff;
      --ja-salmon: #d96f78;
      --ja-lavender: #9b83ad;
      --ja-green: #4caf50;
      --ja-danger: #f44336;
      --ja-text: #e0f2fe;
      --ja-text-muted: #8fc7ef;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      -webkit-tap-highlight-color: transparent;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    }
    body {
      background: var(--ja-bg);
      color: var(--ja-text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
    }

    /* LCARS Mobile Header */
    .lcars-bar-top {
      background: var(--ja-surface);
      border-bottom: 2px solid var(--ja-orange);
      padding: 12px 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 4px 16px rgba(0,0,0,0.6);
    }
    .lcars-title-group {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .lcars-pill {
      background: var(--ja-orange);
      color: #000;
      font-weight: 900;
      font-size: 11px;
      padding: 4px 10px;
      border-radius: 12px;
      letter-spacing: 0.5px;
    }
    .lcars-title {
      font-size: 14px;
      font-weight: 800;
      color: #fff;
      letter-spacing: 0.5px;
    }
    .lcars-status-badge {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 11px;
      font-weight: 700;
      padding: 4px 8px;
      border-radius: 8px;
      background: rgba(63, 130, 173, 0.2);
      border: 1px solid var(--ja-blue);
      color: var(--ja-text-muted);
    }
    .lcars-status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #777;
    }
    .lcars-status-dot.online {
      background: var(--ja-green);
      box-shadow: 0 0 8px var(--ja-green);
    }

    /* Main Container */
    .container {
      flex: 1;
      display: flex;
      flex-direction: column;
      padding: 14px;
      max-width: 600px;
      margin: 0 auto;
      width: 100%;
    }

    /* Tela de Pareamento / Scanner */
    #screen-scanner {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
      text-align: center;
      margin-top: 10px;
    }
    .scanner-hero-card {
      background: var(--ja-surface);
      border: 2px solid var(--ja-blue);
      border-radius: 20px;
      padding: 20px 16px;
      width: 100%;
      box-shadow: 0 8px 24px rgba(0,0,0,0.5);
    }
    .scanner-hero-card h2 {
      font-size: 17px;
      color: var(--ja-orange);
      margin-bottom: 6px;
    }
    .scanner-hero-card p {
      font-size: 12px;
      color: var(--ja-text-muted);
      line-height: 1.4;
    }

    /* Viewfinder da Câmera */
    .viewfinder-wrapper {
      position: relative;
      width: 100%;
      max-width: 320px;
      aspect-ratio: 1;
      border-radius: 24px;
      overflow: hidden;
      background: #000;
      border: 3px solid var(--ja-orange);
      box-shadow: 0 0 24px rgba(255, 153, 0, 0.3);
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 0 auto;
    }
    #video-scanner {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    #canvas-scanner {
      display: none;
    }
    /* Mira óptica */
    .viewfinder-crosshairs {
      position: absolute;
      inset: 20px;
      pointer-events: none;
    }
    .viewfinder-crosshairs::before,
    .viewfinder-crosshairs::after {
      content: '';
      position: absolute;
      width: 32px;
      height: 32px;
    }
    .viewfinder-crosshairs::before {
      top: 0; left: 0;
      border-top: 4px solid var(--ja-cyan);
      border-left: 4px solid var(--ja-cyan);
    }
    .viewfinder-crosshairs::after {
      bottom: 0; right: 0;
      border-bottom: 4px solid var(--ja-cyan);
      border-right: 4px solid var(--ja-cyan);
    }
    .scanner-laser {
      position: absolute;
      left: 10%;
      right: 10%;
      height: 2px;
      background: var(--ja-cyan);
      box-shadow: 0 0 10px var(--ja-cyan);
      top: 20%;
      animation: scanLaser 2s ease-in-out infinite alternate;
    }
    @keyframes scanLaser {
      0% { top: 20%; }
      100% { top: 80%; }
    }

    /* Botões LCARS */
    .btn-lcars {
      background: var(--ja-orange);
      color: #000;
      border: 0;
      border-radius: 14px;
      padding: 14px 20px;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      transition: all 0.2s;
    }
    .btn-lcars:active {
      transform: scale(0.98);
      filter: brightness(0.9);
    }
    .btn-lcars.secondary {
      background: var(--ja-surface);
      border: 2px solid var(--ja-blue);
      color: var(--ja-text-muted);
    }
    .btn-lcars.danger {
      background: var(--ja-danger);
      color: #fff;
    }

    /* Mensagens de feedback */
    .status-msg {
      font-size: 12px;
      font-weight: 700;
      padding: 10px 14px;
      border-radius: 10px;
      width: 100%;
      text-align: center;
    }
    .status-msg.info { background: rgba(63, 130, 173, 0.2); color: var(--ja-text-muted); }
    .status-msg.success { background: rgba(76, 175, 80, 0.2); color: var(--ja-green); border: 1px solid var(--ja-green); }
    .status-msg.error { background: rgba(244, 67, 54, 0.2); color: var(--ja-danger); border: 1px solid var(--ja-danger); }

    /* Tela Autenticada (Dashboard) */
    #screen-dashboard {
      display: none;
      flex-direction: column;
      gap: 14px;
    }
    .dash-header {
      background: var(--ja-surface);
      border-radius: 16px;
      border-left: 6px solid var(--ja-orange);
      padding: 12px 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .dash-header-info b {
      font-size: 11px;
      color: var(--ja-text-muted);
      text-transform: uppercase;
    }
    .dash-header-info h2 {
      font-size: 15px;
      color: #fff;
    }

    /* Abas do Dashboard */
    .dash-tabs {
      display: flex;
      gap: 6px;
      overflow-x: auto;
      padding-bottom: 4px;
    }
    .dash-tab-btn {
      flex: 1;
      padding: 10px 8px;
      border-radius: 12px;
      border: 0;
      background: var(--ja-card);
      color: var(--ja-text-muted);
      font-size: 11px;
      font-weight: 800;
      cursor: pointer;
      white-space: nowrap;
      text-align: center;
      transition: all 0.2s;
    }
    .dash-tab-btn.active {
      background: var(--ja-orange);
      color: #000;
    }

    /* Conteúdo das Abas */
    .tab-content {
      display: none;
      flex-direction: column;
      gap: 10px;
    }
    .tab-content.active {
      display: flex;
    }

    /* Cartões de Dispositivos e Relés */
    .device-card {
      background: var(--ja-surface);
      border: 2px solid var(--ja-blue);
      border-radius: 16px;
      padding: 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
    }
    .device-info h4 {
      font-size: 14px;
      color: #fff;
      margin-bottom: 2px;
    }
    .device-info span {
      font-size: 11px;
      color: var(--ja-text-muted);
    }
    .toggle-btn {
      padding: 8px 18px;
      border-radius: 20px;
      font-weight: 900;
      font-size: 11px;
      cursor: pointer;
      border: 0;
      transition: all 0.2s;
      min-width: 80px;
    }
    .toggle-btn.on {
      background: var(--ja-green);
      color: #000;
      box-shadow: 0 0 10px rgba(76, 175, 80, 0.5);
    }
    .toggle-btn.off {
      background: #233442;
      color: #8fc7ef;
    }

    /* Cartões de Sensores */
    .sensors-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }
    .sensor-card {
      background: var(--ja-surface);
      border: 2px solid #234358;
      border-radius: 14px;
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .sensor-card span {
      font-size: 10px;
      color: var(--ja-text-muted);
      text-transform: uppercase;
    }
    .sensor-card strong {
      font-size: 16px;
      color: var(--ja-amber);
    }

    /* Assistente JARVIS */
    .jarvis-box {
      background: var(--ja-surface);
      border: 2px solid var(--ja-lavender);
      border-radius: 18px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .jarvis-response {
      background: #000;
      border: 1px solid #3f82ad;
      border-radius: 12px;
      padding: 12px;
      font-size: 13px;
      color: #4fc3f7;
      min-height: 70px;
      line-height: 1.4;
    }
    .jarvis-input-row {
      display: flex;
      gap: 8px;
    }
    .jarvis-input-row input {
      flex: 1;
      background: #0b1a26;
      border: 2px solid #3f82ad;
      border-radius: 12px;
      padding: 10px 14px;
      color: #fff;
      font-size: 13px;
      outline: none;
    }
    .jarvis-input-row input:focus {
      border-color: var(--ja-orange);
    }

    /* Footer */
    .lcars-footer {
      margin-top: auto;
      padding: 14px;
      text-align: center;
      font-size: 11px;
      color: var(--ja-text-muted);
      border-top: 1px solid #1a2730;
    }
  </style>
</head>
<body>

  <!-- Top Bar -->
  <header class="lcars-bar-top">
    <div class="lcars-title-group">
      <div class="lcars-pill">CASA</div>
      <div class="lcars-title">MOBILE • JARVIS</div>
    </div>
    <div class="lcars-status-badge">
      <div class="lcars-status-dot" id="header-status-dot"></div>
      <span id="header-status-text">OFFLINE</span>
    </div>
  </header>

  <main class="container">

    <!-- TELA 1: SCANNER QR CODE & CONEXÃO -->
    <section id="screen-scanner">
      <div class="scanner-hero-card">
        <h2>LEITOR DE QR CODE DA CASA</h2>
        <p>Aponte a câmera do seu celular para o QR Code exibido na tela da CASA (na estação no fim da página de Chaves de API) para ganhar acesso imediato.</p>
      </div>

      <div class="viewfinder-wrapper" id="viewfinder-box">
        <video id="video-scanner" playsinline autoplay muted></video>
        <canvas id="canvas-scanner"></canvas>
        <div class="viewfinder-crosshairs"></div>
        <div class="scanner-laser"></div>
      </div>

      <div id="scanner-feedback" class="status-msg info">
        Iniciando câmera traseira para leitura...
      </div>

      <div style="display:flex; flex-direction:column; gap:8px; width:100%;">
        <button type="button" class="btn-lcars" id="btn-toggle-camera">
          <span>&#128247;</span> REINICIAR CÂMERA
        </button>

        <label class="btn-lcars secondary" style="cursor:pointer;">
          <span>&#128193;</span> IMPORTAR FOTO DO QR CODE
          <input type="file" id="file-qr-input" accept="image/*" style="display:none;">
        </label>

        <button type="button" class="btn-lcars secondary" id="btn-manual-token">
          <span>&#9997;</span> DIGITAR CHAVE MANUALMENTE
        </button>
      </div>

      <!-- Modal Digitação Manual -->
      <div id="manual-modal" style="display:none; width:100%; background:var(--ja-surface); border:2px solid var(--ja-blue); border-radius:16px; padding:14px; margin-top:8px;">
        <label style="font-size:11px; color:var(--ja-text-muted); display:block; margin-bottom:4px;">TOKEN / CHAVE DE API:</label>
        <input type="text" id="manual-token-input" placeholder="Cole sua chave aqui" style="width:100%; background:#0b1a26; border:2px solid #3f82ad; border-radius:10px; padding:10px; color:#fff; font-size:12px; margin-bottom:10px;">
        <div style="display:flex; gap:8px;">
          <button type="button" class="btn-lcars" id="btn-submit-manual">CONECTAR</button>
          <button type="button" class="btn-lcars secondary" id="btn-cancel-manual">CANCELAR</button>
        </div>
      </div>
    </section>

    <!-- TELA 2: PAINEL AUTENTICADO DA CASA -->
    <section id="screen-dashboard">
      <div class="dash-header">
        <div class="dash-header-info">
          <b id="dash-device-name">RESIDÊNCIA CONECTADA</b>
          <h2 id="dash-system-name">CASA • JARVIS ONLINE</h2>
        </div>
        <button type="button" class="btn-lcars secondary" id="btn-logout" style="width:auto; padding:8px 14px; font-size:11px;">
          SAIR
        </button>
      </div>

      <!-- Abas de Navegação -->
      <nav class="dash-tabs">
        <button type="button" class="dash-tab-btn active" data-tab="tab-devices">DISPOSITIVOS</button>
        <button type="button" class="dash-tab-btn" data-tab="tab-sensors">SENSORES</button>
        <button type="button" class="dash-tab-btn" data-tab="tab-jarvis">VOZ & IA</button>
        <button type="button" class="dash-tab-btn" data-tab="tab-config">AJUSTES & APK</button>
      </nav>

      <!-- Aba 1: Dispositivos & Relés -->
      <div class="tab-content active" id="tab-devices">
        <div id="devices-list" style="display:flex; flex-direction:column; gap:10px;">
          <!-- Dispositivos carregados dinamicamente via API -->
          <div class="status-msg info">Carregando dispositivos e relés...</div>
        </div>
      </div>

      <!-- Aba 2: Sensores -->
      <div class="tab-content" id="tab-sensors">
        <div class="sensors-grid" id="sensors-grid">
          <!-- Sensores carregados via API -->
          <div class="sensor-card"><span>SISTEMA</span><strong id="sensor-sys">JARVIS</strong></div>
          <div class="sensor-card"><span>STATUS TÚNEL</span><strong id="sensor-tunnel">ONLINE</strong></div>
          <div class="sensor-card"><span>SEGURANÇA</span><strong id="sensor-sec">ATIVADO</strong></div>
          <div class="sensor-card"><span>DISPOSITIVOS</span><strong id="sensor-count">0</strong></div>
        </div>
      </div>

      <!-- Aba 3: JARVIS Voz & Comandos -->
      <div class="tab-content" id="tab-jarvis">
        <div class="jarvis-box">
          <div style="font-size:12px; font-weight:800; color:var(--ja-lavender);">COMANDO DE VOZ & TEXTO JARVIS</div>
          <div class="jarvis-response" id="jarvis-output">
            Olá! Diga ou digite um comando para controlar a residência (ex: "ligar luzes", "status da casa").
          </div>
          <div class="jarvis-input-row">
            <input type="text" id="jarvis-input" placeholder="Digite um comando...">
            <button type="button" class="btn-lcars" id="btn-jarvis-send" style="width:auto; padding:0 18px;">ENVIAR</button>
          </div>
          <button type="button" class="btn-lcars secondary" id="btn-jarvis-mic">
            <span>&#127908;</span> FALAR COM O JARVIS (MICROFONE)
          </button>
        </div>
      </div>

      <!-- Aba 4: Configuração & Download APK -->
      <div class="tab-content" id="tab-config">
        <div class="scanner-hero-card" style="text-align:left;">
          <h3 style="color:var(--ja-orange); font-size:14px; margin-bottom:8px;">INFORMAÇÕES DA CONEXÃO</h3>
          <p style="margin-bottom:6px;"><b>URL Base:</b> <span id="cfg-url">https://maurinsoft.com.br/casa</span></p>
          <p style="margin-bottom:12px;"><b>Chave de API:</b> <code id="cfg-token" style="color:var(--ja-amber); font-family:monospace;">csa_...</code></p>
          
          <h3 style="color:var(--ja-cyan); font-size:14px; margin-bottom:8px;">APLICATIVO ANDROID NATIVO</h3>
          <p style="margin-bottom:12px;">Você também pode baixar e instalar o aplicativo APK oficial do Casa Mobile em seu smartphone Android:</p>
          <a href="/casa/bin/android/" class="btn-lcars" style="text-decoration:none;">
            <span>&#128242;</span> BAIXAR APK DO CASA MOBILE
          </a>
        </div>
      </div>

    </section>

  </main>

  <footer class="lcars-footer">
    CASA • JARVIS RESIDENTIAL AI & IoT PLATFORM &copy; MAURINSOFT
  </footer>

  <script>
    // State
    const STORAGE_KEY_TOKEN = 'casa_mobile_token';
    const STORAGE_KEY_URL = 'casa_mobile_url';
    const STORAGE_KEY_NAME = 'casa_mobile_device_name';

    let currentToken = localStorage.getItem(STORAGE_KEY_TOKEN) || '';
    let currentUrl = localStorage.getItem(STORAGE_KEY_URL) || window.location.origin + '/casa';
    let currentDeviceName = localStorage.getItem(STORAGE_KEY_NAME) || 'Casa Mobile';

    let videoStream = null;
    let scanAnimationId = null;

    // Elements
    const screenScanner = document.getElementById('screen-scanner');
    const screenDashboard = document.getElementById('screen-dashboard');
    const video = document.getElementById('video-scanner');
    const canvas = document.getElementById('canvas-scanner');
    const ctx = canvas.getContext('2d');
    const feedback = document.getElementById('scanner-feedback');
    const headerStatusDot = document.getElementById('header-status-dot');
    const headerStatusText = document.getElementById('header-status-text');

    // Inicialização
    window.addEventListener('DOMContentLoaded', () => {
      // Registra Service Worker para PWA
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/casa/sw.js').catch(()=>{});
      }

      if (currentToken) {
        validateAndEnterDashboard(currentToken, currentUrl, currentDeviceName);
      } else {
        startCameraScanner();
      }
    });

    // Iniciar Scanner de Câmera com jsQR
    async function startCameraScanner() {
      stopCameraScanner();
      feedback.className = 'status-msg info';
      feedback.textContent = 'Solicitando acesso à câmera para leitura...';

      try {
        videoStream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' }, width: { ideal: 640 }, height: { ideal: 480 } }
        });
        video.srcObject = videoStream;
        video.setAttribute('playsinline', true);
        await video.play();

        feedback.textContent = 'Aponte a câmera para o QR Code da CASA na tela';
        requestAnimationFrame(tickScanner);
      } catch (err) {
        feedback.className = 'status-msg error';
        feedback.textContent = 'Câmera indisponível (' + (err.message || 'Permissão negada') + '). Use a importação de foto ou digitação manual.';
      }
    }

    function stopCameraScanner() {
      if (scanAnimationId) {
        cancelAnimationFrame(scanAnimationId);
        scanAnimationId = null;
      }
      if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
        videoStream = null;
      }
    }

    // Leitura contínua dos frames do vídeo
    function tickScanner() {
      if (video.readyState === video.HAVE_ENOUGH_DATA) {
        canvas.height = video.videoHeight;
        canvas.width = video.videoWidth;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);

        if (typeof jsQR !== 'undefined') {
          const code = jsQR(imageData.data, imageData.width, imageData.height, {
            inversionAttempts: 'dontInvert'
          });

          if (code && code.data) {
            onQrCodeDetected(code.data);
            return;
          }
        }
      }
      scanAnimationId = requestAnimationFrame(tickScanner);
    }

    // Processamento do payload lido pelo QR Code
    async function onQrCodeDetected(rawText) {
      stopCameraScanner();

      // Feedback háptico no celular
      if (navigator.vibrate) navigator.vibrate([80, 40, 80]);

      feedback.className = 'status-msg success';
      feedback.textContent = 'QR Code detectado! Conectando com a CASA...';

      let targetUrl = currentUrl;
      let targetToken = '';
      let targetName = 'Casa Mobile';

      const trimmed = rawText.trim();
      if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
        try {
          const parsed = JSON.parse(trimmed);
          if (parsed.url) targetUrl = parsed.url;
          if (parsed.token) targetToken = parsed.token;
          if (parsed.name) targetName = parsed.name;
        } catch(e) {}
      } else if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
        try {
          const u = new URL(trimmed);
          const t = u.searchParams.get('token');
          if (t) targetToken = t;
          targetUrl = u.origin + u.pathname;
        } catch(e) {}
      } else {
        targetToken = trimmed;
      }

      if (!targetToken) {
        feedback.className = 'status-msg error';
        feedback.textContent = 'QR Code lido não continha uma chave de API válida.';
        setTimeout(startCameraScanner, 2500);
        return;
      }

      validateAndEnterDashboard(targetToken, targetUrl, targetName);
    }

    // Validação com o Backend da CASA
    async function validateAndEnterDashboard(token, baseUrl, deviceName) {
      feedback.className = 'status-msg info';
      feedback.textContent = 'Validando credenciais com o servidor...';

      try {
        const cleanUrl = baseUrl.replace(/\/+$/, '');
        const res = await fetch(cleanUrl + '/api/v1/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token
          },
          body: JSON.stringify({
            acao: 'qr_login',
            token: token,
            device_name: deviceName || 'Casa Mobile Web'
          })
        });

        const data = await res.json();
        if (res.ok && data.status === 'ok') {
          // Salva credenciais persistentes
          currentToken = token;
          currentUrl = cleanUrl;
          currentDeviceName = data.user?.nome || deviceName || 'Casa Mobile';

          localStorage.setItem(STORAGE_KEY_TOKEN, currentToken);
          localStorage.setItem(STORAGE_KEY_URL, currentUrl);
          localStorage.setItem(STORAGE_KEY_NAME, currentDeviceName);

          enterDashboard();
          return;
        }

        // Fallback: testa /api/v1/status
        const fallbackRes = await fetch(cleanUrl + '/api/v1/status', {
          headers: { 'Authorization': 'Bearer ' + token }
        });
        if (fallbackRes.ok) {
          currentToken = token;
          currentUrl = cleanUrl;
          currentDeviceName = deviceName || 'Casa Mobile';

          localStorage.setItem(STORAGE_KEY_TOKEN, currentToken);
          localStorage.setItem(STORAGE_KEY_URL, currentUrl);
          localStorage.setItem(STORAGE_KEY_NAME, currentDeviceName);

          enterDashboard();
          return;
        }

        throw new Error(data.mensagem || 'Chave de API recusada pelo servidor');
      } catch (err) {
        feedback.className = 'status-msg error';
        feedback.textContent = 'Erro ao validar chave: ' + (err.message || 'Falha de comunicação');
        setTimeout(startCameraScanner, 3000);
      }
    }

    // Exibir Dashboard Autenticado
    function enterDashboard() {
      screenScanner.style.display = 'none';
      screenDashboard.style.display = 'flex';

      headerStatusDot.classList.add('online');
      headerStatusText.textContent = 'CONECTADO';

      document.getElementById('dash-device-name').textContent = currentDeviceName.toUpperCase();
      document.getElementById('cfg-url').textContent = currentUrl;
      document.getElementById('cfg-token').textContent = currentToken.substring(0, 14) + '...';

      loadDevices();
      loadSensors();
    }

    // Desconectar / Trocar Chave
    document.getElementById('btn-logout').onclick = () => {
      localStorage.removeItem(STORAGE_KEY_TOKEN);
      currentToken = '';
      screenDashboard.style.display = 'none';
      screenScanner.style.display = 'flex';
      headerStatusDot.classList.remove('online');
      headerStatusText.textContent = 'OFFLINE';
      startCameraScanner();
    };

    // Navegação entre Abas
    document.querySelectorAll('.dash-tab-btn').forEach(btn => {
      btn.onclick = () => {
        document.querySelectorAll('.dash-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        const targetId = btn.getAttribute('data-tab');
        document.getElementById(targetId)?.classList.add('active');
      };
    });

    // Carregar Dispositivos da CASA
    async function loadDevices() {
      const listEl = document.getElementById('devices-list');
      try {
        const res = await fetch(currentUrl + '/api/v1/dispositivos', {
          headers: { 'Authorization': 'Bearer ' + currentToken }
        });
        const data = await res.json();
        const devices = data.dispositivos || data.dados || [];

        if (!devices.length) {
          listEl.innerHTML = '<div class="status-msg info">Nenhum dispositivo ou relé cadastrado no sistema.</div>';
          return;
        }

        document.getElementById('sensor-count').textContent = devices.length;

        listEl.innerHTML = devices.map(d => {
          const isOn = d.estado == 1 || d.estado === 'on' || d.status === 'ligado';
          return `
            <div class="device-card">
              <div class="device-info">
                <h4>${escapeHtml(d.nome || d.dispositivo || 'Relé')}</h4>
                <span>Tipo: ${escapeHtml(d.tipo || 'IoT')} | Local: ${escapeHtml(d.ambiente || 'Residência')}</span>
              </div>
              <button type="button" class="toggle-btn ${isOn ? 'on' : 'off'}" onclick="toggleDevice(${d.id}, ${isOn ? 0 : 1})">
                ${isOn ? 'LIGADO' : 'DESLIGADO'}
              </button>
            </div>
          `;
        }).join('');
      } catch (err) {
        listEl.innerHTML = '<div class="status-msg error">Falha ao consultar dispositivos da residência.</div>';
      }
    }

    // Acionar Relé / Dispositivo
    window.toggleDevice = async function(id, newState) {
      try {
        await fetch(currentUrl + '/api/v1/dispositivos/acionar', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + currentToken
          },
          body: JSON.stringify({ id: id, estado: newState })
        });
        loadDevices();
      } catch(e) {
        alert('Erro ao acionar dispositivo: ' + e.message);
      }
    };

    // Carregar Sensores
    async function loadSensors() {
      try {
        const res = await fetch(currentUrl + '/api/v1/sensores', {
          headers: { 'Authorization': 'Bearer ' + currentToken }
        });
        const data = await res.json();
        if (data.sistema) document.getElementById('sensor-sys').textContent = data.sistema;
      } catch(e) {}
    }

    // Assistente JARVIS por Texto
    document.getElementById('btn-jarvis-send').onclick = sendJarvisCommand;
    document.getElementById('jarvis-input').onkeydown = (e) => { if (e.key === 'Enter') sendJarvisCommand(); };

    async function sendJarvisCommand() {
      const input = document.getElementById('jarvis-input');
      const text = input.value.trim();
      if (!text) return;

      const output = document.getElementById('jarvis-output');
      output.textContent = 'JARVIS processando comando: "' + text + '"...';
      input.value = '';

      try {
        const res = await fetch(currentUrl + '/api/v1/comando', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + currentToken
          },
          body: JSON.stringify({ comando: text, origem: 'MOBILE_WEB' })
        });
        const data = await res.json();
        const reply = data.resposta || data.mensagem || 'Comando executado com sucesso pela residência.';
        output.textContent = reply;

        // Reprodução de voz opcional no navegador do celular
        if ('speechSynthesis' in window) {
          const utt = new SpeechSynthesisUtterance(reply);
          utt.lang = 'pt-BR';
          window.speechSynthesis.speak(utt);
        }
      } catch(e) {
        output.textContent = 'Erro ao comunicar com o JARVIS: ' + e.message;
      }
    }

    // Microfone Web Speech
    document.getElementById('btn-jarvis-mic').onclick = () => {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SpeechRecognition) {
        alert('Reconhecimento de voz não suportado pelo seu navegador móvel.');
        return;
      }
      const rec = new SpeechRecognition();
      rec.lang = 'pt-BR';
      const output = document.getElementById('jarvis-output');
      output.textContent = 'Ouvindo... Diga seu comando agora.';
      rec.onresult = (e) => {
        const transcript = e.results[0][0].transcript;
        document.getElementById('jarvis-input').value = transcript;
        sendJarvisCommand();
      };
      rec.onerror = () => { output.textContent = 'Falha ao capturar áudio.'; };
      rec.start();
    };

    // Leitura de Imagem do QR Code via Input File
    document.getElementById('file-qr-input').onchange = (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (evt) => {
        const img = new Image();
        img.onload = () => {
          canvas.width = img.width;
          canvas.height = img.height;
          ctx.drawImage(img, 0, 0);
          const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
          if (typeof jsQR !== 'undefined') {
            const code = jsQR(imgData.data, imgData.width, imgData.height);
            if (code && code.data) {
              onQrCodeDetected(code.data);
            } else {
              alert('Nenhum QR Code válido detectado na imagem selecionada.');
            }
          }
        };
        img.src = evt.target.result;
      };
      reader.readAsDataURL(file);
    };

    // Toggle Câmera
    document.getElementById('btn-toggle-camera').onclick = () => {
      startCameraScanner();
    };

    // Digitação Manual
    const manualModal = document.getElementById('manual-modal');
    document.getElementById('btn-manual-token').onclick = () => {
      manualModal.style.display = 'block';
    };
    document.getElementById('btn-cancel-manual').onclick = () => {
      manualModal.style.display = 'none';
    };
    document.getElementById('btn-submit-manual').onclick = () => {
      const val = document.getElementById('manual-token-input').value.trim();
      if (!val) return;
      manualModal.style.display = 'none';
      onQrCodeDetected(val);
    };

    function escapeHtml(str) {
      return String(str).replace(/[&<>"']/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[m]);
    }
  </script>
</body>
</html>
