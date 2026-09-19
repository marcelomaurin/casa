<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }
require_once(__DIR__ . '/api/db.php');
require_once(__DIR__ . '/api/family_common.php');
$pdo=get_db_pdo(); family_ensure_schema($pdo);
$user=is_array($_SESSION['auth_user']) ? ($_SESSION['auth_user']['nome'] ?? $_SESSION['auth_user']['login'] ?? 'WEB') : (string)$_SESSION['auth_user'];
$channel=family_channel($pdo,'familia');

function web_input(){ $raw=file_get_contents('php://input'); $j=$raw?json_decode($raw,true):[]; return is_array($j)?$j:[]; }
if(isset($_GET['api'])){
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  $api=$_GET['api']; $in=web_input(); global $pdo,$channel,$user;
  if($api==='presence'){
    family_presence($pdo,(int)$channel['id'],$user,'web','browser',['ua'=>substr($_SERVER['HTTP_USER_AGENT']??'',0,180)]);
    $s=$pdo->prepare("SELECT cliente,plataforma,ultimo_ping FROM family_presence WHERE canal_id=:c AND ultimo_ping>=DATE_SUB(NOW(),INTERVAL 90 SECOND) ORDER BY ultimo_ping DESC");$s->execute([':c'=>$channel['id']]);
    echo json_encode(['ok'=>true,'online'=>$s->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);exit;
  }
  if($api==='send'){
    $id=family_send($pdo,(int)$channel['id'],$user,'web',substr($in['type']??'texto',0,30),trim($in['message']??''),$in['data']??[]);
    echo json_encode(['ok'=>true,'id'=>$id]);exit;
  }
  if($api==='poll'){
    $after=max(0,(int)($_GET['after']??0));$s=$pdo->prepare("SELECT id,remetente,origem,tipo,mensagem,dados,criado_em FROM family_messages WHERE canal_id=:c AND id>:a ORDER BY id ASC LIMIT 100");$s->execute([':c'=>$channel['id'],':a'=>$after]);
    echo json_encode(['ok'=>true,'messages'=>$s->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);exit;
  }
  if($api==='call_start'){
    $mode=($in['mode']??'video')==='audio'?'audio':'video';
    $targetClient=trim((string)($in['target_client']??''))?:null;
    $targetPlatform=trim((string)($in['target_platform']??''))?:null;
    $id=family_start_call($pdo,(int)$channel['id'],$user,$mode,$targetClient,$targetPlatform);
    family_send($pdo,(int)$channel['id'],$user,'web','call','Chamada familiar iniciada',['call_id'=>$id,'mode'=>$mode,'target_client'=>$targetClient,'target_platform'=>$targetPlatform]);
    echo json_encode(['ok'=>true,'call_id'=>$id,'mode'=>$mode,'target_client'=>$targetClient,'target_platform'=>$targetPlatform]);exit;
  }
  if($api==='call_current'){
    $visibility=family_call_visible_sql(':me',':platform');
    $s=$pdo->prepare("SELECT * FROM family_calls WHERE canal_id=:c AND status IN('chamando','ativa') AND {$visibility} ORDER BY id DESC LIMIT 1");
    $s->execute([':c'=>$channel['id'],':me'=>$user,':platform'=>'web']);
    echo json_encode(['ok'=>true,'call'=>$s->fetch(PDO::FETCH_ASSOC)?:null],JSON_UNESCAPED_UNICODE);exit;
  }
  if($api==='call_join'){
    $id=(int)($in['call_id']??0);
    $visibility=family_call_visible_sql(':me',':platform');
    $s=$pdo->prepare("UPDATE family_calls SET status='ativa',atendido_por=:me WHERE id=:id AND canal_id=:c AND status='chamando' AND {$visibility}");
    $s->execute([':id'=>$id,':c'=>$channel['id'],':me'=>$user,':platform'=>'web']);
    if($s->rowCount()<1){http_response_code(409);echo json_encode(['ok'=>false,'error'=>'Chamada indisponível']);exit;}
    family_presence($pdo,(int)$channel['id'],$user,'web','browser',['call_id'=>$id]);
    echo json_encode(['ok'=>true,'call_id'=>$id,'answered_by'=>$user]);exit;
  }
  if($api==='signal'){
    $s=$pdo->prepare("INSERT INTO family_call_signals(call_id,remetente,destino,tipo,payload) VALUES(:c,:r,:d,:t,:p)");$s->execute([':c'=>(int)($in['call_id']??0),':r'=>$user,':d'=>$in['target']??null,':t'=>substr($in['type']??'signal',0,30),':p'=>json_encode($in['payload']??new stdClass(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);exit;
  }
  if($api==='signal_poll'){
    $id=(int)($_GET['call_id']??0);$after=max(0,(int)($_GET['after']??0));$s=$pdo->prepare("SELECT id,remetente,destino,tipo,payload FROM family_call_signals WHERE call_id=:c AND id>:a AND (destino IS NULL OR destino='' OR destino=:me) ORDER BY id ASC LIMIT 100");$s->execute([':c'=>$id,':a'=>$after,':me'=>$user]);echo json_encode(['ok'=>true,'signals'=>$s->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);exit;
  }
  if($api==='call_end'){
    $id=(int)($in['call_id']??0);$pdo->prepare("UPDATE family_calls SET status='encerrada',encerrado_em=NOW() WHERE id=:id AND canal_id=:c")->execute([':id'=>$id,':c'=>$channel['id']]);family_send($pdo,(int)$channel['id'],$user,'web','call','Chamada familiar encerrada',['call_id'=>$id]);echo json_encode(['ok'=>true]);exit;
  }
  http_response_code(404);echo json_encode(['ok'=>false]);exit;
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Família CASA</title><link rel="stylesheet" href="/casa/lcars.css?v=20260914b"><style>
body{margin:0;background:#fff8e8;color:#241f25;font-family:Arial,sans-serif}.wrap{max-width:1180px;margin:auto;padding:18px}.bar{background:#f4a261;border-radius:28px 28px 8px 8px;padding:14px 22px;display:flex;justify-content:space-between;align-items:center}.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px;margin-top:16px}.panel{background:white;border:3px solid #b7a2d8;border-radius:22px;padding:16px}.messages{height:420px;overflow:auto;background:#fffdf7;border-radius:14px;padding:10px}.msg{margin:7px 0;padding:9px 12px;border-radius:14px;background:#f7d7c4}.msg.system{background:#e8def8}.row{display:flex;gap:8px}.row input{flex:1;padding:12px;border-radius:12px;border:2px solid #b7a2d8}button{border:0;border-radius:18px;padding:11px 16px;font-weight:700;background:#f4a261;color:#241f25;cursor:pointer}.secondary{background:#b7a2d8}.danger{background:#ee8f83}.online span{display:inline-block;background:#d7f2dc;margin:4px;padding:6px 9px;border-radius:12px}.videos{display:grid;grid-template-columns:1fr 1fr;gap:8px}video{width:100%;background:#111;border-radius:14px;min-height:180px}.hint{font-size:13px;color:#665}.status{padding:8px 0;font-weight:bold}@media(max-width:800px){.grid{grid-template-columns:1fr}.videos{grid-template-columns:1fr}.messages{height:300px}}
</style></head><body><div class="wrap"><div class="bar"><div><b>FAMÍLIA CASA</b><div>Broadcast • conversa • assistência • videochamada</div></div><a href="/casa/index.php">CASA</a></div><div class="grid"><section class="panel"><h2>Canal Família</h2><div id="online" class="online"></div><div id="messages" class="messages"></div><div class="row" style="margin-top:10px"><input id="message" placeholder="Escreva para a família"><button onclick="sendMessage()">ENVIAR</button></div><p class="hint">Celular, site e relógio usam o mesmo canal lógico. Alertas de assistência também aparecem aqui.</p></section><section class="panel"><h2>Chamada familiar</h2><div class="status" id="callStatus">Nenhuma chamada ativa</div><div class="row"><button onclick="startCall('video')">VIDEOCHAMADA</button><button class="secondary" onclick="startCall('audio')">ÁUDIO</button><button id="acceptCallBtn" style="display:none" onclick="acceptIncoming()">ATENDER</button><button class="danger" onclick="endCall()">ENCERRAR</button></div><div class="videos" style="margin-top:12px"><video id="localVideo" autoplay muted playsinline></video><div id="remoteVideos"></div></div><p class="hint">No T‑Watch, a chamada é iniciada/aceita pelo relógio, mas câmera e vídeo são fornecidos pelo celular, pois o relógio não possui câmera.</p></section></div></div><script>
const ME=<?php echo json_encode($user,JSON_UNESCAPED_UNICODE); ?>;let lastMsg=0,callId=0,lastSignal=0,localStream=null,pendingCall=null;const peers={};
async function api(name,data=null,query=''){const opt={headers:{'Content-Type':'application/json'}};if(data!==null){opt.method='POST';opt.body=JSON.stringify(data)}const r=await fetch('familia.php?api='+name+query,opt);return r.json()}
function esc(s){const d=document.createElement('div');d.textContent=s??'';return d.innerHTML}
async function presence(){const r=await api('presence',{});document.getElementById('online').innerHTML=(r.online||[]).map(x=>'<span>'+esc(x.cliente)+' • '+esc(x.plataforma)+'</span>').join('')}
async function poll(){const r=await api('poll',null,'&after='+lastMsg);for(const m of r.messages||[]){lastMsg=Math.max(lastMsg,+m.id);const d=document.createElement('div');d.className='msg '+(m.origem==='sistema'?'system':'');d.innerHTML='<b>'+esc(m.remetente)+'</b> <small>'+esc(m.origem)+'</small><br>'+esc(m.mensagem||'['+m.tipo+']');document.getElementById('messages').appendChild(d)}const box=document.getElementById('messages');box.scrollTop=box.scrollHeight}
async function sendMessage(){const e=document.getElementById('message'),v=e.value.trim();if(!v)return;await api('send',{type:'texto',message:v});e.value='';await poll()}
async function getMedia(mode){if(localStream)return localStream;localStream=await navigator.mediaDevices.getUserMedia({audio:true,video:mode==='video'});document.getElementById('localVideo').srcObject=localStream;return localStream}
async function startCall(mode){const r=await api('call_start',{mode});callId=r.call_id;await getMedia(mode);document.getElementById('callStatus').textContent='Chamando • '+mode+' #'+callId}
async function checkCurrent(){
  const r=await api('call_current');
  const btn=document.getElementById('acceptCallBtn');
  if(!r.call){pendingCall=null;btn.style.display='none';if(!callId)document.getElementById('callStatus').textContent='Nenhuma chamada ativa';return;}
  const c=r.call;
  if(c.status==='ativa'&&(c.iniciado_por===ME||c.atendido_por===ME)){
    if(!callId){callId=+c.id;await getMedia(c.modo);await sendSignal('join',{mode:c.modo});}
    document.getElementById('callStatus').textContent='Chamada '+c.modo+' #'+c.id;
    btn.style.display='none';
    return;
  }
  if(c.status==='chamando'&&c.iniciado_por!==ME){
    pendingCall=c;
    document.getElementById('callStatus').textContent='Chamada '+c.modo+' de '+c.iniciado_por;
    btn.style.display='';
  }else{
    document.getElementById('callStatus').textContent='Chamando... #'+c.id;
    btn.style.display='none';
  }
}
async function acceptIncoming(){
  if(!pendingCall)return;
  const c=pendingCall;
  await api('call_join',{call_id:+c.id});
  callId=+c.id;
  pendingCall=null;
  document.getElementById('acceptCallBtn').style.display='none';
  await getMedia(c.modo);
  document.getElementById('callStatus').textContent='Chamada '+c.modo+' #'+callId;
  await sendSignal('join',{mode:c.modo});
}
async function sendSignal(type,payload,target=null){if(!callId)return;await api('signal',{call_id:callId,type,payload,target})}
async function ensurePeer(name,initiator=false){if(peers[name])return peers[name];const pc=new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'}]});peers[name]=pc;if(localStream)localStream.getTracks().forEach(t=>pc.addTrack(t,localStream));pc.onicecandidate=e=>{if(e.candidate)sendSignal('ice',e.candidate,name)};pc.ontrack=e=>{let v=document.getElementById('peer_'+btoa(unescape(encodeURIComponent(name))).replace(/=/g,''));if(!v){v=document.createElement('video');v.id='peer_'+btoa(unescape(encodeURIComponent(name))).replace(/=/g,'');v.autoplay=true;v.playsInline=true;document.getElementById('remoteVideos').appendChild(v)}v.srcObject=e.streams[0]};if(initiator){const o=await pc.createOffer();await pc.setLocalDescription(o);await sendSignal('offer',o,name)}return pc}
async function pollSignals(){if(!callId)return;const r=await api('signal_poll',null,'&call_id='+callId+'&after='+lastSignal);for(const s of r.signals||[]){lastSignal=Math.max(lastSignal,+s.id);if(s.remetente===ME)continue;const p=JSON.parse(s.payload||'{}');if(s.tipo==='join'){await ensurePeer(s.remetente,true)}else if(s.tipo==='offer'){const pc=await ensurePeer(s.remetente,false);await pc.setRemoteDescription(p);const a=await pc.createAnswer();await pc.setLocalDescription(a);await sendSignal('answer',a,s.remetente)}else if(s.tipo==='answer'){const pc=await ensurePeer(s.remetente,false);await pc.setRemoteDescription(p)}else if(s.tipo==='ice'){const pc=await ensurePeer(s.remetente,false);try{await pc.addIceCandidate(p)}catch(e){}}}}
async function endCall(){if(callId)await api('call_end',{call_id:callId});Object.values(peers).forEach(p=>p.close());for(const k of Object.keys(peers))delete peers[k];if(localStream){localStream.getTracks().forEach(t=>t.stop());localStream=null}document.getElementById('remoteVideos').innerHTML='';document.getElementById('localVideo').srcObject=null;document.getElementById('callStatus').textContent='Nenhuma chamada ativa';callId=0;lastSignal=0}
setInterval(()=>{presence();poll();checkCurrent().catch(()=>{});pollSignals()},2000);presence();poll();checkCurrent().catch(()=>{});
</script></body></html>