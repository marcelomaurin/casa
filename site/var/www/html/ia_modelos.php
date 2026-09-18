<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Modelos de IA - CASA/JARVIS</title>
<link rel="stylesheet" href="/casa/lcars-framework.css?v=1.0.0">
<link rel="stylesheet" href="/casa/lcars-site.css?v=1.0.0">
<style>
body{padding:18px;background:#f2eadc;color:#221f22;font-family:Arial,sans-serif}
.wrap{max-width:1400px;margin:auto}
.card{background:#fffaf0;border:1px solid #cdbfae;border-radius:14px;padding:16px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}
label{font-size:12px;font-weight:700;display:block;margin-bottom:4px}
input,select,textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #b8aa99;border-radius:8px;background:#fff}
button{padding:8px 12px;border:0;border-radius:8px;cursor:pointer;font-weight:700}
.primary{background:#e58a55}.secondary{background:#d3a04d}.danger{background:#d96f78}.ok{background:#6f987e;color:#fff}
table{width:100%;border-collapse:collapse} th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left;font-size:13px;vertical-align:top}
.badge{display:inline-block;padding:3px 7px;border-radius:12px;background:#ddd;margin-right:4px;font-size:11px}
.default{background:#d3a04d}.cpu{background:#9b83ad;color:#fff}.gpu{background:#6f8ca8;color:#fff}
.status{font-size:12px;white-space:pre-wrap}
</style>
</head>
<body><div class="wrap">
<div class="card"><h2>Conexões / Modelos de IA</h2>
<p>O modelo <b>DEFAULT</b> é tentado primeiro. Em falha, o JARVIS tenta os demais modelos ativos pela menor prioridade numérica.</p></div>

<div class="card">
<input type="hidden" id="id" value="0">
<div class="grid">
<div><label>Nome</label><input id="nome" placeholder="Ex.: GPT principal"></div>
<div><label>Provedor</label><select id="provedor"><option value="openai_compatible">OpenAI-compatible</option><option value="ollama">Ollama</option><option value="runpod">RunPod/OpenAI</option></select></div>
<div><label>URL base</label><input id="base_url" placeholder="https://api.openai.com/v1"></div>
<div><label>Modelo</label><input id="modelo" placeholder="modelo exposto pelo endpoint"></div>
<div><label>API Key</label><input id="api_key" type="password" placeholder="deixe vazio ao editar para manter"></div>
<div><label>Prioridade</label><input id="prioridade" type="number" value="100" min="1"></div>
<div><label>Hardware</label><select id="classe_hardware"><option>CPU</option><option>GPU_LOW</option><option>GPU_HIGH</option></select></div>
<div><label>Nível</label><select id="nivel_capacidade"><option>ESTUDANTE</option><option>ESTAGIARIO</option><option>PROFISSIONAL</option><option>PROFESSOR</option></select></div>
<div><label>Timeout (s)</label><input id="timeout_segundos" type="number" value="45"></div>
<div><label>Max tokens</label><input id="max_tokens" type="number" value="600"></div>
<div><label>Temperatura</label><input id="temperatura" type="number" value="0.35" min="0" max="2" step="0.05"></div>
<div><label>Ativo</label><select id="ativo"><option value="1">Sim</option><option value="0">Não</option></select></div>
<div><label>Default</label><select id="padrao"><option value="0">Não</option><option value="1">Sim</option></select></div>
</div>
<div style="margin-top:10px"><label>Observações</label><textarea id="observacoes"></textarea></div>
<div style="margin-top:12px;display:flex;gap:8px"><button class="primary" onclick="salvar()">Salvar</button><button class="secondary" onclick="limpar()">Novo</button></div>
</div>

<div class="card">
<div style="display:flex;justify-content:space-between;align-items:center"><h3>Modelos cadastrados</h3><button class="secondary" onclick="carregar()">Atualizar</button></div>
<div style="overflow:auto"><table><thead><tr><th>Ordem</th><th>Nome / Modelo</th><th>Classificação</th><th>Saúde</th><th>Ações</th></tr></thead><tbody id="tb"></tbody></table></div>
</div>
</div>
<script>
let modelos=[];
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
async function api(acao,data){
 const r=await fetch('/casa/api/ia_modelos.php?acao='+encodeURIComponent(acao),{method:data?'POST':'GET',headers:data?{'Content-Type':'application/json'}:{},body:data?JSON.stringify(data):undefined});
 const j=await r.json().catch(()=>({status:'erro',mensagem:'Resposta inválida'}));
 if(!r.ok) throw new Error(j.mensagem||j.teste?.erro||('HTTP '+r.status));
 return j;
}
async function carregar(){
 const j=await api('listar'); modelos=j.dados||[]; const tb=document.getElementById('tb'); tb.innerHTML='';
 modelos.forEach(m=>{
  const tr=document.createElement('tr');
  tr.innerHTML='<td>'+(m.padrao==1?'<span class="badge default">DEFAULT</span>':'')+'#'+esc(m.prioridade)+'</td>'+
   '<td><b>'+esc(m.nome)+'</b><br>'+esc(m.modelo)+'<br><small>'+esc(m.provedor)+' · '+esc(m.base_url)+'</small></td>'+
   '<td><span class="badge '+(m.classe_hardware==='CPU'?'cpu':'gpu')+'">'+esc(m.classe_hardware)+'</span><span class="badge">'+esc(m.nivel_capacidade)+'</span></td>'+
   '<td class="status">falhas: '+esc(m.falhas_consecutivas)+'<br>sucesso: '+esc(m.ultimo_sucesso||'—')+'<br>erro: '+esc(m.ultimo_erro||'—')+'</td>'+
   '<td><button class="secondary" onclick="editar('+m.id+')">Editar</button> <button class="ok" onclick="testar('+m.id+')">Testar</button> '+(m.padrao==1?'':'<button class="primary" onclick="padrao('+m.id+')">Default</button> <button class="danger" onclick="excluir('+m.id+')">Excluir</button>')+'</td>';
  tb.appendChild(tr);
 });
}
function editar(id){const m=modelos.find(x=>Number(x.id)===Number(id)); if(!m)return; ['id','nome','provedor','base_url','modelo','prioridade','classe_hardware','nivel_capacidade','timeout_segundos','max_tokens','temperatura','ativo','padrao','observacoes'].forEach(k=>{const e=document.getElementById(k); if(e)e.value=m[k]??''}); document.getElementById('api_key').value=''; window.scrollTo({top:0,behavior:'smooth'});}
function limpar(){document.getElementById('id').value=0;document.getElementById('nome').value='';document.getElementById('base_url').value='';document.getElementById('modelo').value='';document.getElementById('api_key').value='';document.getElementById('prioridade').value=100;document.getElementById('classe_hardware').value='CPU';document.getElementById('nivel_capacidade').value='ESTUDANTE';document.getElementById('timeout_segundos').value=45;document.getElementById('max_tokens').value=600;document.getElementById('temperatura').value=0.35;document.getElementById('ativo').value=1;document.getElementById('padrao').value=0;document.getElementById('observacoes').value='';}
async function salvar(){const p={};['id','nome','provedor','base_url','api_key','modelo','prioridade','classe_hardware','nivel_capacidade','timeout_segundos','max_tokens','temperatura','observacoes'].forEach(k=>p[k]=document.getElementById(k).value);p.ativo=document.getElementById('ativo').value==='1';p.padrao=document.getElementById('padrao').value==='1';try{await api('salvar',p);limpar();await carregar();alert('Modelo salvo.');}catch(e){alert(e.message)}}
async function testar(id){try{const j=await api('testar',{id});alert('OK: '+(j.teste?.resposta||'integração válida'));await carregar();}catch(e){alert('Falha: '+e.message);await carregar();}}
async function padrao(id){try{await api('padrao',{id});await carregar();}catch(e){alert(e.message)}}
async function excluir(id){if(!confirm('Excluir este modelo?'))return;try{await api('excluir',{id});await carregar();}catch(e){alert(e.message)}}
carregar();
</script></body></html>