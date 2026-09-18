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
        {id:'seguranca',label:'Defesa & anti-intrusão',tab:'tab-seguranca'},
        {id:'cameras',label:'Câmeras & visão',tab:'tab-cameras'},
        {id:'acessos',label:'Acessos pessoais',url:'/casa/dispositivos_pessoais.php'},
        {id:'familia',label:'Família & presença',url:'/casa/familia.php'}
      ]},
      {title:'EVENTOS',items:[
        {id:'sensores-sec',label:'Sensores & telemetria',tab:'tab-sensores'},
        {id:'api-sec',label:'Acesso web & API segura',tab:'tab-externo'}
      ]}
    ]
  },
  'OPERAÇÕES':{
    subtitle:'Rotinas, agenda, ambientes e execução diária.',
    sections:[
      {title:'ROTINAS',items:[
        {id:'agendamentos',label:'Agendamentos',tab:'tab-agendamentos'},
        {id:'devices-op',label:'Dispositivos & relés',tab:'tab-devices'},
        {id:'iot-op',label:'Cluster IoT',tab:'tab-iot'}
      ]},
      {title:'MONITORAMENTO',items:[
        {id:'sensores-op',label:'Sensores',tab:'tab-sensores'},
        {id:'cameras-op',label:'Câmeras',tab:'tab-cameras'},
        {id:'historico-op',label:'Frases & avisos',tab:'tab-frases'}
      ]}
    ]
  },
  'DISPOSITIVOS':{
    subtitle:'Inventário, nós, IoT, celular, relógio e equipamentos.',
    sections:[
      {title:'EQUIPAMENTOS',items:[
        {id:'devices',label:'Dispositivos & relés',tab:'tab-devices'},
        {id:'iot',label:'ESP32 / Arduino / IoT',tab:'tab-iot'},
        {id:'nodes',label:'Cluster ARM',tab:'tab-nodes'}
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
        {id:'automation-dev',label:'Dispositivos & relés',tab:'tab-devices'},
        {id:'automation-ag',label:'Agendamentos',tab:'tab-agendamentos'},
        {id:'automation-iot',label:'Cluster IoT',tab:'tab-iot'}
      ]},
      {title:'INTEGRAÇÃO',items:[
        {id:'automation-agents',label:'Agentes externos',tab:'tab-agentes'},
        {id:'automation-api',label:'Acesso Web & API',tab:'tab-externo'}
      ]}
    ]
  },
  'IA & VOZ':{
    subtitle:'JARVIS, reconhecimento, voz, RunPod e agentes.',
    sections:[
      {title:'JARVIS',items:[
        {id:'jarvis',label:'Núcleo de reconhecimento & voz',tab:'tab-jarvis'},
        {id:'frases',label:'Frases & avisos',tab:'tab-frases'},
        {id:'agentes',label:'Agentes externos',tab:'tab-agentes'}
      ]},
      {title:'INTELIGÊNCIA',items:[
        {id:'modelos-ia',label:'Conexões & Modelos de IA',url:'/casa/ia_modelos.php'},
        {id:'runpod',label:'Configuração IA legada',tab:'tab-config'},
        {id:'webapi',label:'Acesso Web & API segura',tab:'tab-externo'}
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
        {id:'users',label:'Usuários & senhas',tab:'tab-usuarios'}
      ]}
    ]
  },
  'SISTEMA':{
    subtitle:'Infraestrutura, telemetria, configuração e diagnóstico.',
    sections:[
      {title:'INFRAESTRUTURA',items:[
        {id:'nodes-sys',label:'Cluster ARM',tab:'tab-nodes'},
        {id:'sensors-sys',label:'Sensores & telemetria',tab:'tab-sensores'},
        {id:'iot-sys',label:'Cluster IoT',tab:'tab-iot'}
      ]},
      {title:'ADMINISTRAÇÃO',items:[
        {id:'users-sys',label:'Usuários & senhas',tab:'tab-usuarios'},
        {id:'config-sys',label:'Configurações RunPod / IA',tab:'tab-config'},
        {id:'api-sys',label:'Acesso Web & API',tab:'tab-externo'},
        {id:'legacy',label:'Painel legado completo',url:'/casa/index_legacy.php'}
      ]}
    ]
  }
};

let currentGroup=null;
let currentItem=null;
let suppressHistory=false;

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
    items:topGroupCards().map(g=>({title:g.title,description:g.description,status:'ABRIR',actions:[{label:'ENTRAR',action:'open-group:'+g.id,variant:'primary'}]})),
    footer:{hint:'Os grupos são pequenos; menus grandes são divididos em colunas.'}
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
    layout:'menu-grid',kind:'menu',group, title:group, subtitle:GROUPS[group].subtitle,
    breadcrumb:['CASA','GRUPOS',group],groups:groupRail(group),status:STATUS,sections:GROUPS[group].sections,
    footer:{hint:'Escolha uma opção à esquerda ou no miolo. Use ← GRUPOS para voltar.'}
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
    items:[{title:item.label,description:'Módulo funcional do CASA carregado no painel central.'}],
    footer:{hint:'Use ← GRUPOS para trocar de grupo ou escolha outra opção à esquerda.'}
  });
  if(updateRoute) setRoute(currentGroup,item.id);
  mountModule(item);
}

function sessionExpiredInFrame(frame){
  try{
    const p=frame.contentWindow.location.pathname || '';
    return p.endsWith('/casa/login.php') || p.endsWith('/login.php');
  }catch(e){ return false; }
}

function mountModule(item){
  const content=document.querySelector('#app .ja-content');
  if(!content) return;
  const src=item.url || '/casa/index_legacy.php';
  content.innerHTML='<div class="ja-module-wrap"><div class="ja-module-title"><strong>'+escapeHtml(item.label)+'</strong><small>CASA / módulo funcional</small></div><iframe class="ja-module-frame" title="'+escapeHtml(item.label)+'" src="'+escapeAttr(src)+'"></iframe></div>';
  const frame=content.querySelector('iframe');
  frame.addEventListener('load',()=>{
    if(sessionExpiredInFrame(frame)){
      window.top.location.href='/casa/login.php';
      return;
    }
    try{
      const doc=frame.contentDocument, win=frame.contentWindow;
      if(doc){
        const st=doc.createElement('style');
        st.textContent='.hud-navbar,.nav-tabs-hud{display:none!important} body{padding-top:0!important}.container-fluid{max-width:none!important;padding-left:14px!important;padding-right:14px!important}';
        doc.head.appendChild(st);
      }
      if(item.tab){
        if(win && typeof win.switchTab==='function') win.switchTab(item.tab);
        else if(doc){
          doc.querySelectorAll('.tab-content-item').forEach(x=>x.style.display='none');
          const target=doc.getElementById(item.tab); if(target) target.style.display='block';
        }
      }
    }catch(e){ console.warn('LCARS módulo:',e); }
  });
}

function escapeHtml(v){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function escapeAttr(v){return String(v).replace(/"/g,'&quot;');}

function handleAction(detail){
  const action=detail.action||'';
  if(action==='back') return home();
  if(action==='open-item'){
    const it=findItem(currentGroup,detail.id); if(it) openItem(it); return;
  }
  if(action.startsWith('open-group:')) return openGroup(action.substring(11));
  if(action==='select'){
    const it=findItem(currentGroup,detail.id); if(it) openItem(it); return;
  }
  if(action==='navigate'){
    const it=findItem(currentGroup,detail.id); if(it) openItem(it);
  }
}

function restoreRoute(){
  const params=new URLSearchParams(location.search);
  const g=params.get('grupo');
  const itemId=params.get('item');
  suppressHistory=true;
  if(g && GROUPS[g]){
    openGroup(g,false);
    if(itemId){ const it=findItem(g,itemId); if(it) openItem(it,false); }
  } else home(false);
  suppressHistory=false;
}

document.addEventListener('DOMContentLoaded',()=>{
  const app=document.getElementById('app');
  app.addEventListener('ja:action',e=>handleAction(e.detail||{}));
  restoreRoute();
});

window.addEventListener('popstate',restoreRoute);
window.CASASite={home,openGroup,openItem,groups:GROUPS};
})();
