const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const html = fs.readFileSync(path.join(__dirname, '../../clusters/site/index.html'), 'utf8');
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const elements = new Map();
const document = {
  body: {style: {overflow: ''}},
  activeElement: null,
  getElementById(id) {
    if (!elements.has(id)) elements.set(id, {
      hidden: id === 'cpu-overlay', style: {}, attributes: {},
      addEventListener() {}, setAttribute(key, value) { this.attributes[key] = value; },
      focus() { document.activeElement = this; },
    });
    return elements.get(id);
  },
  addEventListener() {},
};
const context = {document, console, Date, AbortController, setTimeout: () => 1, clearTimeout() {},
  setInterval() {}, clearInterval() {}, fetch: async () => ({ok: false, status: 503})};
vm.createContext(context);
vm.runInContext(script, context);
const el = id => document.getElementById(id);
context.updateCpu({timestamp: 100, usage_pct: 0, cores: 4, temp_c: 0, load_1m: 0,
  history: [{timestamp: 98, usage_pct: 50}, {timestamp: 99, usage_pct: 0}, {timestamp: 100, usage_pct: 0}]});
assert.equal(el('cpu-detail-usage').textContent, '0%');
assert.equal(el('cpu-progress').style.width, '0%');
assert.equal(el('cpu-detail-temp').textContent, '0 °C');
assert.equal(el('cpu-chart-message').hidden, true);
assert.match(el('cpu-chart-line').attributes.d, /600.00,200.00$/);
context.updateCpu({timestamp: 99, usage_pct: 90});
assert.equal(el('cpu-detail-usage').textContent, '0%', 'older requests must not replace newer values');
context.updateCpu({timestamp: 110, usage_pct: null, history: [
  {timestamp: 40, usage_pct: 80}, {timestamp: 100, usage_pct: 10},
  {timestamp: 101, usage_pct: null}, {timestamp: 109, usage_pct: 20}, {timestamp: 110, usage_pct: 30},
]});
assert.equal(el('cpu-detail-usage').textContent, '—');
assert.equal((el('cpu-chart-line').attributes.d.match(/M/g) || []).length, 2, 'missing samples must break the line');
context.cpuUnavailable();
assert.equal(el('cpu-chart-message').hidden, false);
assert.equal(el('cpu-detail-usage').textContent, '—');
document.activeElement = el('cpu-card');
context.openCpuChart();
assert.equal(el('cpu-overlay').hidden, false);
assert.equal(document.activeElement, el('cpu-close'));
context.closeCpuChart();
assert.equal(el('cpu-overlay').hidden, true);
assert.equal(document.activeElement, el('cpu-card'));
assert.equal(document.body.style.overflow, '');
console.log('Cluster CPU UI: OK (zero, missing data, time axis, gaps, stale responses, dialog focus).');
