(function(){
'use strict';

const GROUP_ICONS={
  'SEGURANÇA':'◆',
  'OPERAÇÕES':'◈',
  'DISPOSITIVOS':'▣',
  'AUTOMAÇÃO':'⚙',
  'IA & VOZ':'✦',
  'FAMÍLIA':'●',
  'SISTEMA':'⌘'
};

const STATUS=[
  {label:'WATCH',value:'OK'},
  {label:'SISTEMA',value:'ONLINE'}
];

const GROUPS={
  'SEGURANÇA':{
    subtitle:'Proteção, perímetro, câmeras, acessos e eventos.',
    sections:[
      {title:'PERÍMETRO',items:[
        {id:'seguranca',label:'Defesa & anti-intrusão',module:'security'},
        {id:'cameras',label:'Câmeras & visão',module:'cameras'},
        {id:'acessos',label:'Acessos pessoais',url:'/casa/dispositivos_pessoais.php'},
        {id:'familia',label:'Família & presença',url:'/casa/familia.php'}
      ]},
      {title:'EVENTOS',items:[
        {id:'sensores-sec',label:'Sensores & telemetria',module:'sensors'},
        {id:'api-sec',label:'Acesso web & API segura',module:'external'}
      ]}
    ]
  },
  'OPERAÇÕES':{
    subtitle:'Rotinas, agenda, ambientes e execução diária.',
    sections:[
      {title:'ROTINAS',items:[
        {id:'agendamentos',label:'Agendamentos',module:'schedules'},
        {id:'devices-op',label:'Dispositivos & relés',module:'devices'},
        {id:'iot-op',label:'ESP32 / Arduino / IoT',module:'iot'},
        {id:'cenas',label:'Cenas e regras',module:'automation'}
      ]},
      {title:'MONITORAMENTO',items:[
        {id:'sensores-op',label:'Sensores',module:'sensors'},
        {id:'cameras-op',label:'Câmeras',module:'cameras'},
        {id:'historico-op',label:'Frases & avisos',module:'phrases'}
      ]}
    ]
  },
  'DISPOSITIVOS':{
    subtitle:'Inventário, nós, IoT, celular, relógio e equipamentos.',
    sections:[
      {title:'EQUIPAMENTOS',items:[
        {id:'devices',label:'Dispositivos & relés',module:'devices'},
        {id:'iot',label:'ESP32 / Arduino / IoT',module:'iot'},
        {id:'nodes',label:'Cluster ARM',module:'nodes'}
      ]},
      {title:'PESSOAIS',items:[
        {id:'mobile-dev',label:'Celular',url:'/casa/celular.php'},
        {id:'watch-dev',label:'Watch',url:'/casa/watch.php'},
        {id:'family-dev',label:'Dispositivos da família',url:'/casa/familia.php'},
        {id:'bin-dev',label:'Downloads & APKs (/bin)',url:'/casa/bin/'}
      ]}
    ]
  },
  'AUTOMAÇÃO':{
    subtitle:'Ações, agendas e integrações responsáveis por executar a casa.',
    sections:[
      {title:'EXECUÇÃO',items:[
        {id:'automation-dev',label:'Dispositivos & relés',module:'devices'},
        {id:'automation-ag',label:'Agendamentos',module:'schedules'},
        {id:'automation-iot',label:'ESP32 / Arduino / IoT',module:'iot'},
        {id:'automation-scenes',label:'Cenas e regras',module:'automation'}
      ]},
      {title:'INTEGRAÇÃO',items:[
        {id:'automation-agents',label:'Agentes externos',module:'agents'},
        {id:'automation-api',label:'Acesso Web & API',module:'external'}
      ]}
    ]
  },
  'IA & VOZ':{
    subtitle:'COMPUTER, reconhecimento, voz, RunPod e agentes.',
    sections:[
      {title:'COMPUTER',items:[
        {id:'jarvis',label:'Núcleo de reconhecimento & voz',module:'jarvis'},
        {id:'frases',label:'Frases & avisos',module:'phrases'},
        {id:'agentes',label:'Agentes externos',module:'agents'}
      ]},
      {title:'INTELIGÊNCIA',items:[
        {id:'modelos-ia',label:'Conexões & Modelos de IA',url:'/casa/ia_modelos.php'},
        {id:'runpod',label:'Configurações de IA',module:'config'},
        {id:'webapi',label:'Acesso Web & API segura',module:'external'}
      ]}
    ]
  },
  'FAMÍLIA':{
    subtitle:'Pessoas, presença e dispositivos pessoais.',
    sections:[
      {title:'PESSOAS',items:[
        {id:'family',label:'Família',url:'/casa/familia.php'},
        {id:'mobile-person',label:'Celular',url:'/casa/celular.php'},
        {id:'watch-person',label:'Watch',url:'/casa/watch.php'}
      ]},
      {title:'CONTROLE',items:[
        {id:'users',label:'Usuários & senhas',module:'users'}
      ]}
    ]
  },
  'SISTEMA':{
    subtitle:'Infraestrutura, telemetria, configuração e diagnóstico.',
    sections:[
      {title:'INFRAESTRUTURA',items:[
        {id:'nodes-sys',label:'Cluster ARM',module:'nodes'},
        {id:'sensors-sys',label:'Telemetria operacional',module:'telemetryOps'},
        {id:'iot-sys',label:'ESP32 / Arduino / IoT',module:'iot'},
        {id:'bin-sys',label:'Central de Binários & APKs (/bin)',url:'/casa/bin/'}
      ]},
      {title:'ADMINISTRAÇÃO',items:[
        {id:'users-sys',label:'Usuários & senhas',module:'users'},
        {id:'config-sys',label:'Configurações RunPod / IA',module:'config'},
        {id:'api-sys',label:'Acesso Web & API',module:'external'},
        {id:'health',label:'Saúde do sistema',module:'health'}
      ]}
    ]
  }
};

let currentGroup=null;
let currentItem=null;
let suppressHistory=false;
const moduleState={};

function setRoute(group,item){
  if(suppressHistory) return;
  const u=new URL(location.href);
  if(group) u.searchParams.set('grupo',group); else u.searchParams.delete('grupo');
  if(item) u.searchParams.set('item',item); else u.searchParams.delete('item');
  history.pushState({group:group||null,item:item||null},'',u);
}

function topGroupCards(){
  return Object.keys(GROUPS).map(name=>({title:name,description:GROUPS[name].subtitle,id:name,icon:GROUP_ICONS[name]||'•'}));
}

function home(updateRoute=true){
  currentGroup=null; currentItem=null;
  CASALcars.render('#app',{
    layout:'dashboard',group:'GRUPOS',title:'CASA / COMPUTER',subtitle:'Escolha um grupo. A interface adapta o miolo conforme a tarefa.',
    breadcrumb:[],groups:[{label:'CASA',action:'home',level:0,levelNav:true,active:true}],status:STATUS,columns:3,
    items:topGroupCards().map(g=>({title:g.title,description:g.description,icon:g.icon,actions:[{label:'ACESSAR',action:'open-group:'+g.id,variant:'primary'}]})),
    footer:{hint:'Interface em tela cheia: use grupos e paginação, sem rolagem.'}
  });
  if(updateRoute) setRoute(null,null);
}

function groupRail(group){
  const flat=[];
  GROUPS[group].sections.forEach(s=>s.items.forEach(it=>flat.push(it)));
  const nav=[
    {label:'CASA',action:'home',level:0,levelNav:true},
    {label:group,action:'open-current-group',level:1,levelNav:true,active:!currentItem}
  ];
  if(currentItem){
    const selected=findItem(group,currentItem);
    if(selected) nav.push({label:selected.label,id:selected.id,action:'open-item',level:2,levelNav:true,active:true});
  }
  const choices=flat.filter(it=>!currentItem || it.id!==currentItem).slice(0,Math.max(0,8-nav.length)).map(it=>({
    label:it.label,id:it.id,action:'open-item',level:2,active:false
  }));
  return nav.concat(choices);
}

function openGroup(group,updateRoute=true){
  if(!GROUPS[group]) return home(updateRoute);
  currentGroup=group; currentItem=null;
  CASALcars.render('#app',{
    layout:'menu-grid',kind:'menu',group,title:group,subtitle:GROUPS[group].subtitle,
    breadcrumb:[],groups:groupRail(group),status:STATUS,sections:GROUPS[group].sections,
    footer:{hint:'Escolha uma opção. O painel usa toda a tela e não rola.'}
  });
  if(updateRoute) setRoute(group,null);
}

function findItem(group,id){
  if(!GROUPS[group]) return null;
  for(const sec of GROUPS[group].sections){ for(const it of sec.items){ if(it.id===id) return it; } }
  return null;
}

function openItem(item,updateRoute=true){
  if(currentItem==='jarvis' && (!item || item.id!=='jarvis')){
    if(activeRecognition){try{activeRecognition.abort();}catch(_){}activeRecognition=null;}
    stopComputerSpeech();
    mobileVoiceEnabled=false;
  }
  if(!item || !currentGroup) return;
  currentItem=item.id;
  CASALcars.render('#app',{
    layout:'focus',group:currentGroup,title:item.label,subtitle:GROUPS[currentGroup].subtitle,
    breadcrumb:[],groups:groupRail(currentGroup),status:STATUS,
    items:[{title:item.label,description:'Módulo funcional do CASA no layout atual.'}],
    footer:{hint:'Use os controles do módulo; dados extensos são paginados para evitar rolagem.'}
  });
  if(updateRoute) setRoute(currentGroup,item.id);
  if(item.module) mountNativeModule(item);
  else if(item.url) mountPageModule(item);
  else showModuleError('Módulo não configurado.');
}

function contentNode(){ return document.querySelector('#app .ja-content'); }
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function attr(v){return esc(v).replace(/\n/g,' ');}

async function getJson(url,opt){
  const r=await fetch(url,opt);
  if(r.status===401 || r.status===403) throw new Error('Sessão expirada ou acesso negado.');
  const j=await r.json().catch(()=>({}));
  if(!r.ok) throw new Error(j.mensagem||j.error||('HTTP '+r.status));
  return j;
}
async function postJson(url,data){
  const headers={'Content-Type':'application/json'};
  if(window.CASA_SESSION_TOKEN) headers['X-CASA-Session-Token']=window.CASA_SESSION_TOKEN;
  return getJson(url,{method:'POST',headers,body:JSON.stringify(data||{})});
}
function crud(table,action='listar',extra=''){
  return getJson('/casa/api/crud.php?tabela='+encodeURIComponent(table)+'&acao='+encodeURIComponent(action)+(extra||''));
}

function moduleShell(title,body,actions=''){
  const c=contentNode(); if(!c) return;
  c.innerHTML='<section class="ja-native-module"><header class="ja-native-head"><strong>'+esc(title)+'</strong><div class="ja-native-actions">'+actions+'</div></header><div class="ja-native-body">'+body+'</div></section>';
}
function showModuleError(msg){ moduleShell('Módulo','<div class="ja-empty">'+esc(msg)+'</div>'); }
function loading(title){ moduleShell(title,'<div class="ja-empty">Carregando...</div>'); }

function paginate(name,items,pageSize=6){
  const st=moduleState[name]||(moduleState[name]={page:0});
  const pages=Math.max(1,Math.ceil(items.length/pageSize));
  st.page=Math.max(0,Math.min(st.page,pages-1));
  return {slice:items.slice(st.page*pageSize,(st.page+1)*pageSize),page:st.page,pages,total:items.length};
}
function pager(name,p,rerender){
  if(p.pages<=1) return '';
  return '<div class="ja-pager"><button data-page="-1">‹</button><span>'+(p.page+1)+' / '+p.pages+'</span><button data-page="1">›</button></div>';
}
function bindPager(name,p,rerender){
  document.querySelectorAll('.ja-pager button').forEach(b=>b.onclick=()=>{moduleState[name].page+=Number(b.dataset.page||0);rerender();});
}
function cards(items,render){
  return '<div class="ja-native-grid">'+items.map(render).join('')+'</div>';
}
function actionBtn(label,act,variant=''){return '<button class="ja-mini '+variant+'" data-act="'+attr(act)+'">'+esc(label)+'</button>';}

async function mountNativeModule(item){
  const m=item.module;
  try{
    if(m==='devices') return renderDevices();
    if(m==='sensors') return renderSensors();
    if(m==='telemetryOps') return renderOperationalTelemetry();
    if(m==='nodes') return renderNodes();
    if(m==='schedules') return renderSchedules();
    if(m==='automation') return renderAutomation();
    if(m==='iot') return renderIoT();
    if(m==='security') return renderSecurity();
    if(m==='agents') return renderAgents();
    if(m==='phrases') return renderPhrases();
    if(m==='users') return renderUsers();
    if(m==='config') return renderConfig();
    if(m==='external') return renderExternal();
    if(m==='jarvis') return renderJarvis();
    if(m==='cameras') return renderCameras();
    if(m==='health') return renderHealth();
    showModuleError('Módulo não reconhecido: '+m);
  }catch(e){showModuleError(e.message||String(e));}
}

// Dispositivos & relés: lista somente equipamentos que anunciam capacidade
// "relay"/"switch" (dispositivos_cluster) e permite ligar/desligar gravando o
// estado desejado via /casa/api/iot_dashboard.php. O firmware (ex.: ESP01 Relay)
// consulta /api/v1/device_desired_state.php e aplica o estado.
function relayStateLabel(v){return v===true?'LIGADO':v===false?'DESLIGADO':'—';}
async function renderDevices(){
  loading('Dispositivos & relés');
  let relays=[];
  try{
    const j=await getJson('/casa/api/iot_dashboard.php');
    relays=(j.devices||[]).filter(d=>d.is_relay);
  }catch(e){
    moduleShell('Dispositivos & relés','<div class="ja-empty">'+esc(e.message||'Falha ao carregar relés.')+'</div>','<button id="relay-retry" class="ja-mini">Tentar novamente</button>');
    const r=document.getElementById('relay-retry'); if(r) r.onclick=renderDevices;
    return;
  }
  const p=paginate('devices',relays,6);
  const online=relays.filter(d=>String(d.status||'').toLowerCase()==='online').length;
  const summary='<p>'+esc(relays.length)+' equipamento(s) de acionamento · '+esc(online)+' online. O comando grava o estado desejado; o equipamento aplica na próxima consulta (≈3 s).</p>';
  const body=relays.length?cards(p.slice,d=>{
    const isOn=String(d.status||'').toLowerCase()==='online';
    const pending=d.relay_desired!==null&&d.relay_actual!==null&&d.relay_desired!==d.relay_actual;
    return '<article class="ja-native-card ja-iot-card is-relay">'+
      '<header class="ja-iot-card-head"><div><small>'+esc(String(d.tipo||'IoT').toUpperCase())+'</small><h3>'+esc(d.nome||d.model||d.device_id)+'</h3></div>'+
      '<span class="ja-state '+(isOn?'ok':'off')+'">'+esc(String(d.status||'offline').toUpperCase())+'</span></header>'+
      '<section class="ja-iot-relay">'+
        '<div class="ja-iot-readout"><span>ESTADO FÍSICO</span><strong>'+relayStateLabel(d.relay_actual)+'</strong></div>'+
        '<div class="ja-iot-readout"><span>ESTADO DESEJADO</span><strong>'+relayStateLabel(d.relay_desired)+(pending?' ⏳':'')+'</strong></div>'+
        '<div class="ja-row-actions">'+
          '<button class="ja-mini primary" data-relay="1" data-device="'+attr(d.device_id)+'"'+(d.relay_actual===true&&!pending?' disabled':'')+'>LIGAR</button>'+
          '<button class="ja-mini" data-relay="0" data-device="'+attr(d.device_id)+'"'+(d.relay_actual===false&&!pending?' disabled':'')+'>DESLIGAR</button>'+
        '</div>'+
      '</section>'+
      '<dl class="ja-iot-meta"><dt>Local</dt><dd>'+esc(d.localizacao||'—')+'</dd><dt>Modelo</dt><dd>'+esc(d.model||'—')+'</dd><dt>IP</dt><dd>'+esc(d.ip_address||'—')+'</dd></dl>'+
      '<footer>Heartbeat: '+esc(d.ultimo_heartbeat||'—')+(d.desired_updated?' · Comando: '+esc(d.desired_updated):'')+'</footer>'+
    '</article>';
  })+pager('devices',p):
  '<div class="ja-empty">Nenhum equipamento com relé registrado. Dispositivos que anunciam a capacidade "relay" ou "switch" no heartbeat aparecem aqui automaticamente.</div>';
  moduleShell('Dispositivos & relés',summary+body,'<button id="relay-refresh" class="ja-mini">Atualizar</button>');
  bindPager('devices',p,renderDevices);
  const rf=document.getElementById('relay-refresh'); if(rf) rf.onclick=renderDevices;
  document.querySelectorAll('#app .ja-content [data-relay]').forEach(b=>b.onclick=async()=>{
    const all=document.querySelectorAll('#app .ja-content [data-device="'+CSS.escape(b.dataset.device)+'"]');
    all.forEach(x=>x.disabled=true);
    try{
      await postJson('/casa/api/iot_dashboard.php',{acao:'relay_state',device_id:b.dataset.device,relay_on:b.dataset.relay==='1'});
      await renderDevices();
      setTimeout(()=>{ if(document.getElementById('relay-refresh')) renderDevices(); },4000);
    }catch(e){
      alert(e.message||'Falha ao acionar o relé.');
      all.forEach(x=>x.disabled=false);
    }
  });
}

async function renderSensors(){
  loading('Sensores & telemetria');
  const j=await crud('sensores_telemetria','listar','&limite=50'); const all=j.dados||[]; const p=paginate('sensors',all,8);
  moduleShell('Sensores & telemetria',cards(p.slice,s=>'<article class="ja-native-card metric"><h3>'+esc(s.sensor_nome||'Sensor')+'</h3><strong>'+esc(s.valor_numerico??'—')+' '+esc(s.unidade||'')+'</strong><p>'+esc(s.data_hora||'')+'</p></article>')+pager('sensors',p));
  bindPager('sensors',p,renderSensors);
}


function telemetryDateInputValue(d){
  const pad=n=>String(n).padStart(2,'0');
  return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+'T'+pad(d.getHours())+':'+pad(d.getMinutes());
}

function openTelemetryChatModal(question){
  document.getElementById('ja-telemetry-chat-modal')?.remove();
  const back=document.createElement('div');
  back.id='ja-telemetry-chat-modal';
  back.className='ja-ai-chat-backdrop';
  back.innerHTML=
    '<section class="ja-ai-chat-modal" role="dialog" aria-modal="true">'+
      '<header><div><strong>COMPUTER · TELEMETRIA</strong><span>Análise em andamento</span></div><button type="button" data-close>FECHAR</button></header>'+
      '<div class="ja-ai-chat-body" id="ja-ai-chat-body">'+
        '<div class="ja-ai-msg user"><b>Você</b><div>'+esc(question)+'</div></div>'+
        '<div class="ja-ai-msg assistant pending" id="ja-ai-msg-assistant"><b>COMPUTER</b><div>Analisando sua pergunta...</div></div>'+
      '</div>'+
      '<aside class="ja-ai-task-panel"><strong>EXECUÇÃO</strong><div id="ja-ai-task-list"><div class="ja-ai-task pending">Criando tarefa principal...</div></div></aside>'+
    '</section>';
  document.body.appendChild(back);
  const close=()=>back.remove();
  back.querySelector('[data-close]').onclick=close;
  back.addEventListener('click',e=>{if(e.target===back)close();});
  return back;
}
function updateTelemetryChatModal(modal,result,errorText){
  if(!modal)return;
  const msg=modal.querySelector('#ja-ai-msg-assistant');
  const tasks=modal.querySelector('#ja-ai-task-list');
  const status=modal.querySelector('header span');
  if(errorText){
    if(msg){msg.className='ja-ai-msg assistant error';msg.querySelector('div').textContent=errorText;}
    if(status)status.textContent='Erro na execução';
    if(tasks)tasks.innerHTML='<div class="ja-ai-task error">A tarefa não pôde ser concluída.</div>';
    return;
  }
  if(msg){
    msg.className='ja-ai-msg assistant';
    msg.querySelector('div').textContent=(result&&result.resposta)?result.resposta:'Sem resposta.';
  }
  if(status)status.textContent='Concluído';
  if(tasks){
    const arr=(result&&Array.isArray(result.tarefas_execucao))?result.tarefas_execucao:[];
    tasks.innerHTML=arr.length?arr.map(t=>
      '<div class="ja-ai-task '+((t.status||'').match(/CONCL/i)?'ok':((t.status||'').match(/ERRO/i)?'error':'pending'))+'">'+
      '<span>#'+esc(t.ordem||'')+'</span><b>'+esc(t.titulo||'Tarefa')+'</b><em>'+esc(t.status||'')+'</em></div>'
    ).join(''):'<div class="ja-ai-task ok">Resposta concluída.</div>';
  }
}

async function renderOperationalTelemetry(page=1){
  loading('Telemetria operacional');
  const state=moduleState.telemetryOps||(moduleState.telemetryOps={});
  if(!state.ini){
    const fim=new Date(), ini=new Date(fim.getTime()-24*60*60*1000);
    state.ini=telemetryDateInputValue(ini);
    state.fim=telemetryDateInputValue(fim);
    state.origem='';
    state.busca='';
    state.aiQuestion='';
    state.aiAnswer='';
    state.aiSql='';
  }

  const qs=new URLSearchParams({
    inicio:state.ini||'',
    fim:state.fim||'',
    origem:state.origem||'',
    busca:state.busca||'',
    pagina:String(page),
    limite:'20'
  });

  const j=await getJson('/casa/api/telemetria_operacional.php?'+qs.toString());
  const rows=j.dados||[];
  const pages=Math.max(1,Number(j.paginas||1));

  const metrics=
    '<div class="ja-telemetry-metrics">'+
      '<div><span>EVENTOS</span><strong>'+esc(j.total??rows.length)+'</strong></div>'+
      '<div><span>PÁGINA</span><strong>'+page+' / '+pages+'</strong></div>'+
      '<div class="period"><span>PERÍODO</span><strong>'+esc((state.ini||'').replace('T',' ')+' → '+(state.fim||'').replace('T',' '))+'</strong></div>'+
    '</div>';

  const filters=
    '<div class="ja-telemetry-filters">'+
      '<label><span>INÍCIO</span><input id="tel-ini" type="datetime-local" value="'+attr(state.ini)+'"></label>'+
      '<label><span>FIM</span><input id="tel-fim" type="datetime-local" value="'+attr(state.fim)+'"></label>'+
      '<label><span>ORIGEM</span><input id="tel-origem" value="'+attr(state.origem)+'" placeholder="web, COMPUTER..."></label>'+
      '<label class="search"><span>OPERAÇÃO / TEXTO</span><input id="tel-busca" value="'+attr(state.busca)+'" placeholder="comando, resposta, ação..."></label>'+
      '<button id="tel-filtrar">FILTRAR</button>'+
    '</div>';

  const aiPanel=
    '<section class="ja-telemetry-ai">'+
      '<div class="ja-telemetry-ai-head">'+
        '<strong>ANÁLISE POR IA</strong>'+
        '<span>Somente SELECT · tabela telemetria_operacional</span>'+
      '</div>'+
      '<div class="ja-telemetry-ai-row">'+
        '<input id="tel-ai-q" value="'+attr(state.aiQuestion||'')+'" placeholder="Ex.: Quais falhas ocorreram hoje e de quais IPs vieram?">'+
        '<button id="tel-ai-ask">PERGUNTAR À IA</button>'+
        '<button id="tel-ai-auto">ANALISAR PERÍODO</button>'+
      '</div>'+
      '<div id="tel-ai-answer" class="ja-telemetry-ai-answer">'+esc(state.aiAnswer||'')+'</div>'+
      '<details id="tel-ai-details" '+(state.aiSql?'open':'')+'>'+
        '<summary>SQL gerado</summary>'+
        '<pre id="tel-ai-sql">'+esc(state.aiSql||'')+'</pre>'+
      '</details>'+
    '</section>';

  const list=
    '<div class="ja-telemetry-list">'+
      (rows.length?rows.map(r=>
        '<article class="ja-telemetry-event">'+
          '<header><strong>'+esc(r.data_hora||'')+'</strong><span class="ja-state '+((r.status||'').match(/SUCESS|OK|CONCL/i)?'ok':'')+'">'+esc(r.status||'—')+'</span></header>'+
          '<dl>'+
            '<dt>Origem</dt><dd>'+esc(r.origem||'—')+'</dd>'+
            '<dt>Canal</dt><dd>'+esc(r.canal||r.fonte||'—')+'</dd>'+
            '<dt>IP cliente</dt><dd>'+esc(r.ip_cliente||'—')+'</dd>'+
            '<dt>Operação</dt><dd>'+esc(r.operacao||'—')+'</dd>'+
            '<dt>Solicitado</dt><dd class="wide">'+esc(r.solicitacao||'—')+'</dd>'+
            '<dt>Resposta IA</dt><dd class="wide">'+esc(r.resposta_ia||'—')+'</dd>'+
            '<dt>O que foi feito</dt><dd class="wide">'+esc(r.acao_executada||r.resultado||'—')+'</dd>'+
            '<dt>Modelo</dt><dd>'+esc(r.modelo||'—')+'</dd>'+
            '<dt>Duração</dt><dd>'+esc(r.duracao_ms!=null?(r.duracao_ms+' ms'):'—')+'</dd>'+
          '</dl>'+
        '</article>'
      ).join(''):'<div class="ja-telemetry-empty">Nenhum evento encontrado neste período.</div>')+
    '</div>';

  const nav=
    '<div class="ja-telemetry-pages">'+
      '<button id="tel-prev" '+(page<=1?'disabled':'')+'>‹ ANTERIOR</button>'+
      '<span>'+page+' / '+pages+'</span>'+
      '<button id="tel-next" '+(page>=pages?'disabled':'')+'>PRÓXIMA ›</button>'+
    '</div>';

  moduleShell(
    'Telemetria operacional',
    '<div class="ja-telemetry-layout">'+metrics+filters+aiPanel+list+nav+'</div>'
  );

  document.getElementById('tel-filtrar').onclick=()=>{
    state.ini=document.getElementById('tel-ini').value;
    state.fim=document.getElementById('tel-fim').value;
    state.origem=document.getElementById('tel-origem').value.trim();
    state.busca=document.getElementById('tel-busca').value.trim();
    renderOperationalTelemetry(1);
  };

  document.getElementById('tel-busca').onkeydown=e=>{
    if(e.key==='Enter')document.getElementById('tel-filtrar').click();
  };

  const runTelemetryAI=async(autoMode)=>{
    const answer=document.getElementById('tel-ai-answer');
    const sqlBox=document.getElementById('tel-ai-sql');
    const details=document.getElementById('tel-ai-details');
    const askBtn=document.getElementById('tel-ai-ask');
    const autoBtn=document.getElementById('tel-ai-auto');
    const qInput=document.getElementById('tel-ai-q');

    const question=autoMode
      ? 'Analise este período da telemetria. Identifique falhas, operações mais frequentes, origens, IPs externos relevantes, respostas da IA, ações executadas, tempos anormais e padrões que mereçam atenção.'
      : qInput.value.trim();

    if(!question){
      answer.textContent='Digite uma pergunta sobre a telemetria.';
      answer.className='ja-telemetry-ai-answer error';
      return;
    }

    if(!autoMode) state.aiQuestion=question;
    const chatModal=openTelemetryChatModal(question);
    answer.textContent='Analisando a telemetria...';
    answer.className='ja-telemetry-ai-answer pending';
    askBtn.disabled=true;
    autoBtn.disabled=true;

    try{
      const r=await postJson('/casa/api/telemetria_ia.php',{
        pergunta:question,
        inicio:state.ini||'',
        fim:state.fim||''
      });
      updateTelemetryChatModal(chatModal,r,null);
      state.aiAnswer=r.resposta||'Sem resposta.';
      state.aiSql=r.sql||'';
      answer.textContent=state.aiAnswer;
      answer.className='ja-telemetry-ai-answer ok';
      sqlBox.textContent=state.aiSql;
      if(state.aiSql) details.open=true;
    }catch(e){
      updateTelemetryChatModal(chatModal,null,e.message||'Falha ao analisar a telemetria.');
      state.aiAnswer='';
      state.aiSql='';
      answer.textContent=e.message||'Falha ao analisar a telemetria.';
      answer.className='ja-telemetry-ai-answer error';
      sqlBox.textContent='';
    }finally{
      askBtn.disabled=false;
      autoBtn.disabled=false;
    }
  };

  document.getElementById('tel-ai-ask').onclick=()=>runTelemetryAI(false);
  document.getElementById('tel-ai-auto').onclick=()=>runTelemetryAI(true);
  document.getElementById('tel-ai-q').onkeydown=e=>{
    if(e.key==='Enter')runTelemetryAI(false);
  };

  document.getElementById('tel-prev').onclick=()=>renderOperationalTelemetry(Math.max(1,page-1));
  document.getElementById('tel-next').onclick=()=>renderOperationalTelemetry(Math.min(pages,page+1));
}

async function renderNodes(){
  loading('Clusters e agentes Linux ARM');
  try{
    const j=await getJson('/casa/api/arm_nodes.php');
    const all=j.nodes||[];
    const p=paginate('nodes',all,12);
    const states={online:'ONLINE',offline:'SEM CONTATO RECENTE',registered:'AGUARDANDO CONTATO',revoked:'REVOGADO'};
    const summary='<p>'+esc(all.length)+' agente(s) registrado(s) · '+esc(all.filter(n=>n.online).length)+' online. Inclui registros atuais e legados. Online: contato nos últimos 120 segundos.</p>';
    const body=all.length?cards(p.slice,n=>'<article class="ja-native-card"><h3>'+esc(n.nome)+'</h3><dl>'+
      '<dt>Identificador</dt><dd>'+esc(n.device_id||'Legado sem identificador')+'</dd>'+
      '<dt>Hostname</dt><dd>'+esc(n.hostname||'—')+'</dd>'+
      '<dt>Programa / função</dt><dd>'+esc(n.papel||'—')+'</dd>'+
      '<dt>Plataforma</dt><dd>'+esc(n.platform||'—')+'</dd>'+
      '<dt>IP</dt><dd>'+esc(n.ip_address||'—')+'</dd>'+
      '<dt>Versão implantada</dt><dd>'+(n.version?'<strong style="color:#00e5ff;font-family:monospace;font-size:0.95rem;">'+esc(n.version)+'</strong>':'<span style="opacity:0.6;">Não informada</span>')+(n.deployed_at?'<small style="display:block;opacity:0.8;font-size:0.75rem;margin-top:2px;">Atualizado: '+esc(n.deployed_at)+'</small>':'')+'</dd>'+
      '<dt>CPU</dt><dd>'+esc(n.cpu||'Não informada')+'</dd>'+
      '<dt>RAM</dt><dd>'+esc(n.ram||'Não informada')+'</dd>'+
      '<dt>Capacidades</dt><dd>'+esc((n.capabilities||[]).join(', ')||'Não informadas')+'</dd>'+
      '<dt>Último contato (servidor)</dt><dd>'+esc(n.last_seen||'Ainda não recebido')+'</dd>'+'<dt>Site do Cluster</dt><dd>'+(n.ip_address?'<a href="http://'+esc(n.ip_address)+':8080/" target="_blank" style="display:inline-block;padding:3px 10px;background:#38bdf8;color:#000;border-radius:4px;text-decoration:none;font-weight:bold;font-size:0.8rem;">🌐 ABRIR PORTAL (:8080)</a>':'—')+'</dd>'+'</dl><span class="ja-state '+(n.online?'ok':'')+'">'+esc(states[n.status]||n.status)+'</span></article>')+pager('nodes',p):
      '<div class="ja-empty">Nenhum agente Linux ARM registrado. Os agentes conectados pela API de dispositivos aparecem aqui ao informar a plataforma linux-arm ou a capacidade arm-agent.</div>';
    moduleShell('Clusters e agentes Linux ARM',summary+body,'<button id="nodes-refresh" class="ja-mini">Atualizar lista</button>');
    bindPager('nodes',p,renderNodes);
    document.getElementById('nodes-refresh').onclick=renderNodes;
  }catch(e){
    moduleShell('Clusters e agentes Linux ARM','<div class="ja-empty">'+esc(e.message||'Falha ao carregar agentes.')+'</div>','<button id="nodes-retry" class="ja-mini">Tentar novamente</button>');
    document.getElementById('nodes-retry').onclick=renderNodes;
  }
}


function scheduleExecutorLabel(t){
  const m={executar_cena:'CENA',dispositivo_rele:'RELÉ',aviso_fala:'FALA',comando_jarvis:'IA',dispositivo_devpar:'EQUIPAMENTO'};
  return m[t.tipo_acao]||String(t.executor_tipo||t.tipo_acao||'IA').toUpperCase();
}
function scheduleTargetLabel(t){
  if(t.tipo_acao==='dispositivo_rele'){const p=new URLSearchParams(t.payload||'');return (p.get('relay_on')==='1'?'LIGAR ':'DESLIGAR ')+(p.get('device_id')||'');}
  if(t.tipo_acao==='executar_cena') return String(t.target_node||'').replace(/^cena:/,'');
  return t.target_node||'local';
}
function scheduleCronLabel(t){
  if(t.cron_expr) return t.cron_expr;
  const h=String(t.horario||'').trim();
  const d=String(t.dias_semana||'*').trim()||'*';
  if(/^\d{1,2}:\d{2}$/.test(h)){
    const [hh,mm]=h.split(':');
    return Number(mm)+' '+Number(hh)+' * * '+d;
  }
  if(/^\/?\*\/\d+$/.test(h)) return h+' * * * '+d;
  return h||'—';
}
function scheduleCronValid(expr){
  const p=String(expr||'').trim().split(/\s+/);
  if(p.length!==5)return false;
  return p.every(x=>/^(\*|\*\/\d+|\d+|\d+-\d+|\d+(,\d+)+)$/.test(x));
}
function openScheduleEditor(){
  document.getElementById('ja-schedule-modal')?.remove();
  const modal=document.createElement('div');
  modal.id='ja-schedule-modal';
  modal.className='ja-schedule-backdrop';
  modal.innerHTML=
    '<section class="ja-schedule-modal" role="dialog" aria-modal="true">'+
      '<header><strong>NOVO AGENDAMENTO</strong><button type="button" data-close>FECHAR</button></header>'+
      '<div class="ja-schedule-form">'+
        '<label><span>Título</span><input id="sch-title" placeholder="Ex.: Acordar às 8"></label>'+
        '<label class="wide"><span>Descrição</span><input id="sch-desc" placeholder="Descrição opcional"></label>'+
        '<label class="wide"><span>Cron</span><input id="sch-cron" value="0 8 * * *" placeholder="min hora dia mês dia-semana"><small>Ex.: 0 8 * * * = todos os dias às 08:00 · 0 10 * * 1-5 = seg-sex às 10:00</small></label>'+
        '<label><span>Executor</span><select id="sch-executor"><option value="cena">EXECUTAR CENA</option><option value="rele">LIGAR/DESLIGAR RELÉ</option><option value="ia" selected>IA / COMPUTER</option><option value="fala">FALA</option><option value="equipamento">EQUIPAMENTO (legado)</option></select></label>'+
        '<label id="sch-target-wrap"><span>Destino</span><input id="sch-target" value="local" placeholder="local ou identificação do equipamento"></label>'+
        '<label class="wide"><span>Comando / mensagem</span><textarea id="sch-payload" rows="4" placeholder="Ex.: Me acorde; Ligue a luz da sala"></textarea></label>'+
        '<div id="sch-scene-fields" class="ja-schedule-device is-hidden"><label class="wide"><span>Cena</span><select id="sch-scene"><option value="">Carregando cenas...</option></select></label></div>'+
        '<div id="sch-relay-fields" class="ja-schedule-device is-hidden"><label><span>Relé</span><select id="sch-relay"><option value="">Carregando relés...</option></select></label><label><span>Ação</span><select id="sch-relay-on"><option value="1">LIGAR</option><option value="0">DESLIGAR</option></select></label></div>'+
        '<div id="sch-device-fields" class="ja-schedule-device is-hidden">'+
          '<label><span>ID equipamento</span><input id="sch-device-id" type="number" min="1" placeholder="1"></label>'+
          '<label><span>Parâmetro</span><input id="sch-device-par" value="dev1"></label>'+
          '<label><span>Valor</span><input id="sch-device-value" value="1"></label>'+
        '</div>'+
        '<div id="sch-msg" class="ja-schedule-message"></div>'+
      '</div>'+
      '<footer><button type="button" data-save>SALVAR AGENDAMENTO</button></footer>'+
    '</section>';
  document.body.appendChild(modal);
  const close=()=>modal.remove();
  modal.querySelector('[data-close]').onclick=close;
  modal.addEventListener('click',e=>{if(e.target===modal)close();});
  const executor=modal.querySelector('#sch-executor');
  const dev=modal.querySelector('#sch-device-fields');
  const sceneBox=modal.querySelector('#sch-scene-fields'), relayBox=modal.querySelector('#sch-relay-fields');
  const payloadWrap=modal.querySelector('#sch-payload').closest('label'), targetWrap=modal.querySelector('#sch-target-wrap');
  executor.onchange=()=>{
    const v=executor.value;
    dev.classList.toggle('is-hidden',v!=='equipamento');
    sceneBox.classList.toggle('is-hidden',v!=='cena');
    relayBox.classList.toggle('is-hidden',v!=='rele');
    payloadWrap.style.display=(v==='ia'||v==='fala')?'':'none';
    targetWrap.style.display=(v==='ia'||v==='fala')?'':'none';
  };
  executor.onchange();
  // Cenas e relés vêm da API de automação (os mesmos de Operações › Cenas e regras).
  getJson('/casa/api/automacao.php?acao=estado').then(st=>{
    const sc=modal.querySelector('#sch-scene'), rl=modal.querySelector('#sch-relay');
    sc.innerHTML=(st.scenes||[]).length?st.scenes.map(x=>'<option value="'+attr(x.id)+'">'+esc(x.nome)+(x.ativo?'':' (pausada)')+'</option>').join(''):'<option value="">Nenhuma cena — crie em Cenas e regras</option>';
    const relays=(st.devices||[]).filter(d=>d.is_relay);
    rl.innerHTML=relays.length?relays.map(d=>'<option value="'+attr(d.device_id)+'">'+esc(d.nome)+(d.localizacao?' · '+esc(d.localizacao):'')+'</option>').join(''):'<option value="">Nenhum relé registrado</option>';
  }).catch(()=>{modal.querySelector('#sch-scene').innerHTML='<option value="">Falha ao carregar</option>';modal.querySelector('#sch-relay').innerHTML='<option value="">Falha ao carregar</option>';});
  modal.querySelector('[data-save]').onclick=async()=>{
    const title=modal.querySelector('#sch-title').value.trim();
    const cron=modal.querySelector('#sch-cron').value.trim();
    const payloadText=modal.querySelector('#sch-payload').value.trim();
    const msg=modal.querySelector('#sch-msg');
    if(!title){msg.textContent='Informe o título.';msg.className='ja-schedule-message error';return;}
    if(!scheduleCronValid(cron)){msg.textContent='Cron inválido. Use 5 campos, por exemplo: 0 8 * * *';msg.className='ja-schedule-message error';return;}
    let body={acao:'criar',titulo:title,descricao:modal.querySelector('#sch-desc').value.trim(),cron_expr:cron,executor:executor.value,target_node:modal.querySelector('#sch-target').value.trim()||'local',payload:payloadText};
    if(executor.value==='cena'){
      body.scene_id=Number(modal.querySelector('#sch-scene').value||0);body.payload='';
      if(!body.scene_id){msg.textContent='Escolha a cena.';msg.className='ja-schedule-message error';return;}
    }else if(executor.value==='rele'){
      body.device_id=modal.querySelector('#sch-relay').value;body.relay_on=modal.querySelector('#sch-relay-on').value==='1';body.payload='';
      if(!body.device_id){msg.textContent='Escolha o relé.';msg.className='ja-schedule-message error';return;}
    }else if(executor.value==='equipamento'){
      body.device_id=Number(modal.querySelector('#sch-device-id').value||0);
      body.devparname=modal.querySelector('#sch-device-par').value.trim()||'dev1';
      body.valor=modal.querySelector('#sch-device-value').value;
      if(!body.device_id){msg.textContent='Informe o ID do equipamento.';msg.className='ja-schedule-message error';return;}
    }else if(!payloadText){msg.textContent='Informe o comando ou mensagem.';msg.className='ja-schedule-message error';return;}
    const save=modal.querySelector('[data-save]');
    save.disabled=true;save.textContent='SALVANDO...';
    try{
      await postJson('/casa/api/agendamentos.php',body);
      close();
      renderSchedules();
    }catch(e){
      msg.textContent=e.message||'Falha ao salvar.';
      msg.className='ja-schedule-message error';
      save.disabled=false;save.textContent='SALVAR AGENDAMENTO';
    }
  };
}
async function renderSchedules(){
  loading('Agendamentos');
  const j=await getJson('/casa/api/agendamentos.php?acao=listar');
  const all=j.dados||[];
  const p=paginate('schedules',all,6);
  const body=cards(p.slice,t=>'<article class="ja-native-card"><h3>'+esc(t.titulo||('Tarefa '+t.id))+'</h3><p>'+esc(t.descricao||'')+'</p><dl><dt>Cron</dt><dd><code>'+esc(scheduleCronLabel(t))+'</code></dd><dt>Executor</dt><dd>'+esc(scheduleExecutorLabel(t))+'</dd><dt>Destino</dt><dd>'+esc(scheduleTargetLabel(t))+'</dd></dl><div class="ja-row-actions">'+actionBtn('RODAR AGORA','run:'+t.id,'primary')+actionBtn(t.ativo?'PAUSAR':'ATIVAR','toggle:'+t.id)+actionBtn('EXCLUIR','delete:'+t.id,'danger')+'</div></article>')+pager('schedules',p);
  moduleShell('Agendamentos',body,actionBtn('NOVO AGENDAMENTO','new','primary'));
  document.querySelector('.ja-native-actions [data-act="new"]')?.addEventListener('click',openScheduleEditor);
  document.querySelectorAll('.ja-native-body [data-act]').forEach(b=>b.onclick=async()=>{
    const [a,id]=b.dataset.act.split(':');
    try{
      if(a==='run') await postJson('/casa/api/agendamentos.php',{acao:'executar',id:Number(id)});
      if(a==='toggle'){
        const t=all.find(x=>String(x.id)===id);
        await postJson('/casa/api/agendamentos.php',{acao:'toggle',id:Number(id),ativo:!Number(t.ativo)});
      }
      if(a==='delete'&&confirm('Excluir agendamento?')) await postJson('/casa/api/agendamentos.php',{acao:'excluir',id:Number(id)});
      renderSchedules();
    }catch(e){alert(e.message||'Falha no agendamento.');}
  });
  bindPager('schedules',p,renderSchedules);
}

// ---------------------------------------------------------------------------
// Cenas e regras (módulo nativo). Cena = conjunto de ações disparadas de uma vez
// (botão EXECUTAR ou um agendamento). Regra = "quando X acontecer, faça Y".
// Comandos LIGAR/DESLIGAR em relés viram estado desejado (api/automation_common.php).
// ---------------------------------------------------------------------------
let automationTab='cenas';
const AUTO_API='/casa/api/automacao.php';
const AUTO_OPS={eq:'=',neq:'≠',gt:'>',gte:'≥',lt:'<',lte:'≤',contains:'contém',exists:'existe'};
function autoCmdLabel(c){
  const k=String(c||'').toLowerCase();
  if(['power_on','on','turn_on','relay_on','switch_on','ligar'].includes(k)) return 'LIGAR';
  if(['power_off','off','turn_off','relay_off','switch_off','desligar'].includes(k)) return 'DESLIGAR';
  if(['toggle','relay_toggle','alternar'].includes(k)) return 'ALTERNAR';
  return String(c||'').toUpperCase();
}
function autoDevName(state,id){const d=(state.devices||[]).find(x=>x.device_id===id);return d?(d.nome||id):id+' (não encontrado)';}
function autoActionsText(state,actions){
  if(!actions||!actions.length) return '<em>Nenhuma ação</em>';
  return '<ul class="ja-auto-steps">'+actions.map(a=>'<li><b>'+esc(autoCmdLabel(a.comando))+'</b> '+esc(autoDevName(state,a.device_id))+'</li>').join('')+'</ul>';
}
function autoCondText(c){
  const f=String(c.field||'').replace(/^data\./,'');
  const v=c.value===true?'ligado/verdadeiro':c.value===false?'desligado/falso':String(c.value??'');
  return esc(f)+' '+esc(AUTO_OPS[c.op]||c.op)+(c.op==='exists'?'':' '+esc(v));
}
function autoRuleWhen(state,r){
  const tc=r.trigger_config||{};
  if(r.trigger_type==='schedule') return 'Horário (formato antigo) — recrie em Operações > Agendamentos';
  const src=tc.device_id?autoDevName(state,tc.device_id):'qualquer dispositivo';
  let t=r.trigger_type==='state'?'O estado de <b>'+esc(src)+'</b> atender':'<b>'+esc(src)+'</b> enviar o evento <b>'+esc(tc.event_type||'(qualquer)')+'</b>';
  if((r.conditions||[]).length) t+=' com '+r.conditions.map(autoCondText).join(' e ');
  return t;
}

async function renderAutomation(){
  loading('Cenas e regras');
  let st;
  try{ st=await getJson(AUTO_API+'?acao=estado'); }
  catch(e){
    moduleShell('Cenas e regras','<div class="ja-empty">'+esc(e.message||'Falha ao carregar.')+'</div>','<button id="auto-retry" class="ja-mini">Tentar novamente</button>');
    document.getElementById('auto-retry').onclick=renderAutomation; return;
  }
  const tabs='<button class="ja-mini '+(automationTab==='cenas'?'primary':'')+'" data-auto-tab="cenas">CENAS ('+st.scenes.length+')</button>'+
    '<button class="ja-mini '+(automationTab==='regras'?'primary':'')+'" data-auto-tab="regras">REGRAS ('+st.rules.length+')</button>'+
    '<button class="ja-mini primary" data-auto-new>'+(automationTab==='cenas'?'+ NOVA CENA':'+ NOVA REGRA')+'</button>';
  let body;
  if(automationTab==='cenas'){
    const intro='<p class="ja-auto-intro"><b>Cena</b> é um conjunto de ações disparadas de uma vez — ex.: "Sair de casa" desliga a bomba e as luzes. Execute pelo botão abaixo, pelo app, ou programe em <b>Operações › Agendamentos</b>.</p>';
    const p=paginate('automation-scenes',st.scenes,6);
    body=intro+(st.scenes.length?cards(p.slice,s=>{
      const lr=s.last_run?('Última execução: '+esc(s.last_run.criado_em)+' · '+esc(s.last_run.status)):'Nunca executada';
      return '<article class="ja-native-card ja-auto-card">'+
        '<header class="ja-auto-head"><h3>'+esc(s.nome)+'</h3><span class="ja-state '+(s.ativo?'ok':'off')+'">'+(s.ativo?'ATIVA':'PAUSADA')+'</span></header>'+
        (s.descricao?'<p>'+esc(s.descricao)+'</p>':'')+autoActionsText(st,s.actions)+'<small class="ja-auto-foot">'+lr+'</small>'+
        '<div class="ja-row-actions">'+actionBtn('EXECUTAR','run:'+s.id,'primary')+actionBtn('EDITAR','edit:'+s.id)+actionBtn(s.ativo?'PAUSAR':'ATIVAR','toggle:'+s.id)+actionBtn('EXCLUIR','delete:'+s.id,'danger')+'</div>'+
      '</article>';
    })+pager('automation-scenes',p):'<div class="ja-empty">Nenhuma cena criada. Use <b>+ NOVA CENA</b> para montar a primeira.</div>');
    moduleShell('Cenas e regras',body,tabs);
    bindPager('automation-scenes',p,renderAutomation);
  }else{
    const intro='<p class="ja-auto-intro"><b>Regra</b> reage sozinha a um dispositivo: "<i>quando</i> o sensor/botão X enviar um evento (ou o estado dele mudar), <i>então</i> ligue/desligue Y". Para horários fixos use <b>Operações › Agendamentos</b>.</p>';
    const p=paginate('automation-rules',st.rules,6);
    body=intro+(st.rules.length?cards(p.slice,r=>'<article class="ja-native-card ja-auto-card">'+
        '<header class="ja-auto-head"><h3>'+esc(r.nome)+'</h3><span class="ja-state '+(r.enabled?'ok':'off')+'">'+(r.enabled?'ATIVA':'PAUSADA')+'</span></header>'+
        '<p><span class="ja-auto-tag">QUANDO</span> '+autoRuleWhen(st,r)+'</p>'+
        '<div><span class="ja-auto-tag">ENTÃO</span>'+autoActionsText(st,r.actions)+'</div>'+
        '<small class="ja-auto-foot">Intervalo mínimo: '+esc(r.cooldown_seconds)+' s · Último disparo: '+esc(r.last_triggered_at||'nunca')+'</small>'+
        '<div class="ja-row-actions">'+(r.trigger_type==='schedule'?'':actionBtn('EDITAR','edit:'+r.id))+actionBtn(r.enabled?'PAUSAR':'ATIVAR','toggle:'+r.id)+actionBtn('EXCLUIR','delete:'+r.id,'danger')+'</div>'+
      '</article>')+pager('automation-rules',p):'<div class="ja-empty">Nenhuma regra criada. Use <b>+ NOVA REGRA</b>.</div>');
    moduleShell('Cenas e regras',body,tabs);
    bindPager('automation-rules',p,renderAutomation);
  }
  document.querySelectorAll('[data-auto-tab]').forEach(b=>b.onclick=()=>{automationTab=b.dataset.autoTab;renderAutomation();});
  document.querySelector('[data-auto-new]').onclick=()=>automationTab==='cenas'?openSceneEditor(st,null):openRuleEditor(st,null);
  document.querySelectorAll('.ja-native-body [data-act]').forEach(b=>b.onclick=async()=>{
    const [a,idS]=b.dataset.act.split(':'); const id=Number(idS);
    const isScene=automationTab==='cenas';
    const item=(isScene?st.scenes:st.rules).find(x=>x.id===id);
    try{
      if(a==='edit') return isScene?openSceneEditor(st,item):openRuleEditor(st,item);
      if(a==='toggle') await postJson(AUTO_API,{acao:isScene?'cena_ativar':'regra_ativar',id,ativo:!(isScene?item.ativo:item.enabled)});
      if(a==='delete'){ if(!confirm('Excluir "'+(item?.nome||'')+'"?')) return; await postJson(AUTO_API,{acao:isScene?'cena_excluir':'regra_excluir',id}); }
      if(a==='run'){
        b.disabled=true;
        let r;
        try{ r=await postJson(AUTO_API,{acao:'cena_executar',id}); }
        catch(e){
          if(/sensível|confirm/i.test(e.message||'') && confirm('Esta cena tem ação sensível. Executar mesmo assim?')) r=await postJson(AUTO_API,{acao:'cena_executar',id,confirmar:true});
          else throw e;
        }
        const errs=(r.errors||[]).length, rel=r.relays_applied||0, other=(r.queued||[]).length-rel;
        alert('Cena "'+(item?.nome||'')+'" executada: '+rel+' relé(s) acionado(s)'+(other>0?', '+other+' comando(s) enviados a outros dispositivos':'')+(errs?', '+errs+' falha(s)':'')+'.');
      }
      renderAutomation();
    }catch(e){ alert(e.message||'Falha na operação.'); b.disabled=false; }
  });
}

function autoDeviceOptions(devices,selected,filter){
  const list=filter?devices.filter(filter):devices;
  const relays=list.filter(d=>d.is_relay), others=list.filter(d=>!d.is_relay);
  const opt=d=>'<option value="'+attr(d.device_id)+'"'+(d.device_id===selected?' selected':'')+'>'+esc(d.nome)+(d.localizacao?' · '+esc(d.localizacao):'')+(String(d.status||'').toLowerCase()==='online'?'':' (offline)')+'</option>';
  let h='<option value="">— escolha —</option>';
  if(relays.length) h+='<optgroup label="Relés">'+relays.map(opt).join('')+'</optgroup>';
  if(others.length) h+='<optgroup label="Outros dispositivos">'+others.map(opt).join('')+'</optgroup>';
  if(selected && !list.some(d=>d.device_id===selected)) h+='<option value="'+attr(selected)+'" selected>'+esc(selected)+' (não encontrado)</option>';
  return h;
}
// Linhas de ação reaproveitadas pelos editores de cena e regra.
function autoActionRow(st,a){
  a=a||{};
  const cmd=a.comando||'power_on';
  const label=autoCmdLabel(cmd);
  const known=['LIGAR','DESLIGAR','ALTERNAR'].includes(label);
  const row=document.createElement('div'); row.className='ja-auto-action';
  row.innerHTML='<select data-f="device">'+autoDeviceOptions(st.devices,a.device_id||'')+'</select>'+
    '<select data-f="cmd"><option value="power_on"'+(label==='LIGAR'?' selected':'')+'>LIGAR</option><option value="power_off"'+(label==='DESLIGAR'?' selected':'')+'>DESLIGAR</option><option value="toggle"'+(label==='ALTERNAR'?' selected':'')+'>ALTERNAR</option><option value="custom"'+(known?'':' selected')+'>OUTRO COMANDO…</option></select>'+
    '<input data-f="custom" placeholder="comando (ex.: volume_up)" value="'+(known?'':attr(cmd))+'">'+
    '<input data-f="payload" placeholder=\'parâmetros JSON (opcional) ex.: {"nivel":50}\' value="'+(known||!a.payload||!Object.keys(a.payload).length?'':attr(JSON.stringify(a.payload)))+'">'+
    '<button type="button" class="ja-mini danger" data-f="del" title="Remover ação">✕</button>';
  const sel=row.querySelector('[data-f=cmd]'), dev=row.querySelector('[data-f=device]');
  const sync=()=>{const c=sel.value==='custom';row.querySelector('[data-f=custom]').style.display=c?'':'none';row.querySelector('[data-f=payload]').style.display=c?'':'none';row.classList.toggle('is-custom',c);};
  dev.onchange=()=>{const d=st.devices.find(x=>x.device_id===dev.value); if(d&&!d.is_relay&&sel.value!=='custom'){sel.value='custom';} sync();};
  sel.onchange=sync; sync();
  row.querySelector('[data-f=del]').onclick=()=>row.remove();
  return row;
}
function autoCollectActions(box){
  const out=[];
  for(const row of box.querySelectorAll('.ja-auto-action')){
    const device_id=row.querySelector('[data-f=device]').value;
    let comando=row.querySelector('[data-f=cmd]').value, payload='';
    if(comando==='custom'){comando=row.querySelector('[data-f=custom]').value.trim();payload=row.querySelector('[data-f=payload]').value.trim();}
    if(!device_id&&!comando) continue;
    if(!device_id) throw new Error('Escolha o dispositivo de cada ação.');
    if(!comando) throw new Error('Informe o comando da ação personalizada.');
    if(payload){try{JSON.parse(payload);}catch(e){throw new Error('Parâmetros JSON inválidos em "'+comando+'".');}}
    out.push({device_id,comando,payload});
  }
  if(!out.length) throw new Error('Adicione pelo menos uma ação.');
  return out;
}
function autoModal(title,inner,onSave){
  document.getElementById('ja-auto-modal')?.remove();
  const m=document.createElement('div'); m.id='ja-auto-modal'; m.className='ja-schedule-backdrop';
  m.innerHTML='<section class="ja-schedule-modal ja-auto-modal" role="dialog" aria-modal="true"><header><strong>'+esc(title)+'</strong><button type="button" data-close>FECHAR</button></header>'+
    '<div class="ja-schedule-form">'+inner+'<div class="ja-schedule-message" data-msg></div></div><footer><button type="button" data-save>SALVAR</button></footer></section>';
  document.body.appendChild(m);
  const close=()=>m.remove();
  m.querySelector('[data-close]').onclick=close;
  m.addEventListener('click',e=>{if(e.target===m)close();});
  const save=m.querySelector('[data-save]'), msg=m.querySelector('[data-msg]');
  save.onclick=async()=>{
    msg.textContent='';msg.className='ja-schedule-message';
    save.disabled=true;save.textContent='SALVANDO...';
    try{ await onSave(m); close(); renderAutomation(); }
    catch(e){ msg.textContent=e.message||'Falha ao salvar.'; msg.className='ja-schedule-message error'; save.disabled=false; save.textContent='SALVAR'; }
  };
  return m;
}
function openSceneEditor(st,s){
  const m=autoModal(s?'EDITAR CENA':'NOVA CENA',
    '<label><span>Nome</span><input data-k="nome" value="'+attr(s?.nome||'')+'" placeholder="Ex.: Sair de casa"></label>'+
    '<label><span>Situação</span><select data-k="ativo"><option value="1">Ativa</option><option value="0"'+(s&&!s.ativo?' selected':'')+'>Pausada</option></select></label>'+
    '<label class="wide"><span>Descrição</span><input data-k="descricao" value="'+attr(s?.descricao||'')+'" placeholder="Opcional"></label>'+
    '<div class="wide ja-auto-block"><span class="ja-auto-label">AÇÕES (executadas em ordem)</span><div data-actions></div><button type="button" class="ja-mini" data-add>+ ADICIONAR AÇÃO</button></div>'+
    '<label class="wide ja-auto-check"><input type="checkbox" data-k="stop"'+(s?.stop_on_error?' checked':'')+'> Parar a cena se uma ação falhar</label>',
    async mm=>{
      const nome=mm.querySelector('[data-k=nome]').value.trim(); if(!nome) throw new Error('Informe o nome da cena.');
      await postJson(AUTO_API,{acao:'cena_salvar',id:s?.id||0,nome,descricao:mm.querySelector('[data-k=descricao]').value.trim(),ativo:mm.querySelector('[data-k=ativo]').value==='1',stop_on_error:mm.querySelector('[data-k=stop]').checked,actions:autoCollectActions(mm.querySelector('[data-actions]'))});
    });
  const box=m.querySelector('[data-actions]');
  (s?.actions?.length?s.actions:[null]).forEach(a=>box.appendChild(autoActionRow(st,a)));
  m.querySelector('[data-add]').onclick=()=>box.appendChild(autoActionRow(st,null));
}
function autoCondRow(fields,c){
  c=c||{};
  const row=document.createElement('div'); row.className='ja-auto-cond';
  const ops=Object.entries(AUTO_OPS).map(([k,v])=>'<option value="'+k+'"'+((c.op||'eq')===k?' selected':'')+'>'+esc(v)+'</option>').join('');
  const val=c.value===true?'true':c.value===false?'false':(c.value??'');
  row.innerHTML='<input data-f="field" list="ja-auto-fields" placeholder="campo (ex.: temperature_c)" value="'+attr(String(c.field||'').replace(/^data\./,''))+'"><select data-f="op">'+ops+'</select><input data-f="value" placeholder="valor (ex.: 30, true)" value="'+attr(val)+'"><button type="button" class="ja-mini danger" data-f="del">✕</button>';
  row.querySelector('[data-f=del]').onclick=()=>row.remove();
  return row;
}
function openRuleEditor(st,r){
  const tc=r?.trigger_config||{};
  const m=autoModal(r?'EDITAR REGRA':'NOVA REGRA',
    '<label><span>Nome</span><input data-k="nome" value="'+attr(r?.nome||'')+'" placeholder="Ex.: Presença acende corredor"></label>'+
    '<label><span>Situação</span><select data-k="ativo"><option value="1">Ativa</option><option value="0"'+(r&&!r.enabled?' selected':'')+'>Pausada</option></select></label>'+
    '<div class="wide ja-auto-block"><span class="ja-auto-label">QUANDO</span><div class="ja-auto-when">'+
      '<select data-k="tipo"><option value="event">o dispositivo enviar um evento</option><option value="state"'+(r?.trigger_type==='state'?' selected':'')+'>o estado do dispositivo atender às condições</option></select>'+
      '<select data-k="src">'+autoDeviceOptions(st.devices,tc.device_id||'')+'</select>'+
      '<input data-k="evt" list="ja-auto-events" placeholder="tipo do evento (ex.: sensor.presence) — vazio = qualquer" value="'+attr(tc.event_type||'')+'">'+
    '</div><datalist id="ja-auto-events"></datalist><datalist id="ja-auto-fields"></datalist>'+
    '<span class="ja-auto-label">E SE (condições — todas precisam ser verdadeiras)</span><div data-conds></div><button type="button" class="ja-mini" data-addc>+ CONDIÇÃO</button></div>'+
    '<div class="wide ja-auto-block"><span class="ja-auto-label">ENTÃO</span><div data-actions></div><button type="button" class="ja-mini" data-add>+ ADICIONAR AÇÃO</button></div>'+
    '<label><span>Intervalo mínimo entre disparos (s)</span><input data-k="cool" type="number" min="0" value="'+attr(r?.cooldown_seconds??60)+'"></label>'+
    '<label><span>Descrição</span><input data-k="descricao" value="'+attr(r?.descricao||'')+'" placeholder="Opcional"></label>',
    async mm=>{
      const nome=mm.querySelector('[data-k=nome]').value.trim(); if(!nome) throw new Error('Informe o nome da regra.');
      const src=mm.querySelector('[data-k=src]').value; if(!src) throw new Error('Escolha o dispositivo que dispara a regra.');
      const conditions=[...mm.querySelectorAll('.ja-auto-cond')].map(x=>({field:x.querySelector('[data-f=field]').value.trim(),op:x.querySelector('[data-f=op]').value,value:x.querySelector('[data-f=value]').value})).filter(c=>c.field);
      await postJson(AUTO_API,{acao:'regra_salvar',id:r?.id||0,nome,descricao:mm.querySelector('[data-k=descricao]').value.trim(),enabled:mm.querySelector('[data-k=ativo]').value==='1',
        trigger_type:mm.querySelector('[data-k=tipo]').value,trigger_device_id:src,trigger_event_type:mm.querySelector('[data-k=evt]').value.trim(),
        conditions,cooldown_seconds:Number(mm.querySelector('[data-k=cool]').value||0),actions:autoCollectActions(mm.querySelector('[data-actions]'))});
    });
  const tipo=m.querySelector('[data-k=tipo]'), srcSel=m.querySelector('[data-k=src]'), evt=m.querySelector('[data-k=evt]');
  const refresh=()=>{
    evt.style.display=tipo.value==='event'?'':'none';
    const d=st.devices.find(x=>x.device_id===srcSel.value);
    m.querySelector('#ja-auto-fields').innerHTML=(d?.data_fields||[]).concat(d?.is_relay?['relay_on']:[]).filter((v,i,a)=>a.indexOf(v)===i).map(f=>'<option value="'+attr(f)+'">').join('');
    m.querySelector('#ja-auto-events').innerHTML=(st.events||[]).filter(e=>e.device_id===srcSel.value).map(e=>'<option value="'+attr(e.tipo)+'">').join('');
  };
  tipo.onchange=refresh; srcSel.onchange=refresh; refresh();
  const conds=m.querySelector('[data-conds]');
  (r?.conditions||[]).forEach(c=>conds.appendChild(autoCondRow([],c)));
  m.querySelector('[data-addc]').onclick=()=>conds.appendChild(autoCondRow([],null));
  const box=m.querySelector('[data-actions]');
  (r?.actions?.length?r.actions:[null]).forEach(a=>box.appendChild(autoActionRow(st,a)));
  m.querySelector('[data-add]').onclick=()=>box.appendChild(autoActionRow(st,null));
}

async function renderIoT(){
  loading('Dispositivos ESP32 / Arduino / IoT');
  try{
    const j=await crud('dispositivos_cluster');
    const raw=j.dados||[];
    // Strictly filter ONLY ESP32, Arduino, ESP8266 and microcontroller IoT devices.
    // Exclude all cluster machines (Raspberry Pi, Cubieboard, Linux ARM nodes).
    const all=raw.filter(d=>{
      const tipo=String(d.tipo||'').toLowerCase();
      const devId=String(d.device_id||'').toLowerCase();
      const model=String(d.model||'').toLowerCase();
      const caps=Array.isArray(d.capabilities)?d.capabilities.join(' ').toLowerCase():String(d.capabilities||'').toLowerCase();
      if(tipo==='linux-arm'||tipo==='cluster'||tipo==='server'||caps.includes('arm-agent')||
         devId.startsWith('raspberry')||devId.startsWith('cubie')||
         model.includes('raspberry')||model.includes('cubieboard')){
        return false;
      }
      return true;
    });

    const p=paginate('iot',all,8);
    const summary='<p>'+esc(all.length)+' dispositivo(s) ESP32 / Arduino / IoT · '+esc(all.filter(d=>(d.status||'').toLowerCase()==='online').length)+' online. Exclusivo para microcontroladores (câmeras ESP32-CAM, relés, voz e sensores).</p>';

    const body=all.length?cards(p.slice,d=>{
      const tipo=String(d.tipo||'iot').toUpperCase();
      const badgeColor=tipo.includes('ESP32')?'#e06666':(tipo.includes('ARDUINO')?'#00979d':(tipo.includes('ESP8266')?'#f6b26b':'#674ea7'));
      const rssi=Number(d.sinal_rssi||0);
      const rssiLabel=rssi===0?'Cabeada / Ethernet':(rssi+' dBm '+(rssi>-60?'📶 Excelente':(rssi>-75?'📶 Bom':'📶 Fraco')));
      let capsArr=[];
      try{
        capsArr=Array.isArray(d.capabilities)?d.capabilities:(typeof d.capabilities==='string'&&d.capabilities.startsWith('[')?JSON.parse(d.capabilities):[]);
      }catch(e){capsArr=[];}
      const isRelay=capsArr.includes('relay')||capsArr.includes('switch');
      const isCam=capsArr.includes('camera')||capsArr.includes('streaming');

      let actions='';
      if(isRelay){
        actions+='<a href="/casa/dispositivos_iot.php" class="ja-mini" style="margin-right:6px;background:#f59c73;color:#000;font-weight:bold;text-decoration:none;padding:3px 8px;border-radius:4px;">⚡ Relés</a>';
      }
      if(isCam){
        actions+='<a href="/casa/index.php?grupo=SEGURANCA&item=cameras" class="ja-mini" style="margin-right:6px;background:#8eb9ee;color:#000;font-weight:bold;text-decoration:none;padding:3px 8px;border-radius:4px;">📷 Câmera</a>';
      }
      if(d.ip_address){
        actions+='<a href="http://'+esc(d.ip_address)+'/" target="_blank" class="ja-mini" style="background:#444;color:#fff;font-weight:bold;text-decoration:none;padding:3px 8px;border-radius:4px;">🌐 Web IP</a>';
      }

      return '<article class="ja-native-card">'+
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">'+
          '<h3>'+esc(d.nome||d.device_id)+'</h3>'+
          '<span style="background:'+badgeColor+';color:#fff;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:bold;">'+esc(tipo)+'</span>'+
        '</div>'+
        '<p>'+esc(d.localizacao||'Local não definido')+' · <i>'+esc(d.model||'Módulo IoT')+'</i></p>'+
        '<dl>'+
          '<dt>Identificador</dt><dd>'+esc(d.device_id)+'</dd>'+
          '<dt>Endereço IP</dt><dd>'+esc(d.ip_address||'—')+'</dd>'+
          '<dt>MAC Address</dt><dd>'+esc(d.mac_address||'—')+'</dd>'+
          '<dt>Conexão / Sinal</dt><dd>'+esc(rssiLabel)+'</dd>'+
          '<dt>Capacidades</dt><dd>'+esc(capsArr.join(', ')||'Geral')+'</dd>'+
          '<dt>Ações</dt><dd>'+(actions||'—')+'</dd>'+
        '</dl>'+
        '<span class="ja-state '+((d.status||'').toLowerCase()==='online'?'ok':'off')+'">'+esc((d.status||'OFFLINE').toUpperCase())+'</span>'+
      '</article>';
    })+pager('iot',p):
    '<div class="ja-empty">Nenhum módulo ESP32 ou Arduino registrado no momento. Os microcontroladores pareados via Wi-Fi aparecerão aqui automaticamente.</div>';

    moduleShell('Dispositivos ESP32 / Arduino / IoT',summary+body,'<button id="iot-refresh" class="ja-mini">Atualizar lista</button>');
    bindPager('iot',p,renderIoT);
    document.getElementById('iot-refresh').onclick=renderIoT;
  }catch(e){
    moduleShell('Dispositivos ESP32 / Arduino / IoT','<div class="ja-empty">'+esc(e.message||'Falha ao carregar dispositivos.')+'</div>','<button id="iot-retry" class="ja-mini">Tentar novamente</button>');
    document.getElementById('iot-retry').onclick=renderIoT;
  }
}

async function renderSecurity(){
  loading('Defesa & anti-intrusão');
  const [ips,logs]=await Promise.all([crud('seguranca_ips_bloqueados'),crud('seguranca_logs','listar','&limite=20')]);
  const all=(logs.dados||[]); const p=paginate('security',all,6);
  const summary='<div class="ja-native-summary"><div><b>IPs BLOQUEADOS</b><strong>'+esc((ips.dados||[]).length)+'</strong></div><div><b>EVENTOS</b><strong>'+esc(all.length)+'</strong></div></div>';
  moduleShell('Defesa & anti-intrusão',summary+cards(p.slice,l=>'<article class="ja-native-card"><h3>'+esc(l.evento||'Evento')+'</h3><p>'+esc(l.detalhes||'')+'</p><dl><dt>IP</dt><dd>'+esc(l.origem_ip||'—')+'</dd><dt>Severidade</dt><dd>'+esc(l.severidade||'INFO')+'</dd><dt>Data</dt><dd>'+esc(l.data_hora||'')+'</dd></dl></article>')+pager('security',p));
  bindPager('security',p,renderSecurity);
}

async function renderAgents(){
  loading('Agentes externos');
  const [ag,clima,gh]=await Promise.all([
    crud('agentes_externos'),
    getJson('/casa/api/agente_externo.php?acao=consultar_clima').catch(()=>({})),
    getJson('/casa/api/google_home.php?acao=status').catch(e=>({online:false,mensagem:e.message,devices:[]}))
  ]);
  const all=ag.dados||[];
  const devices=Array.isArray(gh.devices)?gh.devices:[];
  const ghCard='<article class="ja-native-card ja-google-home-card">'+
    '<h3>GOOGLE HOME / HARDWARE</h3>'+
    '<p>'+esc(gh.online?'Agente conectado. O Google Home pode falar respostas do COMPUTER e enviar comandos como hardware.':(gh.mensagem||'Agente Google Home offline.'))+'</p>'+
    '<dl><dt>Status</dt><dd>'+(gh.online?'ONLINE':'OFFLINE')+'</dd><dt>Google Cast</dt><dd>'+esc(devices.length)+'</dd><dt>Hardware</dt><dd>'+esc(gh.hardware_status||'—')+'</dd></dl>'+
    '<label class="ja-google-home-field"><span>Destino</span><select id="gh-device"><option value="">Automático</option>'+
      devices.map(d=>'<option value="'+attr(d.uuid||d.name||'')+'">'+esc(d.name||d.uuid||'Google Home')+'</option>').join('')+
    '</select></label>'+
    '<label class="ja-google-home-field"><span>Comando</span><input id="gh-command" placeholder="Ex.: Como está o sistema?"></label>'+
    '<div class="ja-row-actions">'+actionBtn('ENVIAR E FALAR','gh-send','primary')+actionBtn('ATUALIZAR','gh-refresh')+actionBtn('PROVISIONAR HARDWARE','gh-provision')+'</div>'+
    '<div id="gh-result" class="ja-google-home-result"></div>'+
  '</article>';

  const body='<div class="ja-native-summary"><div><b>AGENTES</b><strong>'+all.length+'</strong></div><div><b>CLIMA</b><strong>'+esc(clima.clima?.temperatura||'—')+'</strong></div></div>'+
    '<div class="ja-native-grid">'+ghCard+
    all.slice(0,5).map(a=>'<article class="ja-native-card"><h3>'+esc(a.nome||a.tipo||'Agente')+'</h3><p>'+esc(a.descricao||'')+'</p><span class="ja-state '+(a.ativo?'ok':'off')+'">'+(a.ativo?'ATIVO':'INATIVO')+'</span></article>').join('')+
    '</div>';

  moduleShell('Agentes externos',body);

  document.querySelector('[data-act="gh-refresh"]')?.addEventListener('click',renderAgents);
  document.querySelector('[data-act="gh-send"]')?.addEventListener('click',async()=>{
    const cmd=document.getElementById('gh-command')?.value.trim()||'';
    const device=document.getElementById('gh-device')?.value||'';
    const out=document.getElementById('gh-result');
    if(!cmd){if(out)out.textContent='Digite um comando.';return;}
    if(out){out.textContent='Enviando ao COMPUTER...';out.className='ja-google-home-result pending';}
    try{
      const r=await postJson('/casa/api/google_home.php',{acao:'comando',comando:cmd,device});
      if(out){out.textContent=r.resposta||r.mensagem||'Comando enviado.';out.className='ja-google-home-result ok';}
    }catch(e){if(out){out.textContent=e.message||'Falha no agente.';out.className='ja-google-home-result error';}}
  });
  document.querySelector('[data-act="gh-provision"]')?.addEventListener('click',async()=>{
    const out=document.getElementById('gh-result');
    if(out){out.textContent='Provisionando hardware virtual...';out.className='ja-google-home-result pending';}
    try{
      const r=await postJson('/casa/api/google_home_hardware.php',{acao:'provisionar',nome:'Google Home / COMPUTER'});
      if(out){
        out.textContent='Hardware provisionado. Copie o token agora e configure CASA_GOOGLE_HOME_DEVICE_TOKEN no serviço: '+(r.device_token||'');
        out.className='ja-google-home-result ok';
      }
    }catch(e){if(out){out.textContent=e.message||'Falha ao provisionar.';out.className='ja-google-home-result error';}}
  });
}

async function renderPhrases(){
  loading('Frases & avisos');
  const j=await crud('frases'); const all=j.dados||[]; const p=paginate('phrases',all,6);
  moduleShell('Frases & avisos',cards(p.slice,f=>'<article class="ja-native-card"><h3>'+esc(f.autor||'COMPUTER')+'</h3><p>“'+esc(f.texto||'')+'”</p><div class="ja-row-actions">'+actionBtn('FALAR','speak:'+f.id,'primary')+actionBtn('EXCLUIR','delete:'+f.id,'danger')+'</div></article>')+pager('phrases',p),actionBtn('NOVA','new','primary'));
  document.querySelector('.ja-native-actions [data-act]')?.addEventListener('click',async()=>{
    const texto=prompt('Texto da frase:');
    if(!texto)return;
    const autor=prompt('Autor/origem:','COMPUTER')||'COMPUTER';
    await postJson('/casa/api/crud.php?tabela=frases&acao=criar',{texto,autor});
    renderPhrases();
  });
  document.querySelectorAll('.ja-native-body [data-act]').forEach(b=>b.onclick=async()=>{
    const [a,id]=b.dataset.act.split(':');
    const f=all.find(x=>String(x.id)===id);
    if(a==='speak'&&f){
      if(!speakComputer(f.texto)){
        alert('Não foi encontrada uma voz pt-BR disponível neste navegador.');
      }
      return;
    }
    if(a==='delete'&&confirm('Excluir frase?')){
      await postJson('/casa/api/crud.php?tabela=frases&acao=excluir&id='+id,{});
      renderPhrases();
    }
  });
  bindPager('phrases',p,renderPhrases);
}

async function renderUsers(){
  loading('Usuários & senhas');
  const j=await crud('usuarios'); const all=j.dados||[]; const p=paginate('users',all,6);
  moduleShell('Usuários & senhas',cards(p.slice,u=>'<article class="ja-native-card"><h3>'+esc(u.nome||u.login)+'</h3><dl><dt>Login</dt><dd>'+esc(u.login||'—')+'</dd><dt>E-mail</dt><dd>'+esc(u.email||'—')+'</dd><dt>Perfil</dt><dd>'+esc(u.perfil||'—')+'</dd></dl><span class="ja-state '+(u.ativo?'ok':'off')+'">'+(u.ativo?'ATIVO':'INATIVO')+'</span></article>')+pager('users',p));
  bindPager('users',p,renderUsers);
}

const IA_REMOTE_PROVIDERS={
  runpod:{label:'RunPod',models:['Qwen/Qwen2.5-7B-Instruct','meta-llama/Meta-Llama-3-8B-Instruct','meta-llama/Llama-3.1-8B-Instruct'],endpoint:'',needsEndpoint:true},
  openai:{label:'OpenAI',models:['gpt-4o','gpt-4o-mini','o3-mini','gpt-4.1','gpt-4.1-mini'],endpoint:'https://api.openai.com/v1'},
  gemini:{label:'Google Gemini',models:['gemini-2.5-flash','gemini-2.5-pro','gemini-2.0-flash'],endpoint:'https://generativelanguage.googleapis.com/v1beta'},
  anthropic:{label:'Anthropic Claude',models:['claude-3-5-sonnet-20241022','claude-3-5-haiku-20241022','claude-3-opus-20240229'],endpoint:'https://api.anthropic.com/v1'},
  openrouter:{label:'OpenRouter',models:['meta-llama/llama-3-8b-instruct:free','google/gemma-2-9b-it:free','deepseek/deepseek-r1:free'],endpoint:'https://openrouter.ai/api/v1'},
  cerebras:{label:'Cerebras',models:['qwen-3-235b-a22b-instruct-2507'],endpoint:'https://api.cerebras.ai/v1'},
  deepseek:{label:'DeepSeek',models:['deepseek-chat','deepseek-reasoner'],endpoint:'https://api.deepseek.com/v1'},
  custom:{label:'OpenAI-compatible',models:[],endpoint:''}
};

function cfgSelect(id,label,options,value){
  return '<label><span>'+esc(label)+'</span><select id="'+attr(id)+'">'+options.map(o=>'<option value="'+attr(o.value)+'" '+(String(o.value)===String(value)?'selected':'')+'>'+esc(o.label)+'</option>').join('')+'</select></label>';
}
function cfgInput(id,label,value,type='text',placeholder=''){
  return '<label><span>'+esc(label)+'</span><input id="'+attr(id)+'" type="'+attr(type)+'" value="'+attr(value||'')+'" placeholder="'+attr(placeholder)+'"></label>';
}
function cfgModelField(provider,value){
  const def=IA_REMOTE_PROVIDERS[provider]||IA_REMOTE_PROVIDERS.custom;
  const listId='ja-model-list';
  return '<label><span>Modelo</span><input id="ja-ia-model" list="'+listId+'" value="'+attr(value||'')+'" placeholder="Selecione ou digite o modelo"><datalist id="'+listId+'">'+def.models.map(m=>'<option value="'+attr(m)+'"></option>').join('')+'</datalist></label>';
}

async function renderConfig(){
  loading('Configurações RunPod / IA');
  const [cfg,models]=await Promise.all([
    crud('configuracoes_sistema'),
    getJson('/casa/api/ia_modelos.php?acao=listar').catch(()=>({dados:[]}))
  ]);
  const map={};(cfg.dados||[]).forEach(x=>map[x.chave]=x.valor);

  let mode=(map.ia_provider||'local').toLowerCase();
  let remote=(map.ia_remote_provider||'').toLowerCase();
  if(!['local','remote'].includes(mode)){
    remote=mode==='runpod'?'runpod':(mode==='openai_compatible'?'custom':mode);
    mode=mode==='local'?'local':'remote';
  }
  if(!IA_REMOTE_PROVIDERS[remote]) remote='runpod';

  const localModel=map.local_model||'llama3.2:3b';
  const remoteModel=map.ia_remote_model||
    (remote==='runpod'?map.runpod_model:'')||
    ((remote==='openai'||remote==='custom')?map.openai_model:'')||
    ((IA_REMOTE_PROVIDERS[remote].models||[])[0]||'');
  const remoteKey=map.ia_remote_api_key||
    (remote==='runpod'?map.runpod_api_key:'')||
    ((remote==='openai'||remote==='custom')?map.openai_api_key:'');
  const remoteUrl=map.ia_remote_base_url||
    ((remote==='openai'||remote==='custom')?map.openai_base_url:'')||
    IA_REMOTE_PROVIDERS[remote].endpoint||'';

  const providers=Object.entries(IA_REMOTE_PROVIDERS).map(([value,p])=>({value,label:p.label}));
  let form=
    '<div class="ja-config-grid ja-ai-config">'+
      cfgSelect('ja-ia-mode','Execução',[{value:'local',label:'Local'},{value:'remote',label:'Remoto'}],mode)+
      cfgSelect('ja-routing','Roteamento',[{value:'auto',label:'Automático'},{value:'local_only',label:'Somente local'},{value:'cloud_only',label:'Somente remoto'}],map.ia_routing_mode||'auto')+
      '<div id="ja-local-fields" class="ja-config-subgrid '+(mode==='local'?'':'is-hidden')+'">'+
        cfgInput('ja-local-url','Servidor local',map.local_ollama_url||'http://127.0.0.1:11434','text','http://127.0.0.1:11434')+
        cfgInput('ja-local-model','Modelo local',localModel,'text','llama3.2:3b')+
      '</div>'+
      '<div id="ja-remote-fields" class="ja-config-subgrid '+(mode==='remote'?'':'is-hidden')+'">'+
        cfgSelect('ja-remote-provider','Provedor remoto',providers,remote)+
        '<div id="ja-remote-model-wrap">'+cfgModelField(remote,remoteModel)+'</div>'+
        cfgInput('ja-remote-key','Chave API / Token',remoteKey,'password','Token do provedor')+
        '<div id="ja-remote-url-wrap">'+cfgInput('ja-remote-url','URL / Endpoint',remoteUrl,'text','Endpoint do provedor')+'</div>'+
        '<div id="ja-runpod-endpoint-wrap" class="'+(remote==='runpod'?'':'is-hidden')+'">'+
          cfgSelect('ja-runpod-protocol','Protocolo RunPod',[
            {value:'native',label:'RunPod nativo'},
            {value:'openai',label:'OpenAI-compatible'}
          ],map.runpod_protocol||'openai')+
          cfgInput('ja-runpod-endpoint','RunPod Endpoint ID',map.runpod_endpoint_id||'','text','xxxxxxxxxxxxxxxxxxxxxxxx')+
        '</div>'+
      '</div>'+
    '</div>'+
    '<div class="ja-native-summary"><div><b>MODELOS CADASTRADOS</b><strong>'+esc((models.dados||[]).length)+'</strong></div><div><b>MODO</b><strong id="ja-ai-mode-summary">'+esc(mode==='local'?'LOCAL':IA_REMOTE_PROVIDERS[remote].label.toUpperCase())+'</strong></div></div>';

  moduleShell('Configurações RunPod / IA',form,actionBtn('TESTAR CONEXÃO','test')+actionBtn('SALVAR','save','primary'));

  const modeEl=document.getElementById('ja-ia-mode');
  const providerEl=document.getElementById('ja-remote-provider');

  function updateMode(){
    const isRemote=modeEl.value==='remote';
    document.getElementById('ja-local-fields')?.classList.toggle('is-hidden',isRemote);
    document.getElementById('ja-remote-fields')?.classList.toggle('is-hidden',!isRemote);
    const s=document.getElementById('ja-ai-mode-summary');
    if(s) s.textContent=isRemote?(IA_REMOTE_PROVIDERS[providerEl.value]?.label||'REMOTO').toUpperCase():'LOCAL';
  }
  function updateProvider(){
    const p=providerEl.value;
    const def=IA_REMOTE_PROVIDERS[p]||IA_REMOTE_PROVIDERS.custom;
    const current=document.getElementById('ja-ia-model')?.value||'';
    const modelValue=(def.models.includes(current)||!current)?(current||def.models[0]||''):current;
    document.getElementById('ja-remote-model-wrap').innerHTML=cfgModelField(p,modelValue);
    document.getElementById('ja-runpod-endpoint-wrap')?.classList.toggle('is-hidden',p!=='runpod');
    document.getElementById('ja-remote-url-wrap')?.classList.toggle('is-hidden',p==='runpod');
    const url=document.getElementById('ja-remote-url');
    if(url && (!url.value || Object.values(IA_REMOTE_PROVIDERS).some(x=>x.endpoint===url.value))) url.value=def.endpoint||'';
    updateMode();
  }
  modeEl.onchange=updateMode;
  providerEl.onchange=updateProvider;
  updateProvider();

  document.querySelector('[data-act="test"]')?.addEventListener('click',async()=>{
    const btn=document.querySelector('[data-act="test"]');
    const payload={
      mode:modeEl.value,
      local_url:document.getElementById('ja-local-url')?.value.trim()||'',
      local_model:document.getElementById('ja-local-model')?.value.trim()||'',
      provider:providerEl.value,
      model:document.getElementById('ja-ia-model')?.value.trim()||'',
      api_key:document.getElementById('ja-remote-key')?.value||'',
      base_url:document.getElementById('ja-remote-url')?.value.trim()||'',
      runpod_endpoint_id:document.getElementById('ja-runpod-endpoint')?.value.trim()||'',
      runpod_protocol:document.getElementById('ja-runpod-protocol')?.value||'openai'
    };

    function testUrl(p){
      if(p.mode==='local') return (p.local_url||'').replace(/\/+$/,'')+'/api/generate';
      if(p.provider==='runpod'){
        if(p.runpod_protocol==='native') return 'https://api.runpod.ai/v2/'+encodeURIComponent(p.runpod_endpoint_id)+'/runsync';
        return 'https://api.runpod.ai/v2/'+encodeURIComponent(p.runpod_endpoint_id)+'/openai/v1/chat/completions';
      }
      if(p.provider==='gemini'){
        const b=(p.base_url||'https://generativelanguage.googleapis.com/v1beta').replace(/\/+$/,'');
        return b+'/models/'+encodeURIComponent(p.model)+':generateContent?key=***';
      }
      if(p.provider==='anthropic') return (p.base_url||'https://api.anthropic.com/v1').replace(/\/+$/,'')+'/messages';
      const defs={
        openai:'https://api.openai.com/v1',
        openrouter:'https://openrouter.ai/api/v1',
        cerebras:'https://api.cerebras.ai/v1',
        deepseek:'https://api.deepseek.com/v1'
      };
      const b=(p.base_url||defs[p.provider]||'').replace(/\/+$/,'');
      return /\/chat\/completions$/i.test(b)?b:b+'/chat/completions';
    }

    document.getElementById('ja-ai-test-modal')?.remove();
    const modal=document.createElement('div');
    modal.id='ja-ai-test-modal';
    modal.className='ja-test-modal-backdrop';
    modal.innerHTML=
      '<section class="ja-test-modal" role="dialog" aria-modal="true" aria-labelledby="ja-test-title">'+
        '<header><div><b id="ja-test-title">TESTE DE CONEXÃO IA</b><small id="ja-test-status">INICIANDO</small></div>'+
        '<button type="button" id="ja-test-close">FECHAR</button></header>'+
        '<div class="ja-test-meta"><span>URL</span><code id="ja-test-url"></code></div>'+
        '<div class="ja-test-meta"><span>MODELO</span><code>'+esc(payload.mode==='local'?payload.local_model:payload.model)+'</code></div>'+
        '<div id="ja-test-result-banner" class="ja-test-result-banner running">TESTE EM ANDAMENTO</div>'+
        '<div class="ja-test-log" id="ja-test-log" aria-live="polite"></div>'+
      '</section>';
    document.body.appendChild(modal);

    const close=()=>modal.remove();
    document.getElementById('ja-test-close').onclick=close;
    modal.addEventListener('click',e=>{if(e.target===modal)close();});
    const log=document.getElementById('ja-test-log');
    const status=document.getElementById('ja-test-status');
    const resultBanner=document.getElementById('ja-test-result-banner');
    const url=testUrl(payload);
    document.getElementById('ja-test-url').textContent=url;

    const add=(type,msg)=>{
      const row=document.createElement('div');
      row.className='ja-test-log-line '+type;
      const now=new Date().toLocaleTimeString('pt-BR',{hour12:false});
      row.innerHTML='<time>'+esc(now)+'</time><span>'+esc(msg)+'</span>';
      log.appendChild(row);
      log.scrollTop=log.scrollHeight;
    };

    add('info','Configuração carregada da tela.');
    add('info','Protocolo: '+(payload.mode==='local'?'LOCAL':(payload.provider==='runpod'?'RUNPOD '+payload.runpod_protocol.toUpperCase():payload.provider.toUpperCase())));
    add('info','URL criada: '+url);

    function requestPayloadForLog(p){
      const prompt='Responda somente: OK CASA';
      if(p.mode==='local'){
        return {
          model:p.local_model,
          prompt,
          stream:false,
          options:{num_predict:16,temperature:0}
        };
      }
      if(p.provider==='runpod' && p.runpod_protocol==='native'){
        return {input:{prompt}};
      }
      if(p.provider==='gemini'){
        return {
          contents:[{role:'user',parts:[{text:prompt}]}],
          generationConfig:{maxOutputTokens:16,temperature:0}
        };
      }
      if(p.provider==='anthropic'){
        return {
          model:p.model,
          max_tokens:16,
          temperature:0,
          messages:[{role:'user',content:prompt}]
        };
      }
      return {
        model:p.model,
        messages:[{role:'user',content:prompt}],
        max_tokens:16,
        temperature:0,
        stream:false
      };
    }

    const requestBody=requestPayloadForLog(payload);
    add('info','Payload enviado: '+JSON.stringify(requestBody));
    add('info','Authorization: Bearer ***');
    add('info','Preparando requisição de teste...');
    if(btn){btn.disabled=true;btn.textContent='TESTANDO...';}

    const ini=performance.now();
    await new Promise(r=>setTimeout(r,120));
    add('send','Enviando requisição...');
    status.textContent='AGUARDANDO RESPOSTA';

    async function requestTest(data,label){
      const started=performance.now();
      const resp=await fetch('/casa/api/testar_ia.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(data)
      });
      const elapsed=Math.round(performance.now()-started);
      let raw='';
      let j={};
      try{raw=await resp.text();j=raw?JSON.parse(raw):{};}catch(_){j={};}
      add(resp.ok?'recv':'error',label+' retornou em '+elapsed+' ms — HTTP '+resp.status+'.');
      if(j.tempo_ms!==undefined) add('info','Tempo medido no servidor: '+j.tempo_ms+' ms.');
      if(j.url) add('info','URL executada: '+j.url);
      if(j.body_preview) add(resp.ok?'recv':'error','Resposta HTTP: '+j.body_preview);
      if(!resp.ok){
        const reported=j.erro_reportado||j.mensagem||j.error||raw||('HTTP '+resp.status);
        add('error','Erro reportado: '+reported);
        const e=new Error(reported);
        e.http=resp.status;
        e.dados=j;
        e.elapsed=elapsed;
        throw e;
      }
      return j;
    }

    try{
      let r;
      if(payload.provider==='runpod' && payload.runpod_protocol==='openai'){
        add('send','Pré-teste: consultando /models para verificar worker e autenticação...');
        status.textContent='TESTANDO /MODELS';
        const pre=await requestTest({...payload,stage:'models'},'GET /models');
        if(pre.url) add('info','URL /models: '+pre.url);
        if(pre.modelos_encontrados!==undefined) add('info','Modelos retornados: '+pre.modelos_encontrados+'.');
        if(pre.modelo_presente===false) add('error','O modelo configurado não apareceu em /models.');
        if(pre.modelo_presente===true) add('ok','Modelo configurado encontrado em /models.');
        add('send','Pré-teste concluído. Enviando /chat/completions...');
        status.textContent='TESTANDO CHAT COMPLETIONS';
        r=await requestTest({...payload,stage:'completion'},'POST /chat/completions');
      }else{
        r=await requestTest(payload,'Requisição');
      }
      const ms=Math.round(performance.now()-ini);
      if(r.url) add('info','URL confirmada pelo servidor: '+r.url);
      if(r.resposta) add('recv','Resposta do modelo: '+r.resposta);
      else if(r.mensagem) add('recv',r.mensagem);
      if(r.body_preview) add('recv','Body: '+r.body_preview);
      add('ok','Teste finalizado com sucesso em '+ms+' ms.');
      status.textContent='SUCESSO';
      if(resultBanner){
        resultBanner.className='ja-test-result-banner success';
        resultBanner.textContent='SUCESSO — CONEXÃO REALIZADA';
      }
      modal.querySelector('.ja-test-modal').classList.add('ok');
    }catch(e){
      const ms=Math.round(performance.now()-ini);
      add('error','Falha: '+(e.message||String(e)));
      add('error','Teste encerrado após '+ms+' ms.');
      status.textContent='FALHA';
      if(resultBanner){
        resultBanner.className='ja-test-result-banner failure';
        resultBanner.textContent='FALHA — TESTE NÃO CONCLUÍDO';
      }
      modal.querySelector('.ja-test-modal').classList.add('error');
    }finally{
      if(btn){btn.disabled=false;btn.textContent='TESTAR CONEXÃO';}
    }
  });

    document.querySelector('[data-act="save"]')?.addEventListener('click',async()=>{
    const mode=modeEl.value;
    const provider=providerEl.value;
    const model=document.getElementById('ja-ia-model')?.value.trim()||'';
    const writes={
      ia_provider:mode,
      ia_routing_mode:document.getElementById('ja-routing').value,
      local_ollama_url:document.getElementById('ja-local-url').value.trim(),
      local_model:document.getElementById('ja-local-model').value.trim(),
      ia_remote_provider:provider,
      ia_remote_model:model,
      ia_remote_api_key:document.getElementById('ja-remote-key')?.value||'',
      ia_remote_base_url:document.getElementById('ja-remote-url')?.value.trim()||'',
      runpod_endpoint_id:document.getElementById('ja-runpod-endpoint')?.value.trim()||'',
      runpod_protocol:document.getElementById('ja-runpod-protocol')?.value||'openai'
    };
    // Compatibilidade com as chaves já usadas pelo JARVIS.
    if(provider==='runpod'){
      writes.runpod_model=model;
      writes.runpod_api_key=writes.ia_remote_api_key;
    }
    if(provider==='openai'||provider==='custom'){
      writes.openai_model=model;
      writes.openai_api_key=writes.ia_remote_api_key;
      writes.openai_base_url=writes.ia_remote_base_url;
    }
    for(const [chave,valor] of Object.entries(writes)){
      await postJson('/casa/api/crud.php?tabela=configuracoes_sistema&acao=atualizar',{chave,valor});
    }
    renderConfig();
  });
}

async function copyToClipboard(text, btnElement, successMsg = 'COPIADO!'){
  let success = false;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    try {
      await navigator.clipboard.writeText(text);
      success = true;
    } catch(e) {}
  }
  if (!success) {
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      success = true;
    } catch(e) {}
  }
  if (btnElement) {
    const orig = btnElement.textContent;
    btnElement.textContent = success ? successMsg : 'FALHA AO COPIAR';
    setTimeout(() => { btnElement.textContent = orig; }, 2200);
  }
}

function generateQrCodeToElement(targetEl, textToEncode, size = 240){
  targetEl.innerHTML = '';
  if (typeof QRCode !== 'undefined') {
    try {
      new QRCode(targetEl, {
        text: textToEncode,
        width: size,
        height: size,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
      return;
    } catch(e) {}
  }
  const encoded = encodeURIComponent(textToEncode);
  targetEl.innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=' + size + 'x' + size + '&data=' + encoded + '" width="' + size + '" height="' + size + '" alt="QR Code">';
}

function openQrCodeModal(tokenData, baseUrl = ''){
  document.getElementById('ja-qr-modal')?.remove();
  const modal = document.createElement('div');
  modal.id = 'ja-qr-modal';
  modal.className = 'ja-schedule-backdrop';

  const token = tokenData.chave_completa || tokenData.token || tokenData.api_key || '';
  const nome = tokenData.nome || tokenData.token?.nome || 'Dispositivo / Integracao';
  const resolvedBaseUrl = baseUrl || (location.origin + '/casa');

  const fullPayloadObj = {
    url: resolvedBaseUrl,
    token: token,
    name: nome
  };
  const jsonText = JSON.stringify(fullPayloadObj, null, 2);
  const rawTokenText = token;

  modal.innerHTML = 
    '<section class="ja-schedule-modal" style="width:min(600px,96vw);" role="dialog" aria-modal="true">' +
      '<header style="background:var(--ja-orange);color:#111;">' +
        '<strong>QR CODE - PAREAMENTO RELOGIO & CELULAR</strong>' +
        '<button type="button" data-close>FECHAR</button>' +
      '</header>' +
      '<div class="ja-qr-modal-body">' +
        '<div style="font-size:12px;color:#8fc7ef;font-weight:700;">' +
          'Aponte a camera do relogio ou celular para o reticulo abaixo.' +
        '</div>' +
        '<div class="ja-qr-types-tabs">' +
          '<button type="button" id="ja-qr-tab-token" class="active">MODO RELOGIO (SOMENTE TOKEN)</button>' +
          '<button type="button" id="ja-qr-tab-json">MODO CELULAR (JSON COMPLETO)</button>' +
        '</div>' +
        '<div class="ja-qr-viewfinder">' +
          '<div class="ja-qr-canvas-box" id="ja-qr-canvas-target"></div>' +
        '</div>' +
        '<pre class="ja-qr-payload-box" id="ja-qr-payload-text"></pre>' +
        '<div class="ja-row-actions" style="margin-top:6px;">' +
          '<button type="button" class="ja-mini primary" id="ja-btn-qr-copy-token">COPIAR TOKEN</button>' +
          '<button type="button" class="ja-mini" id="ja-btn-qr-copy-json">COPIAR JSON</button>' +
        '</div>' +
      '</div>' +
      '<footer>' +
        '<button type="button" data-close style="background:var(--ja-orange);color:#111;">CONCLUIR E FECHAR</button>' +
      '</footer>' +
    '</section>';

  document.body.appendChild(modal);

  const close = () => modal.remove();
  modal.querySelectorAll('[data-close]').forEach(b => b.onclick = close);
  modal.addEventListener('click', e => { if (e.target === modal) close(); });

  const canvasBox = document.getElementById('ja-qr-canvas-target');
  const payloadBox = document.getElementById('ja-qr-payload-text');
  const tabJson = document.getElementById('ja-qr-tab-json');
  const tabToken = document.getElementById('ja-qr-tab-token');
  const btnCopyTok = document.getElementById('ja-btn-qr-copy-token');
  const btnCopyJs = document.getElementById('ja-btn-qr-copy-json');

  let currentMode = 'token'; // Padrão 'token' para relógios

  function renderModalPayload(){
    if (currentMode === 'token') {
      tabToken.classList.add('active');
      tabJson.classList.remove('active');
      payloadBox.textContent = rawTokenText;
      generateQrCodeToElement(canvasBox, rawTokenText, 260);
    } else {
      tabJson.classList.add('active');
      tabToken.classList.remove('active');
      payloadBox.textContent = jsonText;
      generateQrCodeToElement(canvasBox, JSON.stringify(fullPayloadObj), 260);
    }
  }

  tabJson.onclick = () => { currentMode = 'json'; renderModalPayload(); };
  tabToken.onclick = () => { currentMode = 'token'; renderModalPayload(); };

  btnCopyTok.onclick = () => copyToClipboard(rawTokenText, btnCopyTok, 'TOKEN COPIADO!');
  btnCopyJs.onclick = () => copyToClipboard(jsonText, btnCopyJs, 'JSON COPIADO!');

  renderModalPayload();
}

function openNewApiKeyModal(baseUrl, onCreatedCallback){
  document.getElementById('ja-apikey-modal')?.remove();
  const modal = document.createElement('div');
  modal.id = 'ja-apikey-modal';
  modal.className = 'ja-schedule-backdrop';

  modal.innerHTML = 
    '<section class="ja-schedule-modal" style="width:min(620px,96vw);" role="dialog" aria-modal="true">' +
      '<header>' +
        '<strong>CADASTRAR NOVA CHAVE DE API</strong>' +
        '<button type="button" data-close>FECHAR</button>' +
      '</header>' +
      '<form id="ja-form-new-key">' +
        '<div class="ja-config-grid" style="grid-template-columns:1fr;">' +
          '<label>' +
            '<span>NOME / IDENTIFICACAO DO DISPOSITIVO *</span>' +
            '<input type="text" name="nome" placeholder="Ex: Smartwatch LILYGO, Celular Android, Tablet Sala" required autofocus>' +
          '</label>' +
          '<label>' +
            '<span>TIPO DE DEFINICAO DE CHAVE</span>' +
            '<select name="tipo_geracao" id="ja-key-gen-type">' +
              '<option value="auto">Gerar Chave Criptografica Segura Automaticamente (Recomendado)</option>' +
              '<option value="custom">Informar Chave Personalizada Manualmente</option>' +
            '</select>' +
          '</label>' +
          '<div id="ja-custom-token-box" style="display:none;">' +
            '<label>' +
              '<span>DIGITE A CHAVE PERSONALIZADA (Minimo 16 caracteres)</span>' +
              '<input type="text" name="custom_token" placeholder="Insira seu token personalizado seguro">' +
            '</label>' +
          '</div>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
            '<label>' +
              '<span>DISPOSITIVO VINCULADO (OPCIONAL)</span>' +
              '<input type="text" name="device_id" placeholder="Ex: watch-twatch-01, phone-pixel">' +
            '</label>' +
            '<label>' +
              '<span>VALIDADE (DIAS)</span>' +
              '<input type="number" name="validade_dias" placeholder="Vazio = Permanente" min="1" max="3650">' +
            '</label>' +
          '</div>' +
          '<label>' +
            '<span>ESCOPOS DE ACESSO</span>' +
            '<div class="ja-scopes-checkboxes">' +
              '<label><input type="checkbox" name="scopes" value="*" checked> Acesso Total Irrestrito (*)</label>' +
              '<label><input type="checkbox" name="scopes" value="read"> Apenas Leitura (Status / Sensores)</label>' +
              '<label><input type="checkbox" name="scopes" value="devices"> Controle de Dispositivos & Reles</label>' +
              '<label><input type="checkbox" name="scopes" value="audio"> Intercomunicador & Voz</label>' +
            '</div>' +
          '</label>' +
        '</div>' +
        '<footer>' +
          '<button type="button" data-close>CANCELAR</button>' +
          '<button type="submit" class="primary">GERAR & SALVAR CHAVE</button>' +
        '</footer>' +
      '</form>' +
    '</section>';

  document.body.appendChild(modal);

  const form = modal.querySelector('form');
  const genType = document.getElementById('ja-key-gen-type');
  const customBox = document.getElementById('ja-custom-token-box');

  genType.onchange = () => {
    customBox.style.display = genType.value === 'custom' ? 'block' : 'none';
  };

  const close = () => modal.remove();
  modal.querySelectorAll('[data-close]').forEach(b => b.onclick = close);

  form.onsubmit = async (e) => {
    e.preventDefault();
    const btnSubmit = form.querySelector('button[type="submit"]');
    btnSubmit.disabled = true;
    btnSubmit.textContent = 'CRIANDO...';

    const scopesChecked = Array.from(form.querySelectorAll('input[name="scopes"]:checked')).map(i => i.value);

    const payload = {
      nome: form.nome.value.trim(),
      device_id: form.device_id.value.trim() || null,
      validade_dias: form.validade_dias.value ? Number(form.validade_dias.value) : null,
      scopes: scopesChecked.length ? scopesChecked : ['*']
    };

    if (genType.value === 'custom') {
      const ct = form.custom_token.value.trim();
      if (ct.length < 16) {
        alert('A chave personalizada deve ter pelo menos 16 caracteres para seguranca.');
        btnSubmit.disabled = false;
        btnSubmit.textContent = 'GERAR & SALVAR CHAVE';
        return;
      }
      payload.custom_token = ct;
    }

    try {
      const res = await postJson('/casa/api/crud.php?acao=criar_api_key', payload);
      close();
      if (onCreatedCallback) onCreatedCallback(res);
    } catch(err) {
      alert('Falha ao cadastrar chave: ' + (err.message || String(err)));
      btnSubmit.disabled = false;
      btnSubmit.textContent = 'GERAR & SALVAR CHAVE';
    }
  };
}

async function renderExternal(){
  loading('Acesso Web & API');
  try {
    const [tun, keysRes, masterRes] = await Promise.all([
      crud('devices','status_tunnel').catch(()=>({})),
      crud('api_client_tokens','listar_api_keys').catch(()=>({dados:[]})),
      crud('devices','obter_external_api_key').catch(()=>({}))
    ]);

    const t = tun.tunnel || {};
    const allKeys = Array.isArray(keysRes.dados) ? keysRes.dados : [];
    const activeCount = allKeys.filter(k => k.status_formatado === 'ativo').length;
    const masterKey = masterRes.api_key || '';
    const baseUrl = t.url || (location.origin + '/casa');

    // Desativa restricao rigida de grid do LCARS no elemento pai para nunca estourar a tela
    const bodyNode = document.querySelector('.ja-native-body');
    if (bodyNode) {
      bodyNode.style.display = 'block';
      bodyNode.style.height = '100%';
      bodyNode.style.overflow = 'hidden';
    }

    const topBar = 
      '<div class="ja-api-topbar">' +
        '<div class="ja-api-stat"><b>TUNEL</b><strong>' + esc((t.status||'indisponivel').toUpperCase()) + '</strong></div>' +
        '<div class="ja-api-stat"><b>URL PUBLICA</b><strong title="' + attr(baseUrl) + '">' + esc(baseUrl) + '</strong></div>' +
        '<div class="ja-api-stat"><b>CHAVES ATIVAS</b><strong>' + esc(activeCount) + ' de ' + esc(allKeys.length) + '</strong></div>' +
        '<div class="ja-api-stat"><b>ENDPOINT V1</b><strong>/api/v1/status</strong></div>' +
      '</div>';

    const p = paginate('api_keys', allKeys, 6);

    const cardsHtml = allKeys.length ? 
      '<div class="ja-api-cards-grid">' +
        p.slice.map(k => {
          const isRevoked = k.status_formatado === 'revogado';
          const isExpired = k.status_formatado === 'expirado';
          const stateClass = isRevoked ? 'off ja-state-revoked' : (isExpired ? 'off ja-state-expired' : 'ok');
          const stateLabel = isRevoked ? 'REVOGADO' : (isExpired ? 'EXPIRADO' : 'ATIVO');

          let scopesLabel = 'Acesso Total (*)';
          if (Array.isArray(k.scopes) && k.scopes.length) {
            if (!k.scopes.includes('*')) scopesLabel = k.scopes.join(', ');
          }

          const hasToken = Boolean(k.token);
          const actions = 
            '<div class="ja-row-actions">' +
              (hasToken ? actionBtn('QR CODE', 'qrcode:' + k.id, 'primary') : '') +
              (hasToken ? actionBtn('COPIAR', 'copy:' + k.id) : '') +
              (!isRevoked ? actionBtn('REVOGAR', 'revoke:' + k.id, 'danger') : '') +
              actionBtn('EXCLUIR', 'delete:' + k.id, 'danger') +
            '</div>';

          return '<article class="ja-apikey-card">' +
            '<div class="ja-apikey-head">' +
              '<h3>' + esc(k.nome || ('Chave #' + k.id)) + '</h3>' +
              '<span class="ja-state ' + stateClass + '">' + stateLabel + '</span>' +
            '</div>' +
            '<div class="ja-apikey-meta">' +
              '<div><b>Prefixo:</b> <code>' + esc(k.token_prefix || '-') + '</code></div>' +
              '<div><b>Permissoes:</b> <span title="' + attr(scopesLabel) + '">' + esc(scopesLabel) + '</span></div>' +
              '<div><b>Criado:</b> ' + esc(k.criado_em || '-') + '</div>' +
              '<div><b>Ultimo uso:</b> ' + esc(k.ultimo_uso ? (k.ultimo_uso + (k.ultimo_ip ? ' (' + k.ultimo_ip + ')' : '')) : 'Nunca') + '</div>' +
              '<div><b>Validade:</b> ' + esc(k.expira_em || 'Permanente') + '</div>' +
              (k.revogado_em ? '<div class="ja-txt-danger"><b>Revogado em:</b> ' + esc(k.revogado_em) + '</div>' : '') +
              (k.device_id ? '<div><b>Dispositivo:</b> ' + esc(k.device_id) + '</div>' : '') +
            '</div>' +
            '<div class="ja-card-footer">' +
              actions +
            '</div>' +
          '</article>';
        }).join('') +
      '</div>' + pager('api_keys', p) : 
      '<div class="ja-empty">Nenhuma chave de API registrada. Clique em <b>+ INCLUIR CHAVE</b> acima para cadastrar credenciais para Celular, Relogio ou Home Assistant.</div>';

    const masterSection = 
      '<div class="ja-master-key-box">' +
        '<div class="ja-master-key-head">' +
          '<div>' +
            '<strong>CHAVE MESTRE DO SISTEMA (ACESSO TOTAL IRRESTRITO)</strong>' +
            '<p>Credencial administrativa central. Pode ser utilizada para pareamento de contingencia via QR Code.</p>' +
          '</div>' +
          '<div class="ja-master-key-actions">' +
            '<button type="button" class="ja-mini primary" id="ja-btn-qr-master">QR CODE</button>' +
            '<button type="button" class="ja-mini" id="ja-btn-toggle-master">MOSTRAR</button>' +
            '<button type="button" class="ja-mini" id="ja-btn-copy-master">COPIAR</button>' +
            '<button type="button" class="ja-mini danger" id="ja-btn-rotate-master">ROTACIONAR</button>' +
          '</div>' +
        '</div>' +
        '<div class="ja-config-grid" style="margin-top:8px;">' +
          '<label><span>CHAVE MESTRE</span><input type="password" id="ja-master-key-input" value="' + attr(masterKey) + '" readonly></label>' +
          '<label><span>ENDPOINT OFICIAL</span><input value="/api/v1/status" readonly></label>' +
        '</div>' +
      '</div>';

    // Construcao das opcoes de chaves para o seletor da estacao de QR Code
    let keyOptions = '<option value="master">Chave Mestre (Acesso Total)</option>';
    allKeys.forEach(k => {
      if (k.token) {
        keyOptions += '<option value="' + esc(k.id) + '">Chave: ' + esc(k.nome || ('#' + k.id)) + '</option>';
      }
    });

    // ESTACAO FINAL DE LEITURA DE QR CODE (ULTIMA OPERACAO ROLANDO PARA CIMA)
    const qrStationHtml = 
      '<section class="ja-qr-station" id="ja-qr-station-section">' +
        '<div class="ja-qr-station-badge">CAMERA LEITOR QR CODE (ULTIMA OPERACAO NA ROLAGEM)</div>' +
        '<div class="ja-qr-station-head">' +
          '<h3>ESTACAO DE TRANSMISSAO QR CODE PARA RELOGIO & CELULAR</h3>' +
          '<p>Esta operacao fica posicionada ao final da tela (role para cima). Aponte a camera do seu <b>LILYGO Watch</b> ou smartphone para o reticulo optico abaixo para capturar as credenciais instantaneamente.</p>' +
        '</div>' +
        '<div class="ja-qr-camera-guide">' +
          '<span>&#128247;</span> <span>Dica de Foco: Aproxime a camera do relogio a 10-20 cm da tela. No relogio, utilize o MODO RELOGIO (somente token) para leitura ultra-rapida.</span>' +
        '</div>' +
        '<div class="ja-qr-controls-row">' +
          '<label><span>Chave Selecionada:</span> <select id="ja-qr-station-select">' + keyOptions + '</select></label>' +
          '<div class="ja-qr-types-tabs">' +
            '<button type="button" id="ja-qr-station-btn-token" class="active">MODO RELOGIO (SOMENTE TOKEN)</button>' +
            '<button type="button" id="ja-qr-station-btn-json">MODO CELULAR (JSON COMPLETO)</button>' +
          '</div>' +
        '</div>' +
        '<div class="ja-qr-viewfinder">' +
          '<div class="ja-qr-canvas-box" id="ja-qr-station-canvas"></div>' +
        '</div>' +
        '<pre class="ja-qr-payload-box" id="ja-qr-station-payload"></pre>' +
        '<div class="ja-row-actions">' +
          '<button type="button" class="ja-mini primary" id="ja-qr-station-copy-token">COPIAR TOKEN</button>' +
          '<button type="button" class="ja-mini" id="ja-qr-station-copy-json">COPIAR JSON</button>' +
          '<button type="button" class="ja-mini" id="ja-qr-station-fullscreen">TELA CHEIA / ZOOM</button>' +
          '<a href="/casa/mobile.php" target="_blank" class="ja-mini primary" style="text-decoration:none;display:inline-flex;align-items:center;">APP MOBILE (PWA)</a>' +
          '<a href="/casa/bin/" target="_blank" class="ja-mini" style="text-decoration:none;display:inline-flex;align-items:center;">PASTA /BIN/</a>' +
          '<button type="button" class="ja-mini" id="ja-qr-station-top">ROLAR AO TOPO</button>' +
        '</div>' +
      '</section>';

    const fullContainer = 
      '<div class="ja-api-container" id="ja-api-scroll-container">' +
        topBar +
        cardsHtml +
        masterSection +
        qrStationHtml +
      '</div>';

    moduleShell(
      'Acesso Web & API',
      fullContainer,
      actionBtn('+ INCLUIR CHAVE', 'new_key', 'primary') +
      ' <a href="/casa/bin/" target="_blank" class="ja-mini" style="margin-left:8px;text-decoration:none;display:inline-flex;align-items:center;background:#ff9900;color:#000;font-weight:bold;padding:6px 12px;border-radius:12px;">DOWNLOADS /BIN/</a>'
    );

    bindPager('api_keys', p, renderExternal);

    // Controles da estacao de QR Code no final da tela
    const qrCanvas = document.getElementById('ja-qr-station-canvas');
    const qrPayloadEl = document.getElementById('ja-qr-station-payload');
    const qrSelect = document.getElementById('ja-qr-station-select');
    const btnModeJson = document.getElementById('ja-qr-station-btn-json');
    const btnModeToken = document.getElementById('ja-qr-station-btn-token');
    const btnCopyStationTok = document.getElementById('ja-qr-station-copy-token');
    const btnCopyStationJson = document.getElementById('ja-qr-station-copy-json');
    const btnFullscreen = document.getElementById('ja-qr-station-fullscreen');
    const btnScrollTop = document.getElementById('ja-qr-station-top');
    const stationSection = document.getElementById('ja-qr-station-section');

    let stationMode = 'token'; // Padrão 'token' para facilitar leitura por câmeras de relógios

    function getSelectedKeyData(){
      const val = qrSelect ? qrSelect.value : 'master';
      if (val === 'master') {
        return {
          token: masterKey,
          nome: 'Chave Mestre CASA / JARVIS'
        };
      }
      const found = allKeys.find(k => String(k.id) === String(val));
      if (found && found.token) {
        return {
          token: found.token,
          nome: found.nome || ('Chave #' + found.id)
        };
      }
      return {
        token: masterKey,
        nome: 'Chave Mestre CASA / JARVIS'
      };
    }

    function updateStationQr(){
      if (!qrCanvas || !qrPayloadEl) return;
      const kData = getSelectedKeyData();
      const payloadObj = {
        url: baseUrl,
        token: kData.token,
        name: kData.nome
      };
      const jsonStr = JSON.stringify(payloadObj, null, 2);
      const textToEncode = (stationMode === 'json') ? JSON.stringify(payloadObj) : kData.token;

      qrPayloadEl.textContent = (stationMode === 'json') ? jsonStr : kData.token;
      generateQrCodeToElement(qrCanvas, textToEncode, 240);
    }

    if (qrSelect) {
      qrSelect.onchange = updateStationQr;
    }

    if (btnModeJson && btnModeToken) {
      btnModeJson.onclick = () => {
        stationMode = 'json';
        btnModeJson.classList.add('active');
        btnModeToken.classList.remove('active');
        updateStationQr();
      };
      btnModeToken.onclick = () => {
        stationMode = 'token';
        btnModeToken.classList.add('active');
        btnModeJson.classList.remove('active');
        updateStationQr();
      };
    }

    if (btnCopyStationTok) {
      btnCopyStationTok.onclick = () => {
        const kData = getSelectedKeyData();
        copyToClipboard(kData.token, btnCopyStationTok, 'TOKEN COPIADO!');
      };
    }

    if (btnCopyStationJson) {
      btnCopyStationJson.onclick = () => {
        const kData = getSelectedKeyData();
        const payloadObj = { url: baseUrl, token: kData.token, name: kData.nome };
        copyToClipboard(JSON.stringify(payloadObj, null, 2), btnCopyStationJson, 'JSON COPIADO!');
      };
    }

    if (btnFullscreen) {
      btnFullscreen.onclick = () => {
        const kData = getSelectedKeyData();
        openQrCodeModal(kData, baseUrl);
      };
    }

    if (btnScrollTop) {
      btnScrollTop.onclick = () => {
        const cont = document.getElementById('ja-api-scroll-container');
        if (cont) cont.scrollTo({ top: 0, behavior: 'smooth' });
      };
    }

    // Rolar suavemente ate a estacao de QR Code no final da tela
    function scrollToQrStation(keyId = null){
      if (keyId && qrSelect) {
        qrSelect.value = String(keyId);
        updateStationQr();
      }
      if (stationSection) {
        stationSection.scrollIntoView({ behavior: 'smooth', block: 'end' });
        stationSection.classList.remove('ja-station-highlight');
        void stationSection.offsetWidth; // Força reflow para reiniciar animação
        stationSection.classList.add('ja-station-highlight');
      }
    }

    // Inicializa a estacao de QR Code
    updateStationQr();

    // Header action: Incluir chave
    document.querySelector('.ja-native-actions [data-act="new_key"]')?.addEventListener('click', () => {
      openNewApiKeyModal(baseUrl, (createdRes) => {
        renderExternal().then(() => {
          setTimeout(() => {
            const newId = createdRes.token?.id || createdRes.id;
            if (newId) scrollToQrStation(newId);
          }, 200);
        });
      });
    });

    // Card actions (QR Code / Copy / Revoke / Delete)
    document.querySelectorAll('.ja-native-body [data-act]').forEach(b => {
      b.onclick = async () => {
        const [act, idStr] = (b.dataset.act || '').split(':');
        const id = Number(idStr);
        if (!id) return;
        const target = allKeys.find(x => Number(x.id) === id);
        const nome = target ? target.nome : ('#' + id);

        if (act === 'qrcode') {
          if (target && target.token) {
            scrollToQrStation(target.id);
          } else {
            alert('Esta chave antiga foi gravada apenas em formato de hash criptografico e nao pode ser reexibida em QR Code. Crie uma nova chave para gerar o QR Code correspondente.');
          }
        }

        if (act === 'copy') {
          if (target && target.token) {
            copyToClipboard(target.token, b, 'COPIADO!');
          }
        }

        if (act === 'revoke') {
          if (!confirm('Atencao: Deseja realmente REVOGAR o acesso da chave "' + nome + '"?\n\nO celular, relogio ou aplicativo que estiver utilizando esta chave perdera a conexao imediatamente.')) return;
          b.disabled = true;
          try {
            await postJson('/casa/api/crud.php?acao=revogar_api_key', { id });
            renderExternal();
          } catch(e) {
            alert('Falha ao revogar chave: ' + (e.message || String(e)));
            b.disabled = false;
          }
        }

        if (act === 'delete') {
          if (!confirm('Deseja EXCLUIR permanentemente a chave "' + nome + '"?\n\nEsta exclusao e definitiva.')) return;
          b.disabled = true;
          try {
            await postJson('/casa/api/crud.php?acao=excluir_api_key', { id });
            renderExternal();
          } catch(e) {
            alert('Falha ao excluir chave: ' + (e.message || String(e)));
            b.disabled = false;
          }
        }
      };
    });

    // Master key QR Code, toggle, copy & rotate
    const masterInput = document.getElementById('ja-master-key-input');
    const btnQrMaster = document.getElementById('ja-btn-qr-master');
    const btnToggleMaster = document.getElementById('ja-btn-toggle-master');
    const btnCopyMaster = document.getElementById('ja-btn-copy-master');
    const btnRotateMaster = document.getElementById('ja-btn-rotate-master');

    if (btnQrMaster) {
      btnQrMaster.onclick = () => {
        scrollToQrStation('master');
      };
    }

    if (btnToggleMaster && masterInput) {
      btnToggleMaster.onclick = () => {
        if (masterInput.type === 'password') {
          masterInput.type = 'text';
          btnToggleMaster.textContent = 'OCULTAR';
        } else {
          masterInput.type = 'password';
          btnToggleMaster.textContent = 'MOSTRAR';
        }
      };
    }

    if (btnCopyMaster && masterInput) {
      btnCopyMaster.onclick = () => copyToClipboard(masterKey, btnCopyMaster, 'COPIADO!');
    }

    if (btnRotateMaster) {
      btnRotateMaster.onclick = async () => {
        if (!confirm('Atencao: Rotacionar a chave mestre INVALIDA a chave anterior imediatamente. Deseja continuar?')) return;
        btnRotateMaster.disabled = true;
        try {
          await crud('devices', 'rotacionar_external_api_key');
          renderExternal();
        } catch(e) {
          alert('Falha ao rotacionar chave: ' + (e.message || String(e)));
          btnRotateMaster.disabled = false;
        }
      };
    }

  } catch(e) {
    showModuleError('Falha ao carregar Acesso Web & API: ' + (e.message || String(e)));
  }
}

function computerVoice(){
  if(!('speechSynthesis' in window)) return null;
  const voices=window.speechSynthesis.getVoices()||[];
  const br=voices.filter(v=>String(v.lang||'').toLowerCase()==='pt-br');
  if(!br.length) return null;
  const femaleHints=['francisca','maria','luciana','fernanda','vitoria','vitória','camila','leticia','letícia','female','feminina','woman'];
  return br.find(v=>femaleHints.some(h=>(String(v.name||'')+' '+String(v.voiceURI||'')).toLowerCase().includes(h))) || br[0];
}
let computerSpeechGeneration=0;
let computerBargeIn=null;
let computerRequestInFlight=false;
let lastComputerSubmission={text:'',at:0};
async function startComputerBargeIn(generation){
  stopComputerBargeIn();
  if(!navigator.mediaDevices?.getUserMedia)return;
  try{
    const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true}});
    const Ctx=window.AudioContext||window.webkitAudioContext;
    if(!Ctx){stream.getTracks().forEach(t=>t.stop());return;}
    const ctx=new Ctx(),src=ctx.createMediaStreamSource(stream),analyser=ctx.createAnalyser();
    analyser.fftSize=1024; analyser.smoothingTimeConstant=.35; src.connect(analyser);
    const data=new Uint8Array(analyser.fftSize);
    let loudFrames=0,raf=0;
    const state={stream,ctx,raf:0}; computerBargeIn=state;
    const tick=()=>{
      if(computerBargeIn!==state||generation!==computerSpeechGeneration){stopComputerBargeIn();return;}
      analyser.getByteTimeDomainData(data);
      let sum=0;
      for(let i=0;i<data.length;i++){const v=(data[i]-128)/128;sum+=v*v;}
      const rms=Math.sqrt(sum/data.length);
      loudFrames=rms>.075?loudFrames+1:Math.max(0,loudFrames-1);
      if(loudFrames>=7){
        stopComputerSpeech();
        setTimeout(()=>window.CASAComputerActions?.voice?.(),120);
        return;
      }
      state.raf=requestAnimationFrame(tick);
    };
    state.raf=requestAnimationFrame(tick);
  }catch(e){}
}
function stopComputerBargeIn(){
  const state=computerBargeIn; computerBargeIn=null;
  if(!state)return;
  if(state.raf)cancelAnimationFrame(state.raf);
  state.stream?.getTracks().forEach(t=>t.stop());
  state.ctx?.close?.().catch?.(()=>{});
}
function splitComputerSpeech(text,maxChars=140){
  const clean=String(text||'').replace(/\s+/g,' ').trim();
  if(!clean)return [];
  const sentences=clean.match(/[^.!?;:]+[.!?;:]*/g)||[clean];
  const chunks=[];
  for(const raw of sentences){
    let part=raw.trim();
    if(!part)continue;
    while(part.length>maxChars){
      let cut=part.lastIndexOf(' ',maxChars);
      if(cut<Math.floor(maxChars*.55))cut=maxChars;
      chunks.push(part.slice(0,cut).trim());
      part=part.slice(cut).trim();
    }
    if(part)chunks.push(part);
  }
  return chunks;
}
function stopComputerSpeech(){
  computerSpeechGeneration++;
  stopComputerBargeIn();
  if('speechSynthesis' in window)window.speechSynthesis.cancel();
  const btn=document.getElementById('ja-command-stop');
  if(btn){btn.hidden=true;btn.disabled=true;btn.textContent='STOP';}
}
function setComputerSpeaking(active){
  const btn=document.getElementById('ja-command-stop');
  if(btn){btn.hidden=!active;btn.disabled=!active;}
  isComputerSpeaking = active;
  updateMobileVoiceBarUI(false);
  if(!active && isMobileDevice() && mobileVoiceEnabled && !computerRequestInFlight && !activeRecognition && currentGroup==='IA & VOZ' && currentItem==='jarvis'){
    setTimeout(()=>{
      if(mobileVoiceEnabled && !computerRequestInFlight && !activeRecognition && !isComputerSpeaking && currentGroup==='IA & VOZ' && currentItem==='jarvis'){
        window.CASAComputerActions?.voice?.();
      }
    }, 450);
  }
}
function speakComputer(text){
  const speech=String(text||'').trim();
  if(!speech || !('speechSynthesis' in window)) return false;
  const synth=window.speechSynthesis;
  const chunks=splitComputerSpeech(speech);
  if(!chunks.length)return false;
  const generation=++computerSpeechGeneration;
  synth.cancel();
  setComputerSpeaking(true);
  startComputerBargeIn(generation);
  let index=0;
  const voice=computerVoice();
  const speakNext=()=>{
    if(generation!==computerSpeechGeneration)return;
    if(index>=chunks.length){setComputerSpeaking(false);stopComputerBargeIn();return;}
    const u=new SpeechSynthesisUtterance(chunks[index++]);
    u.lang='pt-BR';
    u.rate=1;
    u.pitch=1;
    u.volume=1;
    if(voice)u.voice=voice;
    u.onend=()=>{if(generation===computerSpeechGeneration)setTimeout(speakNext,90);};
    u.onerror=(event)=>{
      if(generation!==computerSpeechGeneration)return;
      const err=String(event?.error||'');
      if(err==='canceled'||err==='interrupted'){setComputerSpeaking(false);return;}
      if(index>=chunks.length){setComputerSpeaking(false);return;}
      setTimeout(speakNext,120);
    };
    synth.speak(u);
  };
  speakNext();
  return true;
}
async function loadComputerHistory(){
  try{
    const j=await getJson('/casa/api/computer_historico.php?limite=25');
    return Array.isArray(j.dados)?j.dados:[];
  }catch(e){
    return [];
  }
}
async function renderJarvis(){
  const isMobile = isMobileDevice();
  mobileVoiceEnabled = isMobile;

  moduleShell('Núcleo COMPUTER',
    '<div class="ja-jarvis">' +
      '<div id="ja-mobile-voice-bar" class="ja-mobile-voice-bar" style="' + (isMobile ? 'display:flex' : 'display:none') + '">' +
        '<div class="ja-mobile-voice-state">' +
          '<span class="ja-voice-pulse ' + (isMobile ? 'active' : '') + '"></span>' +
          '<span id="ja-mobile-voice-label">' + (isMobile ? 'VOZ ATIVA (PADRÃO) · OUVINDO' : 'VOZ INATIVA') + '</span>' +
        '</div>' +
        '<button id="ja-mobile-voice-toggle" type="button" class="ja-mini ' + (isMobile ? 'danger' : 'primary') + '">' +
          (isMobile ? 'PAUSAR VOZ' : 'ATIVAR VOZ') +
        '</button>' +
      '</div>' +
      '<div id="ja-chat-log" class="ja-chat-log">' +
        '<div class="ja-chat-line ai">Carregando histórico...</div>' +
      '</div>' +
      '<div class="ja-command">' +
        '<button id="ja-command-voice" type="button" class="ja-voice-inline-btn" title="Reconhecimento de Voz">🎤 VOZ</button>' +
        '<input id="ja-command-input" placeholder="Digite um comando ou fale com o COMPUTER" autocomplete="off">' +
        '<button id="ja-command-send">ENVIAR</button>' +
        '<button id="ja-command-stop" class="danger" hidden>STOP</button>' +
      '</div>' +
    '</div>'
  );
  const input=document.getElementById('ja-command-input');
  const log=document.getElementById('ja-chat-log');
  await diagnoseVoiceCore();

  const history=await loadComputerHistory();
  let conversationHistory=[];
  if(log){
    log.innerHTML='';
    if(!history.length){
      appendChat('COMPUTER','Pronto.','ai');
    }else{
      history.forEach(h=>{
        const userMsg=String(h.user_msg||'').trim(), botMsg=String(h.bot_msg||'').trim();
        appendChat('Você',userMsg,'user',false);
        appendChat('COMPUTER',botMsg,'ai',false);
        if(userMsg) conversationHistory.push({role:'user',content:userMsg});
        if(botMsg) conversationHistory.push({role:'assistant',content:botMsg});
      });
      log.scrollTop=log.scrollHeight;
    }
  }

  const send=async()=>{
    const cmd=input.value.trim();
    if(!cmd)return;

    const now=Date.now();
    if(computerRequestInFlight)return;
    if(lastComputerSubmission.text===cmd && (now-lastComputerSubmission.at)<1500)return;
    computerRequestInFlight=true;
    lastComputerSubmission={text:cmd,at:now};

    input.value='';
    appendChat('Você',cmd,'user');
    const sendBtn=document.getElementById('ja-command-send');
    if(sendBtn)sendBtn.disabled=true;
    try{
      const historicoTexto=conversationHistory.map(m=>(m.role==='assistant'?'ASSISTANT':'USER')+': '+m.content).join('\n\n');
      const r=await postJson('/casa/api/jarvis.php',{comando:cmd,ia_mode:'auto',historico:historicoTexto,historico_mensagens:conversationHistory});
      const resposta=r.resposta||r.mensagem||'Sem resposta.';
      appendChat('COMPUTER',resposta,'ai');
      conversationHistory.push({role:'user',content:cmd},{role:'assistant',content:String(resposta)});
      if(!speakComputer(resposta)){
        appendChat('Sistema','Voz pt-BR não disponível neste navegador.','error');
      }
    }catch(e){
      appendChat('Sistema',e.message,'error');
    }finally{
      computerRequestInFlight=false;
      if(sendBtn)sendBtn.disabled=false;
    }
  };
  document.getElementById('ja-command-send').onclick=send;
  input.onkeydown=e=>{if(e.key==='Enter')send();};
  const stopBtn=document.getElementById('ja-command-stop');
  if(stopBtn)stopBtn.onclick=stopComputerSpeech;

  const triggerVoice=()=>startVoice(input,send);

  const voiceInlineBtn=document.getElementById('ja-command-voice');
  if(voiceInlineBtn){
    voiceInlineBtn.onclick=()=>{
      if(isMobileDevice()){
        mobileVoiceEnabled = !mobileVoiceEnabled;
        if(mobileVoiceEnabled){
          triggerVoice();
        } else {
          if(activeRecognition){try{activeRecognition.stop();}catch(_){}}
          activeRecognition=null;
          updateMobileVoiceBarUI(false,'VOZ PAUSADA');
        }
      } else {
        triggerVoice();
      }
    };
  }

  const voiceToggleBtn=document.getElementById('ja-mobile-voice-toggle');
  if(voiceToggleBtn){
    voiceToggleBtn.onclick=()=>{
      mobileVoiceEnabled = !mobileVoiceEnabled;
      if(mobileVoiceEnabled){
        triggerVoice();
      } else {
        if(activeRecognition){try{activeRecognition.stop();}catch(_){}}
        activeRecognition=null;
        updateMobileVoiceBarUI(false,'VOZ PAUSADA');
      }
    };
  }

  window.CASAComputerActions={
    voice:triggerVoice,
    clear:async()=>{
      if(!confirm('Deseja apagar todo o histórico de conversas do COMPUTER? Esta ação não pode ser desfeita.')) return;
      const clearBtn=document.getElementById('ja-left-clear');
      if(clearBtn){clearBtn.disabled=true;clearBtn.textContent='LIMPANDO...';}
      try{
        const r=await postJson('/casa/api/computer_historico.php',{acao:'limpar'});
        if(log){
          log.innerHTML='';
          appendChat('COMPUTER',r.mensagem||'Histórico limpo.','ai');
          conversationHistory=[];
        }
      }catch(e){
        appendChat('Sistema',e.message||'Falha ao limpar histórico.','error');
      }finally{
        if(clearBtn){clearBtn.disabled=false;clearBtn.textContent='LIMPAR HISTÓRICO';}
      }
    }
  };

  // Se mobile: habilita e inicia o reconhecimento de voz por padrão
  if(isMobileDevice() && mobileVoiceEnabled){
    setTimeout(()=>{
      if(currentGroup==='IA & VOZ' && currentItem==='jarvis' && mobileVoiceEnabled && !activeRecognition && !computerRequestInFlight){
        triggerVoice();
      }
    }, 350);

    const onUserTouch=()=>{
      if(mobileVoiceEnabled && !activeRecognition && !computerRequestInFlight && !isComputerSpeaking && currentGroup==='IA & VOZ' && currentItem==='jarvis'){
        triggerVoice();
      }
    };
    window.addEventListener('touchstart', onUserTouch, {once:true, passive:true});
    window.addEventListener('click', onUserTouch, {once:true, passive:true});
  }

  scheduleAdminMount();
}
function appendChat(who,text,cls,scroll=true){
  const l=document.getElementById('ja-chat-log');
  if(!l)return;
  const d=document.createElement('div');
  d.className='ja-chat-line '+cls;
  const name=document.createElement('strong');
  name.className='ja-chat-who';
  name.textContent=who+':';
  const body=document.createElement('span');
  body.className='ja-chat-text';
  body.textContent=String(text??'');
  d.appendChild(name);
  d.appendChild(body);
  l.appendChild(d);

  // Mantém o DOM limitado sem perder o histórico persistido no banco.
  while(l.children.length>100) l.removeChild(l.firstChild);
  if(scroll) l.scrollTop=l.scrollHeight;
}
let activeRecognition=null;
let mobileVoiceEnabled=false;
let isComputerSpeaking=false;

function isMobileDevice(){
  const ua = navigator.userAgent || '';
  const mobileRegex = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i;
  const isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
  const isSmallScreen = window.innerWidth <= 768 || (window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
  return mobileRegex.test(ua) || (isTouch && isSmallScreen);
}

function updateMobileVoiceBarUI(listening, customText=null){
  const bar = document.getElementById('ja-mobile-voice-bar');
  const label = document.getElementById('ja-mobile-voice-label');
  const dot = bar?.querySelector('.ja-voice-pulse');
  const toggle = document.getElementById('ja-mobile-voice-toggle');
  const inlineBtn = document.getElementById('ja-command-voice');
  const leftBtn = document.getElementById('ja-left-voice');

  if(inlineBtn){
    if(listening){
      inlineBtn.classList.add('listening');
      inlineBtn.textContent='🎙️ OUVINDO...';
    }else{
      inlineBtn.classList.remove('listening');
      inlineBtn.textContent='🎤 VOZ';
    }
  }
  if(leftBtn){
    leftBtn.textContent = listening ? 'OUVINDO...' : 'VOZ';
    leftBtn.disabled = listening;
  }

  if(!bar) return;
  if(!isMobileDevice()){
    bar.style.display = 'none';
    return;
  }
  bar.style.display = 'flex';

  if(!mobileVoiceEnabled){
    if(label) label.textContent = customText || 'VOZ PAUSADA';
    if(dot){ dot.className = 'ja-voice-pulse'; }
    if(toggle){ toggle.textContent = 'ATIVAR VOZ'; toggle.className = 'ja-mini primary'; }
    return;
  }

  if(toggle){ toggle.textContent = 'PAUSAR VOZ'; toggle.className = 'ja-mini danger'; }

  if(isComputerSpeaking){
    if(label) label.textContent = 'JARVIS FALANDO...';
    if(dot){ dot.className = 'ja-voice-pulse speaking'; }
  } else if(listening){
    if(label) label.textContent = customText || 'VOZ ATIVA · OUVINDO...';
    if(dot){ dot.className = 'ja-voice-pulse active'; }
  } else if(computerRequestInFlight){
    if(label) label.textContent = 'PROCESSANDO...';
    if(dot){ dot.className = 'ja-voice-pulse'; }
  } else {
    if(label) label.textContent = customText || 'VOZ ATIVA (PADRÃO)';
    if(dot){ dot.className = 'ja-voice-pulse active'; }
  }
}

function voiceStatus(state,text,ok=false){
  const el=document.getElementById('ja-mic-state');
  if(el){el.textContent=text;el.className='ja-state '+(ok?'ok':state==='listening'?'warn':'off');}
  window.CASAVoiceStatus=Object.assign({},window.CASAVoiceStatus||{},{mic:{text,kind:ok?'ok':state==='listening'?'warn':'off'}});
  refreshLeftVoiceStatus();
}
async function diagnoseVoiceCore(){
  const SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  const stt=document.getElementById('ja-stt-state'),tts=document.getElementById('ja-tts-state'),dev=document.getElementById('ja-mic-devices');
  const sttText='STT: '+(SR?'disponível':'não suportado');
  const ttsText='TTS: '+(('speechSynthesis' in window)?'disponível':'não suportado');
  if(stt)stt.textContent=sttText;
  if(tts)tts.textContent=ttsText;
  window.CASAVoiceStatus=Object.assign({},window.CASAVoiceStatus||{},{stt:{text:sttText,kind:SR?'ok':'off'},tts:{text:ttsText,kind:('speechSynthesis' in window)?'ok':'off'}});
  refreshLeftVoiceStatus();
  if(!navigator.mediaDevices?.getUserMedia){voiceStatus('error','MIC NÃO SUPORTADO');return false;}
  try{
    const stream=await navigator.mediaDevices.getUserMedia({audio:true});
    stream.getTracks().forEach(t=>t.stop());
    const devices=await navigator.mediaDevices.enumerateDevices();
    const inputs=devices.filter(d=>d.kind==='audioinput');
    const deviceText='Entradas: '+inputs.length+(inputs.length?' · '+inputs.map((d,i)=>d.label||('Microfone '+(i+1))).join(' / '):'');
    if(dev)dev.textContent=deviceText;
    window.CASAVoiceStatus=Object.assign({},window.CASAVoiceStatus||{},{devices:{text:'ENTRADAS: '+inputs.length,detail:deviceText,kind:inputs.length?'ok':'off'}});
    refreshLeftVoiceStatus();
    if(!inputs.length){voiceStatus('error','SEM MICROFONE');return false;}
    voiceStatus('ready','MIC OK',true);return true;
  }catch(e){
    const denied=e?.name==='NotAllowedError'||e?.name==='SecurityError';
    voiceStatus('error',denied?'MIC SEM PERMISSÃO':'MIC INDISPONÍVEL');
    if(dev)dev.textContent='Entradas: não acessíveis';
    window.CASAVoiceStatus=Object.assign({},window.CASAVoiceStatus||{},{devices:{text:'ENTRADAS: INDISP.',detail:'Entradas: não acessíveis',kind:'off'}});
    refreshLeftVoiceStatus();
    return false;
  }
}
async function startVoice(input,done){
  const SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  if(!SR){
    voiceStatus('error','STT NÃO SUPORTADO');
    updateMobileVoiceBarUI(false,'STT NÃO SUPORTADO');
    if(!isMobileDevice()) appendChat('Sistema','Este navegador não oferece SpeechRecognition. Use a digitação ou um navegador compatível.','error');
    return;
  }
  if(activeRecognition)return;
  stopComputerSpeech();
  if(!(await diagnoseVoiceCore())){
    updateMobileVoiceBarUI(false,'MIC INDISPONÍVEL');
    if(!isMobileDevice()) appendChat('Sistema','Não foi possível acessar uma entrada de áudio. Verifique a permissão do microfone no navegador.','error');
    return;
  }
  const r=new SR(); activeRecognition=r;
  r.lang='pt-BR'; r.continuous=false; r.interimResults=false; r.maxAlternatives=1;
  let resultConsumed=false;

  voiceStatus('listening','OUVINDO');
  updateMobileVoiceBarUI(true);

  r.onresult=e=>{
    if(resultConsumed)return;
    const finals=Array.from(e.results).filter(x=>x.isFinal!==false);
    const transcript=finals.map(x=>x[0]?.transcript||'').join(' ').trim();
    if(transcript){
      resultConsumed=true;
      input.value=transcript;
      voiceStatus('ready','RECONHECIDO',true);
      updateMobileVoiceBarUI(false,'RECONHECIDO');
      try{r.stop();}catch(_){}
      done();
    }else if(e.results.length){
      voiceStatus('listening','OUVINDO');
      updateMobileVoiceBarUI(true);
    }else{
      voiceStatus('error','SEM RESULTADO');
      updateMobileVoiceBarUI(false,'SEM RESULTADO');
    }
  };
  r.onnomatch=()=>{
    voiceStatus('error','NÃO RECONHECIDO');
    updateMobileVoiceBarUI(false,'NÃO RECONHECIDO');
    if(!isMobileDevice()) appendChat('Sistema','Não consegui reconhecer a fala. Tente novamente mais próximo do microfone.','error');
  };
  r.onerror=e=>{
    if(e.error==='no-speech'||e.error==='aborted'){
      if(!isMobileDevice()) voiceStatus('ready','MIC OK',true);
      return;
    }
    const map={'not-allowed':'Permissão de microfone negada.','service-not-allowed':'Serviço de reconhecimento bloqueado pelo navegador.','audio-capture':'Nenhum áudio pôde ser capturado.','network':'O serviço de reconhecimento de voz não respondeu pela rede.'};
    voiceStatus('error','ERRO DE VOZ');
    updateMobileVoiceBarUI(false,map[e.error]||'ERRO NO MIC');
    if(e.error==='not-allowed'&&isMobileDevice()){
      mobileVoiceEnabled=false;
    }
    appendChat('Sistema',map[e.error]||('Erro no reconhecimento de voz: '+e.error),'error');
  };
  r.onend=()=>{
    activeRecognition=null;
    updateMobileVoiceBarUI(false);
    const s=document.getElementById('ja-mic-state');
    if(s&&s.textContent==='OUVINDO')voiceStatus('ready','MIC OK',true);

    // Se no mobile com reconhecimento padrão ativo e o computador não está falando nem enviando:
    if(isMobileDevice() && mobileVoiceEnabled && !computerRequestInFlight && !isComputerSpeaking && currentGroup==='IA & VOZ' && currentItem==='jarvis'){
      setTimeout(()=>{
        if(mobileVoiceEnabled && !computerRequestInFlight && !isComputerSpeaking && !activeRecognition && currentGroup==='IA & VOZ' && currentItem==='jarvis'){
          startVoice(input,done);
        }
      }, 500);
    }
  };
  try{
    r.start();
  }catch(e){
    activeRecognition=null;
    updateMobileVoiceBarUI(false,'FALHA AO INICIAR');
    voiceStatus('error','FALHA AO INICIAR');
    if(!isMobileDevice()) appendChat('Sistema',e.message||'Falha ao iniciar reconhecimento de voz.','error');
  }
}

// Carrega a lista de vozes assim que o navegador disponibilizá-la.
if('speechSynthesis' in window){
  window.speechSynthesis.getVoices();
  window.speechSynthesis.addEventListener?.('voiceschanged',()=>window.speechSynthesis.getVoices());
}

async function renderCameras(){
  loading('Câmeras & visão');
  try{
    const j=await crud('dispositivos_cluster');
    const raw=j.dados||[];
    // Filtra câmeras dinamicamente do banco de dados (ESP32-CAM, streaming, etc)
    const cams=raw.filter(d=>{
      const t=String(d.tipo||'').toLowerCase();
      const m=String(d.model||'').toLowerCase();
      const caps=Array.isArray(d.capabilities)?d.capabilities.join(' ').toLowerCase():String(d.capabilities||'').toLowerCase();
      return t==='camera'||caps.includes('camera')||caps.includes('streaming')||m.includes('cam');
    });

    if(!cams.length){
      moduleShell('Câmeras & visão','<div class="ja-empty">Nenhuma câmera registrada no momento. Registre novos módulos ESP32-CAM no banco para visualização dinâmica.</div>');
      return;
    }

    const cardsHtml=cams.map(cam=>{
      const ip=cam.ip_address||cam.local_ip||'';
      const name=esc(cam.nome||cam.device_id||'Câmera');
      const loc=esc(cam.localizacao||'Ambiente monitorado');
      let actions='';
      if(ip){
        actions='<div class="ja-row-actions" style="margin-top:10px;">'+
          '<button class="ja-btn primary" onclick="window.open(\'http://'+esc(ip)+'/capture?t=\'+Date.now(),\'_blank\')">📸 CAPTURAR</button>'+
          '<button class="ja-btn" onclick="window.open(\'http://'+esc(ip)+'/stream\',\'_blank\')">📹 STREAM</button>'+
          '<button class="ja-btn" onclick="fetch(\'http://'+esc(ip)+'/flash/on\').catch(()=>{})">💡 FLASH</button>'+
          '<button class="ja-btn" onclick="postJson(\'/casa/api/jarvis.php\',{comando:\'/cloud Analise a imagem recente da câmera '+name+'\'}).catch(()=>{})">🤖 IA VISÃO</button>'+
        '</div>';
      }else{
        actions='<div style="opacity:0.6;font-size:0.85rem;margin-top:8px;">Aguardando IP atribuído pelo registro central.</div>';
      }

      return '<article class="ja-native-card">'+
        '<h3>'+name+'</h3>'+
        '<p>'+loc+' · <i>'+esc(cam.model||'ESP32-CAM')+'</i></p>'+
        '<dl>'+
          '<dt>Identificador</dt><dd>'+esc(cam.device_id)+'</dd>'+
          '<dt>Endereço IP</dt><dd>'+(ip?'<a href="http://'+esc(ip)+'/" target="_blank" style="color:#38bdf8;text-decoration:none;font-weight:bold;">'+esc(ip)+'</a>':'—')+'</dd>'+
          '<dt>Status</dt><dd><span class="ja-state '+(cam.status==='online'?'ok':'off')+'">'+esc((cam.status||'OFFLINE').toUpperCase())+'</span></dd>'+
        '</dl>'+
        actions+
      '</article>';
    }).join('');

    moduleShell('Câmeras & visão','<div class="ja-camera-grid">'+cardsHtml+'</div>','<button id="cam-refresh" class="ja-mini">Atualizar Câmeras</button>');
    document.getElementById('cam-refresh').onclick=renderCameras;
  }catch(e){
    moduleShell('Câmeras & visão','<div class="ja-empty">Falha ao carregar câmeras do registro: '+esc(e.message||'Erro')+'</div>');
  }
}

async function renderHealth(){
  loading('Saúde do sistema');
  const j=await getJson('/casa/api/v1/system_health.php').catch(()=>getJson('/casa/api/v1/health.php'));
  const entries=Object.entries(j||{}).filter(([k])=>k!=='ok').slice(0,8);
  moduleShell('Saúde do sistema',cards(entries,([k,v])=>'<article class="ja-native-card metric"><h3>'+esc(k)+'</h3><strong>'+esc(typeof v==='object'?JSON.stringify(v):v)+'</strong></article>'));
}

function mountPageModule(item){
  const c=contentNode(); if(!c) return;
  c.innerHTML='<div class="ja-module-wrap"><div class="ja-module-title"><strong>'+esc(item.label)+'</strong><small>CASA / módulo atual</small></div><iframe class="ja-module-frame" title="'+attr(item.label)+'" src="'+attr(item.url)+'" scrolling="no"></iframe></div>';
  const frame=c.querySelector('iframe');
  frame.addEventListener('load',()=>fitFrame(frame));
}
function fitFrame(frame){
  try{
    const doc=frame.contentDocument;if(!doc)return;
    const st=doc.createElement('style');
    st.textContent='html{margin:0!important;width:100%!important;max-width:100%!important;height:100%!important;overflow:hidden!important}body{margin:0!important;width:100%!important;max-width:100%!important;min-width:0!important;height:100%!important;box-sizing:border-box!important;overflow-x:hidden!important;overflow-y:auto!important}*,*:before,*:after{box-sizing:border-box;max-width:100%}';
    doc.head.appendChild(st);
    const body=doc.body;
    body.style.transform='none';
    body.style.transformOrigin='';
    body.style.width='100%';
    body.style.height='100%';
  }catch(e){console.warn('CASA módulo:',e);}
}


function changePassword(){
  document.getElementById('ja-password-modal')?.remove();
  const modal=document.createElement('div');
  modal.id='ja-password-modal';
  modal.className='ja-password-backdrop';
  modal.innerHTML=
    '<section class="ja-password-modal" role="dialog" aria-modal="true" aria-labelledby="ja-password-title">'+
      '<header><strong id="ja-password-title">ALTERAR SENHA</strong><button type="button" data-pass-close>FECHAR</button></header>'+
      '<div class="ja-password-body">'+
        '<label><span>Senha atual</span><input id="ja-pass-current" type="password" autocomplete="current-password"></label>'+
        '<label><span>Nova senha</span><input id="ja-pass-new" type="password" autocomplete="new-password" minlength="8"></label>'+
        '<label><span>Confirmar nova senha</span><input id="ja-pass-confirm" type="password" autocomplete="new-password" minlength="8"></label>'+
        '<div id="ja-pass-message" class="ja-password-message" aria-live="polite"></div>'+
      '</div>'+
      '<footer><button type="button" data-pass-save>ALTERAR SENHA</button></footer>'+
    '</section>';
  document.body.appendChild(modal);

  const close=()=>modal.remove();
  modal.querySelector('[data-pass-close]').onclick=close;
  modal.addEventListener('click',e=>{if(e.target===modal)close();});

  const current=modal.querySelector('#ja-pass-current');
  const next=modal.querySelector('#ja-pass-new');
  const confirm=modal.querySelector('#ja-pass-confirm');
  const msg=modal.querySelector('#ja-pass-message');
  const save=modal.querySelector('[data-pass-save]');
  current.focus();

  const show=(text,type='')=>{
    msg.className='ja-password-message '+type;
    msg.textContent=text;
  };

  save.onclick=async()=>{
    const senhaAtual=current.value;
    const novaSenha=next.value;
    const confirmar=confirm.value;

    if(!senhaAtual || !novaSenha || !confirmar){
      show('Preencha os três campos.','error');
      return;
    }
    if(novaSenha.length<8){
      show('A nova senha deve ter pelo menos 8 caracteres.','error');
      return;
    }
    if(novaSenha!==confirmar){
      show('A confirmação da nova senha não confere.','error');
      return;
    }
    if(senhaAtual===novaSenha){
      show('A nova senha deve ser diferente da senha atual.','error');
      return;
    }

    save.disabled=true;
    save.textContent='ALTERANDO...';
    show('Validando senha atual...','info');
    try{
      const r=await postJson('/casa/api/alterar_senha.php',{
        senha_atual:senhaAtual,
        nova_senha:novaSenha,
        confirmar_senha:confirmar
      });
      current.value='';
      next.value='';
      confirm.value='';
      show(r.mensagem||'Senha alterada com sucesso.','success');
      save.textContent='SENHA ALTERADA';
      setTimeout(close,1400);
    }catch(e){
      show(e.message||'Não foi possível alterar a senha.','error');
      save.disabled=false;
      save.textContent='ALTERAR SENHA';
    }
  };
}

function handleAction(detail){
  const action=detail.action||'';
  if(action==='home' || action==='back') return home();
  if(action==='open-current-group' && currentGroup) return openGroup(currentGroup);
  if(action==='open-item'){const it=findItem(currentGroup,detail.id);if(it)openItem(it);return;}
  if(action.startsWith('open-group:')) return openGroup(action.substring(11));
  if(action==='select' || action==='navigate'){const it=findItem(currentGroup,detail.id);if(it)openItem(it);}
}

function refreshLeftVoiceStatus(){
  const host=document.getElementById('ja-left-voice-status');
  if(!host)return;
  const st=window.CASAVoiceStatus||{};
  const items=[
    st.mic||{text:'MIC: verificando',kind:'off'},
    st.devices||{text:'ENTRADAS: —',detail:'Entradas de áudio ainda não verificadas',kind:'off'},
    st.stt||{text:'STT: verificando',kind:'off'},
    st.tts||{text:'TTS: verificando',kind:'off'}
  ];
  host.innerHTML=items.map(x=>'<button type="button" class="ja-left-status '+attr(x.kind||'off')+'" title="'+attr(x.detail||x.text||'')+'" disabled>'+esc(x.text||'—')+'</button>').join('');
}

function mountAdminTools(){
  const status=document.querySelector('#app .ja-status');
  const template=document.getElementById('ja-admin-template');
  if(!status || !template) return;

  let box=status.querySelector('.ja-admin-tools');
  if(box) return;

  box=document.createElement('section');
  box.className='ja-admin-tools';
  box.setAttribute('aria-label','Funções administrativas');
  box.innerHTML='<div class="ja-admin-title">ADMINISTRAÇÃO</div>';

  const homeBtn=template.querySelector('.home')?.cloneNode(true);
  const passBtn=template.querySelector('.password')?.cloneNode(true);
  const logout=template.querySelector('.logout')?.cloneNode(true);

  if(homeBtn){
    homeBtn.onclick=()=>home();
    box.appendChild(homeBtn);
  }
  if(passBtn){
    passBtn.onclick=()=>changePassword();
    box.appendChild(passBtn);
  }

  if(currentGroup==='IA & VOZ' && currentItem==='jarvis'){
    const voiceStatusBox=document.createElement('div');
    voiceStatusBox.id='ja-left-voice-status';
    voiceStatusBox.className='ja-left-voice-status';
    box.appendChild(voiceStatusBox);
    refreshLeftVoiceStatus();

    const voiceBtn=document.createElement('button');
    voiceBtn.id='ja-left-voice';
    voiceBtn.type='button';
    voiceBtn.className='voice';
    voiceBtn.textContent='VOZ';
    voiceBtn.onclick=()=>window.CASAComputerActions?.voice?.();
    box.appendChild(voiceBtn);

    const clearBtn=document.createElement('button');
    clearBtn.id='ja-left-clear';
    clearBtn.type='button';
    clearBtn.className='clear-history';
    clearBtn.textContent='LIMPAR HISTÓRICO';
    clearBtn.onclick=()=>window.CASAComputerActions?.clear?.();
    box.appendChild(clearBtn);
  }

  if(logout) box.appendChild(logout);

  status.appendChild(box);
}

function scheduleAdminMount(){
  requestAnimationFrame(()=>mountAdminTools());
}

function restoreRoute(){
  const params=new URLSearchParams(location.search),g=params.get('grupo'),itemId=params.get('item');
  suppressHistory=true;
  if(g&&GROUPS[g]){openGroup(g,false);if(itemId){const it=findItem(g,itemId);if(it)openItem(it,false);}}else home(false);
  suppressHistory=false;
}

document.addEventListener('DOMContentLoaded',()=>{
  const app=document.getElementById('app');
  app.addEventListener('ja:action',e=>{handleAction(e.detail||{});scheduleAdminMount();});
  const observer=new MutationObserver(()=>scheduleAdminMount());
  observer.observe(app,{childList:true,subtree:true});
  restoreRoute();
  scheduleAdminMount();
});
window.addEventListener('popstate',restoreRoute);
window.addEventListener('resize',()=>{const f=document.querySelector('.ja-module-frame');if(f)fitFrame(f);});
window.CASASite={home,openGroup,openItem,changePassword,groups:GROUPS};
})();