<?php
/**
 * CASA/JARVIS - entrada principal responsiva com tema LCARS.
 *
 * O dashboard funcional original foi preservado em index_legacy.php.
 * Este arquivo aplica o tema LCARS diretamente na resposta HTML, sem depender
 * de mod_substitute no Apache/Hostinger.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['auth_user'])) {
    header('Location: /casa/login.php');
    exit;
}

ob_start();
require __DIR__ . '/index_legacy.php';
$html = ob_get_clean();

$lcarsLink = '<link rel="stylesheet" href="/casa/lcars.css?v=20260914b">';
if (stripos($html, '/casa/lcars.css') === false) {
    $html = str_ireplace('</head>', "  {$lcarsLink}\n</head>", $html);
}

// Ajustes de implantação sob /casa e remoção de referências fixas do cabeçalho.
$html = str_replace('/login.php?logout=1', '/casa/login.php?logout=1', $html);
$html = str_replace('ONLINE • 192.168.2.12', 'CASA DISTRIBUÍDA • ONLINE', $html);

// Site: IA exclusivamente via RunPod. O endpoint web não possui fallback local.
$html = str_replace("url: 'api/jarvis.php'", "url: 'api/jarvis_site.php'", $html);
$html = str_replace(
    '<option value="auto">⚡ Híbrido (Local: Residencial / Nuvem: Dev & Análise)</option>\n                <option value="local_only">🏠 Apenas Local (llama.cpp)</option>\n                <option value="cloud_only">☁️ Apenas Nuvem (RunPod GPU)</option>',
    '<option value="cloud_only" selected>☁️ RunPod GPU</option>',
    $html
);
$html = str_replace(
    '<option value="auto">⚡ Híbrido Inteligente (Local: Automação / Nuvem: Programação, Análise e Pesquisa)</option>\n              <option value="local_only">🏠 Apenas IA Local (Desativa nuvem, roda tudo no Raspberry)</option>\n              <option value="cloud_only">☁️ Apenas Nuvem Externa (Desativa local, direciona tudo para RunPod GPU)</option>',
    '<option value="cloud_only" selected>☁️ Somente RunPod GPU</option>',
    $html
);
$html = str_replace(
    '<option value="local">Local (llama.cpp no Raspberry Pi 4)</option>\n              <option value="runpod">Nuvem GPU Serverless (RunPod.io)</option>',
    '<option value="runpod" selected>RunPod.io GPU</option>',
    $html
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

// Força valores padrão no navegador mesmo se o banco ainda possuir configuração antiga.
$runpodDefaults = <<<'HTML'
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
$html = str_ireplace('</body>', $runpodDefaults . "\n</body>", $html);

// Marca explicitamente o dashboard para o CSS responsivo LCARS.
$html = str_ireplace('<body>', '<body class="lcars-dashboard">', $html);

echo $html;
