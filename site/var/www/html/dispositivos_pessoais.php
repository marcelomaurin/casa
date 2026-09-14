<?php
session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/family_common.php';
$pdo = get_db_pdo();
family_ensure_schema($pdo);

if (empty($_SESSION['csrf_devices'])) $_SESSION['csrf_devices'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_devices'];
$message = '';
$error = '';

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
                family_send($pdo, (int)$channel['id'], $_SESSION['auth_user']['login'] ?? 'site', 'web', 'texto', $text, ['source'=>'dispositivos_pessoais']);
                $message = 'Mensagem enviada ao canal Família CASA.';
            } elseif ($action === 'notify_mobile' || $action === 'notify_watch') {
                $title = trim($_POST['title'] ?? 'JARVIS');
                $text = trim($_POST['message'] ?? '');
                $priority = in_array($_POST['priority'] ?? 'normal', ['normal','alta','critica'], true) ? $_POST['priority'] : 'normal';
                if ($text === '') throw new RuntimeException('Informe uma mensagem.');
                $table = $action === 'notify_watch' ? 'watch_notificacoes' : 'mobile_notificacoes';
                $stmt = $pdo->prepare("INSERT INTO {$table}(id_dispositivo,titulo,mensagem,prioridade) VALUES(NULL,:t,:m,:p)");
                $stmt->execute([':t'=>substr($title,0,120),':m'=>$text,':p'=>$priority]);
                $message = $action === 'notify_watch' ? 'Notificação enviada ao relógio.' : 'Notificação enviada ao celular.';
            } elseif ($action === 'ack_assist') {
                $id=(int)($_POST['id'] ?? 0);
                if ($id>0) $pdo->prepare("UPDATE assistencia_eventos SET confirmado=1,confirmado_em=NOW() WHERE id=:id")->execute([':id'=>$id]);
                $message='Evento de assistência confirmado.';
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

$presence=[];$watch=null;$assist=[];$mobileEvents=[];
try {
    $channel=family_channel($pdo,'familia');
    $stmt=$pdo->prepare("SELECT cliente,dispositivo,plataforma,metadata,ultimo_ping,
        CASE WHEN ultimo_ping>=DATE_SUB(NOW(),INTERVAL 90 SECOND) THEN 1 ELSE 0 END AS online
        FROM family_presence WHERE canal_id=:c ORDER BY ultimo_ping DESC LIMIT 20");
    $stmt->execute([':c'=>$channel['id']]);
    $presence=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){}
try { $watch=$pdo->query("SELECT * FROM watch_telemetria ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null; } catch(Throwable $e){}
try { $assist=$pdo->query("SELECT id,tipo,severidade,mensagem,pessoa,dispositivo,confirmado,criado_em,confirmado_em FROM assistencia_eventos ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
try { $mobileEvents=$pdo->query("SELECT tipo,descricao,dados,data_hora FROM mobile_eventos ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}

function h($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function ageText($date){ if(!$date)return '--'; $s=time()-strtotime($date); if($s<60)return $s.'s'; if($s<3600)return floor($s/60).' min'; return floor($s/3600).' h'; }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CASA — Celular e Relógio</title><link rel="stylesheet" href="/casa/lcars.css?v=20260914b">
<style>
body{background:#fff7e8;color:#211b22;font-family:Arial,sans-serif;margin:0}.wrap{max-width:1180px;margin:auto;padding:18px}.head{display:flex;gap:12px;align-items:stretch}.elbow{background:#f59c73;border-radius:28px 0 0 28px;min-width:140px;padding:24px;font-weight:900}.title{background:#d8b4ea;flex:1;padding:18px 24px;border-radius:0 24px 24px 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-top:16px}.card{background:#fff;border:3px solid #d8b4ea;border-radius:20px;padding:16px}.card.orange{border-color:#f59c73}.card.blue{border-color:#8eb9ee}.card.green{border-color:#8ccf9c}.pill{display:inline-block;padding:5px 10px;border-radius:999px;font-weight:800}.ok{background:#9bd6a4}.off{background:#ef9b92}.warn{background:#ffd27a}table{width:100%;border-collapse:collapse}td,th{padding:8px;border-bottom:1px solid #ddd;text-align:left}input,textarea,select,button{font:inherit;padding:10px;border-radius:10px;border:1px solid #777;box-sizing:border-box}input,textarea,select{width:100%}button{background:#f59c73;font-weight:800;cursor:pointer}.msg{padding:10px;border-radius:10px;margin-top:12px;background:#dff3df}.err{background:#ffd9d3}.actions{display:flex;gap:8px;flex-wrap:wrap}.actions a{padding:10px 14px;background:#8eb9ee;border-radius:12px;color:#211b22;text-decoration:none;font-weight:800}</style></head><body><div class="wrap">
<div class="head"><div class="elbow">CASA<br>LINK</div><div class="title"><h1>Celular + Relógio</h1><div>Gateway, telemetria, assistência e Família CASA</div></div></div>
<div class="actions" style="margin-top:12px"><a href="/casa/index.php">Dashboard</a><a href="/casa/familia.php">Família CASA</a></div>
<?php if($message):?><div class="msg"><?=h($message)?></div><?php endif;?><?php if($error):?><div class="msg err"><?=h($error)?></div><?php endif;?>
<div class="grid">
<div class="card green"><h2>Dispositivos presentes</h2><?php if(!$presence):?><p>Nenhum celular/relógio registrou presença ainda.</p><?php else:?><table><tr><th>Dispositivo</th><th>Tipo</th><th>Estado</th><th>Último contato</th></tr><?php foreach($presence as $p):?><tr><td><?=h($p['dispositivo'] ?: $p['cliente'])?></td><td><?=h($p['plataforma'])?></td><td><span class="pill <?=$p['online']?'ok':'off'?>"><?=$p['online']?'ONLINE':'OFFLINE'?></span></td><td><?=h(ageText($p['ultimo_ping']))?></td></tr><?php endforeach;?></table><?php endif;?></div>
<div class="card blue"><h2>Última telemetria Watch</h2><?php if(!$watch):?><p>Aguardando telemetria do relógio via <code>/api/v1/watch.php</code>.</p><?php else:?><table><tr><td>Bateria</td><td><?=h($watch['bateria_pct'])?>%</td></tr><tr><td>Passos</td><td><?=h($watch['passos'])?></td></tr><tr><td>Transporte</td><td><?=h($watch['transporte'])?></td></tr><tr><td>Wi-Fi</td><td><?=h($watch['wifi_ssid'])?></td></tr><tr><td>RSSI BLE/Wi-Fi</td><td><?=h($watch['rssi_ble'])?> / <?=h($watch['rssi_wifi'])?></td></tr><tr><td>Sem movimento</td><td><?=h($watch['minutos_sem_movimento'])?> min</td></tr><tr><td>Energia</td><td><?=h($watch['modo_energia'])?></td></tr><tr><td>Atualização</td><td><?=h($watch['data_hora'])?></td></tr></table><?php endif;?></div>
<div class="card orange"><h2>Broadcast Família</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="broadcast"><textarea name="message" rows="4" placeholder="Mensagem para site, celular e relógio"></textarea><button>Transmitir</button></form></div>
<div class="card"><h2>Notificar dispositivo</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input name="title" value="JARVIS" placeholder="Título"><textarea name="message" rows="3" placeholder="Mensagem"></textarea><select name="priority"><option value="normal">Normal</option><option value="alta">Alta</option><option value="critica">Crítica</option></select><div style="display:flex;gap:8px;margin-top:8px"><button name="action" value="notify_mobile">Celular</button><button name="action" value="notify_watch">Relógio</button></div></form></div>
</div>
<div class="card orange" style="margin-top:16px"><h2>Assistência</h2><?php if(!$assist):?><p>Sem eventos.</p><?php else:?><table><tr><th>Data</th><th>Tipo</th><th>Severidade</th><th>Dispositivo</th><th>Mensagem</th><th></th></tr><?php foreach($assist as $a):?><tr><td><?=h($a['criado_em'])?></td><td><?=h($a['tipo'])?></td><td><span class="pill <?=strtolower($a['severidade'])==='critica'?'off':'warn'?>"><?=h($a['severidade'])?></span></td><td><?=h($a['dispositivo'])?></td><td><?=h($a['mensagem'])?></td><td><?php if(!$a['confirmado']):?><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="ack_assist"><input type="hidden" name="id" value="<?=h($a['id'])?>"><button>Confirmar</button></form><?php else:?>OK<?php endif;?></td></tr><?php endforeach;?></table><?php endif;?></div>
<div class="card blue" style="margin-top:16px"><h2>Eventos recentes do celular/gateway</h2><?php if(!$mobileEvents):?><p>Sem eventos.</p><?php else:?><table><tr><th>Data</th><th>Tipo</th><th>Descrição</th></tr><?php foreach($mobileEvents as $e):?><tr><td><?=h($e['data_hora'])?></td><td><?=h($e['tipo'])?></td><td><?=h($e['descricao'])?></td></tr><?php endforeach;?></table><?php endif;?></div>
</div></body></html>
