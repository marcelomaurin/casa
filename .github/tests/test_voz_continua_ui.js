// Reconhecimento de voz contínuo do COMPUTER (lcars-site.js): trechos por pausa, fila durante
// o processamento, descarte da própria fala, reinício automático, Android e botão curto.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

const fonte = fs.readFileSync(path.join(__dirname, '../../site/var/www/html/lcars-site.js'), 'utf8');
const ini = fonte.indexOf('// Reconhecimento de voz CONTÍNUO.');
const fim = fonte.indexOf('// Carrega a lista de vozes assim que o navegador disponibilizá-la.');
assert.ok(ini > 0 && fim > ini, 'bloco de voz não encontrado');
const bloco = fonte.slice(fonte.lastIndexOf('\n', ini - 80), fim);
const iFala = fonte.indexOf('function setComputerSpeaking(active){');
const fFala = fonte.indexOf('function speakComputer(text){');
assert.ok(iFala > 0 && fFala > iFala, 'setComputerSpeaking não encontrada');
const blocoFala = fonte.slice(iFala, fFala);

function ambiente(userAgent) {
  let agora = 1_700_000_000_000; const timers = [];
  const elementos = new Map();
  const el = id => {
    if (!elementos.has(id)) elementos.set(id, {
      id, value: '', textContent: '', title: '', hidden: false, disabled: false, style: {}, className: '',
      attrs: {}, classList: { set: new Set(), toggle(c, on) { on ? this.set.add(c) : this.set.delete(c); }, add(c) { this.set.add(c); }, remove(c) { this.set.delete(c); }, contains(c) { return this.set.has(c); } },
      setAttribute(k, v) { this.attrs[k] = v; }, querySelector() { return null; },
    });
    return elementos.get(id);
  };
  const reconhecedores = [];
  class FalsoSR {
    constructor() { reconhecedores.push(this); this.iniciado = false; this.parado = false; }
    start() { this.iniciado = true; }
    stop() { this.parado = true; }
    abort() { this.parado = true; }
  }
  const chat = []; const enviados = [];
  const ctx = {
    console, Date: { now: () => agora },
    setTimeout: (fn, ms) => { timers.push({ quando: agora + ms, fn }); return timers.length; },
    clearTimeout: id => { if (id && timers[id - 1]) timers[id - 1].fn = null; },
    navigator: { userAgent, mediaDevices: {} }, window: { innerWidth: 1280, SpeechRecognition: FalsoSR },
    document: { activeElement: null, getElementById: el },
    appendChat: (quem, texto, cls) => chat.push({ quem, texto, cls }), refreshLeftVoiceStatus() {},
    computerRequestInFlight: false, currentGroup: 'IA & VOZ', currentItem: 'jarvis',
  };
  ctx.window.matchMedia = () => ({ matches: false });
  vm.createContext(ctx);
  vm.runInContext('var computerRequestInFlight=false;' + bloco + '\n' + blocoFala + '\nvoiceCoreOk=true;', ctx);
  const run = src => vm.runInContext(src, ctx);
  const avancar = ms => {
    const alvo = agora + ms;
    for (;;) {
      const prox = timers.filter(t => t.fn && t.quando <= alvo).sort((a, b) => a.quando - b.quando)[0];
      if (!prox) break;
      agora = prox.quando; const fn = prox.fn; prox.fn = null; fn();
    }
    agora = alvo;
  };
  // Monta um evento de resultado: [[texto, final], ...]
  const resultado = (sr, lista) => {
    const results = lista.map(([t, f]) => Object.assign([{ transcript: t }], { isFinal: f }));
    sr.onresult({ results, resultIndex: 0 });
  };
  const input = el('ja-command-input');
  ctx.enviar = texto => { enviados.push(texto); };
  return { ctx, run, avancar, resultado, reconhecedores, chat, enviados, input, el, espera: () => new Promise(r => setImmediate(r)) };
}

(async () => {
  // ---------------- Computador ----------------
  const A = ambiente('Mozilla/5.0 (X11; Linux x86_64) Chrome/130');
  A.run('ligarVoz(document.getElementById("ja-command-input"), enviar)');
  await A.espera();
  let sr = A.reconhecedores[0];
  assert.ok(sr && sr.iniciado, 'deveria iniciar o reconhecimento');
  assert.equal(sr.continuous, true, 'modo contínuo');
  assert.equal(sr.interimResults, true, 'mostra texto parcial');

  const botao = A.el('ja-command-voice');
  assert.equal(botao.textContent, '🎙️', 'botão curto: só o ícone');
  assert.equal(botao.attrs['aria-pressed'], 'true');

  // 1. Texto parcial aparece; pausa fecha o trecho.
  A.resultado(sr, [['ligue a', false]]);
  assert.equal(A.input.value, 'ligue a', 'texto parcial no campo');
  A.avancar(500);
  A.resultado(sr, [['ligue a luz da sala', true]]);
  A.avancar(899);
  assert.deepEqual(A.enviados, [], 'ainda não houve pausa suficiente');
  A.avancar(2);
  assert.deepEqual(A.enviados, ['ligue a luz da sala'], 'pausa fecha o primeiro trecho');
  assert.equal(A.input.value, '', 'campo limpo após o envio');

  // 2. Mesma sessão: só o que foi dito depois do trecho anterior.
  A.resultado(sr, [['ligue a luz da sala', true], ['desligue a irrigação', true]]);
  A.avancar(950);
  assert.deepEqual(A.enviados.slice(1), ['desligue a irrigação'], 'segundo trecho isolado');

  // 3. Durante o processamento, os trechos entram na fila e saem em ordem.
  A.run('computerRequestInFlight=true');
  A.resultado(sr, [['ligue a luz da sala', true], ['desligue a irrigação', true], ['qual a temperatura', true]]);
  A.avancar(950);
  A.resultado(sr, [['ligue a luz da sala', true], ['desligue a irrigação', true], ['qual a temperatura', true], ['e a umidade', true]]);
  A.avancar(950);
  assert.equal(A.enviados.length, 2, 'nada é enviado enquanto processa');
  assert.equal(A.run('vozFila.length'), 2, 'dois trechos na fila');
  A.run('computerRequestInFlight=false; vozProcessarFila()');
  assert.deepEqual(A.enviados.slice(2), ['qual a temperatura'], 'primeiro da fila');
  A.run('vozProcessarFila()');
  assert.deepEqual(A.enviados.slice(3), ['e a umidade'], 'segundo da fila');

  // 4. A própria fala do COMPUTER é descartada.
  A.run('setComputerSpeaking(true)');
  A.resultado(sr, [['x', true], ['afirmativo luz da sala ligada', false]]);
  A.avancar(2000);
  A.run('setComputerSpeaking(false)');
  A.avancar(200);
  A.resultado(sr, [['x', true], ['afirmativo luz da sala ligada', true]]);
  A.avancar(2000);
  assert.equal(A.enviados.length, 4, 'eco não vira pedido');
  A.avancar(1000);
  A.resultado(sr, [['x', true], ['afirmativo luz da sala ligada', true], ['obrigado', true]]);
  A.avancar(950);
  assert.deepEqual(A.enviados.slice(4), ['obrigado'], 'fala da pessoa depois do eco vale');

  // 5. Ruído curto é ignorado.
  A.resultado(sr, [['x', true], ['afirmativo luz da sala ligada', true], ['obrigado', true], ['é', true]]);
  A.avancar(950);
  assert.equal(A.enviados.length, 5, 'ruído de uma letra não é enviado');

  // 6. O navegador encerra a sessão: o fim da frase não se perde e a escuta reabre.
  A.resultado(sr, [['x', true], ['afirmativo luz da sala ligada', true], ['obrigado', true], ['é', true], ['abre o portão', false]]);
  sr.onend();
  assert.deepEqual(A.enviados.slice(5), ['abre o portão'], 'trecho pendente enviado ao encerrar');
  A.avancar(300);
  await A.espera();
  assert.equal(A.reconhecedores.length, 2, 'reconhecimento reaberto');
  sr = A.reconhecedores[1];
  assert.ok(sr.iniciado);

  // 7. Silêncio (no-speech) não gera mensagem; rede gera uma só.
  sr.onerror({ error: 'no-speech' });
  sr.onerror({ error: 'network' }); sr.onerror({ error: 'network' });
  assert.equal(A.chat.filter(m => m.cls === 'error').length, 1, 'aviso de rede uma vez');

  // 8. Desligar: para e não reabre.
  A.run('desligarVoz()');
  assert.ok(sr.parado, 'reconhecimento parado');
  sr.onend();
  A.avancar(5000);
  await A.espera();
  assert.equal(A.reconhecedores.length, 2, 'não reabre depois de desligar');
  assert.equal(botao.textContent, '🎤', 'ícone de escuta desligada');

  // 9. Permissão negada desliga a escuta.
  A.run('ligarVoz()'); await A.espera();
  const sr3 = A.reconhecedores[2];
  sr3.onerror({ error: 'not-allowed' });
  assert.equal(A.run('mobileVoiceEnabled'), false, 'permissão negada desliga');

  // ---------------- Android (resultados acumulados) ----------------
  const B = ambiente('Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile');
  B.run('ligarVoz(document.getElementById("ja-command-input"), enviar)');
  await B.espera();
  const srB = B.reconhecedores[0];
  B.resultado(srB, [['ligar', true], ['ligar a luz', true], ['ligar a luz da sala', true]]);
  B.avancar(950);
  assert.deepEqual(B.enviados, ['ligar a luz da sala'], 'Android: sem repetição');
  B.resultado(srB, [['ligar', true], ['ligar a luz', true], ['ligar a luz da sala', true], ['fechar', true], ['fechar a porta', true]]);
  B.avancar(950);
  assert.deepEqual(B.enviados.slice(1), ['fechar a porta'], 'Android: segundo trecho');

  console.log('Voz contínua: OK (contínuo, parcial, pausa, fila, eco, ruído, reinício, erros, desligar, Android, botão curto).');
})().catch(e => { console.error(e); process.exit(1); });
