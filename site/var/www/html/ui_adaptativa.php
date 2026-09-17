<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CASA / JARVIS - UI Adaptativa</title>
<link rel="stylesheet" href="/casa/lcars-framework.css?v=1.0.0">
<style>html,body{margin:0;background:#090909}.demo-switch{position:fixed;z-index:9999;right:18px;top:90px;display:flex;gap:6px;flex-wrap:wrap;max-width:440px;justify-content:flex-end}.demo-switch button{border:0;border-radius:14px;padding:8px 10px;font-weight:800;cursor:pointer;background:#fff;color:#111;box-shadow:0 2px 8px #0006}@media(max-width:860px){.demo-switch{position:static;padding:8px;background:#090909}}</style>
</head><body>
<div class="demo-switch" aria-label="Demonstrações de layout">
<button data-demo="security">SEGURANÇA</button><button data-demo="operations">OPERAÇÕES</button><button data-demo="devices">DISPOSITIVOS</button><button data-demo="telemetry">TELEMETRIA</button><button data-demo="alert">ALERTA</button><button data-demo="voice">IA & VOZ</button>
</div>
<div id="app"></div>
<script src="/casa/lcars-framework.js?v=1.0.0"></script>
<script>
const commonStatus=[{label:'RUNPOD',value:'ONLINE'},{label:'WATCH',value:'OK'},{label:'SENSORES',value:'OK'},{label:'ALERTA',value:'01'}];
const commonGroups=(active)=>[
 {label:'← GRUPOS',action:'back',back:true},
 {label:'VISÃO GERAL',action:'overview'},
 {label:'SEGURANÇA',action:'group',active:active==='SEGURANÇA'},
 {label:'OPERAÇÕES',action:'group',active:active==='OPERAÇÕES'},
 {label:'DISPOSITIVOS',action:'group',active:active==='DISPOSITIVOS'},
 {label:'AUTOMAÇÃO',action:'group',active:active==='AUTOMAÇÃO'},
 {label:'IA & VOZ',action:'group',active:active==='IA & VOZ'},
 {label:'FAMÍLIA',action:'group',active:active==='FAMÍLIA'},
 {label:'SISTEMA',action:'group',active:active==='SISTEMA'}
];
const demos={
 security:{layout:'menu-grid',kind:'menu',group:'SEGURANÇA',title:'SEGURANÇA',subtitle:'Acesso, perímetro, câmeras e eventos críticos.',groups:commonGroups('SEGURANÇA'),status:commonStatus,sections:[
  {title:'PERÍMETRO',items:[{label:'Portas e portões'},{label:'Fechaduras'},{label:'Garagem'},{label:'Janelas'}]},
  {title:'MONITORAMENTO',items:[{label:'Câmeras'},{label:'Sensores'},{label:'Movimento'},{label:'Presença'}]},
  {title:'PROTEÇÃO',items:[{label:'Alarmes'},{label:'Sirene'},{label:'Modo viagem'},{label:'Modo noturno'}]},
  {title:'REGISTROS',items:[{label:'Eventos'},{label:'Acessos'},{label:'Alertas'},{label:'Histórico'}]}
 ],footer:{hint:'Grupos pequenos à esquerda; menus extensos são distribuídos em colunas.'}},
 operations:{layout:'menu-grid',kind:'menu',group:'OPERAÇÕES',title:'OPERAÇÕES',subtitle:'Controle do dia a dia da casa em blocos curtos.',groups:commonGroups('OPERAÇÕES'),status:commonStatus,sections:[
  {title:'ROTINAS',items:[{label:'Casa ao acordar'},{label:'Saída de casa'},{label:'Chegada em casa'},{label:'Modo noturno',active:true},{label:'Modo viagem'},{label:'Tudo desligado'}]},
  {title:'AMBIENTES',items:[{label:'Sala'},{label:'Cozinha'},{label:'Quarto'},{label:'Banheiro'},{label:'Garagem'},{label:'Jardim'}]},
  {title:'MANUTENÇÃO',items:[{label:'Energia'},{label:'Água'},{label:'Rede'},{label:'Câmeras'},{label:'Sensores'},{label:'Atualizações'}]},
  {title:'AÇÕES RÁPIDAS',items:[{label:'Reiniciar dispositivos'},{label:'Testar alarme'},{label:'Abrir portão'},{label:'Ver câmeras'},{label:'Histórico'},{label:'Diagnóstico'}]}
 ],items:[{title:'MODO NOTURNO',description:'Apaga as luzes, ativa o alarme, fecha o portão e reduz consumos não essenciais.',status:'PRONTO',active:true,actions:[{label:'EXECUTAR',variant:'primary'},{label:'EDITAR'},{label:'AGENDAR'}]}]},
 devices:{layout:'dashboard',group:'DISPOSITIVOS',title:'DISPOSITIVOS',subtitle:'Resumo operacional; selecione um equipamento para abrir o detalhe.',groups:commonGroups('DISPOSITIVOS'),status:commonStatus,columns:3,items:[
  {title:'WATCH',value:'82%',description:'Bateria',status:'ONLINE'},{title:'CELULAR',value:'67%',description:'Bateria',status:'ONLINE'},{title:'ESP32-CAM PORTÃO',value:'-58 dBm',description:'Wi-Fi',status:'ONLINE'},{title:'TV SALA',value:'STANDBY',description:'Sala',status:'OK'},{title:'SENSOR JARDIM',value:'24.1 °C',description:'Temperatura',status:'ONLINE'},{title:'PORTÃO',value:'FECHADO',description:'Último evento 09:42',status:'OK'}]},
 telemetry:{layout:'telemetry',kind:'telemetry',group:'SISTEMA',title:'TELEMETRIA',subtitle:'Alta densidade de dados usa grade compacta, não menus.',groups:commonGroups('SISTEMA'),status:commonStatus,columns:4,items:[
  {label:'CPU',value:'37%',status:'OK'},{label:'RAM',value:'61%',status:'OK'},{label:'LATÊNCIA',value:'84 ms',status:'OK'},{label:'COMANDOS',value:'128',status:'ATIVO'},{label:'TEMP. SALA',value:'23.8 °C'},{label:'UMIDADE',value:'48%'},{label:'REDE',value:'-51 dBm',status:'OK'},{label:'FALHAS 24H',value:'2',status:'ATENÇÃO'}]},
 alert:{layout:'alert',kind:'alert',urgency:'critical',group:'SEGURANÇA',title:'ALERTA DE SEGURANÇA',subtitle:'Situações críticas substituem densidade por foco e ação.',groups:commonGroups('SEGURANÇA'),status:commonStatus,items:[{title:'PORTÃO ABERTO FORA DO HORÁRIO',description:'O sensor confirmou abertura do portão principal às 02:14. Confirme a situação antes de executar qualquer ação física.',status:'ATENÇÃO IMEDIATA',actions:[{label:'VER CÂMERA',variant:'primary'},{label:'VER EVENTOS'},{label:'FECHAR PORTÃO',variant:'danger'}]}]},
 voice:{layout:'chat',kind:'chat',group:'IA & VOZ',title:'NÚCLEO DE RECONHECIMENTO & VOZ',subtitle:'Conversação ocupa o miolo; controles e status permanecem nas bordas.',groups:commonGroups('IA & VOZ'),status:commonStatus,messages:[{role:'ai',content:'JARVIS pronto. O RunPod está online.'},{role:'user',content:'Mostre a situação da casa.'},{role:'ai',content:'A casa está online. Há um alerta pendente e os principais sensores estão ativos.'}]}
};
function show(name){CASALcars.render('#app',demos[name]||demos.operations)}
document.querySelectorAll('[data-demo]').forEach(b=>b.onclick=()=>show(b.dataset.demo));
document.getElementById('app').addEventListener('ja:action',e=>console.log('LCARS action',e.detail));
show(new URLSearchParams(location.search).get('demo')||'operations');
</script></body></html>
