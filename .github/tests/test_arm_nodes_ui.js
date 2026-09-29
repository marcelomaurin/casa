const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const path=require('node:path');
const src=fs.readFileSync(path.join(__dirname,'../../site/var/www/html/lcars-site.js'),'utf8');
const render=src.slice(src.indexOf('async function renderNodes(){'),src.indexOf('function scheduleCronLabel'));
let body='', actions='', requested='';
let rows=Array.from({length:151},(_,i)=>({device_id:'agent-'+i,nome:i===0?'<script>bad</script>':'Agent '+i,capabilities:['arm-agent'],status:'online',online:true}));
const context={
  loading(){}, getJson:async url=>{requested=url;return {nodes:rows};},
  esc:v=>String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'),
  paginate:(name,items,size)=>({slice:items.slice(0,size),pages:Math.ceil(items.length/size)}),
  cards:(items,fn)=>items.map(fn).join(''), pager:(name,p)=>'pages='+p.pages,
  bindPager(){}, moduleShell:(title,b,a)=>{body=b;actions=a;},
  document:{getElementById:()=>({onclick:null})}
};
vm.createContext(context);
vm.runInContext(render,context);
(async()=>{
  await context.renderNodes();
  assert.equal(requested,'/casa/api/arm_nodes.php');
  assert.ok(body.includes('151 agente(s)'));
  assert.ok(body.includes('pages=13'));
  assert.ok(!body.includes('<script>'));
  assert.ok(body.includes('&lt;script&gt;'));
  assert.ok(actions.includes('nodes-refresh'));
  rows=[];
  await context.renderNodes();
  assert.ok(body.includes('Nenhum agente Linux ARM registrado'));
  context.getJson=async()=>{throw new Error('Sessão expirada');};
  await context.renderNodes();
  assert.ok(body.includes('Sessão expirada'));
  assert.ok(!body.includes('Nenhum agente'));
  assert.ok(actions.includes('nodes-retry'));
  console.log('ARM UI: OK (endpoint, pagination, escaping, empty, error, retry)');
})().catch(e=>{console.error(e);process.exitCode=1;});
