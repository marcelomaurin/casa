// Teste do seletor de idioma na coluna de Administração do CASA.
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const assert=require('node:assert/strict');
const siteRoot=path.join(__dirname,'../../site/var/www/html');
const js=fs.readFileSync(path.join(siteRoot,'lcars-site.js'),'utf8');
const css=fs.readFileSync(path.join(siteRoot,'lcars-site.css'),'utf8');
const html=fs.readFileSync(path.join(siteRoot,'index.php'),'utf8');

assert.match(html,/id="casa-language-picker"/);
assert.match(html,/id="casa-language-select"/);
assert.match(css,/\.casa-language-picker\s*\{[^}]*position:static/s,'selector must not overlay header');
assert.match(css,/\.casa-language-picker:not\(\.in-admin-panel\)/,'mobile fallback remains accessible');
assert.match(css,/@media\(min-width:761px\)\s*\{[^}]*\.ja-status\s*\{[^}]*overflow-y:auto/s);
assert.match(js,/function mountAdminTools\(\)/);
assert.match(js,/placeLanguagePicker\(box\)/);
const start=js.indexOf('const casaLanguagePicker=');
const end=js.indexOf('function mountAdminTools(){',start);
assert.ok(start>0&&end>start,'language placement function exists');
const placement=js.slice(start,end);
class Node {
  constructor(name) {
    this.name=name;this.children=[];this.parentElement=null;this.classes=new Set();
    this.classList={add:(x)=>this.classes.add(x),remove:(x)=>this.classes.delete(x),
      contains:(x)=>this.classes.has(x)};
  }
  appendChild(el){return this.insertBefore(el,null)}
  insertBefore(el,before){
    if(el.parentElement){const idx=el.parentElement.children.indexOf(el);if(idx>=0)el.parentElement.children.splice(idx,1)}
    const i=before?this.children.indexOf(before):-1;
    if(i<0)this.children.push(el);else this.children.splice(i,0,el);
    el.parentElement=this;return el;
  }
  querySelector(selector){return selector==='.logout'?this.children.find(x=>x.name==='logout')||null:null}
  get nextElementSibling(){
    if(!this.parentElement)return null;
    return this.parentElement.children[this.parentElement.children.indexOf(this)+1]||null;
  }
}
const body=new Node('body');
const picker=new Node('casa-language-picker');
const logout=new Node('logout');
const firstBox=new Node('admin1');firstBox.appendChild(logout);
body.appendChild(picker);
let narrow=false;
const document={body,getElementById:id=>id==='casa-language-picker'?picker:null};
const window={matchMedia:()=>({matches:narrow})};
const context={document,window};vm.createContext(context);
const place=vm.runInContext(placement+';placeLanguagePicker',context);
place(firstBox);
assert.equal(picker.parentElement,firstBox,'desktop selector is inside Administration');
assert.equal(picker.nextElementSibling,logout,'selector is before logout');
assert.ok(picker.classList.contains('in-admin-panel'));
place(firstBox);
assert.equal(firstBox.children.filter(x=>x===picker).length,1,'no duplicate after observer callback');

const newBox=new Node('admin2');
newBox.appendChild(new Node('logout'));
place(newBox);
assert.equal(picker.parentElement,newBox,'selector survives LCARS rerender');
assert.equal(firstBox.children.includes(picker),false,'detaches from old panel');

narrow=true;place(newBox);
assert.equal(picker.parentElement,body,'selector stays usable when right column is hidden');
assert.equal(picker.classList.contains('in-admin-panel'),false);
narrow=false;place(newBox);
assert.equal(picker.parentElement,newBox,'selector returns to Administration after resize');
console.log('CASA language sidebar: OK (desktop, rerender, resize, mobile fallback).');
