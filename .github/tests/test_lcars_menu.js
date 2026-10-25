// Menu do painel (lcars-site.js): cada funcionalidade em uma area so, todas
// com tela que existe, e os enderecos antigos chegando ao lugar novo.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '../../site/var/www/html/lcars-site.js'), 'utf8');
// Recorta um bloco do codigo (de "inicio" ate a chave que fecha) contando chaves.
function block(start) {
  const i = src.indexOf(start);
  assert.ok(i >= 0, 'nao achei no lcars-site.js: ' + start);
  let depth = 0, j = src.indexOf('{', i);
  for (; j < src.length; j++) {
    if (src[j] === '{') depth++;
    else if (src[j] === '}' && --depth === 0) break;
  }
  return src.slice(i, j + 1) + ';\n';
}
const code = ['const GROUP_ICONS={', 'const GROUPS={', 'const GROUP_ALIASES={', 'const ITEM_ALIASES={',
  'function resolveGroup(name){', 'function locateItem(id){', 'function findItem(group,id){'].map(block).join('');
const ctx = {};
vm.createContext(ctx);
vm.runInContext(code + 'this.out={GROUPS,GROUP_ICONS,resolveGroup,locateItem};', ctx);
const { GROUPS, GROUP_ICONS, resolveGroup, locateItem } = ctx.out;

// Telas que o painel sabe abrir (mountNativeModule).
const known = new Set([...src.matchAll(/if\(m==='([A-Za-z]+)'\) return render/g)].map(m => m[1]));
assert.ok(known.size >= 15, 'nao achei os modulos do mountNativeModule');

const names = Object.keys(GROUPS);
assert.ok(names.length + 1 <= 9, 'barra lateral comporta no maximo 9 botoes (dashboard + areas)');
const seen = new Map(), ids = new Set(), tones = new Set(), codes = new Set();
for (const g of names) {
  const grp = GROUPS[g];
  assert.ok(grp.tone && grp.code && grp.subtitle, g + ': tone, code e subtitle sao obrigatorios');
  assert.ok(!tones.has(grp.tone) && !codes.has(grp.code), g + ': cor e numero devem ser unicos');
  tones.add(grp.tone); codes.add(grp.code);
  assert.ok(GROUP_ICONS[g], g + ': sem icone');
  for (const sec of grp.sections) for (const it of sec.items) {
    assert.ok(!ids.has(it.id), 'id repetido: ' + it.id);
    ids.add(it.id);
    const key = it.module ? 'module:' + it.module : 'url:' + it.url;
    assert.ok(!seen.has(key), it.label + ' aparece em ' + seen.get(key) + ' e em ' + g);
    seen.set(key, g);
    if (it.module) assert.ok(known.has(it.module), it.label + ': modulo sem tela (' + it.module + ')');
  }
}

// Sem referencias a nave ou espaco nos nomes do menu.
const labels = JSON.stringify(GROUPS).toLowerCase();
for (const w of ['nave', 'estelar', 'tatico', 'tático', 'tripula', 'ponte', 'engenharia'])
  assert.ok(!labels.includes('"' + w) && !labels.includes(' ' + w), 'termo de nave no menu: ' + w);

// Enderecos antigos (menu anterior) continuam abrindo a tela certa.
const oldItems = {
  seguranca: 'security', cameras: 'cameras', acessos: '/casa/celular.php', familia: '/casa/familia.php',
  'sensores-sec': 'sensors', 'api-sec': 'external', atualizacoes: 'updates', agendamentos: 'schedules',
  'devices-op': 'devices', 'iot-op': 'iot', cenas: 'automation', 'sensores-op': 'sensors', 'cameras-op': 'cameras',
  'historico-op': 'phrases', devices: 'devices', iot: 'iot', nodes: 'nodes', 'mobile-dev': '/casa/celular.php',
  'watch-dev': '/casa/watch.php', 'family-dev': '/casa/familia.php', 'bin-dev': '/casa/bin/',
  'automation-dev': 'devices', 'automation-ag': 'schedules', 'automation-iot': 'iot', 'automation-scenes': 'automation',
  'automation-agents': 'agents', 'automation-api': 'external', jarvis: 'jarvis', frases: 'phrases', agentes: 'agents',
  'modelos-ia': '/casa/ia_modelos.php', runpod: 'config', webapi: 'external', family: '/casa/familia.php',
  'mobile-person': '/casa/celular.php', 'watch-person': '/casa/watch.php', users: 'users', 'nodes-sys': 'nodes',
  'sensors-sys': 'telemetryOps', 'iot-sys': 'iot', 'bin-sys': '/casa/bin/', 'users-sys': 'users',
  'config-sys': 'config', 'api-sys': 'external', health: 'health'
};
for (const [id, target] of Object.entries(oldItems)) {
  const f = locateItem(id);
  assert.ok(f, 'endereco antigo sem destino: item=' + id);
  assert.equal(f.item.module || f.item.url, target, 'item=' + id + ' abre a tela errada');
}
for (const g of ['SEGURANÇA', 'SEGURANCA', 'OPERAÇÕES', 'DISPOSITIVOS', 'AUTOMAÇÃO', 'IA & VOZ', 'FAMÍLIA', 'SISTEMA'])
  assert.ok(resolveGroup(g), 'grupo antigo sem destino: ' + g);

console.log('Menu LCARS: OK (' + names.length + ' areas, ' + ids.size + ' funcionalidades sem repeticao, '
  + Object.keys(oldItems).length + ' enderecos antigos)');
