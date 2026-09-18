(function(){
'use strict';

const STATUS=[
  {label:'RUNPOD',value:'CONFIG.'},
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
        {id:'iot-op',label:'Cluster IoT',module:'iot'},
        {id:'cenas',label:'Cenas e regras',url:'/casa/automacao.php'}
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
        {id:'mobile-watch',label:'Celular + Watch',url:'/casa/dispositivos_pessoais.php'},
        {id:'family-dev',label:'Dispositivos da família',url:'/casa/familia.php'}
      ]}
    ]
  },
  'AUTOMAÇÃO':{
    subtitle:'Ações, agendas e integrações responsáveis por executar a casa.',
    sections:[
      {title:'EXECUÇÃO',items:[
        {id:'automation-dev',label:'Dispositivos & relés',module:'devices'},
        {id:'automation-ag',label:'Agendamentos',module:'schedules'},
        {id:'automation-iot',label:'Cluster IoT',module:'iot'},
        {id:'automation-scenes',label:'Cenas e regras',url:'/casa/automacao.php'}
      ]},
      {title:'INTEGRAÇÃO',items:[
        {id:'automation-agents',label:'Agentes externos',module:'agents'},
        {id:'automation-api',label:'Acesso Web & API',module:'external'}
      ]}
    ]
  },
  'IA & VOZ':{
    subtitle:'JARVIS, reconhecimento, voz, RunPod e agentes.',
    sections:[
      {title:'JARVIS',items:[
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
        {id:'personal',label:'Celular + Watch',url:'/casa/dispositivos_pessoais.php'}
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
        {id:'sensors-sys',label:'Sensores & telemetria',module:'sensors'},
        {id:'iot-sys',label:'Cluster IoT',module:'iot'}
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
  return Object.keys(GROUPS).map(name=>({title:name,description:GROUPS[name].subtitle,id:name}));
}

function home(updateRoute=true){
  currentGroup=null; currentItem=null;
  CASALcars.render('#app',{
    layout:'dashboard',group:'GRUPOS',title:'CASA / JARVIS',subtitle:'Escolha um grupo. A interface adapta o miolo conforme a tarefa.',
    breadcrumb:['CASA','GRUPOS'],groups:[{label:'GRUPOS',active:true}],status:STATUS,columns:3,
    items:topGroupCards().map(g=>({title:g.title,description:g.description,actions:[{label:'ACESSAR',action:'open-group:'+g.id,variant:'primary'}]})),
    footer:{hint:'Interface em tela cheia: use grupos e paginação, sem rolagem.'}
  });
  if(updateRoute) setRoute(null,null);
}

function groupRail(group){
  const flat=[];
  GROUPS[group].sections.forEach(s=>s.items.forEach(it=>flat.push(it)));
  return [{label:'← GRUPOS',action:'back',back:true}].concat(flat.slice(0,8).map(it=>({label:it.label,id:it.id,action:'open-item',active:currentItem===it.id})));
}

function openGroup(group,updateRoute=true){
  if(!GROUPS[group]) return home(updateRoute);
  currentGroup=group; currentItem=null;
  CASALcars.render('#app',{
    layout:'menu-grid',kind:'menu',group,title:group,subtitle:GROUPS[group].subtitle,
    breadcrumb:['CASA','GRUPOS',group],groups:groupRail(group),status:STATUS,sections:GROUPS[group].sections,
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
  if(!item || !currentGroup) return;
  currentItem=item.id;
  CASALcars.render('#app',{
    layout:'focus',group:currentGroup,title:item.label,subtitle:GROUPS[currentGroup].subtitle,
    breadcrumb:['CASA','GRUPOS',currentGroup,item.label],groups:groupRail(currentGroup),status:STATUS,
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
  return getJson(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data||{})});
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
    if(m==='nodes') return renderNodes();
    if(m==='schedules') return renderSchedules();
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

async function renderDevices(){
  loading('Dispositivos & relés');
  const j=await crud('devices'); const all=j.dados||[]; const p=paginate('devices',all,6);
  moduleShell('Dispositivos & relés',cards(p.slice,d=>'<article class="ja-native-card"><h3>'+esc(d.devname||('Dispositivo '+d.iddevice))+'</h3><p>'+esc(d.devdesc||'')+'</p><dl><dt>ID</dt><dd>'+esc(d.iddevice)+'</dd><dt>Conexão</dt><dd>'+esc(d.devcon||'—')+'</dd><dt>Tipo</dt><dd>'+esc(d.devtype||'—')+'</dd></dl><span class="ja-state '+(d.devstatus?'ok':'off')+'">'+(d.devstatus?'ONLINE':'OFFLINE')+'</span></article>')+pager('devices',p),'');
  bindPager('devices',p,renderDevices);
}

async function renderSensors(){
  loading('Sensores & telemetria');
  const j=await crud('sensores_telemetria','listar','&limite=50'); const all=j.dados||[]; const p=paginate('sensors',all,8);
  moduleShell('Sensores & telemetria',cards(p.slice,s=>'<article class="ja-native-card metric"><h3>'+esc(s.sensor_nome||'Sensor')+'</h3><strong>'+esc(s.valor_numerico??'—')+' '+esc(s.unidade||'')+'</strong><p>'+esc(s.data_hora||'')+'</p></article>')+pager('sensors',p));
  bindPager('sensors',p,renderSensors);
}

async function renderNodes(){
  loading('Cluster ARM');
  const j=await crud('arm_nodes'); const all=j.dados||[]; const p=paginate('nodes',all,6);
  moduleShell('Cluster ARM',cards(p.slice,n=>'<article class="ja-native-card"><h3>'+esc(n.nome||n.hostname||('Nó '+n.id))+'</h3><dl><dt>IP</dt><dd>'+esc(n.ip_address||n.ip||'—')+'</dd><dt>CPU</dt><dd>'+esc(n.cpu_uso||n.cpu||'—')+'</dd><dt>RAM</dt><dd>'+esc(n.ram_uso||n.ram||'—')+'</dd></dl><span class="ja-state '+((n.status||'').toLowerCase()==='online'?'ok':'')+'">'+esc(n.status||'REGISTRADO')+'</span></article>')+pager('nodes',p));
  bindPager('nodes',p,renderNodes);
}

async function renderSchedules(){
  loading('Agendamentos');
  const j=await crud('tarefas_agendadas'); const all=j.dados||[]; const p=paginate('schedules',all,6);
  moduleShell('Agendamentos',cards(p.slice,t=>'<article class="ja-native-card"><h3>'+esc(t.titulo||('Tarefa '+t.id))+'</h3><p>'+esc(t.descricao||'')+'</p><dl><dt>Horário</dt><dd>'+esc(t.horario||'—')+'</dd><dt>Dias</dt><dd>'+esc(t.dias_semana||'—')+'</dd><dt>Ação</dt><dd>'+esc(t.tipo_acao||'—')+'</dd></dl><div class="ja-row-actions">'+actionBtn('RODAR','run:'+t.id,'primary')+actionBtn(t.ativo?'PAUSAR':'ATIVAR','toggle:'+t.id)+actionBtn('EXCLUIR','delete:'+t.id,'danger')+'</div></article>')+pager('schedules',p));
  document.querySelectorAll('[data-act]').forEach(b=>b.onclick=async()=>{const [a,id]=b.dataset.act.split(':');if(a==='run')await postJson('/casa/api/crud.php?tabela=tarefas_agendadas&acao=executar_tarefa&id='+id,{});if(a==='toggle'){const t=all.find(x=>String(x.id)===id);await postJson('/casa/api/crud.php?tabela=tarefas_agendadas&acao=atualizar',{id:Number(id),ativo:!t.ativo});}if(a==='delete'&&confirm('Excluir agendamento?'))await postJson('/casa/api/crud.php?tabela=tarefas_agendadas&acao=excluir&id='+id,{});renderSchedules();});
  bindPager('schedules',p,renderSchedules);
}

async function renderIoT(){
  loading('Cluster IoT');
  const j=await crud('dispositivos_cluster'); const all=j.dados||[]; const p=paginate('iot',all,6);
  moduleShell('Cluster IoT',cards(p.slice,d=>'<article class="ja-native-card"><h3>'+esc(d.nome||('IoT '+d.id))+'</h3><p>'+esc(d.localizacao||'')+'</p><dl><dt>Tipo</dt><dd>'+esc(d.tipo||'—')+'</dd><dt>IP</dt><dd>'+esc(d.ip_address||'—')+'</dd><dt>RSSI</dt><dd>'+esc(d.sinal_rssi||'—')+'</dd></dl><span class="ja-state '+((d.status||'').toLowerCase()==='online'?'ok':'off')+'">'+esc(d.status||'OFFLINE')+'</span></article>')+pager('iot',p));
  bindPager('iot',p,renderIoT);
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
  const [ag,clima]=await Promise.all([crud('agentes_externos'),getJson('/casa/api/agente_externo.php?acao=consultar_clima').catch(()=>({}))]);
  const all=ag.dados||[];
  const body='<div class="ja-native-summary"><div><b>AGENTES</b><strong>'+all.length+'</strong></div><div><b>CLIMA</b><strong>'+esc(clima.clima?.temperatura||'—')+'</strong></div></div>'+cards(all.slice(0,6),a=>'<article class="ja-native-card"><h3>'+esc(a.nome||a.tipo||'Agente')+'</h3><p>'+esc(a.descricao||'')+'</p><span class="ja-state '+(a.ativo?'ok':'off')+'">'+(a.ativo?'ATIVO':'INATIVO')+'</span></article>');
  moduleShell('Agentes externos',body);
}

async function renderPhrases(){
  loading('Frases & avisos');
  const j=await crud('frases'); const all=j.dados||[]; const p=paginate('phrases',all,6);
  moduleShell('Frases & avisos',cards(p.slice,f=>'<article class="ja-native-card"><h3>'+esc(f.autor||'JARVIS')+'</h3><p>“'+esc(f.texto||'')+'”</p><div class="ja-row-actions">'+actionBtn('FALAR','speak:'+f.id,'primary')+actionBtn('EXCLUIR','delete:'+f.id,'danger')+'</div></article>')+pager('phrases',p),actionBtn('NOVA','new','primary'));
  document.querySelector('.ja-native-actions [data-act]')?.addEventListener('click',async()=>{const texto=prompt('Texto da frase:');if(!texto)return;const autor=prompt('Autor/origem:','JARVIS')||'JARVIS';await postJson('/casa/api/crud.php?tabela=frases&acao=criar',{texto,autor});renderPhrases();});
  document.querySelectorAll('.ja-native-body [data-act]').forEach(b=>b.onclick=async()=>{const [a,id]=b.dataset.act.split(':');const f=all.find(x=>String(x.id)===id);if(a==='speak'&&f)await postJson('/casa/ws/proxy_tts.php?action=falar',{texto:f.texto,speaker:'padrao',reproduzir:true}).catch(()=>{});if(a==='delete'&&confirm('Excluir frase?'))await postJson('/casa/api/crud.php?tabela=frases&acao=excluir&id='+id,{});renderPhrases();});
  bindPager('phrases',p,renderPhrases);
}

async function renderUsers(){
  loading('Usuários & senhas');
  const j=await crud('usuarios'); const all=j.dados||[]; const p=paginate('users',all,6);
  moduleShell('Usuários & senhas',cards(p.slice,u=>'<article class="ja-native-card"><h3>'+esc(u.nome||u.login)+'</h3><dl><dt>Login</dt><dd>'+esc(u.login||'—')+'</dd><dt>E-mail</dt><dd>'+esc(u.email||'—')+'</dd><dt>Perfil</dt><dd>'+esc(u.perfil||'—')+'</dd></dl><span class="ja-state '+(u.ativo?'ok':'off')+'">'+(u.ativo?'ATIVO':'INATIVO')+'</span></article>')+pager('users',p));
  bindPager('users',p,renderUsers);
}

async function renderConfig(){
  loading('Configurações de IA');
  const [cfg,models]=await Promise.all([crud('configuracoes_sistema'),getJson('/casa/api/ia_modelos.php?acao=listar').catch(()=>({dados:[]}))]);
  const wanted=['ia_provider','ia_routing_mode','runpod_endpoint_id','runpod_model','local_model','local_ollama_url','openai_base_url','openai_model'];
  const map={};(cfg.dados||[]).forEach(x=>map[x.chave]=x.valor);
  const body='<div class="ja-config-grid">'+wanted.map(k=>'<label><span>'+esc(k)+'</span><input data-cfg="'+attr(k)+'" value="'+attr(map[k]||'')+'"></label>').join('')+'</div><div class="ja-native-summary"><div><b>MODELOS CADASTRADOS</b><strong>'+esc((models.dados||[]).length)+'</strong></div><div><b>ROTEAMENTO</b><strong>'+esc(map.ia_routing_mode||'auto')+'</strong></div></div>';
  moduleShell('Configurações de IA',body,actionBtn('SALVAR','save','primary'));
  document.querySelector('[data-act="save"]')?.addEventListener('click',async()=>{for(const el of document.querySelectorAll('[data-cfg]'))await postJson('/casa/api/crud.php?tabela=configuracoes_sistema&acao=atualizar',{chave:el.dataset.cfg,valor:el.value});renderConfig();});
}

async function renderExternal(){
  loading('Acesso web & API');
  const [tun,key]=await Promise.all([crud('devices','status_tunnel').catch(()=>({})),crud('devices','obter_external_api_key').catch(()=>({}))]);
  const t=tun.tunnel||{};
  const body='<div class="ja-native-summary"><div><b>TÚNEL</b><strong>'+esc((t.status||'indisponível').toUpperCase())+'</strong></div><div><b>URL</b><strong class="small">'+esc(t.url||'—')+'</strong></div></div><div class="ja-config-grid"><label><span>API KEY</span><input type="password" id="ja-api-key" value="'+attr(key.api_key||'')+'" readonly></label><label><span>ENDPOINT</span><input value="/api/v1/status" readonly></label></div>';
  moduleShell('Acesso web & API',body);
}

function renderJarvis(){
  moduleShell('Núcleo JARVIS','<div class="ja-jarvis"><div id="ja-chat-log" class="ja-chat-log"><div class="ja-chat-line ai">JARVIS pronto.</div></div><div class="ja-command"><input id="ja-command-input" placeholder="Digite um comando para o JARVIS"><button id="ja-command-send">ENVIAR</button><button id="ja-command-mic">VOZ</button></div></div>');
  const input=document.getElementById('ja-command-input');
  const send=async()=>{const cmd=input.value.trim();if(!cmd)return;input.value='';appendChat('Você',cmd,'user');try{const r=await postJson('/casa/api/jarvis.php',{comando:cmd,ia_mode:'auto'});appendChat('JARVIS',r.resposta||r.mensagem||'Sem resposta.','ai');}catch(e){appendChat('Sistema',e.message,'error');}};
  document.getElementById('ja-command-send').onclick=send; input.onkeydown=e=>{if(e.key==='Enter')send();};
  document.getElementById('ja-command-mic').onclick=()=>startVoice(input,send);
}
function appendChat(who,text,cls){const l=document.getElementById('ja-chat-log');if(!l)return;const d=document.createElement('div');d.className='ja-chat-line '+cls;d.textContent=who+': '+text;l.appendChild(d);while(l.children.length>5)l.removeChild(l.firstChild);}
function startVoice(input,done){const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR){alert('Reconhecimento de voz não disponível neste navegador.');return;}const r=new SR();r.lang='pt-BR';r.onresult=e=>{input.value=e.results[0][0].transcript;done();};r.start();}

function renderCameras(){
  moduleShell('Câmeras & visão','<div class="ja-camera-grid"><article class="ja-native-card"><h3>ESP32-CAM PORTÃO</h3><p>Stream configurado em 192.168.2.50.</p><div class="ja-row-actions">'+actionBtn('CAPTURAR','capture','primary')+actionBtn('STREAM','stream')+actionBtn('FLASH','flash')+'</div></article><article class="ja-native-card"><h3>ANÁLISE DE CENA</h3><p>Envia uma solicitação ao JARVIS para análise da captura recente.</p><div class="ja-row-actions">'+actionBtn('ANALISAR','analyze','primary')+'</div></article></div>');
  document.querySelectorAll('[data-act]').forEach(b=>b.onclick=async()=>{if(b.dataset.act==='capture')window.open('http://192.168.2.50/capture?t='+Date.now(),'_blank');if(b.dataset.act==='stream')window.open('http://192.168.2.50/stream','_blank');if(b.dataset.act==='flash')fetch('http://192.168.2.50/flash/on').catch(()=>{});if(b.dataset.act==='analyze'){await postJson('/casa/api/jarvis.php',{comando:'/cloud Analise a imagem recente da câmera de entrada e descreva objetos, pessoas e riscos de segurança detectados.',ia_mode:'auto'}).then(r=>alert(r.resposta||'Solicitação enviada.'));}});
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
    st.textContent='html,body{margin:0!important;width:100%!important;height:100%!important;overflow:hidden!important}';
    doc.head.appendChild(st);
    const body=doc.body;
    body.style.transform='none';body.style.width='100%';body.style.height='auto';
    requestAnimationFrame(()=>{
      const sw=Math.max(body.scrollWidth,doc.documentElement.scrollWidth,1);
      const sh=Math.max(body.scrollHeight,doc.documentElement.scrollHeight,1);
      const vw=Math.max(frame.clientWidth,1),vh=Math.max(frame.clientHeight,1);
      const scale=Math.min(1,vw/sw,vh/sh);
      body.style.transformOrigin='top left';
      body.style.transform='scale('+scale+')';
      body.style.width=(100/scale)+'%';
      body.style.height=(100/scale)+'%';
    });
  }catch(e){console.warn('CASA módulo:',e);}
}

function handleAction(detail){
  const action=detail.action||'';
  if(action==='back') return home();
  if(action==='open-item'){const it=findItem(currentGroup,detail.id);if(it)openItem(it);return;}
  if(action.startsWith('open-group:')) return openGroup(action.substring(11));
  if(action==='select' || action==='navigate'){const it=findItem(currentGroup,detail.id);if(it)openItem(it);}
}
function restoreRoute(){
  const params=new URLSearchParams(location.search),g=params.get('grupo'),itemId=params.get('item');
  suppressHistory=true;
  if(g&&GROUPS[g]){openGroup(g,false);if(itemId){const it=findItem(g,itemId);if(it)openItem(it,false);}}else home(false);
  suppressHistory=false;
}

document.addEventListener('DOMContentLoaded',()=>{const app=document.getElementById('app');app.addEventListener('ja:action',e=>handleAction(e.detail||{}));restoreRoute();});
window.addEventListener('popstate',restoreRoute);
window.addEventListener('resize',()=>{const f=document.querySelector('.ja-module-frame');if(f)fitFrame(f);});
window.CASASite={home,openGroup,openItem,groups:GROUPS};
})();