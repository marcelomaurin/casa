/* CASA/JARVIS Adaptive LCARS Framework
 * A IA escolhe apenas layouts/componentes permitidos. Nenhum HTML arbitrario e aceito.
 */
(function(global){
  'use strict';

  const LAYOUTS = ['auto','focus','menu-grid','dashboard','telemetry','alert','form','chat'];
  const COMPONENTS = ['menu','card','metric','status','action','field','message','gauge'];

  function esc(v){
    return String(v == null ? '' : v)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }
  function arr(v){ return Array.isArray(v) ? v : []; }
  function clamp(n,min,max){ n=parseInt(n,10)||min; return Math.max(min,Math.min(max,n)); }

  function chooseLayout(spec){
    if(spec && LAYOUTS.includes(spec.layout) && spec.layout !== 'auto') return spec.layout;
    const kind = String(spec?.kind || '').toLowerCase();
    const urgency = String(spec?.urgency || '').toLowerCase();
    const items = arr(spec?.items);
    const sections = arr(spec?.sections);
    const fields = arr(spec?.fields);
    const messages = arr(spec?.messages);

    if(urgency === 'critical' || urgency === 'high' || kind === 'alert') return 'alert';
    if(kind === 'chat' || messages.length) return 'chat';
    if(kind === 'form' || fields.length) return 'form';
    if(kind === 'telemetry' || items.filter(x=>x && (x.metric != null || x.value != null)).length >= 6) return 'telemetry';
    if(sections.length >= 2 || items.length >= 12 || kind === 'menu') return 'menu-grid';
    if(items.length >= 4) return 'dashboard';
    return 'focus';
  }

  function normalize(spec){
    spec = spec && typeof spec === 'object' ? spec : {};
    const out = {
      title: String(spec.title || 'CASA / JARVIS'),
      subtitle: String(spec.subtitle || ''),
      group: String(spec.group || 'GERAL'),
      breadcrumb: arr(spec.breadcrumb).slice(0,6).map(String),
      layout: chooseLayout(spec),
      kind: String(spec.kind || ''),
      urgency: String(spec.urgency || 'normal'),
      columns: clamp(spec.columns || 4,1,4),
      groups: arr(spec.groups).slice(0,9),
      sections: arr(spec.sections).slice(0,12),
      items: arr(spec.items).slice(0,48),
      actions: arr(spec.actions).slice(0,8),
      fields: arr(spec.fields).slice(0,24),
      messages: arr(spec.messages).slice(-50),
      status: arr(spec.status).slice(0,8),
      footer: spec.footer && typeof spec.footer==='object' ? spec.footer : {}
    };
    return out;
  }

  function statusClass(s){
    s=String(s||'').toLowerCase();
    if(/erro|falha|crit|danger|offline/.test(s)) return 'ja-danger';
    if(/alert|aten|warning|pend/.test(s)) return 'ja-warning';
    if(/ok|online|ativo|ready|pronto/.test(s)) return 'ja-online';
    return '';
  }

  function renderRail(spec){
    const groups = spec.groups.length ? spec.groups : [
      {label:'CASA',action:'home',level:0,active:true}
    ];
    return '<nav class="ja-rail" aria-label="Navegação por níveis">'+groups.map((g,i)=>{
      const level=Number.isFinite(Number(g.level))?Math.max(0,Math.min(4,Number(g.level))):1;
      const cls='ja-level-'+level+(g.active?' active':'')+(g.levelNav?' level-nav':'');
      const id=esc(g.id || ('g'+i));
      return '<button type="button" class="'+cls+'" data-ja-level="'+level+'" data-ja-action="'+esc(g.action||'navigate')+'" data-ja-id="'+id+'">'+esc(g.label||g.title||'Item')+'</button>';
    }).join('')+'</nav>';
  }

  function renderStatus(spec){
    const items=spec.status.length?spec.status:[
      {label:'RUNPOD',value:'ONLINE'},
      {label:'WATCH',value:'OK'},
      {label:'SENSORES',value:'OK'}
    ];
    return '<aside class="ja-status">'+items.map(s=>'<div class="ja-status-card"><b>'+esc(s.label||'STATUS')+'</b><div class="value '+statusClass(s.value)+'">'+esc(s.value||'--')+'</div>'+(s.detail?'<small>'+esc(s.detail)+'</small>':'')+'</div>').join('')+'</aside>';
  }

  function renderActions(actions){
    actions=arr(actions);
    if(!actions.length) return '';
    return '<div class="ja-actions">'+actions.map((a,i)=>'<button type="button" class="ja-action '+esc(a.variant|| (i===0?'primary':''))+'" data-ja-action="'+esc(a.action||a.id||'action')+'">'+esc(a.label||a.title||'Executar')+'</button>').join('')+'</div>';
  }

  function renderFocus(spec){
    const item=spec.items[0]||{};
    return '<div class="ja-focus"><section class="ja-focus-copy"><h2>'+esc(item.title||item.label||spec.title)+'</h2>'+
      (item.description||spec.subtitle?'<p>'+esc(item.description||spec.subtitle)+'</p>':'')+
      (item.value!=null?'<div class="metric">'+esc(item.value)+'</div>':'')+
      (item.status?'<p class="'+statusClass(item.status)+'"><b>'+esc(item.status)+'</b></p>':'')+
      '</section>'+renderActions(item.actions||spec.actions)+'</div>';
  }

  function inferSections(spec){
    if(spec.sections.length) return spec.sections;
    const chunk=Math.ceil(spec.items.length/Math.min(spec.columns,4));
    const out=[];
    for(let i=0;i<spec.items.length;i+=chunk) out.push({title:'GRUPO '+(out.length+1),items:spec.items.slice(i,i+chunk)});
    return out;
  }

  function renderMenuGrid(spec){
    const sections=inferSections(spec).slice(0,4);
    return '<div class="ja-menu-grid" style="--ja-columns:'+Math.min(4,Math.max(1,sections.length))+'">'+sections.map((s,si)=>'<section class="ja-menu-section"><div class="ja-menu-title">'+esc(s.title||s.label||('GRUPO '+(si+1)))+'</div>'+arr(s.items).slice(0,12).map((it,ii)=>'<button type="button" class="ja-menu-item'+(it.active?' active':'')+'" data-ja-action="'+esc(it.action||'select')+'" data-ja-id="'+esc(it.id||('m'+si+'-'+ii))+'">'+esc(it.label||it.title||'Item')+'</button>').join('')+'</section>').join('')+'</div>'+
      (spec.items.find(x=>x&&x.active)?'<div style="margin-top:12px">'+renderFocus({...spec,items:[spec.items.find(x=>x.active)]})+'</div>':'');
  }

  function renderCards(spec, telemetry){
    const cls=telemetry?'ja-telemetry':'ja-cards';
    return '<div class="'+cls+'" style="--ja-columns:'+spec.columns+'">'+spec.items.map(it=> telemetry ?
      '<div class="ja-gauge"><strong>'+esc(it.value??it.metric??'--')+'</strong><small>'+esc(it.label||it.title||'Métrica')+'</small>'+(it.status?'<div class="'+statusClass(it.status)+'">'+esc(it.status)+'</div>':'')+'</div>' :
      '<article class="ja-card'+(it.icon?' ja-group-card-ui':'')+'">'+(it.icon?'<div class="ja-card-title"><span class="ja-card-icon" aria-hidden="true">'+esc(it.icon)+'</span><h3>'+esc(it.title||it.label||'Item')+'</h3></div>':'<h3>'+esc(it.title||it.label||'Item')+'</h3>')+(it.icon?renderActions(it.actions):'')+(it.value!=null?'<div class="metric">'+esc(it.value)+'</div>':'')+(it.description?'<div class="sub">'+esc(it.description)+'</div>':'')+(it.status?'<span class="state">'+esc(it.status)+'</span>':'')+(it.icon?'':renderActions(it.actions))+'</article>'
    ).join('')+'</div>';
  }

  function renderAlert(spec){
    const it=spec.items[0]||{};
    return '<div class="ja-alert-layout"><section class="ja-alert-hero"><h2>'+esc(it.title||spec.title||'ALERTA')+'</h2><p>'+esc(it.description||spec.subtitle||'Evento que requer atenção.')+'</p>'+(it.status?'<p class="ja-danger"><b>'+esc(it.status)+'</b></p>':'')+'</section><aside class="ja-alert-side">'+renderActions(it.actions||spec.actions)+'</aside></div>';
  }

  function renderForm(spec){
    return '<form class="ja-form" data-ja-form>'+spec.fields.map((f,i)=>'<div class="ja-field'+(f.wide?' wide':'')+'"><label for="ja-f-'+i+'">'+esc(f.label||f.name||'Campo')+'</label>'+
      (f.type==='textarea'?'<textarea id="ja-f-'+i+'" name="'+esc(f.name||('field'+i))+'" placeholder="'+esc(f.placeholder||'')+'">'+esc(f.value||'')+'</textarea>':'<input id="ja-f-'+i+'" type="'+esc(f.type||'text')+'" name="'+esc(f.name||('field'+i))+'" value="'+esc(f.value||'')+'" placeholder="'+esc(f.placeholder||'')+'">')+'</div>').join('')+'<div class="ja-field wide">'+renderActions(spec.actions.length?spec.actions:[{label:'SALVAR',action:'submit',variant:'primary'}])+'</div></form>';
  }

  function renderChat(spec){
    return '<div class="ja-chat"><div class="ja-messages">'+spec.messages.map(m=>'<div class="ja-msg '+(m.role==='user'?'user':'ai')+'">'+esc(m.content||m.text||'')+'</div>').join('')+'</div><div class="ja-chatbar"><input type="text" aria-label="Mensagem" placeholder="Fale com o JARVIS"><button type="button" data-ja-action="send">ENVIAR</button></div></div>';
  }

  function renderBody(spec){
    switch(spec.layout){
      case 'menu-grid': return renderMenuGrid(spec);
      case 'dashboard': return renderCards(spec,false);
      case 'telemetry': return renderCards(spec,true);
      case 'alert': return renderAlert(spec);
      case 'form': return renderForm(spec);
      case 'chat': return renderChat(spec);
      default: return renderFocus(spec);
    }
  }

  function render(target,input){
    const el=typeof target==='string'?document.querySelector(target):target;
    if(!el) throw new Error('CASALcars: destino não encontrado');
    const spec=normalize(input);
    el.innerHTML='<div class="ja-shell">'+
      '<header class="ja-top"><div class="ja-brand"><strong>CASA / JARVIS</strong><span>SUA CASA. MAIS INTELIGENTE.</span></div><div class="ja-system"><span class="ja-chip ok">SISTEMA ONLINE</span></div></header>'+
      '<div class="ja-workspace">'+renderRail(spec)+'<main class="ja-main"><section class="ja-panel"><header class="ja-panel-head"><div><h1>'+esc(spec.title)+'</h1></div><p>'+esc(spec.subtitle)+'</p></header><div class="ja-content">'+renderBody(spec)+'</div></section></main>'+renderStatus(spec)+'</div>'+
      '<footer class="ja-footer"><div>'+esc(spec.footer.hint||'Use ← GRUPOS para voltar aos grupos principais.')+'</div><div class="time" data-ja-clock>--:--</div><div class="online">CASA ONLINE</div></footer></div>';
    updateClock(el);
    bind(el,spec);
    el.dispatchEvent(new CustomEvent('ja:rendered',{detail:{spec}}));
    return spec;
  }

  function updateClock(root){
    const node=root.querySelector('[data-ja-clock]'); if(!node) return;
    const tick=()=>{node.textContent=new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});}; tick();
  }

  function bind(root,spec){
    root.querySelectorAll('[data-ja-action]').forEach(btn=>btn.addEventListener('click',()=>{
      root.dispatchEvent(new CustomEvent('ja:action',{detail:{action:btn.dataset.jaAction,id:btn.dataset.jaId||null,spec}}));
    }));
  }

  global.CASALcars={layouts:LAYOUTS.slice(),components:COMPONENTS.slice(),chooseLayout,normalize,render};
})(window);
