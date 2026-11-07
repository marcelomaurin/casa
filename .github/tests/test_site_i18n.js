// CASA web locale contract: ten languages, pt-BR default, persistence, Arabic RTL.
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const assert=require('node:assert/strict');
const root=path.join(__dirname,'../../site/var/www/html');
const src=fs.readFileSync(path.join(root,'lcars-i18n.js'),'utf8');
const site=fs.readFileSync(path.join(root,'lcars-site.js'),'utf8');
const index=fs.readFileSync(path.join(root,'index.php'),'utf8');
const css=fs.readFileSync(path.join(root,'lcars-site.css'),'utf8');
const keys=['pt-BR','en-US','es-ES','ar-SA','fr-FR','de-DE','ru-RU','ja-JP','zh-CN','hi-IN'];
const screenKeys=[
  'Idioma','SISTEMA','INFRAESTRUTURA','ADMINISTRAÇÃO','Cluster ARM','Orquestração de serviços',
  'Orquestração de serviços do cluster','Cluster ARM — nós e equipamentos',
  'Atualizar lista','Atualizar serviços','Ver Cluster ARM','Tentar novamente',
  'ATIVO','INATIVO','APLICAR NÓ','PARAR','REINICIAR','ATIVAR',
  'Propósito do Daemon:','Se desativado:','Servidor executor:','Atual:',
  'serviços catalogados','ativos','nós online','serviço(s) com redundância',
  'Identificador','Hostname','Programa / função','Serviços Atribuídos ao Nó (Controle Direto)',
  'Último contato (servidor)','Acesso SSH','Site do Cluster'
];
function createApp(saved=null) {
  const savedValues=new Map();
  if(saved!==null)savedValues.set('casa.ui.language',saved);
  const html={lang:'pt-BR',dir:'ltr'};
  const options=[];
  const select={value:'',textContent:'',addEventListener(event,callback){this.onChange=callback;},appendChild(item){options.push(item);}};
  const label={textContent:'Idioma'};
  const document={
    readyState:'complete',documentElement:html,
    createElement(){return {value:'',textContent:''};},
    getElementById(id){return {'casa-language-select':select,'casa-language-label':label}[id]||null;}
  };
  let reloads=0;
  const window={
    document,
    location:{reload(){reloads++;}},
    localStorage:{
      getItem(k){return savedValues.has(k)?savedValues.get(k):null;},
      setItem(k,v){savedValues.set(k,v);}
    }
  };
  const context={window,globalThis:window};
  vm.createContext(context);
  vm.runInContext(src,context,{timeout:1000});
  return {i18n:window.CASAI18n,html,select,options,label,savedValues,reloads:()=>reloads};
}
const app=createApp();
assert.equal(app.i18n.getLanguage(),'pt-BR','Portuguese default even when no browser preference exists');
assert.equal(app.html.lang,'pt-BR');
assert.equal(app.html.dir,'ltr');
assert.equal(app.i18n.t('Cluster ARM'),'Cluster ARM');
assert.deepEqual(Array.from(app.i18n.supported,lang=>lang.tag),keys);
assert.equal(app.options.length,10);
for(const code of keys){
  const applied=app.i18n.setLanguage(code);
  assert.equal(applied,code);
  assert.equal(app.html.lang,code);
  for(const label of screenKeys){
    const translated=app.i18n.t(label);
    assert.ok(typeof translated==='string'&&translated.length>0,'missing '+code+' '+label);
    if(code!=='pt-BR'&&['Orquestração de serviços','APLICAR NÓ','ATIVO'].includes(label))
      assert.notEqual(translated,label,'not translated '+code+' '+label);
  }
}
app.i18n.setLanguage('ar-SA');
assert.equal(app.html.dir,'rtl','Arabic UI must have RTL reading direction');
assert.notEqual(app.i18n.t('APLICAR NÓ'),'APLICAR NÓ');
app.i18n.setLanguage('pt-BR');
assert.equal(app.html.dir,'ltr');
assert.equal(app.i18n.t('Orquestração de serviços'),'Orquestração de serviços');
app.i18n.setLanguage('xx-XX');
assert.equal(app.i18n.getLanguage(),'pt-BR','unknown language falls back to Portuguese');
assert.equal(createApp('unknown').i18n.getLanguage(),'pt-BR');
assert.equal(createApp('ja-JP').i18n.getLanguage(),'ja-JP');
const persisted=createApp();
persisted.select.value='es-ES';
persisted.select.onChange();
assert.equal(persisted.savedValues.get('casa.ui.language'),'es-ES');
assert.equal(persisted.reloads(),1);
assert.match(index,/lcars-i18n\.js\?v=/,'load dictionary before site');
assert.ok(index.indexOf('lcars-i18n.js')<index.indexOf('lcars-site.js'));
assert.match(index,/id="casa-language-select"/);
assert.match(site,/function uiT\(label\)/);
assert.match(site,/function uiSections\(sections\)/);
assert.match(site,/title:uiT\(group\)/);
assert.match(site,/moduleShell\(uiT\('Orquestração de serviços do cluster'\)/);
assert.match(css,/html\[dir="rtl"\]/);
console.log('Casa i18n: OK (10 idiomas, pt-BR padrão, fallback, persistência, RTL e menus).');
