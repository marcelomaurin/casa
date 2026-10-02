<?php
session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/family_common.php';
require_once __DIR__ . '/api/mobile_pairing.php';
$pdo = get_db_pdo();
family_ensure_schema($pdo);

if (empty($_SESSION['csrf_devices'])) $_SESSION['csrf_devices'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_devices'];
$authLogin = is_array($_SESSION['auth_user']) ? (string)($_SESSION['auth_user']['login'] ?? 'site') : (string)$_SESSION['auth_user'];

// Pareamento do Casa Mobile por QR Code (JSON para o JavaScript desta página).
if (isset($_GET['pair'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $out = function(int $code, array $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
    $op = (string)$_GET['pair'];
    try {
        if ($op === 'status') $out(200, mobile_pair_status($pdo, (int)($_GET['id'] ?? 0)));
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['status' => 'erro', 'mensagem' => 'Método não permitido.']);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $out(403, ['status' => 'erro', 'mensagem' => 'Sessão inválida. Atualize a página.']);
        if ($op === 'create') $out(200, ['status' => 'ok'] + mobile_pair_create($pdo, $authLogin));
        if ($op === 'revoke') {
            $in = json_decode(file_get_contents('php://input'), true);
            $ok = mobile_pair_revoke($pdo, trim((string)($in['device_id'] ?? '')));
            $out($ok ? 200 : 404, $ok ? ['status' => 'ok'] : ['status' => 'erro', 'mensagem' => 'Celular não encontrado.']);
        }
        $out(400, ['status' => 'erro', 'mensagem' => 'Operação desconhecida.']);
    } catch (Throwable $e) {
        error_log('pareamento mobile: ' . $e->getMessage());
        $out(500, ['status' => 'erro', 'mensagem' => 'Falha no pareamento.']);
    }
}
$message = '';
$error = '';

$aba = strtolower(trim((string)($_GET['aba'] ?? $_GET['tab'] ?? 'todos')));
if (!in_array($aba, ['celular', 'mobile', 'watch', 'todos'], true)) {
    $aba = 'todos';
}
if ($aba === 'mobile') $aba = 'celular';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $error = 'Sessão inválida. Atualize a página.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'broadcast') {
                $text = trim($_POST['message'] ?? '');
                if ($text === '') throw new RuntimeException('Informe uma mensagem.');
                $channel = family_channel($pdo, 'familia');
                family_send($pdo, (int)$channel['id'], $authLogin, 'web', 'texto', $text, ['source'=>'dispositivos_pessoais']);
                $message = 'Mensagem enviada ao canal Família CASA.';
            } elseif ($action === 'notify_mobile' || $action === 'notify_watch') {
                $title = trim($_POST['title'] ?? 'JARVIS');
                $text = trim($_POST['message'] ?? '');
                $priority = in_array($_POST['priority'] ?? 'normal', ['normal','alta','critica'], true) ? $_POST['priority'] : 'normal';
                if ($text === '') throw new RuntimeException('Informe uma mensagem.');

                if ($action === 'notify_watch') {
                    try {
                        $pdo->exec("CREATE TABLE IF NOT EXISTS watch_notificacoes (
                          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                          id_dispositivo BIGINT UNSIGNED NULL,
                          titulo VARCHAR(120) NOT NULL DEFAULT 'JARVIS',
                          mensagem TEXT NOT NULL,
                          audio_url TEXT NULL,
                          prioridade VARCHAR(20) NOT NULL DEFAULT 'normal',
                          lida TINYINT(1) NOT NULL DEFAULT 0,
                          entregue TINYINT(1) NOT NULL DEFAULT 0,
                          data_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          data_entrega DATETIME NULL,
                          data_leitura DATETIME NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    } catch (Throwable $e) {}

                    $stmt = $pdo->prepare("INSERT INTO watch_notificacoes(id_dispositivo,titulo,mensagem,prioridade) VALUES(NULL,:t,:m,:p)");
                    $stmt->execute([':t'=>substr($title,0,120),':m'=>$text,':p'=>$priority]);

                    // Enfileira também no command bus para o relógio receber em qualquer canal
                    try {
                        $watchDevs = $pdo->query("SELECT device_id FROM dispositivos_cluster WHERE tipo='watch' OR device_id LIKE 'watch-%'")->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($watchDevs)) {
                            $cmdPayload = json_encode(['title'=>$title, 'text'=>$text, 'priority'=>$priority, 'type'=>'phone_notification'], JSON_UNESCAPED_UNICODE);
                            $cmdPri = $priority === 'critica' ? 'critical' : ($priority === 'alta' ? 'high' : 'normal');
                            foreach ($watchDevs as $wd) {
                                $pdo->prepare("INSERT INTO device_commands(device_id, correlation_id, command, payload, priority, status) VALUES(:d, :c, 'notification', :p, :pri, 'enqueued')")
                                    ->execute([':d'=>$wd, ':c'=>'notif_'.bin2hex(random_bytes(8)), ':p'=>$cmdPayload, ':pri'=>$cmdPri]);
                            }
                        }
                    } catch (Throwable $e) {}

                    $message = 'Notificação enviada com sucesso para o relógio! Aguarde até 5s para vibração.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO mobile_notificacoes(id_dispositivo,titulo,mensagem,prioridade) VALUES(NULL,:t,:m,:p)");
                    $stmt->execute([':t'=>substr($title,0,120),':m'=>$text,':p'=>$priority]);
                    $message = 'Notificação enviada com sucesso para o celular!';
                }
            } elseif ($action === 'ack_assist') {
                $id=(int)($_POST['id'] ?? 0);
                if ($id>0) $pdo->prepare("UPDATE assistencia_eventos SET confirmado=1,confirmado_em=NOW() WHERE id=:id")->execute([':id'=>$id]);
                $message='Evento de assistência confirmado.';
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

$presence=[];$watch=null;$assist=[];$mobileEvents=[];$watchNotifs=[];
try {
    $channel=family_channel($pdo,'familia');
    $stmt=$pdo->prepare("SELECT cliente,dispositivo,plataforma,metadata,ultimo_ping,
        CASE WHEN ultimo_ping>=DATE_SUB(NOW(),INTERVAL 90 SECOND) THEN 1 ELSE 0 END AS online
        FROM family_presence WHERE canal_id=:c ORDER BY ultimo_ping DESC LIMIT 20");
    $stmt->execute([':c'=>$channel['id']]);
    $presence=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){}


$watch = null;
try { $watch = $pdo->query("SELECT * FROM watch_telemetria ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null; } catch(Throwable $e){}
try {
    $wDev = $pdo->query("SELECT * FROM dispositivos_cluster WHERE tipo='watch' OR model LIKE '%Watch%' OR device_id LIKE 'watch%' ORDER BY ultimo_heartbeat DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($wDev) {
        if (!$watch) {
            $m = json_decode($wDev['metadata'] ?? '{}', true) ?: [];
            $watch = [
                'cliente' => $wDev['nome'],
                'bateria_pct' => $wDev['battery_pct'],
                'passos' => $m['steps'] ?? 0,
                'rssi_ble' => null,
                'rssi_wifi' => $wDev['sinal_rssi'],
                'wifi_ssid' => $m['wifi_ssid'] ?? null,
                'transporte' => $wDev['transport'] ?: 'wifi',
                'minutos_sem_movimento' => 0,
                'modo_energia' => $m['power_mode'] ?? 'NORMAL',
                'alerta_ativo' => 0,
                'dados' => $wDev['metadata'],
                'data_hora' => $wDev['ultimo_heartbeat']
            ];
        } elseif (empty($watch['bateria_pct']) && !empty($wDev['battery_pct'])) {
            $watch['bateria_pct'] = $wDev['battery_pct'];
        }
    }
} catch(Throwable $e){}

$mobile = null;
try { $mobile = $pdo->query("SELECT * FROM mobile_telemetria ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null; } catch(Throwable $e){}
try {
    $mPres = $pdo->query("SELECT * FROM family_presence WHERE plataforma='mobile' ORDER BY ultimo_ping DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($mPres) {
        $meta = json_decode($mPres['metadata'] ?? '{}', true) ?: [];
        if (!$mobile || (strtotime($mPres['ultimo_ping']) > strtotime($mobile['data_hora'] ?? '2000-01-01'))) {
            $mobile = [
                'cliente' => $mPres['cliente'],
                'dispositivo' => $mPres['dispositivo'] ?: 'Casa Mobile',
                'modelo' => $meta['model'] ?? ($meta['modelo'] ?? ($mPres['dispositivo'] ?: 'Android')),
                'fabricante' => $meta['manufacturer'] ?? ($meta['fabricante'] ?? null),
                'bateria_pct' => isset($meta['battery']) ? (int)$meta['battery'] : ($mobile['bateria_pct'] ?? null),
                'carregando' => !empty($meta['charging']) ? 1 : ($mobile['carregando'] ?? 0),
                'tipo_rede' => $meta['network_type'] ?? (!empty($meta['wifi']) ? 'Wi-Fi' : 'Celular'),
                'wifi_ssid' => $meta['wifi_ssid'] ?? ($meta['ssid'] ?? ($mobile['wifi_ssid'] ?? null)),
                'rssi_wifi' => $meta['rssi'] ?? ($mobile['rssi_wifi'] ?? null),
                'ip_local' => $meta['local_ip'] ?? ($mobile['ip_local'] ?? null),
                'ip_publico' => $mobile['ip_publico'] ?? null,
                'watch_online_count' => $meta['watch_online_count'] ?? 0,
                'dados' => $mPres['metadata'],
                'data_hora' => $mPres['ultimo_ping']
            ];
        }
    }
} catch(Throwable $e){}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM assistencia_eventos")->fetchAll(PDO::FETCH_COLUMN);
    $pCol = in_array('pessoa_ref', $cols, true) ? 'pessoa_ref' : (in_array('pessoa', $cols, true) ? 'pessoa' : "''");
    $dCol = in_array('dispositivo_ref', $cols, true) ? 'dispositivo_ref' : (in_array('dispositivo', $cols, true) ? 'dispositivo' : "''");
    $assist = $pdo->query("SELECT id, tipo, severidade, mensagem, {$pCol} AS pessoa, {$dCol} AS dispositivo, confirmado, criado_em, confirmado_em FROM assistencia_eventos ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$phones=[];
try { $phones = mobile_pair_list($pdo); } catch(Throwable $e){}
try { $mobileEvents=$pdo->query("SELECT tipo,descricao,dados,data_hora FROM mobile_eventos ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
try { $watchNotifs=$pdo->query("SELECT id,titulo,mensagem,prioridade,entregue,lida,data_hora,data_entrega FROM watch_notificacoes ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}

function h($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function ageText($date){ if(!$date)return '--'; $s=time()-strtotime($date); if($s<60)return $s.'s'; if($s<3600)return floor($s/60).' min'; return floor($s/3600).' h'; }

?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CASA — <?= $aba==='celular'?'Celular':($aba==='watch'?'Watch':'Celular & Relógio') ?></title>
  <link rel="stylesheet" href="/casa/lcars.css?v=20260914b">
  <style>
    body{background:#fff7e8;color:#211b22;font-family:Arial,sans-serif;margin:0}
    .wrap{max-width:1180px;margin:auto;padding:18px}
    .head{display:flex;gap:12px;align-items:stretch}
    .elbow{background:<?= $aba==='watch'?'#a855f7':'#f59c73' ?>;border-radius:28px 0 0 28px;min-width:140px;padding:24px;font-weight:900;color:<?= $aba==='watch'?'#fff':'#211b22' ?>}
    .title{background:<?= $aba==='watch'?'#e9d5ff':'#d8b4ea' ?>;flex:1;padding:18px 24px;border-radius:0 24px 24px 0}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-top:16px}
    .card{background:#fff;border:3px solid #d8b4ea;border-radius:20px;padding:16px}
    .card.orange{border-color:#f59c73}
    .card.blue{border-color:#8eb9ee}
    .card.green{border-color:#8ccf9c}
    .card.purple{border-color:#a855f7}
    .pill{display:inline-block;padding:5px 10px;border-radius:999px;font-weight:800}
    .ok{background:#9bd6a4}
    .off{background:#ef9b92}
    .warn{background:#ffd27a}
    .crit{background:#ef4444;color:#fff}
    table{width:100%;border-collapse:collapse}
    td,th{padding:8px;border-bottom:1px solid #ddd;text-align:left}
    input,textarea,select,button{font:inherit;padding:10px;border-radius:10px;border:1px solid #777;box-sizing:border-box}
    input,textarea,select{width:100%}
    button{background:#f59c73;font-weight:800;cursor:pointer}
    .msg{padding:10px;border-radius:10px;margin-top:12px;background:#dff3df}
    .err{background:#ffd9d3}
    .actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
    .actions a{padding:10px 14px;background:#8eb9ee;border-radius:12px;color:#211b22;text-decoration:none;font-weight:800;transition:0.2s}
    .actions a.active{background:#f59c73;color:#000;box-shadow:0 0 0 2px #211b22}
    .qr-box{background:#fff;padding:10px;border-radius:12px;width:fit-content;margin:10px auto;border:2px solid #ddd}
    .pair-steps{margin:6px 0 10px;padding-left:20px;font-size:.92rem}.pair-steps li{margin:3px 0}
    .pair-status{text-align:center;font-weight:800;padding:8px;border-radius:10px;margin-top:8px}
    .pair-status.waiting{background:#fff3c4}.pair-status.paired{background:#c8f0cf}.pair-status.expired{background:#ffd9d3}
    .small{font-size:.82rem;color:#5b5560}

    .gauge-bar{height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin:4px 0 8px}
    .gauge-fill{height:100%;transition:width 0.4s ease}
    .gauge-green{background:#10b981}
    .gauge-yellow{background:#f59e0b}
    .gauge-red{background:#ef4444}
    .pulse-dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#10b981;margin-right:6px;box-shadow:0 0 0 0 rgba(16,185,129,0.7);animation:pulse 2s infinite}
    @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(16,185,129,0.7)}70%{box-shadow:0 0 0 8px rgba(16,185,129,0)}100%{box-shadow:0 0 0 0 rgba(16,185,129,0)}}
    .pulse-dot.off{background:#9ca3af;animation:none;box-shadow:none}
    .stat-val{font-size:1.4rem;font-weight:900;color:#1e1b4b}

  </style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <div class="elbow">CASA<br><?= $aba==='watch'?'WATCH':($aba==='celular'?'MOBILE':'LINK') ?></div>
    <div class="title">
      <h1><?= $aba==='celular'?'JARVIS Mobile · Celular':($aba==='watch'?'JARVIS Watch · Relógio Inteligente':'Celular & Relógio') ?></h1>
      <div><?= $aba==='celular'?'Controle pessoal e monitoramento de presença móvel':($aba==='watch'?'LilyGo T-Watch, telemetria biométrica, assistência SOS e comandos':'Gateway, telemetria, assistência e Família CASA') ?></div>
    </div>
  </div>

  <div class="actions">
    <a href="/casa/index.php" target="_top">Dashboard</a>
    <a href="/casa/dispositivos_pessoais.php?aba=celular" class="<?= $aba==='celular'?'active':'' ?>">📱 Celular</a>
    <a href="/casa/dispositivos_pessoais.php?aba=watch" class="<?= $aba==='watch'?'active':'' ?>">⌚ Watch</a>
    <a href="/casa/dispositivos_pessoais.php?aba=todos" class="<?= $aba==='todos'?'active':'' ?>">Todos</a>
    <a href="/casa/familia.php">Família CASA</a>

    <a href="/casa/bin/">Downloads & APKs (/bin)</a>
  </div>

  <?php if($message):?><div class="msg"><?=h($message)?></div><?php endif;?>
  <?php if($error):?><div class="msg err"><?=h($error)?></div><?php endif;?>

  <div class="grid">
    <?php if($aba==='celular' || $aba==='todos'): ?>
      <!-- Presença Celulares -->
      <div class="card green">
        <h2>📱 Celulares presentes</h2>
        <?php 
          $celPresence = array_filter($presence, function($p){
              $plat = strtolower($p['plataforma'] ?? '');
              return in_array($plat, ['mobile','android','ios','web','pwa']) || $plat === '';
          });
        ?>
        <?php if(!$celPresence):?>
          <p>Nenhum celular registrou presença recentemente.<br>Aguardando comunicação dos dispositivos móveis.</p>
        <?php else:?>
          <table>
            <tr><th>Dispositivo</th><th>Tipo</th><th>Estado</th><th>Último contato</th></tr>
            <?php foreach($celPresence as $p):?>
              <tr>
                <td><b><?=h($p['dispositivo'] ?: $p['cliente'])?></b></td>
                <td><?=h(strtoupper($p['plataforma'] ?: 'MOBILE'))?></td>
                <td><span class="pill <?=$p['online']?'ok':'off'?>"><?=$p['online']?'ONLINE':'OFFLINE'?></span></td>
                <td><?=h(ageText($p['ultimo_ping']))?></td>
              </tr>
            <?php endforeach;?>
          </table>
        <?php endif;?>
      </div>

      <!-- Pareamento do Casa Mobile -->
      <div class="card orange">
        <h2>📷 Parear celular (Casa Mobile)</h2>
        <ol class="pair-steps">
          <li>Instale o app <b>Casa Mobile</b> (<a href="/casa/bin/android/" target="_top">baixar APK</a>).</li>
          <li>Clique em <b>Gerar QR Code</b> abaixo.</li>
          <li>No app, toque em <b>LER QR CODE DA CASA</b> e aponte para a tela.</li>
        </ol>
        <button type="button" id="pair-new" style="width:100%">Gerar QR Code de acesso</button>
        <div id="pair-area" style="display:none">
          <div class="qr-box"><div id="pair-qr"></div></div>
          <div id="pair-status" class="pair-status waiting">Aguardando leitura pelo celular…</div>
          <p class="small" style="text-align:center">Uso único · válido por 10 minutos. Não compartilhe este QR Code.</p>
        </div>
      </div>

      <!-- Celulares pareados -->
      <div class="card blue">
        <h2>🔑 Celulares pareados</h2>
        <?php if(!$phones):?>
          <p>Nenhum celular pareado com credencial própria ainda.</p>
          <?php if(!empty($celPresence)):?>
            <p class="small" style="color:#b45309;margin-top:6px;">💡 Há celulares comunicando via presença de rede ao lado. Para controle total e credenciais individuais exclusivas, gere o QR Code de acesso acima.</p>
          <?php endif;?>
        <?php else:?>
          <table>
            <tr><th>Celular</th><th style="text-align:center">Estado</th><th></th></tr>
            <?php foreach($phones as $ph):
              $rev = !empty($ph['credential_revoked_at']);
              $isOnline = !empty($ph['online']) || (!empty($ph['ultimo_heartbeat']) && strtotime($ph['ultimo_heartbeat']) >= (time() - 120));
            ?>
              <tr>
                <td><b><?=h($ph['nome'])?></b><?php if(!empty($ph['model'])):?> <span class="small">· <?=h($ph['model'])?></span><?php endif;?>
                  <br><span class="small">Registrado <?=h($ph['criado_em'])?><?php if(!empty($ph['metadata']['paired_by'])):?> por <?=h($ph['metadata']['paired_by'])?><?php endif;?></span>
                  <br><span class="small">Último contato: <?=h($ph['ultimo_uso'] ?: ($ph['ultimo_heartbeat'] ?: '--'))?> (<?=h(ageText($ph['ultimo_heartbeat'] ?: $ph['ultimo_uso']))?>)</span></td>
                <td style="text-align:center">
                  <?php if($rev):?>
                    <span class="pill off">REVOGADO</span>
                  <?php elseif($isOnline):?>
                    <span class="pill ok">ONLINE</span>
                  <?php else:?>
                    <span class="pill off">OFFLINE</span>
                  <?php endif;?>
                </td>
                <td style="text-align:right">
                  <?php if(!$rev):?>
                    <button type="button" class="pair-revoke" data-device="<?=h($ph['device_id'])?>" data-name="<?=h($ph['nome'])?>" style="padding:5px 9px;font-size:.8rem;background:#ef9b92">Revogar</button>
                  <?php endif;?>
                </td>
              </tr>
            <?php endforeach;?>
          </table>
        <?php endif;?>
      </div>
    </div>

    
      <!-- Telemetria Celular -->
      <div class="card green" id="card-telemetria-celular">
        <h2><span class="pulse-dot <?= (!empty($mobile['data_hora']) && (time()-strtotime($mobile['data_hora'])<=90)) ? '' : 'off' ?>" id="cel-pulse"></span>📱 Telemetria Celular (Casa Mobile)</h2>
        <?php if(!$mobile):?>
          <p id="cel-waiting">Aguardando telemetria do celular via aplicativo <b>Casa Mobile</b>.</p>
        <?php else:
          $mbPct = isset($mobile['bateria_pct']) ? max(0, min(100, (int)$mobile['bateria_pct'])) : null;
          $mbClass = ($mbPct !== null && $mbPct >= 50) ? 'gauge-green' : (($mbPct !== null && $mbPct >= 20) ? 'gauge-yellow' : 'gauge-red');
          $mbOnline = !empty($mobile['data_hora']) && (time() - strtotime($mobile['data_hora']) <= 90);
        ?>
          <div id="cel-content">
            <table style="margin-top:6px;">
              <tr>
                <td style="width:40%;">Bateria</td>
                <td>
                  <?php if($mbPct !== null): ?>
                    <span class="stat-val" id="cel-batt-val"><?=h($mbPct)?>%</span>
                    <?= !empty($mobile['carregando']) ? ' <span title="Carregando na tomada">⚡ Carregando</span>' : '' ?>
                    <div class="gauge-bar"><div id="cel-batt-bar" class="gauge-fill <?=$mbClass?>" style="width:<?=$mbPct?>%;"></div></div>
                  <?php else: ?>
                    <span id="cel-batt-val">--</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <td>Conexão / Rede</td>
                <td id="cel-net-val">
                  <b><?=h(strtoupper($mobile['tipo_rede'] ?: 'Wi-Fi'))?></b>
                  <?php if(!empty($mobile['wifi_ssid'])): ?>
                    · <?=h($mobile['wifi_ssid'])?>
                  <?php endif; ?>
                  <?php if(isset($mobile['rssi_wifi']) && $mobile['rssi_wifi'] !== null): ?>
                    <span class="small">(<?=h($mobile['rssi_wifi'])?> dBm)</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php if(!empty($mobile['ip_local'])): ?>
                <tr><td>IP Local</td><td id="cel-ip-val"><code><?=h($mobile['ip_local'])?></code></td></tr>
              <?php endif; ?>
              <tr>
                <td>Aparelho</td>
                <td id="cel-device-val">
                  <b><?=h($mobile['modelo'] ?: ($mobile['dispositivo'] ?: 'Casa Mobile'))?></b>
                  <?php if(!empty($mobile['fabricante'])): ?> <span class="small">(<?=h($mobile['fabricante'])?>)</span><?php endif; ?>
                </td>
              </tr>
              <?php if(isset($mobile['watch_online_count'])): ?>
                <tr><td>Watches Pareados</td><td id="cel-watches-val"><?= (int)$mobile['watch_online_count'] ?> relógio(s) conectado(s) via app</td></tr>
              <?php endif; ?>
              <tr>
                <td>Status do Link</td>
                <td id="cel-status-val">
                  <span class="pill <?= $mbOnline ? 'ok' : 'off' ?>"><?= $mbOnline ? 'ONLINE' : 'OFFLINE' ?></span>
                  <span class="small" style="margin-left:6px;">Último ping: <?=h(ageText($mobile['data_hora']))?> atrás</span>
                </td>
              </tr>
            </table>
          </div>
        <?php endif;?>
      </div>

    <!-- Notificar Celular -->
      <div class="card">
        <h2>🔔 Notificar Celular</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?=h($csrf)?>">
          <input name="title" value="CASA / JARVIS" placeholder="Título">
          <textarea name="message" rows="3" placeholder="Mensagem para exibir no celular..."></textarea>
          <select name="priority">
            <option value="normal">Normal</option>
            <option value="alta">Alta</option>
            <option value="critica">Crítica</option>
          </select>
          <button name="action" value="notify_mobile">Enviar para o Celular</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if($aba==='watch' || $aba==='todos'): ?>
      <!-- Pareamento do JARVIS Watch -->
      <div class="card orange">
        <h2>⌚ Parear Relógio (JARVIS Watch)</h2>
        <ol class="pair-steps">
          <li><b>No relógio:</b> Quando não configurado, deslize para cima (swipe up) na tela inicial para exibir o <b>QR Code do relógio</b> (ou em <i>CONFIG › CEL</i>).</li>
          <li><b>No app Casa Mobile:</b> Abra <i>Configuração › Novos Devices › Watch</i> e toque em <b>📷 QR RELÓGIO</b>.</li>
          <li><b>Conexão Direta:</b> O app mostrará as etapas da conexão em tempo real (1 a 5) até a sincronização.</li>
          <li>Alternativamente, toque em <b>Gerar QR Code de acesso</b> abaixo e leia com <b>📷 QR DO SITE</b> no app para transferir as chaves.</li>
        </ol>
        <button type="button" id="pair-watch-new" style="width:100%">Gerar QR Code de acesso para o Relógio</button>
        <div id="pair-watch-area" style="display:none">
          <div class="qr-box"><div id="pair-watch-qr"></div></div>
          <div id="pair-watch-status" class="pair-status waiting">Aguardando leitura pelo app Casa Mobile…</div>
          <p class="small" style="text-align:center">Uso único · válido por 10 minutos.</p>
        </div>
      </div>
      <!-- Telemetria Watch -->
      <div class="card blue">
        <h2>⌚ Telemetria Watch</h2>
        <?php if(!$watch):?>
          <p>Aguardando telemetria do relógio via <code>/api/v1/watch.php</code>.</p>
        <?php else:?>
          <table>
            <tr><td>Bateria</td><td><b><?=h($watch['bateria_pct'])?>%</b></td></tr>
            <tr><td>Passos</td><td><?=h($watch['passos'])?></td></tr>
            <tr><td>Transporte</td><td><?=h($watch['transporte'])?></td></tr>
            <tr><td>Wi-Fi</td><td><?=h($watch['wifi_ssid'])?></td></tr>
            <tr><td>RSSI BLE/Wi-Fi</td><td><?=h($watch['rssi_ble'])?> / <?=h($watch['rssi_wifi'])?></td></tr>
            <tr><td>Sem movimento</td><td><?=h($watch['minutos_sem_movimento'])?> min</td></tr>
            <tr><td>Energia</td><td><span class="pill ok"><?=h(strtoupper($watch['modo_energia']?:'NORMAL'))?></span></td></tr>
            <tr><td>Atualização</td><td><?=h($watch['data_hora'])?></td></tr>
          </table>
        <?php endif;?>
      </div>

      <!-- Notificar Watch -->
      <div class="card purple">
        <h2>🔔 Alerta para o Relógio (JARVIS Watch)</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?=h($csrf)?>">
          <input name="title" value="CASA / WATCH" placeholder="Título">
          <textarea name="message" rows="3" placeholder="Mensagem para exibir no visor do relógio..."></textarea>
          <select name="priority">
            <option value="normal">Normal (1 vibração)</option>
            <option value="alta">Alta (2 vibrações)</option>
            <option value="critica">Crítica (SOS urgente)</option>
          </select>
          <button name="action" value="notify_watch" style="background:#a855f7;color:#fff;width:100%;margin-top:6px;">Enviar para o Relógio</button>
        </form>

        <?php if(!empty($watchNotifs)): ?>
          <h3 style="font-size:0.95rem;margin-top:14px;border-top:1px solid #e9d5ff;padding-top:10px;">Status das notificações enviadas:</h3>
          <table style="font-size:0.85rem;">
            <tr><th>Mensagem</th><th style="text-align:center">Entrega</th></tr>
            <?php foreach($watchNotifs as $wn): ?>
              <tr>
                <td><b><?=h($wn['titulo'])?></b>: <?=h($wn['mensagem'])?>
                  <br><span class="small"><?=h($wn['data_hora'])?> · <?=h($wn['prioridade'])?></span></td>
                <td style="text-align:center">
                  <?php if($wn['entregue']): ?>
                    <span class="pill ok" title="Entregue em <?=h($wn['data_entrega'])?>">✓ ENTREGUE</span>
                  <?php else: ?>
                    <span class="pill warn" title="Aguardando relógio consultar a API">⏳ PENDENTE</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if($aba==='watch' || $aba==='todos'): ?>
    <!-- Assistência / SOS -->
    <div class="card orange" style="margin-top:16px">
      <h2>🚨 Assistência & SOS</h2>
      <?php if(!$assist):?><p>Sem eventos de emergência.</p><?php else:?>
        <table>
          <tr><th>Data</th><th>Tipo</th><th>Severidade</th><th>Dispositivo</th><th>Mensagem</th><th>Ação</th></tr>
          <?php foreach(array_slice($assist, 0, 8) as $a):?>
            <tr>
              <td><?=h($a['criado_em'])?></td>
              <td><?=h($a['tipo'])?></td>
              <td><span class="pill <?=strtolower($a['severidade'])==='critica'?'off':'warn'?>"><?=h($a['severidade'])?></span></td>
              <td><?=h($a['dispositivo'])?></td>
              <td><?=h($a['mensagem'])?></td>
              <td>
                <?php if(!$a['confirmado']):?>
                  <form method="post" style="margin:0;"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="ack_assist"><input type="hidden" name="id" value="<?=h($a['id'])?>"><button style="padding:4px 8px;font-size:0.8rem;">Confirmar</button></form>
                <?php else:?>
                  <span style="color:#059669;font-weight:bold;">✓ Visto</span>
                <?php endif;?>
              </td>
            </tr>
          <?php endforeach;?>
        </table>
      <?php endif;?>
    </div>
  <?php endif; ?>

  <?php if($aba==='celular' || $aba==='todos'): ?>
    <!-- Eventos do Celular -->
    <div class="card blue" style="margin-top:16px">
      <h2>📱 Eventos recentes do celular / gateway</h2>
      <?php if(!$mobileEvents):?><p>Sem eventos recentes.</p><?php else:?>
        <table>
          <tr><th style="width:180px;">Data</th><th style="width:160px;">Tipo</th><th>Descrição</th></tr>
          <?php foreach($mobileEvents as $e):?>
            <tr>
              <td><?=h($e['data_hora'])?></td>
              <td><b><?=h($e['tipo'])?></b></td>
              <td><?=h($e['descricao'])?></td>
            </tr>
          <?php endforeach;?>
        </table>
      <?php endif;?>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  const CSRF = <?= json_encode($csrf) ?>;
  const btn = document.getElementById('pair-new');
  if (!btn) return;
  const area = document.getElementById('pair-area'), qr = document.getElementById('pair-qr'), st = document.getElementById('pair-status');
  let timer = null, countdown = null;
  function setStatus(cls, text){ st.className = 'pair-status ' + cls; st.textContent = text; }
  function stop(){ clearInterval(timer); clearInterval(countdown); timer = countdown = null; }
  async function post(op, body){
    const r = await fetch('?pair=' + op, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify(body||{})});
    const j = await r.json().catch(()=>({}));
    if (!r.ok || j.status === 'erro') throw new Error(j.mensagem || ('HTTP ' + r.status));
    return j;
  }
  btn.onclick = async function(){
    stop(); btn.disabled = true; btn.textContent = 'Gerando…';
    try {
      const t = await post('create');
      qr.innerHTML = '';
      new QRCode(qr, {text: t.qr, width: 230, height: 230, colorDark:'#000000', colorLight:'#ffffff', correctLevel: QRCode.CorrectLevel.M});
      area.style.display = '';
      let left = t.ttl;
      const tick = () => { const m = Math.floor(left/60), s = String(left%60).padStart(2,'0'); setStatus('waiting', 'Aguardando leitura pelo celular… expira em ' + m + ':' + s); left--; if (left < 0) { stop(); qr.innerHTML=''; setStatus('expired', 'QR Code expirado. Gere um novo.'); } };
      tick(); countdown = setInterval(tick, 1000);
      timer = setInterval(async () => {
        try {
          const r = await fetch('?pair=status&id=' + encodeURIComponent(t.id), {cache:'no-store'});
          const j = await r.json();
          if (j.status === 'paired') { stop(); qr.innerHTML = ''; setStatus('paired', '✓ Celular pareado: ' + (j.device_nome || j.device_id) + '. Atualizando lista…'); setTimeout(()=>location.reload(), 2500); }
          else if (j.status === 'expired') { stop(); qr.innerHTML=''; setStatus('expired', 'QR Code expirado. Gere um novo.'); }
        } catch(e) {}
      }, 2500);
    } catch(e) {
      area.style.display = ''; qr.innerHTML = ''; setStatus('expired', 'Falha ao gerar o QR Code: ' + e.message);
    } finally { btn.disabled = false; btn.textContent = 'Gerar novo QR Code'; }
  };
  
  const btnWatch = document.getElementById('pair-watch-new');
  if (btnWatch) {
    const areaW = document.getElementById('pair-watch-area'), qrW = document.getElementById('pair-watch-qr'), stW = document.getElementById('pair-watch-status');
    let timerW = null, countdownW = null;
    function setStatusW(cls, text){ stW.className = 'pair-status ' + cls; stW.textContent = text; }
    function stopW(){ clearInterval(timerW); clearInterval(countdownW); timerW = countdownW = null; }
    btnWatch.onclick = async function(){
      stopW(); btnWatch.disabled = true; btnWatch.textContent = 'Gerando…';
      try {
        const t = await post('create');
        qrW.innerHTML = '';
        new QRCode(qrW, {text: t.qr, width: 230, height: 230, colorDark:'#000000', colorLight:'#ffffff', correctLevel: QRCode.CorrectLevel.M});
        areaW.style.display = '';
        let left = t.ttl;
        const tick = () => { const m = Math.floor(left/60), s = String(left%60).padStart(2,'0'); setStatusW('waiting', 'Aguardando leitura pelo app… expira em ' + m + ':' + s); left--; if (left < 0) { stopW(); qrW.innerHTML=''; setStatusW('expired', 'QR Code expirado. Gere um novo.'); } };
        tick(); countdownW = setInterval(tick, 1000);
        timerW = setInterval(async () => {
          try {
            const r = await fetch('?pair=status&id=' + encodeURIComponent(t.id), {cache:'no-store'});
            const j = await r.json();
            if (j.status === 'paired') { stopW(); qrW.innerHTML = ''; setStatusW('paired', '✓ Pareado com sucesso! Atualizando…'); setTimeout(()=>location.reload(), 2500); }
            else if (j.status === 'expired') { stopW(); qrW.innerHTML=''; setStatusW('expired', 'QR Code expirado. Gere um novo.'); }
          } catch(e) {}
        }, 2500);
      } catch(e) {
        areaW.style.display = ''; qrW.innerHTML = ''; setStatusW('expired', 'Falha: ' + e.message);
      } finally { btnWatch.disabled = false; btnWatch.textContent = 'Gerar novo QR Code'; }
    };
  }

  document.querySelectorAll('.pair-revoke').forEach(b => b.onclick = async () => {
    if (!confirm('Revogar o acesso de "' + b.dataset.name + '"? O app nesse celular deixará de funcionar até ser pareado de novo.')) return;
    b.disabled = true;
    try { await post('revoke', {device_id: b.dataset.device}); location.reload(); }
    catch(e) { alert(e.message); b.disabled = false; }
  });
})();
</script>
</body>
</html>
