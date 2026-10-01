<?php
// CASA • JARVIS Residential AI Platform - Portal de Binários e Instaladores
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>CASA • Central de Binários & Instaladores</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/x-icon" href="/casa/favicon.ico">
  <style>
    :root {
      --bg: #03080e;
      --surface: #07121b;
      --card: #0a1b28;
      --orange: #ff9900;
      --amber: #f2a000;
      --blue: #3f82ad;
      --cyan: #00e5ff;
      --text: #e0f2fe;
      --muted: #8fc7ef;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    body { background: var(--bg); color: var(--text); padding: 16px; min-height: 100vh; }
    .header {
      background: var(--surface);
      border: 2px solid var(--orange);
      border-radius: 18px;
      padding: 16px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      flex-wrap: wrap;
      gap: 12px;
    }
    .header h1 { font-size: 18px; color: #fff; display: flex; align-items: center; gap: 10px; }
    .header .badge { background: var(--orange); color: #000; font-size: 11px; font-weight: 900; padding: 4px 10px; border-radius: 10px; }
    .header a { color: var(--muted); text-decoration: none; font-size: 12px; font-weight: 700; border: 1px solid var(--blue); padding: 6px 12px; border-radius: 10px; }
    .header a:hover { background: var(--blue); color: #fff; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; }
    .card {
      background: var(--surface);
      border: 2px solid var(--blue);
      border-radius: 16px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 12px;
    }
    .card h3 { font-size: 15px; color: var(--amber); margin-bottom: 4px; }
    .card p { font-size: 12px; color: var(--muted); line-height: 1.4; }
    .btn {
      background: var(--orange);
      color: #000;
      border: 0;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 12px;
      font-weight: 800;
      text-align: center;
      text-decoration: none;
      display: block;
      margin-top: 8px;
    }
    .btn:hover { filter: brightness(1.1); }
    .btn.sec { background: #162a38; color: var(--muted); border: 1px solid var(--blue); }
    .btn.sec:hover { background: var(--blue); color: #fff; }
    .files-list { list-style: none; margin-top: 8px; font-size: 12px; }
    .files-list li { padding: 4px 0; border-bottom: 1px solid #14232f; display: flex; justify-content: space-between; }
    .files-list a { color: var(--cyan); text-decoration: none; }
  </style>
</head>
<body>
  <div class="header">
    <h1><span class="badge">CASA</span> REPOSITÓRIO DE BINÁRIOS & INSTALADORES (/bin)</h1>
    <a href="/casa/">PAINEL PRINCIPAL &rarr;</a>
  </div>

  <div class="grid">
    <div class="card">
      <div>
        <h3>&#128241; Android Mobile (Casa Mobile)</h3>
        <p>Aplicativo móvel nativo para smartphones Android com leitor de QR Code integrado.</p>
        <ul class="files-list">
          <?php
            $files = glob(__DIR__ . '/android/*.apk');
            if ($files) {
              foreach ($files as $f) {
                $bn = basename($f);
                echo '<li><a href="android/' . urlencode($bn) . '" download>' . htmlspecialchars($bn) . '</a> <span>' . round(filesize($f)/1048576, 1) . ' MB</span></li>';
              }
            } else {
              echo '<li style="color:#8fc7ef;">Compilações via GitHub Actions (v2.7.0+)</li>';
            }
          ?>
        </ul>
      </div>
      <div>
        <a href="/casa/mobile.php" class="btn">&#128242; ABRIR APP WEB (PWA)</a>
        <a href="android/README.md" class="btn sec">VER INSTRUÇÕES DE INSTALAÇÃO</a>
      </div>
    </div>

    <div class="card">
      <div>
        <h3>&#128250; Android TV (Jarvis TV)</h3>
        <p>Aplicativo para Smart TVs e TV Box com controle remoto e comandos de automação residencial.</p>
        <ul class="files-list">
          <?php
            $files_tv = glob(__DIR__ . '/android-tv/*.apk');
            if ($files_tv) {
              foreach ($files_tv as $f) {
                $bn = basename($f);
                echo '<li><a href="android-tv/' . urlencode($bn) . '" download>' . htmlspecialchars($bn) . '</a> <span>' . round(filesize($f)/1048576, 1) . ' MB</span></li>';
              }
            } else {
              echo '<li style="color:#8fc7ef;">Compilações via GitHub Actions (v1.0.0+)</li>';
            }
          ?>
        </ul>
      </div>
      <div>
        <a href="android-tv/README.md" class="btn sec">VER DETALHES</a>
      </div>
    </div>

    <div class="card">
      <div>
        <h3>&#9201; Firmware IoT & Relógio</h3>
        <p>Firmwares compilados para LILYGO T-Watch, ESP32-CAM e módulos de automação residencial.</p>
      </div>
      <div>
        <a href="firmware/README.md" class="btn sec">VER DETALHES</a>
      </div>
    </div>

    <div class="card">
      <div>
        <h3>&#128039; Linux</h3>
        <p>Serviços de background, daemons de controle e agentes para servidores Linux.</p>
      </div>
      <div>
        <a href="linux/README.md" class="btn sec">VER DETALHES</a>
      </div>
    </div>

    <div class="card">
      <div>
        <h3>&#128421; Windows</h3>
        <p>Utilitários e clientes de desktop para sistemas operacionais Windows.</p>
      </div>
      <div>
        <a href="windows/README.md" class="btn sec">VER DETALHES</a>
      </div>
    </div>
  </div>
</body>
</html>
