<?php
/**
 * CASA/JARVIS - entrada principal responsiva com tema LCARS.
 *
 * O dashboard funcional original foi preservado em index_legacy.php.
 * Este arquivo aplica o tema LCARS diretamente na resposta HTML, sem depender
 * de mod_substitute no Apache/Hostinger.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['auth_user'])) { header('Location: /casa/login.php'); exit; }

ob_start();
require __DIR__ . '/index_legacy.php';
$html = ob_get_clean();

$lcarsLink = '<link rel="stylesheet" href="/casa/lcars.css?v=20260914b">';
if (stripos($html, '/casa/lcars.css') === false) $html = str_ireplace('</head>', "  {$lcarsLink}\n</head>", $html);

// Framework adaptativo: usado por telas dirigidas por regras/IA e pelo laboratorio.
$adaptiveAssets = '<link rel="stylesheet" href="/casa/lcars-framework.css?v=1.0.0">' . "\n" .
                  '<script defer src="/casa/lcars-framework.js?v=1.0.0"></script>';
if (stripos($html, '/casa/lcars-framework.css') === false) {
    $html = str_ireplace('</head>', "  {$adaptiveAssets}\n</head>", $html);
}

$html = str_replace('/login.php?logout=1', '/casa/login.php?logout=1', $html);
$html = str_replace('ONLINE • 192.168.2.12', 'CASA DISTRIBUÍDA • ONLINE', $html);

// Site: IA exclusivamente via RunPod. O endpoint web não possui fallback local.
$html = str_replace("url: 'api/jarvis.php'", "url: 'api/jarvis_site.php'", $html);
$html = str_replace(
    '<option value="auto">⚡ Híbrido (Local: Residencial / Nuvem: Dev & Análise)</option>\n                <option value="local_only">🏠 Apenas Local (llama.cpp)</option>\n                <option value="cloud_only">☁️ Apenas Nuvem (RunPod GPU)</option>',
    '<option value="cloud_only" selected>☁️ RunPod GPU</option>', $html
);
$html = str_replace(
    '<option value="auto">⚡ Híbrido Inteligente (Local: Automação / Nuvem: Programação, Análise e Pesquisa)</option>\n              <option value="local_only">🏠 Apenas IA Local (Desativa nuvem, roda tudo no Raspberry)</option>\n              <option value="cloud_only">☁️ Apenas Nuvem Externa (Desativa local, direciona tudo para RunPod GPU)</option>',
    '<option value="cloud_only" selected>☁️ Somente RunPod GPU</option>', $html
);
$html = str_replace(
    '<option value="local">Local (llama.cpp no Raspberry Pi 4)</option>\n              <option value="runpod">Nuvem GPU Serverless (RunPod.io)</option>',
    '<option value="runpod" selected>RunPod.io GPU</option>', $html
);
$html = str_replace('AUTO', 'RUNPOD', $html);
$html = str_replace("var iaMode = $('#selectIaMode').val();", "var iaMode = 'cloud_only';", $html);
$html = str_replace('ia_mode: "auto"', 'ia_mode: "cloud_only"', $html);
$html = str_replace("var badgeText = (res.target_ia === 'runpod') ? 'RUNPOD GPU' : 'LLAMA.CPP LOCAL';", "var badgeText = 'RUNPOD GPU';", $html);
$html = str_replace("var badgeText = (c.valor === 'auto') ? 'AUTO' : (c.valor === 'local_only' ? 'LOCAL' : 'NUVEM');", "var badgeText = 'RUNPOD';", $html);
$html = str_replace("var badgeText = (modo === 'auto') ? 'AUTO' : (modo === 'local_only' ? 'LOCAL' : 'NUVEM');", "var badgeText = 'RUNPOD';", $html);
$html = str_replace("$('#selectIaMode').val(c.valor);", "$('#selectIaMode').val('cloud_only');", $html);
$html = str_replace("$('#cfg_ia_routing_mode').val(c.valor);", "$('#cfg_ia_routing_mode').val('cloud_only');", $html);
$html = str_replace("data: JSON.stringify({ chave: 'ia_routing_mode', valor: modo })", "data: JSON.stringify({ chave: 'ia_routing_mode', valor: 'cloud_only' })", $html);
$html = str_replace("var chaves = ['ia_provider', 'ia_routing_mode', 'ia_cloud_keywords', 'runpod_api_key', 'runpod_endpoint_id', 'runpod_model', 'local_model', 'jarvis_activation_word', 'jarvis_voice'];", "var chaves = ['ia_provider', 'ia_routing_mode', 'runpod_api_key', 'runpod_endpoint_id', 'runpod_model', 'jarvis_activation_word', 'jarvis_voice'];", $html);
$html = str_replace('Estratégia de Roteamento Multi-IA:', 'Modo de Inteligência Artificial:', $html);
$html = str_replace('Provedor Padrão de Inteligência Artificial:', 'Provedor de Inteligência Artificial:', $html);
$html = str_replace('Configurações e parâmetros Multi-IA atualizados com sucesso!', 'Configurações do RunPod atualizadas com sucesso!', $html);
$html = str_replace('Configurações (RunPod / IA)', 'Configurações RunPod', $html);
$html = str_replace('Parâmetros do Sistema & RunPod.io GPU', 'Configuração RunPod.io GPU', $html);
$html = str_replace('Modelo Nuvem (RunPod):', 'Modelo RunPod:', $html);
$html = str_replace('Modelo Local (llama.cpp):', 'Modelo Local (não utilizado pelo site):', $html);
$html = str_replace('Tarefas residenciais rápidas (luz, irrigação, sensores) rodam localmente com latência mínima. Perguntas complexas de programação, cálculos ou pesquisa utilizam o RunPod GPU.', 'Todas as solicitações de inteligência artificial feitas pelo site são processadas exclusivamente pelo RunPod GPU.', $html);

$siteEnhancements = <<<'HTML'
<style>
.jarvis-device-bar{position:fixed;right:16px;bottom:16px;z-index:9999;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;max-width:620px}
.jarvis-device-bar a{display:block;text-decoration:none;color:#241c25;font-weight:900;padding:11px 16px;border-radius:18px 18px 4px 18px;box-shadow:0 3px 12px #0002}
.jarvis-device-bar .devices{background:#8eb9ee}.jarvis-device-bar .family{background:#d8b4ea}.jarvis-device-bar .watch{background:#f59c73}.jarvis-device-bar .adaptive{background:#f2a000}
@media(max-width:720px){.jarvis-device-bar{left:8px;right:8px;bottom:8px}.jarvis-device-bar a{flex:1;text-align:center;padding:10px 6px;font-size:12px}}
</style>
<div class="jarvis-device-bar" aria-label="Integração celular, relógio e interface adaptativa">
  <a class="adaptive" href="/casa/ui_adaptativa.php">UI ADAPTATIVA</a>
  <a class="devices" href="/casa/dispositivos_pessoais.php">CELULAR + WATCH</a>
  <a class="family" href="/casa/familia.php">FAMÍLIA</a>
  <a class="watch" href="/casa/dispositivos_pessoais.php#watch">WATCH STATUS</a>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var mode = document.getElementById('selectIaMode');
  if (mode) { mode.value = 'cloud_only'; mode.disabled = true; }
  var cfgMode = document.getElementById('cfg_ia_routing_mode');
  if (cfgMode) { cfgMode.value = 'cloud_only'; cfgMode.disabled = true; }
  var provider = document.getElementById('cfg_ia_provider');
  if (provider) { provider.value = 'runpod'; provider.disabled = true; }
  var badge = document.getElementById('provedorBadge');
  if (badge) badge.textContent = 'RUNPOD';
});
</script>
HTML;
$html = str_ireplace('</body>', $siteEnhancements . "\n</body>", $html);
$html = str_ireplace('<body>', '<body class="lcars-dashboard">', $html);

echo $html;
