(function(){
'use strict';
let busy=false,lastKey='';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const isIoTRoute=()=>['iot','iot-op','automation-iot','iot-sys'].includes(new URL(location.href).searchParams.get('item')||'');
async function api(url,opt){const r=await fetch(url,opt);const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.mensagem||('HTTP '+r.status));return j;}
function state(v){return v===true?'LIGADO':v===false?'DESLIGADO':'—';}
function card(d){
 const online=String(d.status||'').toLowerCase()==='online';
 const title=esc(d.nome||d.model||d.device_id);
 const base='<header class="ja-iot-card-head"><div><small>'+esc((d.tipo||'IOT').toUpperCase())+'</small><h3>'+title+'</h3></div><span class="ja-state '+(online?'ok':'off')+'">'+esc((d.status||'offline').toUpperCase())+'</span></header>';
 const info='<dl class="ja-iot-meta"><dt>Modelo</dt><dd>'+esc(d.model||'—')+'</dd><dt>Local</dt><dd>'+esc(d.localizacao||'—')+'</dd><dt>IP</dt><dd>'+esc(d.ip_address||'—')+'</dd><dt>Sinal</dt><dd>'+esc(d.sinal_rssi!=null?d.sinal_rssi+' dBm':'—')+'</dd></dl>';
 let functional='';
 if(d.is_relay) functional+='<section class="ja-iot-relay"><div class="ja-iot-readout"><span>ESTADO FÍSICO</span><strong>'+state(d.relay_actual)+'</strong></div><div class="ja-iot-readout"><span>ESTADO DESEJADO</span><strong>'+state(d.relay_desired)+'</strong></div><div class="ja-row-actions"><button class="ja-mini primary" data-relay="1" data-device="'+esc(d.device_id)+'">LIGAR</button><button class="ja-mini" data-relay="0" data-device="'+esc(d.device_id)+'">DESLIGAR</button></div></section>';
 if(d.is_environment){const e=d.environment||{};functional+='<section class="ja-iot-environment"><div class="ja-iot-metric"><span>TEMPERATURA</span><strong>'+esc(e.temperature_c??'—')+(e.temperature_c!=null?' °C':'')+'</strong></div><div class="ja-iot-metric"><span>UMIDADE</span><strong>'+esc(e.humidity_pct??'—')+(e.humidity_pct!=null?' %':'')+'</strong></div><small>Última mudança: '+esc(e.observed_at||'aguardando leitura')+'</small></section>';}
 return '<article class="ja-native-card ja-iot-card '+(d.is_relay?'is-relay ':'')+(d.is_environment?'is-sensor':'')+'">'+base+functional+info+'<footer>Heartbeat: '+esc(d.ultimo_heartbeat||'—')+'</footer></article>';
}
async function render(){
 if(!isIoTRoute()||busy)return;
 const content=document.querySelector('#app .ja-content');if(!content)return;
 const key=location.search+'|'+(content.querySelector('.ja-native-head>strong')?.textContent||'');
 if(key===lastKey&&content.querySelector('.ja-iot-dashboard'))return;
 busy=true;
 try{
  const j=await api('/casa/api/iot_dashboard.php');const devices=j.devices||[];
  const relays=devices.filter(d=>d.is_relay).length,sensors=devices.filter(d=>d.is_environment).length,online=devices.filter(d=>String(d.status).toLowerCase()==='online').length;
  content.innerHTML='<section class="ja-native-module ja-iot-dashboard"><header class="ja-native-head"><strong>CLUSTER IOT · CONTROLE REAL</strong><div class="ja-iot-counts"><span>'+online+' ONLINE</span><span>'+relays+' RELÉS</span><span>'+sensors+' SENSORES</span></div></header><div class="ja-native-body"><div class="ja-iot-grid">'+(devices.length?devices.map(card).join(''):'<div class="ja-empty">Nenhum dispositivo IoT cadastrado.</div>')+'</div></div></section>';
  content.querySelectorAll('[data-relay]').forEach(b=>b.onclick=async()=>{b.disabled=true;try{await api('/casa/api/iot_dashboard.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({acao:'relay_state',device_id:b.dataset.device,relay_on:b.dataset.relay==='1'})});lastKey='';await render();}catch(e){alert(e.message);}finally{b.disabled=false;}});
  lastKey=key;
 }catch(e){content.innerHTML='<section class="ja-native-module"><div class="ja-empty">'+esc(e.message)+'</div></section>';}
 finally{busy=false;}
}
const observer=new MutationObserver(()=>{if(isIoTRoute())setTimeout(render,0);});
observer.observe(document.getElementById('app'),{childList:true,subtree:true});
window.addEventListener('popstate',()=>setTimeout(render,0));
document.addEventListener('click',()=>setTimeout(render,60),true);
setTimeout(render,100);
})();
