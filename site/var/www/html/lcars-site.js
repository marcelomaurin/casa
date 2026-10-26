(function(){
'use strict';

const GROUP_ICONS={
  'OPERAÇÕES':'◈',
  'SEGURANÇA':'◆',
  'AMBIENTE':'◎',
  'IA & VOZ':'✦',
  'FAMÍLIA':'●',
  'SISTEMA':'⚙',
  'ACESSOS':'⌘'
};

const STATUS=[
  {label:'WATCH',value:'OK'},
  {label:'SISTEMA',value:'ONLINE'}
];

// Divisao por area real da casa, no estilo de paineis LCARS: cada area tem
// cor e numero proprios, e cada funcionalidade aparece em UMA area so. Antes
// eram 49 entradas para 22 funcionalidades (ESP32/IoT e Acesso web & API
// repetidos em 4 grupos cada).
// tone: cor da area na barra lateral; code: numero do painel.
const GROUPS={
  'OPERAÇÕES':{
    tone:'operacoes', code:'01',
    subtitle:'Controle do dia a dia: relés, cenas, regras e agenda.',
    sections:[
      {title:'CONTROLE',items:[
        {id:'devices',label:'Dispositivos & relés',module:'devices'},
        {id:'cenas',label:'Cenas e regras',module:'automation'}
      ]},
      {title:'AGENDA',items:[
        {id:'agendamentos',label:'Agendamentos',module:'schedules'}
      ]}
    ]
  },
  'SEGURANÇA':{
    tone:'seguranca', code:'02',
    subtitle:'Proteção da casa: câmeras, perímetro e defesa contra intrusão.',
    sections:[
      {title:'PERÍMETRO',items:[
        {id:'cameras',label:'Câmeras & visão',module:'cameras'},
        {id:'seguranca',label:'Defesa & anti-intrusão',module:'security'}
      ]}
    ]
  },
  'AMBIENTE':{
    tone:'ambiente', code:'03',
    subtitle:'Leituras dos sensores: temperatura, umidade e histórico.',
    sections:[
      {title:'SENSORES',items:[
        {id:'sensores',label:'Sensores & telemetria',module:'sensors'},
        {id:'ambiente',label:'Histórico ambiental',url:'/casa/dispositivos_iot.php'}
      ]}
    ]
  },
  'IA & VOZ':{
    tone:'ia', code:'04',
    subtitle:'Assistente da casa: comandos de voz, avisos e modelos de IA.',
    sections:[
      {title:'ASSISTENTE',items:[
        {id:'jarvis',label:'Reconhecimento & voz',module:'jarvis'},
        {id:'frases',label:'Frases & avisos',module:'phrases'}
      ]},
      {title:'INTELIGÊNCIA',items:[
        {id:'modelos-ia',label:'Conexões & modelos de IA',url:'/casa/ia_modelos.php'},
        {id:'config',label:'Configurações de IA',module:'config'}
      ]}
    ]
  },
  'FAMÍLIA':{
    tone:'familia', code:'05',
    subtitle:'Pessoas da casa, presença e aparelhos pessoais.',
    sections:[
      {title:'PESSOAS',items:[
        {id:'familia',label:'Família & presença',url:'/casa/familia.php'}
      ]},
      {title:'APARELHOS PESSOAIS',items:[
        {id:'celular',label:'Celular',url:'/casa/celular.php'},
        {id:'relogio',label:'Relógio',url:'/casa/watch.php'}
      ]}
    ]
  },
  'SISTEMA':{
    tone:'sistema', code:'06',
    subtitle:'Equipamentos, servidores, diagnóstico e manutenção.',
    sections:[
      {title:'EQUIPAMENTOS',items:[
        {id:'iot',label:'ESP32 / Arduino / IoT',module:'iot'},
        {id:'nodes',label:'Cluster ARM',module:'nodes'}
      ]},
      {title:'DIAGNÓSTICO',items:[
        {id:'health',label:'Saúde do sistema',module:'health'},
        {id:'telemetria',label:'Telemetria operacional',module:'telemetryOps'}
      ]},
      {title:'MANUTENÇÃO',items:[
        {id:'atualizacoes',label:'Atualizações',module:'updates'},
        {id:'binarios',label:'Programas & APKs (/bin)',url:'/casa/bin/'}
      ]}
    ]
  },
  'ACESSOS':{
    tone:'acessos', code:'07',
    subtitle:'Usuários, chaves de acesso e integrações externas.',
    sections:[
      {title:'ACESSO',items:[
        {id:'usuarios',label:'Usuários & senhas',module:'users'},
        {id:'api',label:'Acesso web & API',module:'external'}
      ]},
      {title:'INTEGRAÇÕES',items:[
        {id:'agentes',label:'Agentes externos',module:'agents'}
      ]}
    ]
  }
};

// Enderecos antigos (?grupo=...&item=...) continuam funcionando: links salvos,
// automacao.php, o site local do cluster e paginas externas.
const GROUP_ALIASES={
  'SEGURANCA':'SEGURANÇA','OPERACOES':'OPERAÇÕES','DISPOSITIVOS':'SISTEMA','AUTOMAÇÃO':'OPERAÇÕES',
  'AUTOMACAO':'OPERAÇÕES','FAMILIA':'FAMÍLIA','IA E VOZ':'IA & VOZ','ADMINISTRAÇÃO':'ACESSOS','ADMINISTRACAO':'ACESSOS'
};
const ITEM_ALIASES={
  'acessos':'celular','sensores-sec':'sensores','sensores-op':'sensores','sensors-sys':'telemetria',
  'api-sec':'api','automation-api':'api','webapi':'api','api-sys':'api',
  'automation-ag':'agendamentos','devices-op':'devices','automation-dev':'devices',
  'iot-op':'iot','automation-iot':'iot','iot-sys':'iot','automation-scenes':'cenas',
  'cameras-op':'cameras','historico-op':'frases','nodes-sys':'nodes',
  'mobile-dev':'celular','mobile-person':'celular','watch-dev':'relogio','watch-person':'relogio',
  'family-dev':'familia','family':'familia','bin-dev':'binarios','bin-sys':'binarios',
  'automation-agents':'agentes','runpod':'config','config-sys':'config',
  'users':'usuarios','users-sys':'usuarios'
};
function resolveGroup(name){
  if(!name) return null;
  if(GROUPS[name]) return name;
  const up=String(name).toUpperCase();
  return GROUPS[up]?up:(GROUP_ALIASES[up]||GROUP_ALIASES[name]||null);
}
// Acha a area de um item pelo id (ou id antigo).
function locateItem(id){
  const key=ITEM_ALIASES[id]||id;
  for(const g of Object.keys(GROUPS)){
    const it=findItem(g,key);
    if(it) return {group:g,item:it};
  }
  return null;
}

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
  return Object.keys(GROUPS).map(name=>({title:name,description:GROUPS[name].subtitle,id:name,code:GROUPS[name].code||'',icon:GROUP_ICONS[name]||'•'}));
}
// Botoes das areas na barra lateral, cada uma com a sua cor e numero.
function departmentRail(){
  return Object.keys(GROUPS).map(name=>({label:name,action:'open-group:'+name,level:1,tone:GROUPS[name].tone,code:GROUPS[name].code}));
}

let dashboardMetrics = null;

async function loadDashboardMetrics(){
  try {
    const j = await getJson('/casa/api/iot_dashboard.php');
    if (j && j.status === 'ok') {
      dashboardMetrics = j.summary || {};
      if (!currentGroup && !currentItem) {
        updateDashboardCardsInDOM(dashboardMetrics);
      }
    }
  } catch (e) {
    console.warn('Dashboard telemetria:', e);
  }
}

function updateDashboardCardsInDOM(summary){
  if (!summary) return;
  const cards = document.querySelectorAll('#app .ja-card');
  cards.forEach(card => {
    const title = card.querySelector('h3')?.textContent || '';
    const metric = card.querySelector('.metric');
    const sub = card.querySelector('.sub');
    if (!metric) return;
    if (title.includes('DISPOSITIVOS & IOT')) {
      const on = summary.online_devices ?? 0;
      const tot = summary.total_devices ?? 0;
      const relOn = summary.relays_on ?? 0;
      metric.textContent = on + ' / ' + tot + ' ONLINE';
      if (sub) sub.textContent = (summary.relays_count || 0) + ' relés (' + relOn + ' ligados) · Sensores ativos';
    } else if (title.includes('TEMPERATURA & CLIMA')) {
      if (summary.temperature_c != null) {
        metric.textContent = summary.temperature_c + ' °C';
        if (sub) sub.textContent = 'Umidade: ' + (summary.humidity_pct != null ? summary.humidity_pct + '%' : '—') + ' · Conforto térmico';
      }
    } else if (title.includes('CELULAR & PRESENÇA')) {
      const mobOn = summary.mobiles_online ?? 0;
      metric.textContent = mobOn > 0 ? (mobOn + ' ONLINE') : 'MONITORANDO';
      if (sub) sub.textContent = 'Casa Mobile · ' + (mobOn > 0 ? 'Dispositivo conectado' : 'Aguardando ping');
    } else if (title.includes('JARVIS WATCH')) {
      const wOn = summary.watch_online ?? 0;
      metric.textContent = wOn > 0 ? 'CONECTADO' : 'AGUARDANDO';
      if (sub) sub.textContent = wOn > 0 ? 'LilyGo T-Watch ativo · Telemetria OK' : 'Aguardando telemetria do relógio';
    }
  });
}

function home(updateRoute=true){
  currentGroup=null; currentItem=null;
  const devMetric = dashboardMetrics ? (dashboardMetrics.online_devices + ' / ' + dashboardMetrics.total_devices + ' ONLINE') : 'ONLINE';
  const tempMetric = (dashboardMetrics && dashboardMetrics.temperature_c != null) ? (dashboardMetrics.temperature_c + ' °C') : '-- °C';
  const mobMetric = (dashboardMetrics && dashboardMetrics.mobiles_online > 0) ? (dashboardMetrics.mobiles_online + ' ATIVO(S)') : 'CONECTADO';
  const watchMetric = (dashboardMetrics && dashboardMetrics.watch_online > 0) ? 'ONLINE' : 'OK';

  const homeRail = [{label:'DASHBOARD',action:'home',level:0,levelNav:true,active:true}].concat(departmentRail());

  const dashboardCards = [
    {
      title: 'DISPOSITIVOS & IOT',
      description: 'Controle de iluminação, relés e equipamentos ESP32',
      icon: '▣',
      value: devMetric,
      status: 'ONLINE',
      actions: [{label:'CONTROLAR', action:'open-direct:OPERAÇÕES:devices', variant:'primary'}]
    },
    {
      title: 'TEMPERATURA & CLIMA',
      description: 'Leituras ambientais dos sensores IoT da residência',
      icon: '◈',
      value: tempMetric,
      status: 'OK',
      actions: [{label:'SENSORES', action:'open-direct:AMBIENTE:sensores', variant:'primary'}]
    },
    {
      title: 'CELULAR & PRESENÇA',
      description: 'Casa Mobile, credenciais pareadas e presença na residência',
      icon: '📱',
      value: mobMetric,
      status: 'OK',
      actions: [{label:'CELULARES', action:'open-direct:FAMÍLIA:celular', variant:'primary'}]
    },
    {
      title: 'JARVIS WATCH',
      description: 'LilyGo T-Watch, telemetria biométrica, SOS e bateria',
      icon: '⌚',
      value: watchMetric,
      status: 'OK',
      actions: [{label:'WATCH', action:'open-direct:FAMÍLIA:relogio', variant:'primary'}]
    },
    {
      title: 'DEFESA & PERÍMETRO',
      description: 'Câmeras de vídeo, anti-intrusão e eventos de segurança',
      icon: '◆',
      value: 'ATIVO',
      status: 'SEGURO',
      actions: [{label:'CÂMERAS', action:'open-direct:SEGURANÇA:cameras', variant:'primary'}]
    },
    {
      title: 'VOZ & INTELIGÊNCIA IA',
      description: 'Central de comandos, síntese e reconhecimento de voz',
      icon: '✦',
      value: 'PRONTO',
      status: 'JARVIS',
      actions: [{label:'FALAR', action:'open-direct:IA & VOZ:jarvis', variant:'primary'}]
    },
    {
      title: 'AUTOMAÇÃO & CENAS',
      description: 'Rotinas, agendamentos automáticos e regras operacionais',
      icon: '⚙',
      value: 'AGENDADO',
      status: 'ATIVO',
      actions: [{label:'ROTINAS', action:'open-direct:OPERAÇÕES:cenas', variant:'primary'}]
    },
    {
      title: 'SISTEMA & SERVIDORES',
      description: 'Cluster ARM, saúde da infraestrutura e banco de dados',
      icon: '⌘',
      value: '100% OK',
      status: 'ONLINE',
      actions: [{label:'SAÚDE', action:'open-direct:SISTEMA:health', variant:'primary'}]
    },
    {
      title: 'ÁREAS DA CASA',
      description: 'Operações, Segurança, Ambiente, IA & voz, Família, Sistema e Acessos',
      icon: '●',
      value: Object.keys(GROUPS).length + ' ÁREAS',
      status: 'MENU',
      actions: [{label:'ABRIR', action:'open-groups-overview', variant:'primary'}]
    }
  ];

  CASALcars.render('#app',{
    layout:'dashboard',group:'DASHBOARD',title:'CASA / COMPUTER · DASHBOARD',subtitle:'Painel de controle operacional e monitoramento residencial em tempo real.',
    breadcrumb:['CASA','DASHBOARD'],groups:homeRail,status:STATUS,columns:3,
    items:dashboardCards,
    footer:{hint:'Interface operacional central: use os cartões para ação rápida ou as áreas à esquerda.'}
  });

  if(updateRoute) setRoute(null,null);
  loadDashboardMetrics();
}

function renderGroupsMenu(){
  currentGroup='GRUPOS'; currentItem=null;
  CASALcars.render('#app',{
    layout:'dashboard',group:'GRUPOS',title:'CASA / COMPUTER · ÁREAS',subtitle:'Escolha uma área. Cada função da casa fica em um só lugar.',
    breadcrumb:['CASA','ÁREAS'],groups:[{label:'DASHBOARD',action:'home',level:0,levelNav:true},{label:'ÁREAS',action:'open-groups-overview',level:1,levelNav:true,active:true}].concat(departmentRail().slice(0,7)),status:STATUS,columns:3,
    items:topGroupCards().map(g=>({title:g.code+' · '+g.title,description:g.description,icon:g.icon,actions:[{label:'ACESSAR',action:'open-group:'+g.id,variant:'primary'}]})),
    footer:{hint:'Interface em tela cheia: use as áreas e a paginação, sem rolagem.'}
  });
  setRoute('GRUPOS',null);
}

function groupRail(group){
  const flat=[];
  GROUPS[group].sections.forEach(s=>s.items.forEach(it=>flat.push(it)));
  const nav=[
    {label:'CASA',action:'home',level:0,levelNav:true},
    {label:group,action:'open-current-group',level:1,levelNav:true,active:!currentItem,tone:GROUPS[group].tone,code:GROUPS[group].code}
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
  group=resolveGroup(group);
  if(!group) return home(updateRoute);
  currentGroup=group; currentItem=null;
  CASALcars.render('#app',{
    layout:'menu-grid',kind:'menu',group,title:group,subtitle:GROUPS[group].subtitle,
    breadcrumb:[],groups:groupRail(group),status:STATUS,sections:GROUPS[group].sections,
    footer:{hint:'Escolha uma opção. Cada cartão mostra os números atuais do item.'}
  });
  if(updateRoute) setRoute(group,null);
  decorateAreaStats(group);
}

// Estatisticas dos cartoes de cada area: numero atual, legenda e um mini
// grafico com a atividade dos ultimos 7 dias do proprio item.
let areaStatsCache=null, areaStatsAt=0;
async function loadAreaStats(){
  if(areaStatsCache && Date.now()-areaStatsAt<30000) return areaStatsCache;
  const j=await getJson('/casa/api/painel_estatisticas.php');
  areaStatsCache=(j&&j.itens)||{}; areaStatsAt=Date.now();
  return areaStatsCache;
}
function areaStatBars(serie,legenda){
  if(!Array.isArray(serie)||!serie.length) return '';
  const max=Math.max(1,...serie), total=serie.reduce((a,b)=>a+b,0);
  const dias=['dom','seg','ter','qua','qui','sex','sáb'], hoje=new Date().getDay();
  const barras=serie.map((n,i)=>{
    const dia=dias[(hoje-(serie.length-1-i)+70)%7];
    return '<i style="height:'+Math.max(6,Math.round(n/max*100))+'%" title="'+attr(dia+': '+n)+'"'+(n?'':' class="zero"')+'></i>';
  }).join('');
  return '<span class="ja-stat-chart" role="img" aria-label="'+attr((legenda||'atividade')+', últimos 7 dias: '+serie.join(', '))+'">'+barras+'</span>'+
    '<span class="ja-stat-chart-cap">'+esc((legenda||'atividade')+' · 7 dias: '+total)+'</span>';
}
async function decorateAreaStats(group){
  let itens;
  try{ itens=await loadAreaStats(); }catch(e){ return; }
  if(currentGroup!==group || currentItem) return;   // usuario ja saiu da area
  document.querySelectorAll('#app .ja-menu-item[data-ja-id]').forEach(btn=>{
    const it=findItem(group,btn.getAttribute('data-ja-id'));
    const st=itens[btn.getAttribute('data-ja-id')];
    if(!it || !st || btn.querySelector('.ja-stat')) return;
    const estado=st.estado==='alerta'?' alerta':'';
    btn.classList.add('ja-has-stat');
    btn.innerHTML='<span class="ja-stat-label">'+esc(it.label)+'</span>'+
      '<span class="ja-stat'+estado+'">'+
        (st.valor!=null&&st.valor!==''?'<b class="ja-stat-value">'+esc(st.valor)+'</b>':'')+
        '<span class="ja-stat-caption">'+esc(st.legenda||'')+(st.detalhe?' · próximo: '+esc(st.detalhe):'')+'</span>'+
        areaStatBars(st.serie,st.serie_legenda)+
      '</span>';
  });
  renderAreaAnalysis(group,itens);
}

// Painel de analise da area: grafico 3D cruzando os itens com os ultimos 7 dias
// (ou, sem historico, os numeros atuais) e um resumo em texto do que ele mostra.
function areaAnalysisData(group,itens){
  const lista=[];
  GROUPS[group].sections.forEach(sec=>sec.items.forEach(it=>{ if(itens[it.id]) lista.push({it,st:itens[it.id]}); }));
  const comSerie=lista.filter(x=>Array.isArray(x.st.serie)&&x.st.serie.length===7);
  const dias=['dom','seg','ter','qua','qui','sex','sáb'], hoje=new Date().getDay();
  const colDias=Array.from({length:7},(_,i)=>i===6?'hoje':dias[(hoje-6+i+7)%7]);
  const texto=[];
  lista.forEach(x=>{ if(x.st.total!=null && x.st.num!=null && x.st.num<x.st.total)
    texto.push('⚠ '+x.it.label+': '+(x.st.total-x.st.num)+' de '+x.st.total+' fora do ar'); });
  if(comSerie.length){
    const valores=comSerie.map(x=>x.st.serie);
    const porDia=colDias.map((_,j)=>valores.reduce((a,l)=>a+(l[j]||0),0));
    const total=porDia.reduce((a,b)=>a+b,0);
    if(total){
      const pico=porDia.indexOf(Math.max(...porDia));
      const somas=comSerie.map(x=>x.st.serie.reduce((a,b)=>a+b,0));
      const topo=somas.indexOf(Math.max(...somas));
      const media=total/7;
      texto.unshift('Pico: '+colDias[pico]+' ('+porDia[pico]+' registros)',
        'Mais ativo: '+comSerie[topo].it.label+' ('+somas[topo]+' em 7 dias)',
        'Hoje: '+porDia[6]+' · média diária: '+media.toFixed(1).replace('.',','));
    } else texto.unshift('Sem atividade registrada nos últimos 7 dias.');
    return {titulo:'ANÁLISE · ÚLTIMOS 7 DIAS', linhas:comSerie.map(x=>x.it.label), colunas:colDias, valores,
      unidade:'registros', texto, descricao:'Atividade dos itens da área '+group+' nos últimos 7 dias'};
  }
  const numericos=lista.filter(x=>typeof x.st.num==='number');
  if(!numericos.length) return null;
  const temTotal=numericos.some(x=>x.st.total!=null);
  return {titulo:'ANÁLISE · SITUAÇÃO ATUAL', linhas:numericos.map(x=>x.it.label),
    colunas:temTotal?['online','fora do ar']:['agora'],
    valores:numericos.map(x=>temTotal?[x.st.num,(x.st.total!=null?x.st.total-x.st.num:0)]:[x.st.num]),
    unidade:'', texto:texto.length?texto:numericos.map(x=>x.it.label+': '+x.st.num+' '+(x.st.legenda||'')),
    descricao:'Números atuais dos itens da área '+group};
}
let areaChart=null;
function renderAreaAnalysis(group,itens){
  const grid=document.querySelector('#app .ja-menu-grid');
  if(!grid || grid.querySelector('.ja-area-analise') || !window.CASA3D) return;
  const d=areaAnalysisData(group,itens);
  if(!d) return;
  const secoes=GROUPS[group].sections.length;
  const span=secoes>=3?1:2;
  grid.style.setProperty('--ja-columns',String(Math.min(4,secoes+span)));
  const sec=document.createElement('section');
  sec.className='ja-menu-section ja-area-analise';
  sec.style.gridColumn='span '+span;
  sec.innerHTML='<div class="ja-menu-title">'+esc(d.titulo)+'</div>'+
    '<div class="ja-area-3d"></div>'+
    '<div class="ja-3d-legenda">'+d.linhas.map((l,i)=>'<span><i style="background:'+CASA3D.cor(i)+'"></i>'+esc(l)+'</span>').join('')+'</div>'+
    '<ul class="ja-analise-texto">'+d.texto.slice(0,4).map(t=>'<li'+(t.startsWith('⚠')?' class="alerta"':'')+'>'+esc(t)+'</li>').join('')+'</ul>'+
    '<div class="ja-analise-dica">Arraste para girar · role para aproximar</div>';
  grid.appendChild(sec);
  if(areaChart) try{areaChart.destruir();}catch(_){}
  CASA3D.barras(sec.querySelector('.ja-area-3d'),d).then(c=>{areaChart=c;});
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
    if(m==='updates') return renderUpdates(false);
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
  try {
    let all = [];
    try {
      const j = await crud('sensores_telemetria', 'listar', '&limite=50');
      if (j && Array.isArray(j.dados)) all = j.dados;
    } catch(errCrud) {
      console.warn('Falha ao consultar sensores_telemetria:', errCrud);
    }

    // Se sensores_telemetria nao tiver registros, busca de iot_leituras como fallback
    if (!all.length) {
      try {
        const jEnv = await crud('iot_leituras', 'listar', '&limite=50');
        if (jEnv && Array.isArray(jEnv.dados) && jEnv.dados.length) {
          all = jEnv.dados.map(item => ({
            id: item.id,
            sensor_nome: (item.tipo_sensor || 'Sensor IoT').toUpperCase(),
            valor_numerico: item.temperatura_c != null ? item.temperatura_c : (item.umidade_pct != null ? item.umidade_pct : item.rssi),
            unidade: item.temperatura_c != null ? '°C' : (item.umidade_pct != null ? '%' : (item.rssi != null ? 'dBm' : '')),
            data_hora: item.data_hora,
            detalhes: item.temperatura_c != null && item.umidade_pct != null ? (item.temperatura_c + '°C • ' + item.umidade_pct + '% UR') : ''
          }));
        }
      } catch(eEnv) {}
    }

    const p = paginate('sensors', all, 12);
    const summary = '<p>' + esc(all.length) + ' leitura(s) de sensores registradas • Exibindo página ' + p.page + ' de ' + p.pages + '</p>';

    const body = all.length ? 
      cards(p.slice, s => '<article class="ja-native-card metric" style="position:relative;">' +
        '<div style="position:absolute;top:10px;right:10px;"><span class="ja-state ok" style="font-size:0.7rem;padding:2px 6px;">ATIVO</span></div>' +
        '<h3>' + esc(s.sensor_nome || 'Sensor') + '</h3>' +
        '<strong style="font-size:1.6rem;color:#00e5ff;font-family:monospace;display:block;margin:6px 0;">' + esc(s.valor_numerico != null ? s.valor_numerico : '—') + ' ' + esc(s.unidade || '') + '</strong>' +
        (s.detalhes ? '<div style="font-size:0.8rem;color:#a5f3fc;margin-bottom:4px;">' + esc(s.detalhes) + '</div>' : '') +
        '<p style="opacity:0.75;font-size:0.75rem;margin:0;">Leitura: ' + esc(s.data_hora || 'Recente') + '</p>' +
      '</article>') + pager('sensors', p) :
      '<div class="ja-empty">Nenhuma leitura de sensor registrada ainda no sistema.<br><br>' +
      'Sensores IoT (temperatura, umidade, presença, luminosidade, DHT22/ESP32) alimentarão este painel automaticamente via telemetria.' +
      '<div style="margin-top:15px;"><button type="button" id="sensors-add-mock" class="ja-mini primary" style="cursor:pointer;padding:6px 14px;font-weight:bold;">+ REGISTRAR LEITURA DE TESTE</button></div>' +
      '</div>';

    moduleShell('Sensores & telemetria', summary + body, '<button type="button" id="sensors-refresh" class="ja-mini">Atualizar</button>');
    bindPager('sensors', p, renderSensors);

    const btnRefresh = document.getElementById('sensors-refresh');
    if (btnRefresh) btnRefresh.onclick = renderSensors;

    const btnMock = document.getElementById('sensors-add-mock');
    if (btnMock) {
      btnMock.onclick = async () => {
        btnMock.disabled = true;
        btnMock.textContent = 'Registrando...';
        try {
          await crud('sensores_telemetria', 'criar', '', {
            sensor_nome: 'DHT22 Sala',
            valor_numerico: 24.5,
            unidade: '°C',
            raw_data: JSON.stringify({temperatura: 24.5, umidade: 62.0, status: 'ok'})
          });
          await crud('sensores_telemetria', 'criar', '', {
            sensor_nome: 'Umidade Sala',
            valor_numerico: 62.0,
            unidade: '%',
            raw_data: JSON.stringify({umidade: 62.0})
          });
          renderSensors();
        } catch(err) {
          alert('Erro ao registrar leitura: ' + (err.message || String(err)));
          btnMock.disabled = false;
          btnMock.textContent = '+ REGISTRAR LEITURA DE TESTE';
        }
      };
    }
  } catch(e) {
    moduleShell('Sensores & telemetria', '<div class="ja-empty">' + esc(e.message || 'Falha ao carregar sensores.') + '</div>', '<button type="button" id="sensors-retry" class="ja-mini">Tentar novamente</button>');
    const btnRetry = document.getElementById('sensors-retry');
    if (btnRetry) btnRetry.onclick = renderSensors;
  }
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

function openNewNodeModal(){
  document.getElementById('ja-node-new-modal')?.remove();
  const modal=document.createElement('div');
  modal.id='ja-node-new-modal';
  modal.className='ja-schedule-backdrop';
  modal.innerHTML=
    '<section class="ja-schedule-modal" style="width:min(580px,96vw);" role="dialog" aria-modal="true">'+
      '<header><strong>CADASTRAR NOVO NÓ DO CLUSTER</strong><button type="button" data-close>FECHAR</button></header>'+
      '<div class="ja-schedule-form">'+
        '<label class="wide"><span>Identificador (Device ID)</span><input id="node-new-devid" placeholder="Ex.: cluster-rpi4-hub (gerado automaticamente se vazio)"></label>'+
        '<label class="wide"><span>Nome de Exibição *</span><input id="node-new-nome" placeholder="Ex.: Raspberry Pi 4B (Hub Principal)"></label>'+
        '<label><span>Endereço IP</span><input id="node-new-ip" placeholder="Ex.: 192.168.2.14"></label>'+
        '<label><span>Hostname</span><input id="node-new-hostname" placeholder="Ex.: rpi4-hub"></label>'+
        '<label class="wide"><span>Função / Papel</span><input id="node-new-papel" placeholder="Ex.: Gateway Automacao / Agente ARM"></label>'+
        '<div id="node-new-msg" class="ja-schedule-message"></div>'+
      '</div>'+
      '<footer><button type="button" data-save style="background:var(--ja-cyan, #38bdf8);color:#000;">CADASTRAR NÓ</button></footer>'+
    '</section>';
  document.body.appendChild(modal);

  const close=()=>modal.remove();
  modal.querySelector('[data-close]').onclick=close;
  modal.addEventListener('click',e=>{if(e.target===modal)close();});

  const btnSave=modal.querySelector('[data-save]');
  const msgBox=modal.querySelector('#node-new-msg');

  btnSave.onclick=async()=>{
    const devId=modal.querySelector('#node-new-devid').value.trim();
    const nome=modal.querySelector('#node-new-nome').value.trim();
    const ip=modal.querySelector('#node-new-ip').value.trim();
    const hostname=modal.querySelector('#node-new-hostname').value.trim();
    const papel=modal.querySelector('#node-new-papel').value.trim();

    if(!nome){
      msgBox.className='ja-schedule-message error';
      msgBox.textContent='O nome do nó é obrigatório.';
      return;
    }

    btnSave.disabled=true;
    btnSave.textContent='CADASTRANDO...';
    msgBox.className='ja-schedule-message';
    msgBox.textContent='';

    try{
      const payload={
        acao: 'criar',
        device_id: devId,
        nome: nome,
        ip_address: ip,
        hostname: hostname,
        papel: papel
      };
      const res=await postJson('/casa/api/arm_nodes.php', payload);
      if(res && res.status==='ok'){
        msgBox.className='ja-schedule-message ok';
        msgBox.style.color='#4ade80';
        msgBox.textContent='Nó cadastrado com sucesso!';
        setTimeout(()=>{
          close();
          renderNodes();
        }, 800);
      }else{
        throw new Error(res?.mensagem||'Erro ao cadastrar nó.');
      }
    }catch(err){
      msgBox.className='ja-schedule-message error';
      msgBox.textContent=err.message||'Falha ao cadastrar nó.';
      btnSave.disabled=false;
      btnSave.textContent='CADASTRAR NÓ';
    }
  };
}

function openNodeEditor(node){
  document.getElementById('ja-node-edit-modal')?.remove();
  const modal=document.createElement('div');
  modal.id='ja-node-edit-modal';
  modal.className='ja-schedule-backdrop';
  modal.innerHTML=
    '<section class="ja-schedule-modal" style="width:min(580px,96vw);" role="dialog" aria-modal="true">'+
      '<header><strong>EDITAR NÓ DO CLUSTER</strong><button type="button" data-close>FECHAR</button></header>'+
      '<div class="ja-schedule-form">'+
        '<label class="wide"><span>Identificador (Device ID)</span><input id="node-edit-devid" value="'+attr(node.device_id||'')+'" readonly style="opacity:0.7;cursor:not-allowed;background:#181818;"></label>'+
        '<label class="wide"><span>Nome de Exibição</span><input id="node-edit-nome" value="'+attr(node.nome||'')+'" placeholder="Ex.: Raspberry Pi 4B (Hub Principal)"></label>'+
        '<label><span>Endereço IP</span><input id="node-edit-ip" value="'+attr(node.ip_address||'')+'" placeholder="Ex.: 192.168.2.14"></label>'+
        '<label><span>Hostname</span><input id="node-edit-hostname" value="'+attr(node.hostname||'')+'" placeholder="Ex.: cubieboard"></label>'+
        '<label class="wide"><span>Função / Papel</span><input id="node-edit-papel" value="'+attr(node.papel||'')+'" placeholder="Ex.: Gateway Automacao / Serial"></label>'+
        '<div id="node-edit-msg" class="ja-schedule-message"></div>'+
      '</div>'+
      '<footer>'+
        '<button type="button" data-del style="background:#ef4444;color:#fff;margin-right:auto;border:none;border-radius:4px;padding:6px 12px;font-size:0.8rem;font-weight:bold;cursor:pointer;">EXCLUIR NÓ</button>'+
        '<button type="button" data-save style="background:var(--ja-cyan, #38bdf8);color:#000;">SALVAR ALTERAÇÕES</button>'+
      '</footer>'+
    '</section>';
  document.body.appendChild(modal);

  const close=()=>modal.remove();
  modal.querySelector('[data-close]').onclick=close;
  modal.addEventListener('click',e=>{if(e.target===modal)close();});

  const btnSave=modal.querySelector('[data-save]');
  const btnDel=modal.querySelector('[data-del]');
  const msgBox=modal.querySelector('#node-edit-msg');

  if(btnDel){
    btnDel.onclick=async()=>{
      if(!confirm('Deseja realmente excluir o nó "'+(node.nome||node.device_id)+'" do cluster?\n\nEsta ação removerá o nó permanentemente do cluster e do banco de dados.')) return;
      btnDel.disabled=true;
      btnDel.textContent='EXCLUINDO...';
      try{
        const res=await postJson('/casa/api/arm_nodes.php', {
          acao: 'excluir',
          device_id: node.device_id||'',
          id: node.id||''
        });
        if(res && res.status==='ok'){
          msgBox.className='ja-schedule-message ok';
          msgBox.style.color='#4ade80';
          msgBox.textContent='Nó excluído com sucesso!';
          setTimeout(()=>{
            close();
            renderNodes();
          }, 600);
        }else{
          throw new Error(res?.mensagem||'Erro ao excluir nó.');
        }
      }catch(err){
        msgBox.className='ja-schedule-message error';
        msgBox.textContent=err.message||'Falha ao excluir nó.';
        btnDel.disabled=false;
        btnDel.textContent='EXCLUIR NÓ';
      }
    };
  }

  btnSave.onclick=async()=>{
    const nome=modal.querySelector('#node-edit-nome').value.trim();
    const ip=modal.querySelector('#node-edit-ip').value.trim();
    const hostname=modal.querySelector('#node-edit-hostname').value.trim();
    const papel=modal.querySelector('#node-edit-papel').value.trim();

    if(!nome){
      msgBox.className='ja-schedule-message error';
      msgBox.textContent='O nome do nó não pode estar vazio.';
      return;
    }

    btnSave.disabled=true;
    btnSave.textContent='SALVANDO...';
    msgBox.className='ja-schedule-message';
    msgBox.textContent='';

    try{
      const payload={
        device_id: node.device_id||'',
        id: node.id||'',
        nome: nome,
        ip_address: ip,
        hostname: hostname,
        papel: papel
      };
      const res=await postJson('/casa/api/arm_nodes.php', payload);
      if(res && res.status==='ok'){
        msgBox.className='ja-schedule-message ok';
        msgBox.style.color='#4ade80';
        msgBox.textContent='Alterações salvas com sucesso!';
        setTimeout(()=>{
          close();
          renderNodes();
        }, 800);
      }else{
        throw new Error(res?.mensagem||'Erro ao salvar nó.');
      }
    }catch(err){
      msgBox.className='ja-schedule-message error';
      msgBox.textContent=err.message||'Falha ao salvar.';
      btnSave.disabled=false;
      btnSave.textContent='SALVAR ALTERAÇÕES';
    }
  };
}

async function renderNodes(){
  loading('Clusters e agentes Linux ARM');
  try{
    const j=await getJson('/casa/api/arm_nodes.php');
    const all=j.nodes||[];
    const p=paginate('nodes',all,12);
    const states={online:'ONLINE',offline:'SEM CONTATO RECENTE',registered:'AGUARDANDO CONTATO',revoked:'REVOGADO'};
    const summary='<p>'+esc(all.length)+' agente(s) registrado(s) • '+esc(all.filter(n=>n.online).length)+' online. Online: contato nos últimos 40 segundos (heartbeat a cada 20s). Se em 40s não responder, o cluster caiu.</p>';
    const body=all.length?cards(p.slice,(n,i)=>'<article class="ja-native-card ja-node-card" style="position:relative;">'+
      '<div style="position:absolute;top:10px;right:10px;display:flex;gap:5px;z-index:2;">'+
        (n.online && (n.up_to_date === false) ? '<button type="button" class="ja-node-update-btn" data-node-idx="'+attr(String(i))+'" style="background:#eab308;color:#000;border:none;border-radius:4px;padding:3px 9px;font-size:0.75rem;font-weight:900;letter-spacing:0.5px;cursor:pointer;box-shadow:0 2px 4px rgba(234,179,8,0.5);" title="Atualizar versão do nó diretamente do Git">⚡ ATUALIZAR (GIT)</button>' : '')+
        '<button type="button" class="ja-node-edit-btn" data-node-idx="'+attr(String(i))+'" style="background:#38bdf8;color:#000;border:none;border-radius:4px;padding:3px 9px;font-size:0.75rem;font-weight:900;letter-spacing:0.5px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.5);" title="Editar informações do nó">EDITAR</button>'+
        '<button type="button" class="ja-node-del-btn" data-node-idx="'+attr(String(i))+'" style="background:#ef4444;color:#fff;border:none;border-radius:4px;padding:3px 9px;font-size:0.75rem;font-weight:900;letter-spacing:0.5px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.5);" title="Excluir nó do cluster">EXCLUIR</button>'+
      '</div>'+
      '<h3>'+esc(n.nome)+'</h3><dl>'+
      '<dt>Identificador</dt><dd>'+esc(n.device_id||'Legado sem identificador')+'</dd>'+
      '<dt>Hostname</dt><dd>'+esc(n.hostname||'—')+'</dd>'+
      '<dt>Programa / função</dt><dd>'+esc(n.papel||'—')+'</dd>'+
      '<dt>Plataforma</dt><dd>'+esc(n.platform||'—')+'</dd>'+
      '<dt>IP</dt><dd>'+esc(n.ip_address||'—')+'</dd>'+
      '<dt>Versão Git do Cluster</dt><dd>'+
        (n.version?'<strong style="color:#00e5ff;font-family:monospace;font-size:0.95rem;">'+esc(n.version)+'</strong>':'<span style="opacity:0.6;">Não informada</span>')+
        (n.online ? (
          n.up_to_date === true ?
            '<div style="margin-top:4px;"><span style="display:inline-block;background:rgba(34,197,94,0.15);border:1px solid #22c55e;color:#22c55e;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:bold;">✓ CLUSTER ATUALIZADO</span></div>' :
            (n.up_to_date === false ?
              '<div style="margin-top:5px;padding:6px 8px;background:rgba(234,179,8,0.15);border:1px solid #eab308;border-radius:4px;">'+
                '<strong style="color:#eab308;font-size:0.75rem;display:block;">⚠ ATUALIZAÇÃO DISPONÍVEL NO GIT</strong>'+
                '<small style="display:block;color:#cbd5e1;font-size:0.72rem;margin-top:2px;">Remoto: <code style="color:#fde047;">#'+esc(n.remote_commit ? n.remote_commit.substring(0,7) : 'master')+'</code> ('+esc(n.branch||'master')+')</small>'+
                '<button type="button" class="ja-node-update-btn" data-node-idx="'+attr(String(i))+'" style="margin-top:6px;background:#eab308;color:#000;border:none;border-radius:4px;padding:4px 10px;font-size:0.75rem;font-weight:900;cursor:pointer;display:inline-block;" title="Atualizar nó diretamente do Git">⚡ ATUALIZAR AGORA (GIT)</button>'+
              '</div>' :
              '<small style="display:block;opacity:0.6;font-size:0.72rem;margin-top:2px;">Verificação em andamento...</small>')
        ) : '<div style="margin-top:4px;"><span style="color:#94a3b8;font-size:0.75rem;font-style:italic;">Status indisponível (nó offline)</span></div>')+
        (n.deployed_at?'<small style="display:block;opacity:0.8;font-size:0.75rem;margin-top:2px;">Atualizado: '+esc(n.deployed_at)+'</small>':'')+
      '</dd>'+
      '<dt>CPU</dt><dd>'+esc(n.cpu||'Não informada')+'</dd>'+
      '<dt>RAM</dt><dd>'+esc(n.ram||'Não informada')+'</dd>'+
      '<dt>Capacidades</dt><dd>'+esc((n.capabilities||[]).join(', ')||'Não informadas')+'</dd>'+
      '<dt>Último contato (servidor)</dt><dd>'+esc(n.last_seen||'Ainda não recebido')+'</dd>'+
      '<dt>Acesso SSH</dt><dd><code style="background:#02070c;border:1px solid #24516b;padding:2px 7px;border-radius:4px;color:#00e5ff;font-family:monospace;font-size:0.85rem;user-select:all;">ssh mmm@'+esc(n.ip_address||'—')+'</code></dd>'+
      '<dt>Site do Cluster</dt><dd>'+(n.ip_address?'<a href="http://'+esc(n.ip_address)+':8080/" target="_blank" style="display:inline-block;padding:3px 10px;background:#38bdf8;color:#000;border-radius:4px;text-decoration:none;font-weight:bold;font-size:0.8rem;">ABRIR PORTAL (:8080)</a>':'—')+'</dd>'+
      '</dl><span class="ja-state '+(n.online?'ok':'')+'">'+esc(states[n.status]||n.status)+'</span></article>')+pager('nodes',p):
      '<div class="ja-empty">Nenhum agente Linux ARM registrado. Clique em <b>+ NOVO NÓ</b> acima ou conecte agentes pela API de dispositivos.</div>';
    moduleShell('Clusters e agentes Linux ARM',summary+body,'<button id="nodes-add" class="ja-mini primary" style="margin-right:8px;">+ NOVO NÓ</button><button id="nodes-refresh" class="ja-mini">Atualizar lista</button>');
    bindPager('nodes',p,renderNodes);
    document.getElementById('nodes-add').onclick=openNewNodeModal;
    document.getElementById('nodes-refresh').onclick=renderNodes;
    document.querySelectorAll('.ja-node-edit-btn').forEach(btn=>{
      btn.onclick=(e)=>{
        e.stopPropagation();
        const idx=parseInt(btn.getAttribute('data-node-idx'),10);
        if(p.slice[idx]) openNodeEditor(p.slice[idx]);
      };
    });
    document.querySelectorAll('.ja-node-update-btn').forEach(btn=>{
      btn.onclick=async(e)=>{
        e.stopPropagation();
        const idx=parseInt(btn.getAttribute('data-node-idx'),10);
        const n=p.slice[idx];
        if(!n) return;
        if(!n.online){
          alert('Este nó está offline. A atualização só pode ser realizada com o equipamento online.');
          return;
        }
        const confirmMsg = 'Deseja atualizar o nó "' + (n.nome||n.device_id) + '" para a versão mais recente do Git?\n\n' +
          '• Repositório: GitHub (branch ' + (n.branch||'master') + ')\n' +
          '• Commit remoto: ' + (n.remote_commit ? n.remote_commit.substring(0,7) : 'HEAD') + '\n' +
          '• A atualização executará o casa-cluster-update diretamente no nó e reiniciará os serviços.\n\n' +
          'Confirmar atualização agora?';
        if(!confirm(confirmMsg)) return;

        btn.disabled=true;
        const oldText = btn.textContent;
        btn.textContent='ATUALIZANDO (GIT)...';
        try{
          const res = await postJson('/casa/api/arm_nodes.php', {
            acao: 'atualizar',
            device_id: n.device_id||'',
            id: n.id||'',
            ip_address: n.ip_address||''
          });
          alert('✓ ' + (res.mensagem || 'Nó atualizado com sucesso!') + '\n\n' + (res.metodo ? 'Método: ' + res.metodo + '\n' : '') + (res.detalhes ? '\n' + res.detalhes.substring(0, 500) : ''));
          renderNodes();
        }catch(err){
          alert('❌ Falha na atualização: ' + (err.message||String(err)));
          btn.disabled=false;
          btn.textContent=oldText;
        }
      };
    });
    document.querySelectorAll('.ja-node-del-btn').forEach(btn=>{
      btn.onclick=async(e)=>{
        e.stopPropagation();
        const idx=parseInt(btn.getAttribute('data-node-idx'),10);
        const n=p.slice[idx];
        if(!n) return;
        if(!confirm('Deseja realmente excluir o nó "'+(n.nome||n.device_id)+'" do cluster?\n\nEsta ação removerá o nó permanentemente do cluster e do banco de dados.')) return;
        btn.disabled=true;
        try{
          await postJson('/casa/api/arm_nodes.php', {
            acao: 'excluir',
            device_id: n.device_id||'',
            id: n.id||''
          });
          renderNodes();
        }catch(err){
          alert('Falha ao excluir nó: ' + (err.message||String(err)));
          btn.disabled=false;
        }
      };
    });
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

// Segurança › Atualizações: versões dos clusters (commit instalado x master no
// GitHub) e pedido de atualização forçada. Os nós executam casa-cluster-update,
// que busca os fontes direto do Git a cada ~2 minutos.
// ---------------------------------------------------------------------------
const UPD_API='/casa/api/cluster_updates.php';
const UPD_STATES={atualizado:['ATUALIZADO','ok'],desatualizado:['DESATUALIZADO','warn'],falhou:['FALHOU','off'],sem_contato:['SEM CONTATO','off'],sem_atualizador:['SEM ATUALIZADOR','off'],desconhecido:['DESCONHECIDO','off']};
const UPD_COMP={updated:'atualizado',unchanged:'sem mudança',failed:'FALHOU',not_installed:'não instalado'};
let updTimer=null;
function updSha(s){return s?'<a class="ja-upd-sha" href="https://github.com/marcelomaurin/casa/commit/'+attr(s)+'" target="_blank" rel="noopener">#'+esc(s.slice(0,7))+'</a>':'<span class="ja-upd-none">—</span>';}
function updWhen(v){if(!v)return '—';const d=new Date(String(v).replace(' ','T'));return isNaN(d)?esc(v):esc(d.toLocaleString('pt-BR'));}
async function renderUpdates(refresh){
  clearTimeout(updTimer);
  if(!refresh) loading('Atualizações dos clusters');
  let st;
  try{ st=await getJson(UPD_API+'?acao=estado'+(refresh?'&refresh=1':'')); }
  catch(e){
    moduleShell('Atualizações dos clusters','<div class="ja-empty">'+esc(e.message||'Falha ao carregar.')+'</div>','<button id="upd-retry" class="ja-mini">Tentar novamente</button>');
    document.getElementById('upd-retry').onclick=()=>renderUpdates(true); return;
  }
  const L=st.latest, nodes=st.nodes||[];
  const count=k=>nodes.filter(n=>n.estado===k).length;
  const head='<div class="ja-upd-head"><div><span class="ja-upd-tag">MASTER</span> '+(L?updSha(L.sha)+' '+esc(L.message||'')+'<small>'+(L.date?updWhen(L.date)+(L.author?' · '+esc(L.author):''):'')+(L.source==='nodes'?' (informado pelos nós)':'')+'</small>':'<span class="ja-upd-none">GitHub indisponível agora</span>')+'</div>'+
    '<div class="ja-iot-counts"><span>'+count('atualizado')+' ATUALIZADO(S)</span><span>'+count('desatualizado')+' DESATUALIZADO(S)</span><span>'+(count('falhou')+count('sem_contato')+count('sem_atualizador'))+' ATENÇÃO</span></div></div>'+
    '<p class="ja-upd-intro">Cada cluster consulta a branch <b>'+esc(st.branch)+'</b> no GitHub a cada ~2 minutos e instala os fontes novos sozinho. <b>FORÇAR</b> reinstala todos os arquivos e reinicia os serviços ativos na próxima consulta do nó.</p>';
  const p=paginate('updates',nodes,6);
  const body=nodes.length?cards(p.slice,n=>{
    const [lbl,cls]=UPD_STATES[n.estado]||[String(n.estado||'').toUpperCase(),'off'];
    const comps=Object.entries(n.componentes||{}).filter(([k,c])=>c.status!=='not_installed');
    return '<article class="ja-native-card ja-upd-card">'+
      '<header class="ja-upd-cardhead"><h3>'+esc(n.nome)+'</h3><span class="ja-state '+cls+'">'+lbl+'</span></header>'+
      '<dl><dt>Instalado</dt><dd>'+updSha(n.commit)+(n.branch?' <small>('+esc(n.branch)+')</small>':'')+'</dd>'+
      '<dt>Última verificação</dt><dd>'+updWhen(n.ultima_verificacao)+'</dd>'+
      '<dt>Última atualização</dt><dd>'+updWhen(n.ultima_atualizacao)+'</dd>'+
      '<dt>Atualizador</dt><dd>'+(n.updater_version?'v'+esc(n.updater_version):'<span class="ja-upd-none">não reportou</span>')+(n.ip?' · '+esc(n.ip):'')+'</dd></dl>'+
      (comps.length?'<ul class="ja-upd-comps">'+comps.map(([k,c])=>'<li class="'+(c.status==='failed'?'bad':'')+'"><b>'+esc(k)+'</b> '+esc(UPD_COMP[c.status]||c.status)+(c.files_changed?' ('+esc(c.files_changed)+' arq.)':'')+'</li>').join('')+'</ul>':'')+
      (n.erro?'<p class="ja-upd-err">'+esc(n.erro)+'</p>':'')+
      (n.estado==='sem_atualizador'?'<p class="ja-upd-note">Instale o casa-cluster-update neste nó (clusters/infraestrutura/casa-cluster-update/install.sh).</p>':'')+
      (n.forcar_pendente?'<p class="ja-upd-note">⏳ Atualização forçada pedida '+(n.forcar_pedido_por?'por '+esc(n.forcar_pedido_por)+' ':'')+'em '+updWhen(n.forcar_pedido_em)+' — aguardando o nó.</p>':'')+
      '<div class="ja-row-actions">'+actionBtn(n.forcar_pendente?'AGUARDANDO…':'FORÇAR ATUALIZAÇÃO','force:'+n.device_id,'primary')+'</div>'+
    '</article>';
  })+pager('updates',p):'<div class="ja-empty">Nenhum cluster registrado. Os nós Linux ARM aparecem aqui ao enviar heartbeat ou relatório do atualizador.</div>';
  moduleShell('Atualizações dos clusters','<div class="ja-upd-layout">'+head+body+'</div>','<button class="ja-mini" id="upd-refresh">VERIFICAR AGORA</button><button class="ja-mini primary" id="upd-all"'+(nodes.length?'':' disabled')+'>FORÇAR TODOS</button>');
  bindPager('updates',p,()=>renderUpdates(false));
  document.getElementById('upd-refresh').onclick=()=>renderUpdates(true);
  document.getElementById('upd-all').onclick=async()=>{
    if(!confirm('Forçar a atualização de todos os '+nodes.length+' cluster(s)? Os serviços ativos serão reiniciados.'))return;
    try{await postJson(UPD_API,{acao:'forcar_todos'});renderUpdates(false);}catch(e){alert(e.message);}
  };
  document.querySelectorAll('.ja-upd-card [data-act^="force:"]').forEach(b=>{
    const id=b.dataset.act.slice(6); const n=nodes.find(x=>x.device_id===id);
    if(n&&n.forcar_pendente) b.disabled=true;
    b.onclick=async()=>{
      if(!confirm('Forçar a atualização de "'+(n?.nome||id)+'"? Os serviços ativos serão reiniciados.'))return;
      b.disabled=true;
      try{await postJson(UPD_API,{acao:'forcar',device_id:id});renderUpdates(false);}catch(e){alert(e.message);b.disabled=false;}
    };
  });
  // Enquanto houver pedido pendente, acompanha sozinho (o nó responde em até ~2 min).
  if(nodes.some(n=>n.forcar_pendente)) updTimer=setTimeout(()=>{ if(document.getElementById('upd-refresh')) renderUpdates(false); },15000);
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
        actions+='<a href="/casa/index.php?grupo=SEGURAN%C3%87A&item=cameras" class="ja-mini" style="margin-right:6px;background:#8eb9ee;color:#000;font-weight:bold;text-decoration:none;padding:3px 8px;border-radius:4px;">📷 Câmera</a>';
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

// ---------------------------------------------------------------------------
// Segurança › Defesa & anti-intrusão. Os contadores abrem janelas flutuantes:
// IPs bloqueados (com data do bloqueio e desbloqueio) e a lista de eventos.
// ---------------------------------------------------------------------------
const SEC_API='/casa/api/seguranca_painel.php';
function secSev(s){const k=String(s||'INFO').toUpperCase();const c={CRITICO:'crit',ALTO:'crit',AVISO:'warn',INFO:'info'}[k]||'info';return '<span class="ja-sec-sev '+c+'">'+esc(k)+'</span>';}
function secWhen(v){if(!v)return '—';const d=new Date(String(v).replace(' ','T'));return isNaN(d)?esc(v):esc(d.toLocaleString('pt-BR'));}
function secModal(title,inner){
  document.getElementById('ja-sec-modal')?.remove();
  const m=document.createElement('div');m.id='ja-sec-modal';m.className='ja-schedule-backdrop';
  m.innerHTML='<section class="ja-schedule-modal ja-sec-modal" role="dialog" aria-modal="true"><header><strong>'+esc(title)+'</strong><button type="button" data-close>FECHAR</button></header><div class="ja-sec-body">'+inner+'</div></section>';
  document.body.appendChild(m);
  const close=()=>{m.remove();document.removeEventListener('keydown',esc_);};
  const esc_=e=>{if(e.key==='Escape')close();};
  document.addEventListener('keydown',esc_);
  m.querySelector('[data-close]').onclick=close;
  m.addEventListener('click',e=>{if(e.target===m)close();});
  return m;
}
async function openBlockedIps(){
  const m=secModal('IPs BLOQUEADOS','<div class="ja-empty">Carregando...</div>');
  const body=m.querySelector('.ja-sec-body');
  try{
    const j=await getJson(SEC_API+'?acao=bloqueados');
    const r=j.regra||{};
    let h='<p class="ja-sec-note">Regra: '+esc(r.falhas)+' falhas em '+esc(r.janela_min)+' min bloqueiam o IP por '+esc(r.bloqueio_min)+' min.</p>';
    const atv=Array.isArray(j?.ativos)?j.ativos:[];
    h+='<h4>Bloqueados agora ('+atv.length+')</h4>';
    h+=atv.length?'<div class="ja-sec-scroll"><table class="ja-sec-table"><thead><tr><th>IP</th><th>Bloqueado em</th><th>Até</th><th>Falhas</th><th>Origem</th><th>Motivo</th><th></th></tr></thead><tbody>'+
      j.ativos.map(a=>'<tr><td class="mono">'+esc(a.ip)+'</td><td>'+secWhen(a.bloqueado_em)+'</td><td>'+secWhen(a.bloqueado_ate)+'<small> ('+esc(a.restante_min)+' min)</small></td><td>'+esc(a.tentativas)+'</td><td>'+esc(a.origem||'—')+'</td><td>'+esc(a.motivo||'')+'</td><td><button class="ja-mini danger" data-unblock="'+attr(a.ip)+'">DESBLOQUEAR</button></td></tr>').join('')+'</tbody></table></div>'
      :'<p class="ja-sec-empty">Nenhum IP bloqueado no momento.</p>';
    const obs=Array.isArray(j?.observacao)?j.observacao:[];
    h+='<h4>Em observação ('+obs.length+')</h4><p class="ja-sec-note">IPs com falhas recentes que ainda não atingiram o limite.</p>';
    h+=obs.length?'<div class="ja-sec-scroll"><table class="ja-sec-table"><thead><tr><th>IP</th><th>Falhas</th><th>Última falha</th><th>Origem</th><th>Motivo</th><th></th></tr></thead><tbody>'+
      obs.map(a=>'<tr><td class="mono">'+esc(a.ip)+'</td><td>'+esc(a.tentativas)+'</td><td>'+secWhen(a.ultima_falha)+'</td><td>'+esc(a.origem||'—')+'</td><td>'+esc(a.motivo||'')+'</td><td><button class="ja-mini" data-unblock="'+attr(a.ip)+'">ZERAR</button></td></tr>').join('')+'</tbody></table></div>'
      :'<p class="ja-sec-empty">Nenhum IP em observação.</p>';
    const hist=Array.isArray(j?.historico)?j.historico:[];
    if(hist.length) h+='<h4>Últimos bloqueios automáticos</h4><div class="ja-sec-scroll"><table class="ja-sec-table"><thead><tr><th>Data</th><th>IP</th><th>Detalhes</th></tr></thead><tbody>'+hist.map(x=>'<tr><td>'+secWhen(x.data_hora)+'</td><td class="mono">'+esc(x.ip)+'</td><td>'+esc(x.detalhes||'')+'</td></tr>').join('')+'</tbody></table></div>';
    body.innerHTML=h;
    body.querySelectorAll('[data-unblock]').forEach(b=>b.onclick=async()=>{
      if(!confirm('Liberar o IP '+b.dataset.unblock+'?'))return;
      b.disabled=true;
      try{await postJson(SEC_API,{acao:'desbloquear',ip:b.dataset.unblock});openBlockedIps();renderSecurity();}catch(e){alert(e.message);b.disabled=false;}
    });
  }catch(e){body.innerHTML='<div class="ja-empty">'+esc(e.message||'Falha ao carregar.')+'</div>';}
}
async function openSecurityEvents(){
  const m=secModal('EVENTOS DE SEGURANÇA',
    '<div class="ja-sec-filters"><select data-f="sev"><option value="">Todas as severidades</option><option>CRITICO</option><option>ALTO</option><option>AVISO</option><option>INFO</option></select>'+
    '<select data-f="fonte"><option value="todos">Painel, hardware e API</option><option value="casa">Painel e hardware</option><option value="api_v1">API v1 (avisos e críticos)</option></select>'+
    '<input data-f="q" placeholder="Buscar IP, evento, usuário ou texto"><button class="ja-mini primary" data-f="go">FILTRAR</button></div>'+
    '<div class="ja-sec-scroll ja-sec-events"><table class="ja-sec-table"><thead><tr><th>Data</th><th>Evento</th><th>IP</th><th>Usuário / cliente</th><th>Severidade</th><th>Origem</th><th>Detalhes</th></tr></thead><tbody></tbody></table></div>'+
    '<div class="ja-sec-more"><span data-f="info"></span><button class="ja-mini" data-f="more" hidden>CARREGAR MAIS</button></div>');
  const tb=m.querySelector('tbody'),info=m.querySelector('[data-f=info]'),more=m.querySelector('[data-f=more]');
  let offset=0,total=0;
  const load=async(reset)=>{
    if(reset){offset=0;total=0;tb.innerHTML='<tr><td colspan="7" class="ja-sec-empty">Carregando...</td></tr>';}
    const q=new URLSearchParams({acao:'eventos',limite:'50',offset:String(offset),sev:m.querySelector('[data-f=sev]').value,fonte:m.querySelector('[data-f=fonte]').value,q:m.querySelector('[data-f=q]').value.trim()});
    try{
      const j=await getJson(SEC_API+'?'+q.toString());
      if(reset)tb.innerHTML='';
      tb.insertAdjacentHTML('beforeend',j.eventos.map(e=>'<tr class="'+(e.bloqueado==1?'blocked':'')+'"><td>'+secWhen(e.data_hora)+'</td><td><b>'+esc(e.evento)+'</b></td><td class="mono">'+esc(e.ip||'—')+'</td><td>'+esc(e.usuario||'—')+'</td><td>'+secSev(e.severidade)+'</td><td>'+esc(e.origem||'—')+'</td><td class="det">'+esc(e.detalhes||'')+'</td></tr>').join(''));
      total+=j.eventos.length;offset+=j.eventos.length;
      if(!total)tb.innerHTML='<tr><td colspan="7" class="ja-sec-empty">Nenhum evento encontrado.</td></tr>';
      info.textContent=total?total+' evento(s) exibido(s)':'';
      more.hidden=!j.mais;
    }catch(e){tb.innerHTML='<tr><td colspan="7" class="ja-sec-empty">'+esc(e.message||'Falha ao carregar.')+'</td></tr>';}
  };
  m.querySelector('[data-f=go]').onclick=()=>load(true);
  m.querySelector('[data-f=q]').addEventListener('keydown',e=>{if(e.key==='Enter')load(true);});
  m.querySelectorAll('select').forEach(s=>s.onchange=()=>load(true));
  more.onclick=()=>load(false);
  load(true);
}
async function renderSecurity(){
  loading('Defesa & anti-intrusão');
  let j;
  try{ j=await getJson(SEC_API+'?acao=resumo'); }
  catch(e){
    moduleShell('Defesa & anti-intrusão','<div class="ja-empty">'+esc(e.message||'Falha ao carregar.')+'</div>','<button id="sec-retry" class="ja-mini">Tentar novamente</button>');
    document.getElementById('sec-retry').onclick=renderSecurity; return;
  }
  const summary='<div class="ja-native-summary ja-sec-summary">'+
    '<button type="button" data-open="ips" title="Ver IPs bloqueados"><b>IPs BLOQUEADOS</b><strong>'+esc(j?.bloqueados_ativos ?? 0)+'</strong><small>'+esc(j?.em_observacao ?? 0)+' em observação · ver lista ›</small></button>'+
    '<button type="button" data-open="eventos" title="Ver lista de eventos"><b>EVENTOS (24 h)</b><strong>'+esc(j?.eventos_24h ?? 0)+'</strong><small>'+esc(j?.criticos_24h ?? 0)+' crítico(s) · ver lista ›</small></button></div>';
  const rec=Array.isArray(j?.recentes)?j.recentes:[];
  const body=rec.length?cards(rec,l=>'<article class="ja-native-card ja-sec-card" data-open="eventos"><h3>'+esc(l.evento||'Evento')+'</h3><p>'+esc(l.detalhes||'')+'</p><dl><dt>IP</dt><dd>'+esc(l.ip||'—')+'</dd><dt>Severidade</dt><dd>'+secSev(l.severidade)+'</dd><dt>Origem</dt><dd>'+esc(l.origem||'—')+(l.usuario?' · '+esc(l.usuario):'')+'</dd><dt>Data</dt><dd>'+secWhen(l.data_hora)+'</dd></dl></article>')
    :'<div class="ja-empty">Nenhum evento de segurança registrado ainda.</div>';
  moduleShell('Defesa & anti-intrusão','<div class="ja-sec-layout">'+summary+body+'</div>','<button class="ja-mini" id="sec-refresh">ATUALIZAR</button>');
  document.getElementById('sec-refresh').onclick=renderSecurity;
  document.querySelectorAll('.ja-native-body [data-open]').forEach(el=>el.onclick=()=>el.dataset.open==='ips'?openBlockedIps():openSecurityEvents());
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
        const dt = h.data_hora || null;
        if(userMsg) appendChat('Você',userMsg,'user',false,dt);
        if(botMsg) appendChat('COMPUTER',botMsg,'ai',false,dt);
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
    const userTime = new Date();
    appendChat('Você',cmd,'user',true,userTime);
    const sendBtn=document.getElementById('ja-command-send');
    if(sendBtn)sendBtn.disabled=true;
    try{
      const historicoTexto=conversationHistory.map(m=>(m.role==='assistant'?'ASSISTANT':'USER')+': '+m.content).join('\n\n');
      const r=await postJson('/casa/api/jarvis.php',{comando:cmd,ia_mode:'auto',historico:historicoTexto,historico_mensagens:conversationHistory});
      const resposta=r.resposta||r.mensagem||'Sem resposta.';
      const botTime = r.data_hora || new Date();
      appendChat('COMPUTER',resposta,'ai',true,botTime);
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
function formatChatTimestamp(dt){
  if(!dt) dt = new Date();
  if(typeof dt === 'string'){
    const match = dt.match(/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?/);
    if(match){
      const [, y, m, d, hr, min, sec] = match;
      return `${d}/${m}/${y} ${hr}:${min}${sec ? ':' + sec : ''}`;
    }
    const parsed = new Date(dt);
    if(!isNaN(parsed.getTime())) dt = parsed;
    else return dt;
  }
  if(dt instanceof Date){
    const pad = n => String(n).padStart(2, '0');
    return `${pad(dt.getDate())}/${pad(dt.getMonth()+1)}/${dt.getFullYear()} ${pad(dt.getHours())}:${pad(dt.getMinutes())}:${pad(dt.getSeconds())}`;
  }
  return String(dt);
}

function appendChat(who,text,cls,scroll=true,timestamp=null){
  const l=document.getElementById('ja-chat-log');
  if(!l)return;
  const d=document.createElement('div');
  d.className='ja-chat-line '+cls;

  const timeStr = formatChatTimestamp(timestamp || new Date());
  const timeEl = document.createElement('div');
  timeEl.className = 'ja-chat-timestamp';
  timeEl.textContent = timeStr;

  const contentEl = document.createElement('div');
  contentEl.className = 'ja-chat-content';

  const name=document.createElement('strong');
  name.className='ja-chat-who';
  name.textContent=who+': ';

  const body=document.createElement('span');
  body.className='ja-chat-text';
  body.textContent=String(text??'');

  contentEl.appendChild(name);
  contentEl.appendChild(body);

  d.appendChild(timeEl);
  d.appendChild(contentEl);
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

// =============================================================================
// MODULO: CAMERAS & VISAO (ESP32-CAM, CAMERAS IP, CFTV/DVR/NVR) + WIZARD
// =============================================================================

let camFilter = 'all';
let cachedCameras = [];

async function renderCameras(){
  loading('Câmeras & visão');
  try{
    let raw = [];
    try {
      const resp = await getJson('/casa/api/camera_discovery.php?acao=listar');
      if(resp && resp.ok && Array.isArray(resp.cameras)) {
        raw = resp.cameras;
      }
    } catch(e) { /* fallback para crud */ }

    if(!raw.length) {
      try {
        const j = await crud('dispositivos_cluster');
        raw = j.dados || [];
      } catch(e) {}
    }

    // Normaliza cameras e classifica tipos
    cachedCameras = raw.filter(d => {
      const t = String(d.tipo||'').toLowerCase();
      const m = String(d.model||d.modelo||'').toLowerCase();
      const caps = Array.isArray(d.capabilities)?d.capabilities.join(' ').toLowerCase():String(d.capabilities||'').toLowerCase();
      return t==='camera'||t==='espcam'||t==='esp32_cam'||t==='ip_camera'||t==='camera_ip'||t==='cftv'||t==='dvr'||t==='nvr'||
             caps.includes('camera')||caps.includes('streaming')||caps.includes('rtsp')||caps.includes('cftv')||caps.includes('onvif')||
             m.includes('cam')||m.includes('dvr')||m.includes('cftv')||m.includes('nvr');
    }).map(c => {
      const t = String(c.tipo||'').toLowerCase();
      const caps = Array.isArray(c.capabilities)?c.capabilities.join(' ').toLowerCase():String(c.capabilities||'').toLowerCase();
      const m = String(c.model||c.modelo||'').toLowerCase();
      let norm = 'espcam';
      let normLabel = 'ESP32-CAM';
      let normBadge = 'background:#f59e0b;color:#111;';

      if (t==='cftv'||t==='dvr'||t==='nvr'||caps.includes('cftv')||m.includes('dvr')||m.includes('cftv')||m.includes('nvr')) {
        norm = 'cftv';
        normLabel = 'CFTV / DVR';
        normBadge = 'background:#c084fc;color:#111;';
      } else if (t==='ip_camera'||t==='camera_ip'||caps.includes('rtsp')||caps.includes('onvif')||caps.includes('ip_camera')) {
        norm = 'ip_camera';
        normLabel = 'CÂMERA IP';
        normBadge = 'background:#38bdf8;color:#111;';
      } else {
        norm = 'espcam';
        normLabel = 'ESP32-CAM';
        normBadge = 'background:#f59e0b;color:#111;';
      }

      let meta = {};
      if (typeof c.metadata === 'object' && c.metadata !== null) {
        meta = c.metadata;
      } else if (typeof c.metadata === 'string' && c.metadata.trim()) {
        try { meta = JSON.parse(c.metadata); } catch(e) {}
      }

      return {
        ...c,
        tipo_norm: norm,
        tipo_label: normLabel,
        tipo_badge: normBadge,
        meta: meta
      };
    });

    const totalAll = cachedCameras.length;
    const totalEsp = cachedCameras.filter(c => c.tipo_norm === 'espcam').length;
    const totalIp = cachedCameras.filter(c => c.tipo_norm === 'ip_camera').length;
    const totalCftv = cachedCameras.filter(c => c.tipo_norm === 'cftv').length;

    // Filtra por categoria selecionada
    const visibleCams = cachedCameras.filter(c => {
      if (camFilter === 'espcam') return c.tipo_norm === 'espcam';
      if (camFilter === 'ip_camera') return c.tipo_norm === 'ip_camera';
      if (camFilter === 'cftv') return c.tipo_norm === 'cftv';
      return true;
    });

    // Toolbar de Acoes & Abas de Filtro
    const filterTabsHtml =
      '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #1e293b;">'+
        '<button class="ja-mini '+(camFilter==='all'?'primary':'')+'" onclick="setCamFilter(\'all\')">TODAS ('+totalAll+')</button>'+
        '<button class="ja-mini '+(camFilter==='espcam'?'primary':'')+'" onclick="setCamFilter(\'espcam\')">📷 ESP32-CAM ('+totalEsp+')</button>'+
        '<button class="ja-mini '+(camFilter==='ip_camera'?'primary':'')+'" onclick="setCamFilter(\'ip_camera\')">🌐 CÂMERAS IP ('+totalIp+')</button>'+
        '<button class="ja-mini '+(camFilter==='cftv'?'primary':'')+'" onclick="setCamFilter(\'cftv\')">📼 CFTV / DVR ('+totalCftv+')</button>'+
      '</div>';

    let bodyHtml = '';

    if (!visibleCams.length) {
      bodyHtml = filterTabsHtml +
        '<div class="ja-empty" style="padding:28px 16px;text-align:center;">'+
          '<p style="font-size:1.1rem;margin-bottom:12px;color:#cbd5e1;">Nenhuma câmera encontrada na categoria selecionada.</p>'+
          '<div style="display:flex;gap:10px;justify-content:center;margin-top:14px;">'+
            '<button class="ja-btn primary" onclick="openCameraDiscoveryWizard()">🔍 BUSCAR CÂMERAS NA REDE</button>'+
            '<button class="ja-btn" onclick="openCameraEditorModal()">+ ADICIONAR MANUAL</button>'+
          '</div>'+
        '</div>';
    } else {
      const cardsHtml = visibleCams.map(cam => {
        const ip = cam.ip_address || cam.local_ip || '';
        const name = esc(cam.nome || cam.device_id || 'Câmera');
        const loc = esc(cam.localizacao || 'Ambiente monitorado');
        const canal = cam.meta?.canal || 1;
        const modelo = esc(cam.meta?.modelo || cam.model || cam.modelo || cam.tipo_label);
        const webPort = cam.meta?.web_port || 80;
        const camId = cam.id || cam.device_id;
        const status = String(cam.status || 'OFFLINE').toUpperCase();
        const isOnline = status === 'ONLINE';
        const statusBoxId = 'cam-status-box-' + String(camId).replace(/[^a-zA-Z0-9_-]/g, '_');
        const badgeId = 'cam-badge-' + String(camId).replace(/[^a-zA-Z0-9_-]/g, '_');
        const lastSeenText = cam.visto_por_ultimo || (cam.ultimo_heartbeat ? 'Último sinal: ' + esc(cam.ultimo_heartbeat) : 'Sem sinal');

        // Badges do Header
        let badges = '<span class="ja-state" style="'+cam.tipo_badge+'font-weight:bold;">'+cam.tipo_label+'</span>';
        if (cam.tipo_norm === 'cftv') {
          badges += '<span class="ja-state" style="background:#818cf8;color:#111;margin-left:4px;font-weight:bold;">CH '+canal+'</span>';
        }

        // Acoes por tipo de camera
        let actions = '';
        if (ip) {
          if (cam.tipo_norm === 'espcam') {
            actions =
              '<div class="ja-row-actions" style="margin-top:10px;flex-wrap:wrap;gap:5px;">'+
                '<button class="ja-btn primary" style="font-size:0.75rem;padding:5px 9px;" onclick="openCameraSnapshotModal(\''+attr(camId)+'\')">📸 FOTO</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;" onclick="openCameraStreamModal(\''+attr(camId)+'\')">▶ STREAM</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;background:#0369a1;color:#fff;" onclick="openCameraPropertiesModal(\''+attr(camId)+'\')">ℹ PROPRIEDADES</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;" onclick="fetch(\'http://'+esc(ip)+'/flash/on\').catch(()=>{})">💡 FLASH</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;" onclick="postJson(\'/casa/api/jarvis.php\',{comando:\'/cloud Analise a imagem recente da câmera '+name+'\'}).catch(()=>{})">🤖 IA VISÃO</button>'+
                '<button class="ja-mini" style="font-size:0.75rem;padding:5px 8px;" onclick="openCameraEditorModal(\''+attr(camId)+'\')">⚙</button>'+
                '<button class="ja-mini danger" style="font-size:0.75rem;padding:5px 8px;" onclick="deleteCamera(\''+attr(camId)+'\')">🗑</button>'+
              '</div>';
          } else if (cam.tipo_norm === 'ip_camera') {
            actions =
              '<div class="ja-row-actions" style="margin-top:10px;flex-wrap:wrap;gap:5px;">'+
                '<button class="ja-btn primary" style="font-size:0.75rem;padding:5px 9px;" onclick="openCameraStreamModal(\''+attr(camId)+'\')">▶ VER STREAM</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;background:#0369a1;color:#fff;" onclick="openCameraPropertiesModal(\''+attr(camId)+'\')">ℹ PROPRIEDADES</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;" onclick="openCameraSnapshotModal(\''+attr(camId)+'\')">📸 SNAPSHOT</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;" onclick="window.open(\'http://'+esc(ip)+':'+webPort+'/\',\'_blank\')">🌐 PAINEL</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;" onclick="postJson(\'/casa/api/jarvis.php\',{comando:\'/cloud Analise a imagem da câmera '+name+'\'}).catch(()=>{})">🤖 IA VISÃO</button>'+
                '<button class="ja-mini" style="font-size:0.75rem;padding:5px 8px;" onclick="openCameraEditorModal(\''+attr(camId)+'\')">⚙</button>'+
                '<button class="ja-mini danger" style="font-size:0.75rem;padding:5px 8px;" onclick="deleteCamera(\''+attr(camId)+'\')">🗑</button>'+
              '</div>';
          } else { // cftv
            actions =
              '<div class="ja-row-actions" style="margin-top:10px;flex-wrap:wrap;gap:5px;">'+
                '<button class="ja-btn primary" style="font-size:0.75rem;padding:5px 9px;" onclick="openCameraStreamModal(\''+attr(camId)+'\')">▶ RTSP CANAL '+canal+'</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;background:#0369a1;color:#fff;" onclick="openCameraPropertiesModal(\''+attr(camId)+'\')">ℹ PROPRIEDADES</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;" onclick="openCameraSnapshotModal(\''+attr(camId)+'\')">📸 SNAPSHOT</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;" onclick="window.open(\'http://'+esc(ip)+':'+webPort+'/\',\'_blank\')">📼 PAINEL DVR</button>'+
                '<button class="ja-btn" style="font-size:0.75rem;padding:5px 9px;" onclick="postJson(\'/casa/api/jarvis.php\',{comando:\'/cloud Analise a imagem do canal '+canal+' do DVR '+name+'\'}).catch(()=>{})">🤖 IA VISÃO</button>'+
                '<button class="ja-mini" style="font-size:0.75rem;padding:5px 8px;" onclick="openCameraEditorModal(\''+attr(camId)+'\')">⚙</button>'+
                '<button class="ja-mini danger" style="font-size:0.75rem;padding:5px 8px;" onclick="deleteCamera(\''+attr(camId)+'\')">🗑</button>'+
              '</div>';
          }
        } else {
          actions = '<div style="opacity:0.6;font-size:0.85rem;margin-top:8px;">Aguardando IP / Offline</div>';
        }

        // Telemetria de Wi-Fi da Casa e Medicao
        const wifiSsid = cam.meta?.wifi_ssid || cam.meta?.wifi || '';
        const wifiRssi = (typeof cam.sinal_rssi === 'number' && cam.sinal_rssi !== 0) ? cam.sinal_rssi : (cam.meta?.wifi_rssi || null);
        const wifiSurvey = Array.isArray(cam.meta?.wifi_survey) ? cam.meta.wifi_survey : [];

        let wifiMeterHtml = '';
        if (wifiSsid || wifiSurvey.length > 0 || cam.tipo_norm === 'espcam') {
          let surveyItems = wifiSurvey.map(ws => {
            const sName = esc(ws.ssid || 'Wi-Fi');
            const sRssi = parseInt(ws.rssi, 10);
            const isConn = Boolean(ws.connected || (wifiSsid && ws.ssid === wifiSsid));
            let color = '#22c55e';
            if (sRssi <= -80 || isNaN(sRssi) || sRssi <= -900) {
              color = '#ef4444';
            } else if (sRssi <= -70) {
              color = '#f59e0b';
            } else if (sRssi <= -60) {
              color = '#38bdf8';
            }
            const rssiDisp = (sRssi > -200 && sRssi < 0) ? sRssi + ' dBm' : 'Sem sinal';
            return '<div style="display:flex;justify-content:space-between;align-items:center;background:#050811;border:1px solid '+(isConn?'#38bdf8':'#1e293b')+';padding:4px 8px;border-radius:6px;font-size:0.75rem;margin-bottom:3px;">'+
              '<span style="color:'+(isConn?'#38bdf8':'#cbd5e1')+';font-weight:'+(isConn?'bold':'normal')+';">📶 '+sName+(isConn?' <span style="color:#22c55e;font-size:0.68rem;">(Conectado)</span>':'')+'</span>'+
              '<span style="color:'+color+';font-weight:bold;">'+rssiDisp+'</span>'+
            '</div>';
          }).join('');

          wifiMeterHtml =
            '<div style="margin-top:8px;padding-top:6px;border-top:1px dashed #1e293b;">'+
              '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">'+
                '<strong style="font-size:0.78rem;color:#f59e0b;">SINAL WI-FI DA CASA (GRAVAÇÃO)</strong>'+
                '<button type="button" class="ja-mini" style="font-size:0.65rem;padding:1px 5px;" onclick="openWifiSurveyHistoryModal(\''+attr(camId)+'\')">Histórico</button>'+
              '</div>'+
              (surveyItems || '<div style="font-size:0.75rem;color:#94a3b8;">Aguardando telemetria de sinal da câmera...</div>')+
            '</div>';
        }

        return '<article class="ja-native-card" style="display:flex;flex-direction:column;justify-content:space-between;">'+
          '<div>'+
            '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px;">'+
              '<h3 style="margin:0;font-size:1.05rem;">'+name+'</h3>'+
              '<div>'+badges+'</div>'+
            '</div>'+
            '<p style="margin:2px 0 6px;color:#94a3b8;font-size:0.85rem;">'+loc+' · <i>'+modelo+'</i></p>'+
            '<dl>'+
              '<dt>Dispositivo</dt><dd>'+esc(cam.device_id)+'</dd>'+
              '<dt>Endereço IP</dt><dd>'+(ip?'<a href="http://'+esc(ip)+':'+webPort+'/" target="_blank" style="color:#38bdf8;text-decoration:none;font-weight:bold;">'+esc(ip)+'</a>':'—')+'</dd>'+
              '<dt>Protocolo</dt><dd>'+(cam.tipo_norm==='espcam'?'HTTP / MJPEG':(cam.tipo_norm==='cftv'?'RTSP Multi-Canal (Porta 554)':'RTSP / ONVIF (Porta 554)'))+'</dd>'+
              '<dt>Status</dt><dd id="'+statusBoxId+'" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;"><span id="'+badgeId+'" class="ja-state '+(isOnline?'ok':'off')+'">'+esc(status)+'</span><span style="font-size:0.75rem;color:#94a3b8;">('+esc(lastSeenText)+')</span>'+(ip?'<button type="button" class="ja-mini" style="font-size:0.68rem;padding:2px 6px;" onclick="testCameraDirect(\''+attr(camId)+'\')">Testar</button>':'')+'</dd>'+
            '</dl>'+
            wifiMeterHtml+
          '</div>'+
          actions+
        '</article>';
      }).join('');

      bodyHtml = filterTabsHtml + '<div class="ja-camera-grid">'+cardsHtml+'</div>';
    }

    const headActions =
      '<button id="cam-wifi-btn" class="ja-mini" style="background:#0284c7;color:#fff;font-weight:bold;margin-right:4px;" onclick="openHouseWifiModal()">📶 WI-FI DA CASA</button>'+
      '<button id="cam-discover" class="ja-mini primary" style="font-weight:900;" onclick="openCameraDiscoveryWizard()">🔍 BUSCAR CÂMERAS</button>'+
      '<button id="cam-add-btn" class="ja-mini" onclick="openCameraEditorModal()">+ NOVA CÂMERA</button>'+
      '<button id="cam-refresh" class="ja-mini" onclick="renderCameras()">ATUALIZAR</button>';

    moduleShell('Câmeras & visão', bodyHtml, headActions);

    // Dispara teste de conectividade nao-bloqueante para cameras com IP
    visibleCams.forEach(cam => {
      const cid = cam.id || cam.device_id;
      if (cam.ip_address) {
        setTimeout(() => testCameraDirect(cid), 150);
      }
    });
  }catch(e){
    moduleShell('Câmeras & visão','<div class="ja-empty">Falha ao carregar câmeras: '+esc(e.message||'Erro')+'</div>');
  }
}

async function testCameraDirect(camId) {
  const cam = findCamById(camId);
  if (!cam) return;
  const ip = cam.ip_address || cam.local_ip || '';
  if (!ip) return;

  const badgeId = 'cam-badge-' + String(camId).replace(/[^a-zA-Z0-9_-]/g, '_');
  const badgeEl = document.getElementById(badgeId);
  if (badgeEl) {
    badgeEl.className = 'ja-state';
    badgeEl.style.background = '#64748b';
    badgeEl.style.color = '#fff';
    badgeEl.textContent = 'TESTANDO...';
  }

  let online = false;
  // 1. Tenta teste direto na LAN com timeout curto de 2s
  try {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 2000);
    await fetch('http://' + ip + (cam.tipo_norm === 'espcam' ? '/status' : '/'), {
      mode: 'no-cors',
      cache: 'no-store',
      signal: ctrl.signal
    });
    clearTimeout(t);
    online = true;
  } catch(e) {
    online = false;
  }

  // 2. Se falhou na LAN (ou acessando da nuvem externa), consulta probe da API
  if (!online) {
    try {
      const resp = await getJson('/casa/api/camera_discovery.php?acao=probe_ip&ip=' + encodeURIComponent(ip));
      if (resp && resp.ok && resp.camera && resp.camera.portas_abertas && resp.camera.portas_abertas.length > 0) {
        online = true;
      }
    } catch(e) {}
  }

  if (badgeEl) {
    if (online) {
      badgeEl.className = 'ja-state ok';
      badgeEl.removeAttribute('style');
      badgeEl.textContent = 'ONLINE';
      // Registra heartbeat na nuvem
      postJson('/casa/api/camera_discovery.php?acao=heartbeat', {
        device_id: cam.device_id || camId,
        ip: ip
      }).catch(() => {});
    } else {
      badgeEl.className = 'ja-state off';
      badgeEl.removeAttribute('style');
      badgeEl.textContent = 'OFFLINE';
    }
  }
}
window.testCameraDirect = testCameraDirect;


// -------------------------------------------------------------
// GESTAO DAS REDES WI-FI DA CASA (LCARS)
// -------------------------------------------------------------
async function openHouseWifiModal() {
  document.getElementById('ja-house-wifi-modal')?.remove();

  const modal = document.createElement('div');
  modal.id = 'ja-house-wifi-modal';
  modal.className = 'ja-schedule-backdrop';
  modal.innerHTML =
    '<section class="ja-schedule-modal" style="width:min(680px,96vw);" role="dialog">'+
      '<header style="background:var(--ja-blue,#0284c7);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:12px 16px;">'+
        '<strong style="font-size:1.05rem;">📶 REDES WI-FI DA CASA (DISTRIBUIÇÃO AUTOMÁTICA)</strong>'+
        '<button type="button" onclick="document.getElementById(\'ja-house-wifi-modal\').remove()" style="background:#111;color:#fff;border:0;border-radius:10px;padding:6px 12px;font-weight:bold;cursor:pointer;">FECHAR</button>'+
      '</header>'+
      '<div style="padding:16px;">'+
        '<div style="background:#0b1120;border:1px solid #1e293b;border-radius:10px;padding:12px;margin-bottom:14px;font-size:0.85rem;color:#cbd5e1;line-height:1.4;">'+
          'ℹ <b>Como funciona:</b> Todas as redes Wi-Fi cadastradas aqui são consultadas pelo Daemon da Casa e repassadas automaticamente para cada ESP32-CAM. '+
          'Antes de se conectar, a câmera escaneia o ambiente e seleciona automaticamente a rede com o <b>melhor sinal RSSI</b> para garantir transmissão fluida sem congelamentos.'+
        '</div>'+
        '<div id="ja-house-wifi-list" style="margin-bottom:16px;">Carregando redes da casa...</div>'+
        '<div style="background:#0f172a;border:1px solid #334155;border-radius:10px;padding:14px;">'+
          '<h4 style="margin:0 0 10px;color:#38bdf8;font-size:0.95rem;">+ Adicionar Nova Rede Wi-Fi</h4>'+
          '<form id="ja-form-add-wifi" onsubmit="saveHouseWifi(event)" style="display:flex;flex-direction:column;gap:10px;">'+
            '<div style="display:flex;gap:10px;flex-wrap:wrap;">'+
              '<input type="text" id="wifi-new-ssid" placeholder="Nome da Rede (SSID)" required style="flex:1;min-width:180px;background:#050811;border:1px solid #38bdf8;color:#fff;padding:8px 12px;border-radius:8px;">'+
              '<input type="text" id="wifi-new-pass" placeholder="Senha do Wi-Fi" required style="flex:1;min-width:180px;background:#050811;border:1px solid #38bdf8;color:#fff;padding:8px 12px;border-radius:8px;">'+
              '<button type="submit" class="ja-btn primary" style="padding:8px 16px;">SALVAR REDE</button>'+
            '</div>'+
          '</form>'+
        '</div>'+
      '</div>'+
    '</section>';

  document.body.appendChild(modal);
  await loadHouseWifiList();
}

async function loadHouseWifiList() {
  const container = document.getElementById('ja-house-wifi-list');
  if (!container) return;
  try {
    const resp = await getJson('/casa/api/camera_discovery.php?acao=get_house_wifis');
    const wifis = resp?.wifis || [];
    if (!wifis.length) {
      container.innerHTML = '<div style="color:#94a3b8;font-size:0.9rem;">Nenhuma rede Wi-Fi cadastrada. Adicione uma rede abaixo.</div>';
      return;
    }
    const html = wifis.map(w => {
      return '<div style="display:flex;justify-content:space-between;align-items:center;background:#050811;border:1px solid #1e293b;padding:10px 14px;border-radius:8px;margin-bottom:8px;">'+
        '<div>'+
          '<strong style="color:#38bdf8;font-size:0.95rem;">📶 '+esc(w.ssid)+'</strong>'+
          '<div style="font-size:0.75rem;color:#94a3b8;margin-top:2px;">Senha: <span style="font-family:monospace;color:#cbd5e1;">'+esc(w.senha || '••••••••')+'</span> · Prioridade: '+esc(w.prioridade)+'</div>'+
        '</div>'+
        '<button type="button" class="ja-mini danger" onclick="deleteHouseWifi('+w.id+',\''+esc(w.ssid)+'\')">EXCLUIR</button>'+
      '</div>';
    }).join('');
    container.innerHTML = html;
  } catch(e) {
    container.innerHTML = '<div style="color:#ef4444;">Erro ao carregar redes: '+esc(e.message||'Falha')+'</div>';
  }
}

async function saveHouseWifi(e) {
  e.preventDefault();
  const ssid = document.getElementById('wifi-new-ssid')?.value?.trim();
  const senha = document.getElementById('wifi-new-pass')?.value?.trim();
  if (!ssid) return;
  try {
    await postJson('/casa/api/camera_discovery.php?acao=save_house_wifi', { ssid, senha, prioridade: 1 });
    document.getElementById('wifi-new-ssid').value = '';
    document.getElementById('wifi-new-pass').value = '';
    await loadHouseWifiList();
    alert('Rede Wi-Fi "' + ssid + '" salva com sucesso! O Daemon da casa irá distribuí-la para as câmeras.');
  } catch(err) {
    alert('Erro ao salvar rede Wi-Fi: ' + (err.message || 'Falha'));
  }
}

async function deleteHouseWifi(id, ssid) {
  if (!confirm('Deseja realmente remover a rede Wi-Fi "' + ssid + '" da casa?')) return;
  try {
    await postJson('/casa/api/camera_discovery.php?acao=delete_house_wifi', { id, ssid });
    await loadHouseWifiList();
  } catch(err) {
    alert('Erro ao remover rede: ' + (err.message || 'Falha'));
  }
}

async function openWifiSurveyHistoryModal(camId) {
  document.getElementById('ja-wifi-survey-modal')?.remove();
  const modal = document.createElement('div');
  modal.id = 'ja-wifi-survey-modal';
  modal.className = 'ja-schedule-backdrop';
  modal.innerHTML =
    '<section class="ja-schedule-modal" style="width:min(720px,96vw);" role="dialog">'+
      '<header style="background:var(--ja-orange,#f59e0b);color:#111;display:flex;justify-content:space-between;align-items:center;padding:12px 16px;">'+
        '<strong style="font-size:1.05rem;">📊 HISTÓRICO DE SINAL WI-FI (COLETA A CADA 1 MINUTO)</strong>'+
        '<button type="button" onclick="document.getElementById(\'ja-wifi-survey-modal\').remove()" style="background:#111;color:#fff;border:0;border-radius:10px;padding:6px 12px;font-weight:bold;cursor:pointer;">FECHAR</button>'+
      '</header>'+
      '<div id="ja-wifi-survey-body" style="padding:16px;max-height:65vh;overflow-y:auto;">'+
        'Carregando histórico gravado...'+
      '</div>'+
    '</section>';
  document.body.appendChild(modal);

  try {
    const resp = await getJson('/casa/api/camera_discovery.php?acao=get_wifi_survey&device_id=' + encodeURIComponent(camId));
    const list = resp?.surveys || [];
    const body = document.getElementById('ja-wifi-survey-body');
    if (!list.length) {
      body.innerHTML = '<div style="color:#94a3b8;padding:20px;text-align:center;">Nenhuma medição registrada ainda para este dispositivo pelo daemon.</div>';
      return;
    }
    const rows = list.map(s => {
      const redesHtml = (s.redes_detectadas || []).map(r => {
        return '<span style="background:#1e293b;padding:2px 6px;border-radius:4px;font-size:0.75rem;margin-right:4px;">'+esc(r.ssid)+': <b>'+esc(r.rssi)+' dBm</b></span>';
      }).join(' ');
      return '<tr style="border-bottom:1px solid #1e293b;">'+
        '<td style="padding:8px;font-size:0.8rem;color:#cbd5e1;">'+esc(s.coletado_em)+'</td>'+
        '<td style="padding:8px;font-size:0.8rem;color:#38bdf8;font-weight:bold;">'+esc(s.wifi_conectado || '—')+' ('+esc(s.rssi_conectado)+' dBm)</td>'+
        '<td style="padding:8px;">'+redesHtml+'</td>'+
      '</tr>';
    }).join('');
    body.innerHTML =
      '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;">'+
        '<thead><tr style="border-bottom:2px solid #334155;text-align:left;color:#94a3b8;">'+
          '<th style="padding:8px;">Data/Hora</th><th style="padding:8px;">Conectado</th><th style="padding:8px;">Força das Redes da Casa</th>'+
        '</tr></thead>'+
        '<tbody>'+rows+'</tbody>'+
      '</table>';
  } catch(e) {
    document.getElementById('ja-wifi-survey-body').innerHTML = '<div style="color:#ef4444;">Erro ao carregar telemetria: '+esc(e.message||'Falha')+'</div>';
  }
}

window.openHouseWifiModal = openHouseWifiModal;
window.saveHouseWifi = saveHouseWifi;
window.deleteHouseWifi = deleteHouseWifi;
window.openWifiSurveyHistoryModal = openWifiSurveyHistoryModal;


// -------------------------------------------------------------
// VISAO DE PROPRIEDADES DA CAMERA & SINAL WI-FI (LCARS)
// -------------------------------------------------------------
async function openCameraPropertiesModal(camId) {
  const cam = findCamById(camId);
  if (!cam) return;

  document.getElementById('ja-cam-props-modal')?.remove();

  const name = esc(cam.nome || cam.device_id || 'Câmera');
  const did = esc(cam.device_id || cam.id);
  const ip = cam.ip_address || cam.local_ip || '';
  const mac = esc(cam.mac_address || cam.meta?.mac || '—');
  const loc = esc(cam.localizacao || 'Residência');
  const modelo = esc(cam.meta?.modelo || cam.model || cam.tipo_label || 'ESP32-CAM');
  const webPort = cam.meta?.web_port || 80;
  const isOnline = Boolean(cam.is_online || String(cam.status||'').toUpperCase() === 'ONLINE');
  const statusText = isOnline ? 'ONLINE' : 'OFFLINE';
  const statusColor = isOnline ? '#22c55e' : '#ef4444';
  const lastSeen = esc(cam.visto_por_ultimo || (cam.ultimo_heartbeat ? 'Último sinal: ' + cam.ultimo_heartbeat : 'Nunca comunicou'));
  const token = cam.meta?.token || '';
  const maskedToken = token ? token.substring(0, 6) + '••••••••••••••••' + token.substring(token.length - 4) : 'Não registrado';

  const wifiSsid = cam.meta?.wifi_ssid || cam.meta?.wifi || 'Não detectado';
  const wifiRssi = (typeof cam.sinal_rssi === 'number' && cam.sinal_rssi !== 0) ? cam.sinal_rssi : (cam.meta?.wifi_rssi || null);
  const wifiSurvey = Array.isArray(cam.meta?.wifi_survey) ? cam.meta.wifi_survey : [];
  const wifiUpdatedAt = cam.meta?.wifi_updated_at || cam.ultimo_heartbeat || 'Aguardando telemetria';

  // Renderiza tabela visual das redes da casa detectadas pela camera
  let wifiTableHtml = '';
  if (wifiSurvey.length > 0) {
    wifiTableHtml = wifiSurvey.map(ws => {
      const sSsid = esc(ws.ssid || 'Wi-Fi');
      const sRssi = parseInt(ws.rssi, 10);
      const isConn = Boolean(ws.connected || (wifiSsid && ws.ssid === wifiSsid));

      let qualLabel = 'Excelente';
      let qualColor = '#22c55e';
      let pct = 95;

      if (sRssi <= -85 || isNaN(sRssi) || sRssi <= -900) {
        qualLabel = 'Fraco / Fora de alcance';
        qualColor = '#ef4444';
        pct = 15;
      } else if (sRssi <= -75) {
        qualLabel = 'Regular';
        qualColor = '#f59e0b';
        pct = 45;
      } else if (sRssi <= -65) {
        qualLabel = 'Bom';
        qualColor = '#38bdf8';
        pct = 75;
      }

      const rssiStr = (sRssi > -200 && sRssi < 0) ? sRssi + ' dBm' : 'Sem sinal';

      return '<div style="background:#0b1120;border:1px solid '+(isConn?'#38bdf8':'#1e293b')+';border-radius:10px;padding:10px 14px;margin-bottom:8px;">'+
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'+
          '<div style="display:flex;align-items:center;gap:8px;">'+
            '<strong style="font-size:0.95rem;color:'+(isConn?'#38bdf8':'#cbd5e1')+';">📶 '+sSsid+'</strong>'+
            (isConn ? '<span class="ja-state ok" style="font-size:0.7rem;padding:2px 8px;">CONECTADA (ATIVA)</span>' : '<span style="font-size:0.75rem;color:#94a3b8;">No alcance</span>')+
          '</div>'+
          '<div style="text-align:right;">'+
            '<span style="font-weight:bold;font-size:0.95rem;color:'+qualColor+';">'+rssiStr+'</span>'+
            '<small style="margin-left:6px;color:#94a3b8;font-size:0.75rem;">('+qualLabel+')</small>'+
          '</div>'+
        '</div>'+
        '<div style="background:#1e293b;border-radius:6px;height:6px;overflow:hidden;">'+
          '<div style="background:'+qualColor+';width:'+pct+'%;height:100%;transition:width 0.4s;"></div>'+
        '</div>'+
      '</div>';
    }).join('');
  } else {
    wifiTableHtml =
      '<div style="background:#0b1120;border:1px dashed #334155;border-radius:10px;padding:18px;text-align:center;color:#94a3b8;font-size:0.9rem;">'+
        'Nenhum relatório de sinal recebido ainda para esta câmera.<br>'+
        '<small style="color:#64748b;margin-top:4px;display:block;">O Daemon da Casa coleta as forças de Wi-Fi a cada 1 minuto e grava automaticamente na nuvem.</small>'+
      '</div>';
  }

  const modal = document.createElement('div');
  modal.id = 'ja-cam-props-modal';
  modal.className = 'ja-schedule-backdrop';
  modal.innerHTML =
    '<section class="ja-schedule-modal" style="width:min(780px,96vw);max-height:90vh;display:flex;flex-direction:column;" role="dialog">'+
      '<header style="background:var(--ja-blue,#0284c7);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:12px 18px;flex-shrink:0;">'+
        '<div style="display:flex;align-items:center;gap:10px;">'+
          '<strong style="font-size:1.1rem;">ℹ PROPRIEDADES DA CÂMERA: '+name+'</strong>'+
          '<span class="ja-state" style="background:'+statusColor+';color:#111;font-weight:bold;font-size:0.75rem;padding:3px 8px;">'+statusText+'</span>'+
        '</div>'+
        '<button type="button" onclick="document.getElementById(\'ja-cam-props-modal\').remove()" style="background:#111;color:#fff;border:0;border-radius:10px;padding:6px 12px;font-weight:bold;cursor:pointer;">FECHAR</button>'+
      '</header>'+
      '<div style="padding:18px;overflow-y:auto;display:flex;flex-direction:column;gap:16px;">'+

        '<!-- SEÇÃO 1: REDES WI-FI DA CASA & INTENSIDADE DE SINAL -->'+
        '<div style="background:#050811;border:1px solid #38bdf8;border-radius:14px;padding:16px;">'+
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">'+
            '<div>'+
              '<h3 style="margin:0;color:#38bdf8;font-size:1.05rem;display:flex;align-items:center;gap:8px;">📶 REDES WI-FI DA CASA & FORÇA DO SINAL</h3>'+
              '<small style="color:#94a3b8;font-size:0.8rem;">Coleta automática do Daemon da Casa (1x por minuto) para gravação contínua</small>'+
            '</div>'+
            '<div style="display:flex;gap:6px;">'+
              '<button type="button" class="ja-btn primary" style="font-size:0.75rem;padding:5px 10px;" onclick="refreshCameraWifiLive(\''+attr(camId)+'\')">🔄 MEDIR SINAL AGORA</button>'+
              '<button type="button" class="ja-btn" style="font-size:0.75rem;padding:5px 10px;" onclick="openWifiSurveyHistoryModal(\''+attr(camId)+'\')">📊 HISTÓRICO</button>'+
              '<button type="button" class="ja-btn" style="font-size:0.75rem;padding:5px 10px;" onclick="openHouseWifiModal()">⚙ WI-FI DA CASA</button>'+
            '</div>'+
          '</div>'+

          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">'+
            '<div style="background:#0b1120;border:1px solid #1e293b;border-radius:10px;padding:10px;">'+
              '<small style="color:#94a3b8;display:block;">Wi-Fi Atualmente Conectado</small>'+
              '<strong style="font-size:1.1rem;color:#38bdf8;">'+esc(wifiSsid)+'</strong>'+
            '</div>'+
            '<div style="background:#0b1120;border:1px solid #1e293b;border-radius:10px;padding:10px;">'+
              '<small style="color:#94a3b8;display:block;">Intensidade da Conexão Ativa</small>'+
              '<strong style="font-size:1.1rem;color:'+(wifiRssi&&wifiRssi>-75?'#22c55e':'#f59e0b')+';">'+(wifiRssi?wifiRssi+' dBm':'—')+'</strong>'+
            '</div>'+
          '</div>'+

          '<div>'+
            '<div style="margin-bottom:8px;font-size:0.85rem;color:#cbd5e1;font-weight:bold;">Força Detectada em Cada Rede Wi-Fi da Residência:</div>'+
            '<div id="ja-cam-props-wifilist">'+wifiTableHtml+'</div>'+
            '<div style="margin-top:8px;text-align:right;font-size:0.75rem;color:#64748b;">Última telemetria: '+esc(wifiUpdatedAt)+'</div>'+
          '</div>'+
        '</div>'+

        '<!-- SEÇÃO 2: IDENTIFICAÇÃO E HARDWARE -->'+
        '<div style="background:#050811;border:1px solid #1e293b;border-radius:14px;padding:16px;">'+
          '<h3 style="margin:0 0 12px;color:#f59e0b;font-size:1rem;">📋 DADOS TÉCNICOS & REDE</h3>'+
          '<dl style="display:grid;grid-template-columns:140px 1fr;row-gap:8px;column-gap:12px;margin:0;font-size:0.88rem;">'+
            '<dt style="color:#94a3b8;">Dispositivo ID:</dt><dd style="margin:0;font-family:monospace;color:#fff;">'+did+'</dd>'+
            '<dt style="color:#94a3b8;">Endereço IP:</dt><dd style="margin:0;font-family:monospace;">'+(ip?'<a href="http://'+esc(ip)+':'+webPort+'/" target="_blank" style="color:#38bdf8;text-decoration:none;font-weight:bold;">'+esc(ip)+'</a> (Porta: '+webPort+')':'—')+'</dd>'+
            '<dt style="color:#94a3b8;">Endereço MAC:</dt><dd style="margin:0;font-family:monospace;color:#cbd5e1;">'+mac+'</dd>'+
            '<dt style="color:#94a3b8;">Modelo / Tipo:</dt><dd style="margin:0;color:#cbd5e1;">'+modelo+'</dd>'+
            '<dt style="color:#94a3b8;">Localização:</dt><dd style="margin:0;color:#cbd5e1;">'+loc+'</dd>'+
            '<dt style="color:#94a3b8;">Status Online:</dt><dd style="margin:0;"><span class="ja-state" style="background:'+statusColor+';color:#111;font-weight:bold;padding:2px 6px;">'+statusText+'</span> ('+lastSeen+')</dd>'+
            '<dt style="color:#94a3b8;">Token Serial (Cofre):</dt><dd style="margin:0;font-family:monospace;color:#e2e8f0;"><span id="token-mask-'+did+'">'+esc(maskedToken)+'</span> '+(token?'<button type="button" class="ja-mini" style="margin-left:8px;padding:1px 6px;" onclick="toggleTokenView(\''+did+'\',\''+esc(token)+'\')">Ver</button>':'')+'</dd>'+
          '</dl>'+
        '</div>'+

        '<!-- SEÇÃO 3: TRANSMISSÃO & STREAMING -->'+
        '<div style="background:#050811;border:1px solid #1e293b;border-radius:14px;padding:16px;">'+
          '<h3 style="margin:0 0 12px;color:#c084fc;font-size:1rem;">🎥 FLUXOS & CAPTURA</h3>'+
          '<dl style="display:grid;grid-template-columns:140px 1fr;row-gap:8px;column-gap:12px;margin:0;font-size:0.88rem;">'+
            '<dt style="color:#94a3b8;">Stream de Vídeo:</dt><dd style="margin:0;font-family:monospace;word-break:break-all;color:#38bdf8;">'+esc(cam.meta?.stream_url || (cam.tipo_norm==='espcam'?'http://'+ip+':81/stream':'rtsp://'+ip+':554/stream1'))+'</dd>'+
            '<dt style="color:#94a3b8;">Snapshot (Foto):</dt><dd style="margin:0;font-family:monospace;word-break:break-all;color:#cbd5e1;">'+esc(cam.meta?.snapshot_url || '/casa/api/camera_discovery.php?acao=get_snapshot&device_id='+encodeURIComponent(did))+'</dd>'+
          '</dl>'+
          '<div style="display:flex;gap:8px;margin-top:14px;justify-content:flex-end;">'+
            '<button type="button" class="ja-btn" onclick="openCameraEditorModal(\''+attr(camId)+'\');document.getElementById(\'ja-cam-props-modal\').remove();">⚙ EDITAR CONFIGURAÇÕES</button>'+
            '<button type="button" class="ja-btn primary" onclick="openCameraStreamModal(\''+attr(camId)+'\')">▶ ABRIR STREAM AO VIVO</button>'+
          '</div>'+
        '</div>'+

      '</div>'+
    '</section>';

  document.body.appendChild(modal);
}

function toggleTokenView(did, fullToken) {
  const el = document.getElementById('token-mask-' + did);
  if (!el) return;
  if (el.textContent.includes('••')) {
    el.textContent = fullToken;
  } else {
    el.textContent = fullToken.substring(0, 6) + '••••••••••••••••' + fullToken.substring(fullToken.length - 4);
  }
}

async function refreshCameraWifiLive(camId) {
  const cam = findCamById(camId);
  if (!cam) return;
  const ip = cam.ip_address || cam.local_ip || '';
  const did = cam.device_id || camId;
  const listEl = document.getElementById('ja-cam-props-wifilist');
  if (listEl) {
    listEl.innerHTML = '<div style="color:#38bdf8;padding:12px;text-align:center;">🔄 Solicitando escaneamento de sinal de Wi-Fi da câmera em tempo real...</div>';
  }

  // Tenta requisitar da camera diretamente via LAN ou via API
  let data = null;
  if (ip) {
    try {
      const tok = cam.meta?.token || '';
      const ctrl = new AbortController();
      const t = setTimeout(() => ctrl.abort(), 3500);
      const res = await fetch('http://' + ip + '/wifi_survey?refresh=1' + (tok ? '&token=' + encodeURIComponent(tok) : ''), {
        headers: tok ? {'X-Camera-Token': tok} : {},
        signal: ctrl.signal
      });
      clearTimeout(t);
      if (res.ok) {
        data = await res.json();
      }
    } catch(e) {}
  }

  if (data && Array.isArray(data.wifis)) {
    // Atualiza cache em memoria
    if (!cam.meta) cam.meta = {};
    cam.meta.wifi_survey = data.wifis;
    cam.meta.wifi_ssid = data.connected_ssid || cam.meta.wifi_ssid;
    cam.meta.wifi_rssi = data.connected_rssi || cam.meta.wifi_rssi;
    cam.meta.wifi_updated_at = new Date().toLocaleTimeString();

    // Notifica backend
    postJson('/casa/api/camera_discovery.php?acao=report_wifi_scan', {
      device_id: did,
      ip: ip,
      wifi_conectado: data.connected_ssid,
      rssi_conectado: data.connected_rssi,
      redes_detectadas: data.wifis
    }).catch(() => {});

    openCameraPropertiesModal(camId);
  } else {
    // Se falhou medicao direta na LAN, consulta o historico mais recente da API
    try {
      const resp = await getJson('/casa/api/camera_discovery.php?acao=get_wifi_survey&device_id=' + encodeURIComponent(did) + '&limit=1');
      if (resp && resp.surveys && resp.surveys.length > 0) {
        const last = resp.surveys[0];
        if (!cam.meta) cam.meta = {};
        cam.meta.wifi_survey = last.redes_detectadas || [];
        cam.meta.wifi_ssid = last.wifi_conectado;
        cam.meta.wifi_rssi = last.rssi_conectado;
        cam.meta.wifi_updated_at = last.coletado_em;
        openCameraPropertiesModal(camId);
        return;
      }
    } catch(e) {}
    alert('Não foi possível obter resposta em tempo real da câmera em ' + (ip || 'IP indefinido') + '. Verifique se a câmera está conectada à rede local.');
    openCameraPropertiesModal(camId);
  }
}

window.openCameraPropertiesModal = openCameraPropertiesModal;
window.refreshCameraWifiLive = refreshCameraWifiLive;
window.toggleTokenView = toggleTokenView;

function setCamFilter(filter) {
  camFilter = filter;
  renderCameras();
}

function findCamById(idOrDeviceId) {
  return cachedCameras.find(c => String(c.id) === String(idOrDeviceId) || String(c.device_id) === String(idOrDeviceId)) || null;
}

// -------------------------------------------------------------
// WIZARD DE BUSCA E DETECCAO DE CAMERAS (LCARS)
// -------------------------------------------------------------
let wizardState = {
  step: 1,
  mode: 'range', // 'range' ou 'single'
  subnet: '192.168.2.',
  start: 1,
  end: 30,
  singleIp: '192.168.2.9',
  user: 'admin',
  pass: '',
  foundCameras: []
};

function openCameraDiscoveryWizard() {
  document.getElementById('ja-cam-wizard-modal')?.remove();
  wizardState.step = 1;
  wizardState.foundCameras = [];

  const modal = document.createElement('div');
  modal.id = 'ja-cam-wizard-modal';
  modal.className = 'ja-schedule-backdrop';
  modal.innerHTML =
    '<section class="ja-schedule-modal" style="width:min(820px,96vw);" role="dialog" aria-modal="true">'+
      '<header style="background:var(--ja-orange);color:#111;display:flex;justify-content:space-between;align-items:center;padding:12px 16px;">'+
        '<strong style="font-size:1.1rem;letter-spacing:1px;">🔍 ASSISTENTE DE BUSCA DE CÂMERAS (WIZARD)</strong>'+
        '<button type="button" onclick="document.getElementById(\'ja-cam-wizard-modal\').remove()" style="background:#111;color:#fff;border:0;border-radius:10px;padding:6px 12px;font-weight:bold;cursor:pointer;">FECHAR</button>'+
      '</header>'+
      '<div id="ja-wizard-content" class="ja-schedule-form" style="padding:16px;">'+
        renderWizardStep1()+
      '</div>'+
    '</section>';

  document.body.appendChild(modal);
}

function renderWizardStepper(step) {
  return '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px;text-align:center;font-size:0.8rem;font-weight:bold;">'+
    '<div style="padding:8px;border-radius:10px;background:'+(step===1?'var(--ja-orange);color:#111':'#1e293b;color:#94a3b8')+';">1. PARÂMETROS</div>'+
    '<div style="padding:8px;border-radius:10px;background:'+(step===2?'var(--ja-orange);color:#111':'#1e293b;color:#94a3b8')+';">2. VARREDURA</div>'+
    '<div style="padding:8px;border-radius:10px;background:'+(step===3?'var(--ja-orange);color:#111':'#1e293b;color:#94a3b8')+';">3. REVISÃO & TIPO</div>'+
    '<div style="padding:8px;border-radius:10px;background:'+(step===4?'var(--ja-orange);color:#111':'#1e293b;color:#94a3b8')+';">4. CONCLUÍDO</div>'+
  '</div>';
}

function renderWizardStep1() {
  return renderWizardStepper(1) +
    '<div style="background:#111;border:1px solid #334155;border-radius:14px;padding:16px;margin-bottom:16px;">'+
      '<h4 style="margin:0 0 10px;color:#38bdf8;text-transform:uppercase;">Identificação de Câmeras na Rede Local</h4>'+
      '<p style="margin:0 0 14px;color:#cbd5e1;font-size:0.9rem;line-height:1.4;">'+
        'Este assistente faz a varredura automática da rede para identificar dispositivos de vídeo e classificar seus tipos: '+
        '<strong>ESP32-CAM</strong> (MJPEG), <strong>Câmeras IP</strong> (RTSP/ONVIF) e <strong>Gravadores CFTV / DVR</strong> (Intelbras, Hikvision, Dahua).'+
      '</p>'+
      '<div style="display:flex;gap:16px;margin-bottom:14px;">'+
        '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">'+
          '<input type="radio" name="wiz-mode" value="range" '+(wizardState.mode==='range'?'checked':'')+' onchange="wizardState.mode=\'range\';document.getElementById(\'wiz-range-box\').style.display=\'block\';document.getElementById(\'wiz-single-box\').style.display=\'none\';">'+
          '<span>Varredura por Faixa de IP</span>'+
        '</label>'+
        '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">'+
          '<input type="radio" name="wiz-mode" value="single" '+(wizardState.mode==='single'?'checked':'')+' onchange="wizardState.mode=\'single\';document.getElementById(\'wiz-range-box\').style.display=\'none\';document.getElementById(\'wiz-single-box\').style.display=\'block\';">'+
          '<span>Sondar IP Específico</span>'+
        '</label>'+
      '</div>'+
      '<div id="wiz-range-box" style="display:'+(wizardState.mode==='range'?'block':'none')+';">'+
        '<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;margin-bottom:12px;">'+
          '<label><span>Sub-rede</span><input id="wiz-subnet" type="text" value="'+esc(wizardState.subnet)+'" placeholder="192.168.2."></label>'+
          '<label><span>IP Inicial</span><input id="wiz-start" type="number" min="1" max="254" value="'+wizardState.start+'"></label>'+
          '<label><span>IP Final</span><input id="wiz-end" type="number" min="1" max="254" value="'+wizardState.end+'"></label>'+
        '</div>'+
        '<div style="display:flex;gap:6px;margin-bottom:12px;">'+
          '<button type="button" class="ja-mini" onclick="document.getElementById(\'wiz-start\').value=1;document.getElementById(\'wiz-end\').value=30;">Faixa Rápida (1-30)</button>'+
          '<button type="button" class="ja-mini" onclick="document.getElementById(\'wiz-start\').value=1;document.getElementById(\'wiz-end\').value=100;">Faixa Ampla (1-100)</button>'+
          '<button type="button" class="ja-mini" onclick="document.getElementById(\'wiz-start\').value=1;document.getElementById(\'wiz-end\').value=254;">Rede Completa (1-254)</button>'+
        '</div>'+
      '</div>'+
      '<div id="wiz-single-box" style="display:'+(wizardState.mode==='single'?'block':'none')+';margin-bottom:12px;">'+
        '<label><span>Endereço IP do Dispositivo / Câmera</span><input id="wiz-single-ip" type="text" value="'+esc(wizardState.singleIp)+'" placeholder="Ex: 192.168.2.9"></label>'+
      '</div>'+
      '<div style="background:#0a0f1d;border:1px solid #1e293b;border-radius:10px;padding:12px;margin-bottom:12px;">'+
        '<strong style="font-size:0.85rem;color:#94a3b8;display:block;margin-bottom:8px;">Protocolos e Portas Verificados:</strong>'+
        '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:0.85rem;color:#cbd5e1;">'+
          '<div>✔ <strong>ESP32-CAM:</strong> Portas 80, 81</div>'+
          '<div>✔ <strong>Câmeras IP:</strong> RTSP (554), ONVIF (8899)</div>'+
          '<div>✔ <strong>CFTV / DVR:</strong> 554, 8000 (Hik), 37777 (Intelbras)</div>'+
        '</div>'+
      '</div>'+
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">'+
        '<label><span>Usuário padrão para teste</span><input id="wiz-user" type="text" value="'+esc(wizardState.user)+'" placeholder="admin"></label>'+
        '<label><span>Senha padrão (opcional)</span><input id="wiz-pass" type="password" value="'+esc(wizardState.pass)+'" placeholder="Senha do stream"></label>'+
      '</div>'+
    '</div>'+
    '<div style="display:flex;justify-content:flex-end;gap:10px;">'+
      '<button type="button" class="ja-btn" onclick="document.getElementById(\'ja-cam-wizard-modal\').remove()">CANCELAR</button>'+
      '<button type="button" class="ja-btn primary" onclick="startCameraWizardScan()">🔍 INICIAR VARREDURA</button>'+
    '</div>';
}

async function startCameraWizardScan() {
  wizardState.mode = document.querySelector('input[name="wiz-mode"]:checked')?.value || 'range';
  if (wizardState.mode === 'range') {
    wizardState.subnet = document.getElementById('wiz-subnet')?.value || '192.168.2.';
    wizardState.start = parseInt(document.getElementById('wiz-start')?.value || '1', 10);
    wizardState.end = parseInt(document.getElementById('wiz-end')?.value || '30', 10);
  } else {
    wizardState.singleIp = document.getElementById('wiz-single-ip')?.value?.trim() || '';
  }
  wizardState.user = document.getElementById('wiz-user')?.value || 'admin';
  wizardState.pass = document.getElementById('wiz-pass')?.value || '';

  const content = document.getElementById('ja-wizard-content');
  if (!content) return;

  wizardState.step = 2;
  content.innerHTML = renderWizardStepper(2) +
    '<div style="background:#111;border:1px solid #334155;border-radius:14px;padding:32px 16px;text-align:center;">'+
      '<div style="font-size:2.8rem;margin-bottom:12px;animation:ja-pulse 1.5s infinite;">📡</div>'+
      '<h3 style="margin:0 0 8px;color:#38bdf8;">VARREDURA DE REDE EM EXECUÇÃO...</h3>'+
      '<p id="wiz-scan-status" style="margin:0 0 16px;color:#cbd5e1;font-size:0.95rem;">Sondando portas de vídeo (80, 81, 554, 8000, 37777, 8899)...</p>'+
      '<div style="width:100%;height:10px;background:#1e293b;border-radius:6px;overflow:hidden;margin:0 auto 16px;max-width:500px;">'+
        '<div id="wiz-progress-bar" style="width:15%;height:100%;background:var(--ja-orange);transition:width 0.3s;"></div>'+
      '</div>'+
      '<small style="color:#64748b;">Aguarde alguns instantes enquanto os dispositivos respondem...</small>'+
    '</div>';

  const pBar = document.getElementById('wiz-progress-bar');
  const pStatus = document.getElementById('wiz-scan-status');

  try {
    let cameras = [];
    if (wizardState.mode === 'single') {
      if (pBar) pBar.style.width = '60%';
      const resp = await postJson('/casa/api/camera_discovery.php?acao=probe_ip', {
        ip: wizardState.singleIp,
        user: wizardState.user,
        pass: wizardState.pass
      });
      if (resp && resp.ok && resp.is_camera && resp.camera) {
        cameras.push(resp.camera);
      }
    } else {
      if (pBar) pBar.style.width = '45%';
      // Chama backend scan
      try {
        const resp = await postJson('/casa/api/camera_discovery.php?acao=scan', {
          subnet: wizardState.subnet,
          start: wizardState.start,
          end: wizardState.end,
          user: wizardState.user,
          pass: wizardState.pass
        });
        if (resp && resp.ok && Array.isArray(resp.cameras)) {
          cameras = resp.cameras;
        }
      } catch(errScan) {
        console.warn('Scan backend falhou, tentando fallback:', errScan);
      }

      // Probing client-side fallback caso scan backend tenha retornado vazio
      if (!cameras.length && wizardState.subnet.startsWith('192.168.2.')) {
        if (pStatus) pStatus.textContent = 'Verificando nós do cluster e dispositivos ativos conhecidos...';
        // Checa especificamente IPs conhecidos da sub-rede local
        const knownTestIps = ['192.168.2.9', '192.168.2.13', '192.168.2.14', '192.168.2.16'];
        for (const testIp of knownTestIps) {
          try {
            const probe = await postJson('/casa/api/camera_discovery.php?acao=probe_ip', {
              ip: testIp,
              user: wizardState.user,
              pass: wizardState.pass
            });
            if (probe && probe.ok && probe.is_camera && probe.camera) {
              cameras.push(probe.camera);
            }
          } catch(e) {}
        }
      }
    }

    if (pBar) pBar.style.width = '100%';
    wizardState.foundCameras = cameras;

    setTimeout(() => {
      renderWizardStep3();
    }, 600);

  } catch(e) {
    if (content) {
      content.innerHTML = renderWizardStepper(2) +
        '<div class="ja-empty" style="padding:24px;text-align:center;">'+
          '<p style="color:#ef4444;font-size:1.1rem;margin-bottom:12px;">Falha na execução da varredura: '+esc(e.message||'Erro de rede')+'</p>'+
          '<button class="ja-btn" onclick="openCameraDiscoveryWizard()">VOLTAR E TENTAR NOVAMENTE</button>'+
        '</div>';
    }
  }
}

function renderWizardStep3() {
  const content = document.getElementById('ja-wizard-content');
  if (!content) return;
  wizardState.step = 3;

  if (!wizardState.foundCameras.length) {
    content.innerHTML = renderWizardStepper(3) +
      '<div style="background:#111;border:1px solid #334155;border-radius:14px;padding:24px;text-align:center;">'+
        '<h3 style="color:#f59e0b;margin:0 0 10px;">NENHUMA NOVA CÂMERA DETECTADA NA FAIXA</h3>'+
        '<p style="color:#cbd5e1;font-size:0.95rem;margin:0 0 16px;">'+
          'Não encontramos portas ativas de vídeo na faixa pesquisada ('+wizardState.subnet+wizardState.start+' - '+wizardState.end+').'+
        '</p>'+
        '<div style="display:flex;justify-content:center;gap:10px;">'+
          '<button class="ja-btn" onclick="openCameraDiscoveryWizard()">RECONFIGURAR BUSCA</button>'+
          '<button class="ja-btn primary" onclick="openCameraEditorModal()">+ ADICIONAR MANUALMENTE</button>'+
        '</div>'+
      '</div>';
    return;
  }

  const itemsHtml = wizardState.foundCameras.map((cam, idx) => {
    const ip = esc(cam.ip);
    const ports = esc((cam.portas_abertas||[]).join(', '));
    const suggestedName = esc(cam.tipo_label + ' (' + ip + ')');
    const stream = esc(cam.stream_url||'');
    const snapshot = esc(cam.snapshot_url||'');
    const canal = cam.canal || 1;

    return '<div class="ja-wizard-cam-item" style="background:#0b1120;border:1px solid #1e293b;border-radius:12px;padding:14px;margin-bottom:12px;">'+
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">'+
        '<label style="display:flex;align-items:center;gap:8px;font-size:1rem;font-weight:bold;cursor:pointer;">'+
          '<input type="checkbox" id="wiz-chk-'+idx+'" checked>'+
          '<span style="color:#38bdf8;">'+ip+'</span>'+
          '<span style="font-size:0.8rem;background:#1e293b;padding:3px 8px;border-radius:6px;color:#94a3b8;">Portas: '+ports+'</span>'+
        '</label>'+
        '<div style="font-size:0.85rem;color:#cbd5e1;">Fabricante provável: <strong>'+esc(cam.fabricante||'Genérico')+'</strong></div>'+
      '</div>'+
      '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;">'+
        '<label><span>Nome da Câmera</span><input type="text" id="wiz-name-'+idx+'" value="'+suggestedName+'"></label>'+
        '<label><span>Tipo de Câmera</span>'+
          '<select id="wiz-type-'+idx+'">'+
            '<option value="espcam" '+(cam.tipo==='espcam'?'selected':'')+'>📷 ESP32-CAM (MJPEG)</option>'+
            '<option value="ip_camera" '+(cam.tipo==='ip_camera'?'selected':'')+'>🌐 Câmera IP (RTSP/ONVIF)</option>'+
            '<option value="cftv" '+(cam.tipo==='cftv'?'selected':'')+'>📼 CFTV / DVR / NVR</option>'+
          '</select>'+
        '</label>'+
        '<label><span>Localização</span>'+
          '<input type="text" id="wiz-loc-'+idx+'" value="Residência" placeholder="Ex: Entrada, Garagem, Sala">'+
        '</label>'+
      '</div>'+
      '<div style="display:grid;grid-template-columns:1fr 3fr;gap:10px;">'+
        '<label><span>Canal (CFTV)</span><input type="number" id="wiz-channel-'+idx+'" min="1" max="64" value="'+canal+'"></label>'+
        '<label><span>URL de Stream (RTSP / HTTP)</span><input type="text" id="wiz-stream-'+idx+'" value="'+stream+'"></label>'+
      '</div>'+
    '</div>';
  }).join('');

  content.innerHTML = renderWizardStepper(3) +
    '<div style="background:#111;border:1px solid #334155;border-radius:14px;padding:16px;margin-bottom:16px;">'+
      '<h4 style="margin:0 0 8px;color:#38bdf8;text-transform:uppercase;">Câmeras Detectadas ('+wizardState.foundCameras.length+')</h4>'+
      '<p style="margin:0 0 14px;color:#cbd5e1;font-size:0.9rem;">'+
        'Selecione as câmeras que deseja cadastrar no CASA. Você pode ajustar o nome, tipo, canal e URLs de stream sugeridas antes de salvar.'+
      '</p>'+
      itemsHtml+
    '</div>'+
    '<div style="display:flex;justify-content:space-between;align-items:center;">'+
      '<button type="button" class="ja-btn" onclick="openCameraDiscoveryWizard()">VOLTAR</button>'+
      '<button type="button" class="ja-btn primary" onclick="saveWizardCameras()">✔ ADICIONAR CÂMERAS AO CASA</button>'+
    '</div>';
}

async function saveWizardCameras() {
  const content = document.getElementById('ja-wizard-content');
  if (!content) return;

  const toSave = [];
  wizardState.foundCameras.forEach((cam, idx) => {
    const chk = document.getElementById('wiz-chk-' + idx);
    if (chk && chk.checked) {
      const name = document.getElementById('wiz-name-' + idx)?.value?.trim() || cam.nome || ('Câmera ' + cam.ip);
      const tipo = document.getElementById('wiz-type-' + idx)?.value || cam.tipo;
      const loc = document.getElementById('wiz-loc-' + idx)?.value?.trim() || 'Residência';
      const canal = parseInt(document.getElementById('wiz-channel-' + idx)?.value || '1', 10);
      const stream = document.getElementById('wiz-stream-' + idx)?.value?.trim() || cam.stream_url;

      toSave.push({
        ip_address: cam.ip,
        nome: name,
        tipo: tipo,
        localizacao: loc,
        canal: canal,
        stream_url: stream,
        snapshot_url: cam.snapshot_url || '',
        modelo: cam.modelo || '',
        fabricante: cam.fabricante || '',
        user: wizardState.user,
        pass: wizardState.pass,
        web_port: cam.web_port || 80
      });
    }
  });

  if (!toSave.length) {
    alert('Selecione pelo menos uma câmera para salvar.');
    return;
  }

  content.innerHTML = renderWizardStepper(4) +
    '<div style="background:#111;border:1px solid #334155;border-radius:14px;padding:32px 16px;text-align:center;">'+
      '<div style="font-size:2.8rem;margin-bottom:12px;">💾</div>'+
      '<h3 style="margin:0 0 8px;color:#38bdf8;">SALVANDO CÂMERAS NO CLUSTER...</h3>'+
      '<p style="margin:0;color:#cbd5e1;">Gravando registros e atualizando catálogo de vídeo do JARVIS...</p>'+
    '</div>';

  try {
    const resp = await postJson('/casa/api/camera_discovery.php?acao=salvar_cameras', { cameras: toSave });
    content.innerHTML = renderWizardStepper(4) +
      '<div style="background:#111;border:1px solid #334155;border-radius:14px;padding:32px 16px;text-align:center;">'+
        '<div style="font-size:2.8rem;margin-bottom:12px;">🎉</div>'+
        '<h3 style="margin:0 0 8px;color:#34d399;">CÂMERAS CADASTRADAS COM SUCESSO!</h3>'+
        '<p style="margin:0 0 16px;color:#cbd5e1;font-size:0.95rem;">'+
          esc(resp.mensagem || (toSave.length + ' câmera(s) foram adicionadas ao sistema.'))+
        '</p>'+
        '<button class="ja-btn primary" onclick="document.getElementById(\'ja-cam-wizard-modal\').remove();renderCameras();">VER CÂMERAS NO PAINEL</button>'+
      '</div>';
  } catch(e) {
    content.innerHTML = renderWizardStepper(4) +
      '<div class="ja-empty" style="padding:24px;text-align:center;">'+
        '<p style="color:#ef4444;font-size:1.1rem;margin-bottom:12px;">Falha ao salvar câmeras: '+esc(e.message||'Erro')+'</p>'+
        '<button class="ja-btn" onclick="renderWizardStep3()">VOLTAR E TENTAR NOVAMENTE</button>'+
      '</div>';
  }
}

// -------------------------------------------------------------
// MODAL DE STREAM / VISUALIZACAO (MJPEG ou RTSP)
// -------------------------------------------------------------
function openCameraStreamModal(camId) {
  const cam = findCamById(camId);
  if (!cam) return;

  document.getElementById('ja-cam-stream-modal')?.remove();
  const ip = cam.ip_address || cam.local_ip || '';
  const name = esc(cam.nome || cam.device_id);
  const streamUrl = cam.meta?.stream_url || (cam.tipo_norm==='espcam' ? 'http://'+ip+':81/stream' : 'rtsp://'+ip+':554/stream1');
  const webPort = cam.meta?.web_port || 80;

  const isMjpeg = cam.tipo_norm === 'espcam' || streamUrl.startsWith('http');

  let playerHtml = '';
  if (isMjpeg) {
    playerHtml =
      '<div style="background:#000;border-radius:12px;overflow:hidden;text-align:center;margin-bottom:14px;min-height:260px;display:flex;align-items:center;justify-content:center;">'+
        '<img src="'+esc(streamUrl)+'" alt="'+name+'" style="max-width:100%;max-height:480px;object-fit:contain;" onerror="this.onerror=null;this.src=\'http://'+esc(ip)+'/stream\';">'+
      '</div>'+
      '<div style="display:flex;justify-content:space-between;align-items:center;">'+
        '<small style="color:#94a3b8;">Stream HTTP MJPEG ao vivo</small>'+
        '<button class="ja-mini" onclick="window.open(\''+esc(streamUrl)+'\',\'_blank\')">ABRIR EM NOVA ABA</button>'+
      '</div>';
  } else {
    // RTSP Stream (Camera IP / CFTV)
    playerHtml =
      '<div style="background:#0b1120;border:1px solid #1e293b;border-radius:14px;padding:16px;margin-bottom:14px;">'+
        '<h4 style="margin:0 0 10px;color:#38bdf8;">Fluxo de Vídeo RTSP (H.264 / H.265)</h4>'+
        '<p style="margin:0 0 12px;color:#cbd5e1;font-size:0.9rem;line-height:1.4;">'+
          'Navegadores web modernos requerem um cliente ou reprodutor compatível (como VLC, OBS Studio, aplicativo mobile ou proxy WebRTC/HLS) para decodificar fluxos diretos RTSP.'+
        '</p>'+
        '<div style="background:#050811;border:1px solid #38bdf8;border-radius:10px;padding:12px;margin-bottom:12px;word-break:break-all;font-family:monospace;font-size:0.9rem;color:#38bdf8;">'+
          esc(streamUrl)+
        '</div>'+
        '<div style="display:flex;gap:8px;flex-wrap:wrap;">'+
          '<button class="ja-btn primary" onclick="navigator.clipboard.writeText(\''+esc(streamUrl)+'\').then(()=>alert(\'URL RTSP copiada para a área de transferência!\'))">📋 COPIAR LINK RTSP</button>'+
          '<button class="ja-btn" onclick="window.open(\'http://'+esc(ip)+':'+webPort+'/\',\'_blank\')">🌐 ABRIR PAINEL WEB DA CÂMERA</button>'+
          '<button class="ja-btn" onclick="openCameraSnapshotModal(\''+attr(camId)+'\')">📸 VER FOTO SNAPSHOT</button>'+
        '</div>'+
      '</div>';
  }

  const modal = document.createElement('div');
  modal.id = 'ja-cam-stream-modal';
  modal.className = 'ja-schedule-backdrop';
  modal.innerHTML =
    '<section class="ja-schedule-modal" style="width:min(780px,96vw);" role="dialog">'+
      '<header style="background:var(--ja-orange);color:#111;display:flex;justify-content:space-between;align-items:center;padding:12px 16px;">'+
        '<strong style="font-size:1.05rem;">▶ STREAM: '+name+'</strong>'+
        '<button type="button" onclick="document.getElementById(\'ja-cam-stream-modal\').remove()" style="background:#111;color:#fff;border:0;border-radius:10px;padding:6px 12px;font-weight:bold;cursor:pointer;">FECHAR</button>'+
      '</header>'+
      '<div style="padding:16px;">'+
        playerHtml+
      '</div>'+
    '</section>';

  document.body.appendChild(modal);
}

// -------------------------------------------------------------
// MODAL DE SNAPSHOT / FOTO COM IA
// -------------------------------------------------------------
function openCameraSnapshotModal(camId) {
  const cam = findCamById(camId);
  if (!cam) return;

  document.getElementById('ja-cam-snapshot-modal')?.remove();
  const ip = cam.ip_address || cam.local_ip || '';
  const name = esc(cam.nome || cam.device_id);
  const did = cam.device_id || camId;
  const cloudSnapUrl = '/casa/api/camera_discovery.php?acao=get_snapshot&device_id=' + encodeURIComponent(did);
  const localSnapUrl = cam.meta?.snapshot_url || (cam.tipo_norm==='espcam' ? 'http://'+ip+'/capture' : 'http://'+ip+'/snapshot.jpg');
  const snapUrl = cloudSnapUrl;
  const cacheBustUrl = snapUrl + (snapUrl.includes('?') ? '&' : '?') + 't=' + Date.now();

  const modal = document.createElement('div');
  modal.id = 'ja-cam-snapshot-modal';
  modal.className = 'ja-schedule-backdrop';
  modal.innerHTML =
    '<section class="ja-schedule-modal" style="width:min(720px,96vw);" role="dialog">'+
      '<header style="background:var(--ja-orange);color:#111;display:flex;justify-content:space-between;align-items:center;padding:12px 16px;">'+
        '<strong style="font-size:1.05rem;">📸 SNAPSHOT: '+name+'</strong>'+
        '<button type="button" onclick="document.getElementById(\'ja-cam-snapshot-modal\').remove()" style="background:#111;color:#fff;border:0;border-radius:10px;padding:6px 12px;font-weight:bold;cursor:pointer;">FECHAR</button>'+
      '</header>'+
      '<div style="padding:16px;text-align:center;">'+
        '<div style="background:#000;border-radius:12px;overflow:hidden;margin-bottom:14px;min-height:240px;display:flex;align-items:center;justify-content:center;">'+
          '<img id="ja-snap-img" src="'+esc(cacheBustUrl)+'" alt="'+name+'" style="max-width:100%;max-height:450px;object-fit:contain;" onerror="this.onerror=null;this.parentElement.innerHTML=\'<p style=padding:20px;color:#ef4444;>Não foi possível carregar o snapshot direto. Verifique se a câmera permite requisições HTTP locais na porta.</p>\';">'+
        '</div>'+
        '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">'+
          '<button class="ja-btn primary" onclick="const img=document.getElementById(\'ja-snap-img\');if(img)img.src=\''+esc(snapUrl)+'\'+\'?t=\'+Date.now();">🔄 RECARREGAR FOTO</button>'+
          '<button class="ja-btn" onclick="window.open(\''+esc(snapUrl)+'\',\'_blank\')">💾 ABRIR ORIGINAL</button>'+
          '<button class="ja-btn" onclick="postJson(\'/casa/api/jarvis.php\',{comando:\'/cloud Analise a imagem da câmera '+name+'\'}).then(()=>alert(\'Análise solicitada ao Jarvis!\'))">🤖 ANALISAR COM IA</button>'+
        '</div>'+
      '</div>'+
    '</section>';

  document.body.appendChild(modal);
}

// -------------------------------------------------------------
// MODAL DE CADASTRO / EDICAO MANUAL DE CAMERA
// -------------------------------------------------------------
function openCameraEditorModal(camId = null) {
  document.getElementById('ja-cam-edit-modal')?.remove();
  const cam = camId ? findCamById(camId) : null;
  const isEdit = !!cam;

  const id = cam?.id || '';
  const did = cam?.device_id || ('cam-' + Date.now().toString(36));
  const nome = cam?.nome || '';
  const tipo = cam?.tipo_norm || 'ip_camera';
  const ip = cam?.ip_address || cam?.local_ip || '';
  const loc = cam?.localizacao || 'Residência';
  const canal = cam?.meta?.canal || 1;
  const stream = cam?.meta?.stream_url || '';
  const snap = cam?.meta?.snapshot_url || '';
  const user = cam?.meta?.user || 'admin';
  const pass = cam?.meta?.pass || '';

  const modal = document.createElement('div');
  modal.id = 'ja-cam-edit-modal';
  modal.className = 'ja-schedule-backdrop';
  modal.innerHTML =
    '<section class="ja-schedule-modal" style="width:min(680px,96vw);" role="dialog">'+
      '<header style="background:var(--ja-orange);color:#111;display:flex;justify-content:space-between;align-items:center;padding:12px 16px;">'+
        '<strong style="font-size:1.05rem;">'+(isEdit?'⚙ EDITAR CÂMERA':'➕ ADICIONAR NOVA CÂMERA')+'</strong>'+
        '<button type="button" onclick="document.getElementById(\'ja-cam-edit-modal\').remove()" style="background:#111;color:#fff;border:0;border-radius:10px;padding:6px 12px;font-weight:bold;cursor:pointer;">FECHAR</button>'+
      '</header>'+
      '<div class="ja-schedule-form" style="padding:16px;">'+
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">'+
          '<label><span>Nome da Câmera</span><input id="ce-nome" type="text" value="'+esc(nome)+'" placeholder="Ex: Câmera Portão"></label>'+
          '<label><span>Tipo de Câmera</span>'+
            '<select id="ce-tipo">'+
              '<option value="espcam" '+(tipo==='espcam'?'selected':'')+'>📷 ESP32-CAM (MJPEG Stream)</option>'+
              '<option value="ip_camera" '+(tipo==='ip_camera'?'selected':'')+'>🌐 Câmera IP (RTSP/ONVIF)</option>'+
              '<option value="cftv" '+(tipo==='cftv'?'selected':'')+'>📼 CFTV / DVR / NVR Multi-Canal</option>'+
            '</select>'+
          '</label>'+
        '</div>'+
        '<div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:12px;">'+
          '<label><span>Endereço IP / Host</span><input id="ce-ip" type="text" value="'+esc(ip)+'" placeholder="192.168.2.X"></label>'+
          '<label><span>Localização</span><input id="ce-loc" type="text" value="'+esc(loc)+'" placeholder="Garagem, Entrada..."></label>'+
        '</div>'+
        '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">'+
          '<label><span>Canal (CFTV)</span><input id="ce-canal" type="number" min="1" max="64" value="'+canal+'"></label>'+
          '<label><span>Usuário Stream</span><input id="ce-user" type="text" value="'+esc(user)+'"></label>'+
          '<label><span>Senha Stream</span><input id="ce-pass" type="password" value="'+esc(pass)+'"></label>'+
        '</div>'+
        '<div style="margin-bottom:12px;">'+
          '<label><span>URL de Stream (RTSP ou HTTP)</span><input id="ce-stream" type="text" value="'+esc(stream)+'" placeholder="rtsp://admin:senha@192.168.2.X:554/..."></label>'+
        '</div>'+
        '<div style="margin-bottom:16px;">'+
          '<label><span>URL de Snapshot (Foto)</span><input id="ce-snap" type="text" value="'+esc(snap)+'" placeholder="http://192.168.2.X/capture"></label>'+
        '</div>'+
        '<div style="display:flex;justify-content:space-between;align-items:center;">'+
          '<div>'+
            (isEdit ? '<button type="button" class="ja-btn danger" onclick="deleteCamera(\''+attr(camId)+'\');document.getElementById(\'ja-cam-edit-modal\').remove();">EXCLUIR</button>' : '')+
          '</div>'+
          '<div style="display:flex;gap:10px;">'+
            '<button type="button" class="ja-btn" onclick="document.getElementById(\'ja-cam-edit-modal\').remove()">CANCELAR</button>'+
            '<button type="button" class="ja-btn primary" onclick="saveCameraEditor(\''+attr(id)+'\',\''+attr(did)+'\')">SALVAR CÂMERA</button>'+
          '</div>'+
        '</div>'+
      '</div>'+
    '</section>';

  document.body.appendChild(modal);
}

async function saveCameraEditor(id, deviceId) {
  const nome = document.getElementById('ce-nome')?.value?.trim();
  const tipo = document.getElementById('ce-tipo')?.value;
  const ip = document.getElementById('ce-ip')?.value?.trim();
  const loc = document.getElementById('ce-loc')?.value?.trim() || 'Residência';
  const canal = parseInt(document.getElementById('ce-canal')?.value || '1', 10);
  const user = document.getElementById('ce-user')?.value || '';
  const pass = document.getElementById('ce-pass')?.value || '';
  let stream = document.getElementById('ce-stream')?.value?.trim();
  let snap = document.getElementById('ce-snap')?.value?.trim();

  if (!ip) {
    alert('O endereço IP da câmera é obrigatório.');
    return;
  }

  // Gera stream padrao se vazio
  if (!stream) {
    if (tipo === 'espcam') stream = 'http://' + ip + ':81/stream';
    else if (tipo === 'cftv') stream = 'rtsp://' + (user ? user + (pass ? ':' + pass : '') + '@' : '') + ip + ':554/cam/realmonitor?channel=' + canal + '&subtype=0';
    else stream = 'rtsp://' + (user ? user + (pass ? ':' + pass : '') + '@' : '') + ip + ':554/stream1';
  }
  if (!snap && tipo === 'espcam') {
    snap = 'http://' + ip + '/capture';
  }

  const payload = {
    id: id ? parseInt(id, 10) : undefined,
    device_id: deviceId || ('cam-' + ip.replace(/\./g, '-')),
    nome: nome || ('Câmera ' + ip),
    tipo: tipo,
    ip_address: ip,
    localizacao: loc,
    canal: canal,
    stream_url: stream,
    snapshot_url: snap,
    user: user,
    pass: pass
  };

  try {
    const resp = await postJson('/casa/api/camera_discovery.php?acao=salvar', payload);
    document.getElementById('ja-cam-edit-modal')?.remove();
    renderCameras();
  } catch(e) {
    alert('Erro ao salvar câmera: ' + (e.message || 'Falha de rede'));
  }
}

async function deleteCamera(camId) {
  const cam = findCamById(camId);
  const name = cam?.nome || camId;
  if (!confirm('Deseja realmente excluir a câmera "' + name + '" do CASA?')) {
    return;
  }
  try {
    await postJson('/casa/api/camera_discovery.php?acao=excluir', {
      id: cam?.id || undefined,
      device_id: cam?.device_id || camId
    });
    renderCameras();
  } catch(e) {
    alert('Erro ao excluir câmera: ' + (e.message || 'Falha'));
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
  if(action==='home' || action==='back' || action==='dashboard') return home();
  if(action==='open-groups-overview') return renderGroupsMenu();
  if(action==='open-current-group' && currentGroup) return openGroup(currentGroup);
  if(action.startsWith('open-direct:')){
    const parts = action.split(':');
    const found = locateItem(parts[2]);
    const grp = found ? found.group : resolveGroup(parts[1]);
    if(grp){
      openGroup(grp, false);
      if(found) openItem(found.item);
    }
    return;
  }
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
  box.innerHTML='<div class="ja-admin-title">SESSÃO</div>';

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
  const params=new URLSearchParams(location.search);
  const g=params.get('grupo');
  const itemId=params.get('item');
  suppressHistory=true;
  if(itemId==='dashboard' || g==='dashboard' || g==='DASHBOARD' || (!g && !itemId)){
    home(false);
  } else if(itemId && locateItem(itemId)){
    // O item decide a area: ids antigos e grupos renomeados caem no lugar novo.
    const found=locateItem(itemId);
    openGroup(found.group,false);
    openItem(found.item,false);
  } else if(g && resolveGroup(g)){
    openGroup(resolveGroup(g),false);
  } else {
    home(false);
  }
  suppressHistory=false;
}

function bootLCARS(){
  const app=document.getElementById('app');
  if(!app) return;
  app.addEventListener('ja:action',e=>{handleAction(e.detail||{});scheduleAdminMount();});
  const observer=new MutationObserver(()=>scheduleAdminMount());
  observer.observe(app,{childList:true,subtree:true});
  restoreRoute();
  scheduleAdminMount();
}

if(document.readyState==='loading'){
  document.addEventListener('DOMContentLoaded',bootLCARS);
} else {
  bootLCARS();
}
window.addEventListener('popstate',restoreRoute);
window.addEventListener('resize',()=>{const f=document.querySelector('.ja-module-frame');if(f)fitFrame(f);});
// Expor funcoes globais para acoes de eventos onclick nos templates HTML do LCARS
window.renderCameras = renderCameras;
window.setCamFilter = setCamFilter;
window.openCameraStreamModal = openCameraStreamModal;
window.openCameraSnapshotModal = openCameraSnapshotModal;
window.openCameraEditorModal = openCameraEditorModal;
window.saveCameraEditor = saveCameraEditor;
window.deleteCamera = deleteCamera;
window.openCameraDiscoveryWizard = openCameraDiscoveryWizard;
window.startCameraWizardScan = startCameraWizardScan;
window.saveWizardCameras = saveWizardCameras;
window.renderWizardStep1 = renderWizardStep1;
window.renderWizardStep3 = renderWizardStep3;
window.postJson = postJson;
window.getJson = getJson;
window.findCamById = findCamById;

window.CASASite={home,openGroup,openItem,changePassword,groups:GROUPS,openCameraSnapshotModal,openCameraStreamModal,openCameraEditorModal,renderCameras};
})();