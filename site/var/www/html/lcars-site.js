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

async function renderExternal(){
  loading('Acesso web & API');
  const [tun,key]=await Promise.all([crud('devices','status_tunnel').catch(()=>({})),crud('devices','obter_external_api_key').catch(()=>({}))]);
  const t=tun.tunnel||{};
  const body='<div class="ja-native-summary"><div><b>TÚNEL</b><strong>'+esc((t.status||'indisponível').toUpperCase())+'</strong></div><div><b>URL</b><strong class="small">'+esc(t.url||'—')+'</strong></div></div><div class="ja-config-grid"><label><span>API KEY</span><input type="password" id="ja-api-key" value="'+attr(key.api_key||'')+'" readonly></label><label><span>ENDPOINT</span><input value="/api/v1/status" readonly></label></div>';
  moduleShell('Acesso web & API',body);
}

function computerVoice(){
  if(!('speechSynthesis' in window)) return null;
  const voices=window.speechSynthesis.getVoices()||[];
  const br=voices.filter(v=>String(v.lang||'').toLowerCase()==='pt-br');
  if(!br.length) return null;
  const femaleHints=['francisca','maria','luciana','fernanda','vitoria','vitória','camila','leticia','letícia','female','feminina','woman'];
  return br.find(v=>femaleHints.some(h=>(String(v.name||'')+' '+String(v.voiceURI||'')).toLowerCase().includes(h))) || br[0];
}
function speakComputer(text){
  const speech=String(text||'').trim();
  if(!speech || !('speechSynthesis' in window)) return false;
  window.speechSynthesis.cancel();
  const u=new SpeechSynthesisUtterance(speech);
  u.lang='pt-BR';
  u.rate=1;
  u.pitch=1;
  const v=computerVoice();
  if(v) u.voice=v;
  window.speechSynthesis.speak(u);
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
  moduleShell('Núcleo COMPUTER','<div class="ja-jarvis"><div id="ja-chat-log" class="ja-chat-log"><div class="ja-chat-line ai">Carregando histórico...</div></div><div class="ja-command"><input id="ja-command-input" placeholder="Digite um comando para o COMPUTER"><button id="ja-command-send">ENVIAR</button><button id="ja-command-mic">VOZ</button></div></div>');
  const input=document.getElementById('ja-command-input');
  const log=document.getElementById('ja-chat-log');

  const history=await loadComputerHistory();
  if(log){
    log.innerHTML='';
    if(!history.length){
      appendChat('COMPUTER','Pronto.','ai');
    }else{
      history.forEach(h=>{
        appendChat('Você',h.user_msg||'','user',false);
        appendChat('COMPUTER',h.bot_msg||'','ai',false);
      });
      log.scrollTop=log.scrollHeight;
    }
  }

  const send=async()=>{
    const cmd=input.value.trim();
    if(!cmd)return;
    input.value='';
    appendChat('Você',cmd,'user');
    try{
      const r=await postJson('/casa/api/jarvis.php',{comando:cmd,ia_mode:'auto'});
      const resposta=r.resposta||r.mensagem||'Sem resposta.';
      appendChat('COMPUTER',resposta,'ai');
      if(!speakComputer(resposta)){
        appendChat('Sistema','Voz pt-BR não disponível neste navegador.','error');
      }
    }catch(e){
      appendChat('Sistema',e.message,'error');
    }
  };
  document.getElementById('ja-command-send').onclick=send;
  input.onkeydown=e=>{if(e.key==='Enter')send();};
  document.getElementById('ja-command-mic').onclick=()=>startVoice(input,send);
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
function startVoice(input,done){
  const SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  if(!SR){alert('Reconhecimento de voz não disponível neste navegador.');return;}
  const r=new SR();
  r.lang='pt-BR';
  r.onresult=e=>{input.value=e.results[0][0].transcript;done();};
  r.start();
}

// Carrega a lista de vozes assim que o navegador disponibilizá-la.
if('speechSynthesis' in window){
  window.speechSynthesis.getVoices();
  window.speechSynthesis.addEventListener?.('voiceschanged',()=>window.speechSynthesis.getVoices());
}

function renderCameras(){
  moduleShell('Câmeras & visão','<div class="ja-camera-grid"><article class="ja-native-card"><h3>ESP32-CAM PORTÃO</h3><p>Stream configurado em 192.168.2.50.</p><div class="ja-row-actions">'+actionBtn('CAPTURAR','capture','primary')+actionBtn('STREAM','stream')+actionBtn('FLASH','flash')+'</div></article><article class="ja-native-card"><h3>ANÁLISE DE CENA</h3><p>Envia uma solicitação ao COMPUTER para análise da captura recente.</p><div class="ja-row-actions">'+actionBtn('ANALISAR','analyze','primary')+'</div></article></div>');
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
function restoreRoute(){
  const params=new URLSearchParams(location.search),g=params.get('grupo'),itemId=params.get('item');
  suppressHistory=true;
  if(g&&GROUPS[g]){openGroup(g,false);if(itemId){const it=findItem(g,itemId);if(it)openItem(it,false);}}else home(false);
  suppressHistory=false;
}

document.addEventListener('DOMContentLoaded',()=>{const app=document.getElementById('app');app.addEventListener('ja:action',e=>handleAction(e.detail||{}));restoreRoute();});
window.addEventListener('popstate',restoreRoute);
window.addEventListener('resize',()=>{const f=document.querySelector('.ja-module-frame');if(f)fitFrame(f);});
window.CASASite={home,openGroup,openItem,changePassword,groups:GROUPS};
})();