const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const file=fs.readFileSync(path.join(__dirname,'../../site/var/www/html/lcars-site.js'),'utf8');
const start=file.indexOf('function prepareComputerSpeech(text) {');
const end=file.indexOf('function splitComputerSpeech(',start);
assert.ok(start>=0&&end>start,'speech normalizer defined before splitting');
const prepare=new Function(file.slice(start,end)+';return prepareComputerSpeech;')();
const tests=[
  ['A tarefa **Plano #153** não foi rodada.','A tarefa Plano número 153 não foi rodada.'],
  ['## Diagnóstico\n- **Servidor:** offline\n- **Worker:** sem heartbeat','Diagnóstico. Servidor: offline. Worker: sem heartbeat.'],
  ['O programa C# usa `worker.php`.','O programa C# usa worker.php.'],
  ['[Detalhes](https://example.org) do plano #153.','Detalhes do plano número 153.'],
  ['1. Verificar o daemon\n2. Reiniciar serviço','Item 1: Verificar o daemon. Item 2: Reiniciar serviço.'],
  ['```python\nprint("hello")\n```\nTestado','Há um trecho de código na resposta escrita. Testado.'],
  ['O dispositivo não está online.','O dispositivo não está online.'],
  ['', '']
];
for(const [input,expected] of tests){
  assert.equal(prepare(input),expected,JSON.stringify(input));
  assert.equal(prepare(prepare(input)),expected,'idempotent spoken text');
}
assert.match(file,/function speakComputer\(text\)\{\s*const speech=prepareComputerSpeech\(text\);/);
assert.match(file,/speakComputer\(r\.resposta_fala \|\| resposta\)/);
const server=fs.readFileSync(path.join(__dirname,'../../site/var/www/html/api/jarvis.php'),'utf8');
assert.match(server,/\$texto = casa_texto_para_fala\(\(string\)\$texto\);/);
assert.match(server,/'resposta_fala'=>casa_texto_para_fala\(\$respostaLimpa\)/);
console.log('Texto natural de voz no navegador: OK ('+tests.length+' casos).');
