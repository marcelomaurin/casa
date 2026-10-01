const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const html = fs.readFileSync(path.join(__dirname, '../../clusters/site/index.html'), 'utf8');
assert.ok(html.indexOf('id="process-heading"') < html.indexOf('<!-- Cluster Services Section -->'));
const script = html.match(/<script id="processes-script">([\s\S]*?)<\/script>/)[1];
const elements = new Map();
const makeElement = () => ({value: '', children: [], disabled: false, textContent: '',
  appendChild(child) { this.children.push(child); }, replaceChildren() { this.children = []; },
  setAttribute() {}, addEventListener() {}, focus() {}});
const document = {activeElement: null, createElement: makeElement,
  getElementById(id) { if (!elements.has(id)) elements.set(id, makeElement()); return elements.get(id); }};
const context = {document, console, Date, AbortController, setTimeout: () => 1, clearTimeout() {}, setInterval() {},
  fetch: async () => ({ok: false, status: 503})};
vm.createContext(context);
vm.runInContext(script, context);
const run = source => vm.runInContext(source, context);
const sample = {pid: 222, start_time: '123', name: '<img onerror=bad>', user: 'root', state: 'S', cpu_pct: 0, memory_mb: 4, can_terminate: true};
run('processFresh = true; processTerminationEnabled = true;');
document.getElementById('process-admin-token').value = 'test';
context.renderProcesses([sample]);
run("selectedProcesses.add('222:123'); updateProcessControls();");
assert.equal(document.getElementById('process-kill').disabled, false);
assert.equal(document.getElementById('process-rows').children[0].children[1].textContent, '<img onerror=bad>');
assert.equal(document.getElementById('process-rows').children[0].children[5].textContent, '0%');
context.renderProcesses([{...sample, start_time: '456'}]);
assert.equal(run('selectedProcesses.size'), 0, 'reused PID must lose its selection');
run("selectedProcesses.add('222:456'); processFresh = false; updateProcessControls();");
assert.equal(document.getElementById('process-kill').disabled, true, 'stale list must not enable termination');
context.renderProcesses([{...sample, can_terminate: false, protected: true}]);
assert.equal(document.getElementById('process-rows').children[0].children[0].children[0].disabled, true);
context.renderProcesses([]);
assert.match(document.getElementById('process-rows').children[0].children[0].textContent, /Nenhum processo/);
console.log('Cluster processes UI: OK (placement, safe text, selection identity, stale data, protected, empty).');

(async () => {
  await new Promise(resolve => setImmediate(resolve));
  const requests = [];
  context.fetch = async (url, options = {}) => {
    requests.push({url, ...options});
    return {ok: true, json: async () => options.method === 'POST'
      ? {results: [{pid: 222, ok: true, message: 'Encerramento solicitado.'}]}
      : {processes: [sample], termination_enabled: true}};
  };
  context.renderProcesses([sample]);
  run("processFresh = true; processTerminationEnabled = true; selectedProcesses.add('222:123');");
  document.getElementById('process-admin-token').value = 'test-only-token';
  context.updateProcessControls();
  context.prepareProcessTermination();
  assert.equal(document.getElementById('process-confirm-box').hidden, false);
  assert.match(document.getElementById('process-confirm-message').textContent, /PID 222/);
  context.cancelProcessTermination();
  assert.equal(requests.length, 0, 'cancel must not send anything');
  context.prepareProcessTermination();
  await context.killSelectedProcesses();
  const post = requests.find(request => request.method === 'POST');
  assert.equal(post.headers.Authorization, 'Bearer test-only-token');
  assert.deepEqual(JSON.parse(post.body), {processes: [{pid: 222, start_time: '123'}]});
  assert.equal(document.getElementById('process-admin-token').value, '');
  assert.match(document.getElementById('process-result').textContent, /Encerramento solicitado/);
  console.log('Process confirmation: OK (cancel, explicit confirmation, identity payload, token cleared).');
})().catch(error => { console.error(error); process.exitCode = 1; });
