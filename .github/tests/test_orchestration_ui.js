// Garante que Cluster ARM e Orquestracao sao telas independentes e preservam as APIs.
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const path=require('node:path');
const src=fs.readFileSync(path.join(__dirname,'../../site/var/www/html/lcars-site.js'),'utf8');
const css=fs.readFileSync(path.join(__dirname,'../../site/var/www/html/lcars-site.css'),'utf8');
assert.match(src,/\{id:'nodes-sys',label:'Cluster ARM',module:'nodes'\}/);
assert.match(src,/\{id:'orchestration-sys',label:'Orquestração de serviços',module:'orchestration'\}/);
assert.match(src,/if\(m==='orchestration'\) return renderOrchestration\(\)/);
assert.match(css,/\.ja-native-body>\.ja-orch-layout\s*\{[^}]*overflow-y:auto/s);
const start=src.indexOf('async function renderNodes(){');
const end=src.indexOf('function scheduleExecutorLabel(',start);
assert.ok(start>0 && end>start,'expected module boundaries');
const code=src.slice(start,end);
const nodes=[
  {id:1,device_id:'rpi-1',nome:'Raspberry Sala',ip_address:'192.168.2.13',online:true,status:'online',services_detail:[]},
  {id:2,device_id:'rpi-2',nome:'Raspberry Oficina',ip_address:'192.168.2.14',online:false,status:'offline',services_detail:[]}
];
const services=[{id:'casa-tarefas',label:'<img onerror=bad>',active:true,
  owner_device_id:'rpi-1',owner_ip:'192.168.2.13',owner_name:'Raspberry Sala',
  purpose:'Executa tarefas',impact_when_stopped:'Fila parada'}];
let body='',actions='',title='',calls=[],errorMode=false;
const serviceMoveBtn={
  disabled:false,textContent:'APLICAR NÓ',getAttribute(name){return {'data-svc':'casa-tarefas'}[name];},onclick:null
};
const serviceControlBtn={
  disabled:false,textContent:'REINICIAR',
  getAttribute(name){return {'data-svc':'casa-tarefas','data-act':'restart','data-dev':'rpi-1','data-ip':'192.168.2.13'}[name];},onclick:null
};
const dom={
  getElementById(){return {onclick:null};},
  querySelectorAll(sel){
    if(sel==='.ja-orch-move-btn')return [serviceMoveBtn];
    if(sel==='.ja-svc-ctrl-btn')return [serviceControlBtn];
    return [];
  },
  querySelector(sel){
    if(sel.includes('ja-orch-select'))return {
      value:'rpi-2',selectedIndex:0,
      options:[{getAttribute:k=>k==='data-ip'?'192.168.2.14':null}]
    };
    return null;
  }
};
const context={
  uiT:v=>String(v),
  loading(){},document:dom,alert(msg){throw new Error('Unexpected alert '+msg);},confirm:()=>false,
  getJson:async url=>{
    assert.equal(url,'/casa/api/arm_nodes.php');
    if(errorMode)throw new Error('Sessão expirada');
    return {nodes,orchestration:services,service_catalog:[]};
  },
  postJson:async(url,data)=>{calls.push({url,data});return {status:'ok'};},
  esc:v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
  attr:v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
  paginate:(n,a,size)=>({slice:a.slice(0,size),pages:1}),cards:(items,fn)=>items.map(fn).join(''),pager:()=>'',bindPager(){},
  moduleShell:(t,b,a)=>{title=t;body=b;actions=a;},openNewNodeModal(){},openNodeEditor(){},openNodeServicesModal(){}
};
vm.createContext(context);
vm.runInContext(code,context);
(async()=>{
  await context.renderNodes();
  assert.match(title,/Cluster ARM/);
  assert.match(body,/Raspberry Sala/);
  assert.ok(!body.includes('ORQUESTRAÇÃO DE SERVIÇOS DO CLUSTER'),'cluster must not embed orchestration');
  assert.ok(!body.includes('ja-orch-service'),'cluster must not show orchestration cards');
  assert.match(actions,/item=orchestration-sys/);

  await context.renderOrchestration();
  assert.match(title,/Orquestração de serviços/);
  assert.match(body,/ja-orch-layout/);
  assert.match(body,/ja-orch-grid/);
  assert.match(body,/Executa tarefas/);
  assert.match(body,/&lt;img onerror=bad&gt;/,'must escape service label');
  assert.ok(!body.includes('Identificador</dt>'),'no node inventory on orchestration page');
  assert.match(actions,/item=nodes-sys/);
  assert.equal(typeof serviceMoveBtn.onclick,'function');
  assert.equal(typeof serviceControlBtn.onclick,'function');

  await serviceMoveBtn.onclick({stopPropagation(){}});
  assert.equal(calls[0].url,'/casa/api/arm_nodes.php');
  assert.equal(calls[0].data.acao,'mover_servico');
  assert.equal(calls[0].data.service,'casa-tarefas');
  assert.equal(calls[0].data.device_id,'rpi-2');
  await serviceControlBtn.onclick({stopPropagation(){}});
  assert.equal(calls[1].data.acao,'controlar_servico');
  assert.equal(calls[1].data.service_action,'restart');

  errorMode=true;
  await context.renderOrchestration();
  assert.match(body,/Sessão expirada/);
  assert.match(actions,/orch-retry/);
  console.log('Cluster/orquestração UI: OK (duas rotas, grade, API, escaping, erros).');
})().catch(e=>{console.error(e);process.exitCode=1;});
