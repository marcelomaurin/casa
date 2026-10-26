/* CASA / COMPUTER - graficos 3D do painel (Three.js, no proprio navegador).
 *
 *   CASA3D.barras(elemento, {
 *     linhas:  ['Dispositivos & relés', 'Cenas e regras'],   // eixo da profundidade
 *     colunas: ['qui', 'sex', 'sáb', 'dom', 'seg', 'ter', 'qua'], // eixo da frente
 *     valores: [[0, 2, 5, ...], [1, 0, 3, ...]],             // valores[linha][coluna]
 *     unidade: 'eventos'
 *   }) -> Promise<{destruir()}>
 *
 * A biblioteca (vendor/three.min.js, ~600 KB) so e baixada na primeira vez que
 * um grafico aparece. Arraste para girar, use a roda do mouse (ou pinca) para
 * aproximar; parado, o grafico gira devagar. Passar o cursor (ou tocar) numa
 * barra mostra o valor. Sem WebGL, o elemento recebe um aviso e nada quebra.
 */
(function(){
  'use strict';
  const CORES = [0xf2a000, 0x8fc7ef, 0xc6a7df, 0xe59bc5, 0xff9966, 0x9999ff, 0xef6969, 0x6fd39a];
  let carregando = null;

  function carregar(){
    if (window.THREE) return Promise.resolve(window.THREE);
    if (carregando) return carregando;
    carregando = new Promise((ok, falha) => {
      const s = document.createElement('script');
      s.src = '/casa/vendor/three.min.js?v=0.149.0';
      s.async = true;
      s.onload = () => window.THREE ? ok(window.THREE) : falha(new Error('Three.js nao carregou'));
      s.onerror = () => { carregando = null; falha(new Error('Falha ao baixar Three.js')); };
      document.head.appendChild(s);
    });
    return carregando;
  }

  function temWebGL(){
    try {
      const c = document.createElement('canvas');
      return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
    } catch (e) { return false; }
  }

  // Texto em textura, para rotulos que acompanham a camera (sprites).
  function rotulo(THREE, texto, opt){
    opt = opt || {};
    const px = 64, pad = 18;
    const c = document.createElement('canvas');
    const ctx = c.getContext('2d');
    ctx.font = '900 ' + px + 'px Arial, sans-serif';
    const w = Math.ceil(ctx.measureText(texto).width) + pad * 2;
    c.width = w; c.height = px + pad;
    ctx.font = '900 ' + px + 'px Arial, sans-serif';
    ctx.textBaseline = 'middle';
    if (opt.fundo) { ctx.fillStyle = opt.fundo; ctx.beginPath(); ctx.roundRect ? ctx.roundRect(0, 0, w, c.height, 22) : ctx.rect(0, 0, w, c.height); ctx.fill(); }
    ctx.fillStyle = opt.cor || '#f6f0e7';
    ctx.fillText(texto, pad, c.height / 2 + 2);
    const tex = new THREE.CanvasTexture(c);
    tex.minFilter = THREE.LinearFilter;
    const sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, transparent: true, depthWrite: false }));
    const altura = opt.altura || 0.32;
    sp.scale.set(altura * w / c.height, altura, 1);
    return sp;
  }

  function encurtar(t, n){ t = String(t || ''); return t.length > n ? t.slice(0, n - 1) + '…' : t; }

  async function barras(el, dados){
    if (!el) return { destruir(){} };
    el.classList.add('ja-3d');
    if (!temWebGL()) { el.innerHTML = '<div class="ja-3d-aviso">Gráfico 3D indisponível neste navegador.</div>'; return { destruir(){} }; }
    let THREE;
    try { THREE = await carregar(); }
    catch (e) { el.innerHTML = '<div class="ja-3d-aviso">Não foi possível carregar o gráfico 3D.</div>'; return { destruir(){} }; }
    if (!el.isConnected) return { destruir(){} };

    const linhas = dados.linhas || [], colunas = dados.colunas || [], valores = dados.valores || [];
    const nL = Math.max(1, linhas.length), nC = Math.max(1, colunas.length);
    let max = 0;
    valores.forEach(l => (l || []).forEach(v => { max = Math.max(max, Number(v) || 0); }));
    const ALT = 3.2, PASSO = 1.15, LADO = 0.72;
    // Painel alto e estreito (coluna lateral, celular): os dias correm em
    // profundidade e a camera fica mais alta, usando a altura do painel.
    const deitado = el.clientWidth < el.clientHeight * 0.95;

    el.innerHTML = '<div class="ja-3d-dica" aria-hidden="true"></div>';
    const dica = el.firstChild;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
    renderer.setPixelRatio(Math.min(2, window.devicePixelRatio || 1));
    renderer.domElement.setAttribute('role', 'img');
    renderer.domElement.setAttribute('aria-label', dados.descricao || 'Gráfico 3D de barras');
    el.appendChild(renderer.domElement);

    const cena = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 1000);
    cena.add(new THREE.AmbientLight(0xffffff, 0.62));
    const luz = new THREE.DirectionalLight(0xffffff, 0.85);
    luz.position.set(6, 12, 8);
    cena.add(luz);

    const grupo = new THREE.Group();
    cena.add(grupo);
    const largura = (deitado ? nL : nC) * PASSO, profundidade = (deitado ? nC : nL) * PASSO;
    const x0 = -largura / 2 + PASSO / 2, z0 = -profundidade / 2 + PASSO / 2;
    // Posicao da barra (linha i, coluna j) no chao, conforme a orientacao.
    const pos = (i, j) => deitado ? { x: x0 + i * PASSO, z: z0 + j * PASSO } : { x: x0 + j * PASSO, z: z0 + i * PASSO };

    // Base e grade do chao.
    const base = new THREE.Mesh(new THREE.BoxGeometry(largura + 0.5, 0.08, profundidade + 0.5),
      new THREE.MeshLambertMaterial({ color: 0x0b1724 }));
    base.position.y = -0.04;
    grupo.add(base);
    const grade = new THREE.GridHelper(Math.max(largura, profundidade) + 0.5, Math.max(nC, nL) * 2, 0x24516b, 0x16324a);
    grade.position.y = 0.002;
    grade.scale.set((largura + 0.5) / (Math.max(largura, profundidade) + 0.5), 1, (profundidade + 0.5) / (Math.max(largura, profundidade) + 0.5));
    grupo.add(grade);

    // Barras.
    const barrasMesh = [];
    const geo = new THREE.BoxGeometry(LADO, 1, LADO);
    geo.translate(0, 0.5, 0);
    for (let i = 0; i < nL; i++) {
      const cor = CORES[i % CORES.length];
      for (let j = 0; j < nC; j++) {
        const v = Number((valores[i] || [])[j]) || 0;
        const h = max > 0 ? Math.max(0.04, v / max * ALT) : 0.04;
        const mat = new THREE.MeshLambertMaterial({ color: cor, transparent: v === 0, opacity: v === 0 ? 0.28 : 1 });
        const m = new THREE.Mesh(geo, mat);
        const p0 = pos(i, j);
        m.position.set(p0.x, 0, p0.z);
        m.scale.y = 0.001;
        m.userData = { alvo: h, valor: v, linha: linhas[i], coluna: colunas[j], cor };
        grupo.add(m);
        barrasMesh.push(m);
        if (v > 0 && nL * nC <= 40) {
          const r = rotulo(THREE, String(v), { altura: 0.26 });
          r.position.set(m.position.x, h + 0.25, m.position.z);
          r.userData.segue = m;
          grupo.add(r);
        }
      }
    }
    // Rotulos das colunas (dias) junto a borda do chao. Os nomes das linhas
    // ficam na legenda HTML, fora da cena, para nao roubar largura.
    colunas.forEach((c, j) => {
      const r = rotulo(THREE, encurtar(c, 6), { altura: deitado ? 0.5 : 0.34, cor: '#a9c7e8' });
      const p0 = pos(0, j);
      if (deitado) { r.center.set(0, 0.5); r.position.set(largura / 2 + 0.3, 0.05, p0.z); }
      else r.position.set(p0.x, 0.05, profundidade / 2 + 0.55);
      grupo.add(r);
    });
    const rotuloMax = deitado ? 1.3 : 0;

    // Camera em coordenadas esfericas em volta do centro da cena (barras +
    // rotulos). A distancia e recalculada pela largura do painel, para a cena
    // inteira caber tanto num painel largo quanto num estreito ou no celular.
    const minX = -largura / 2 - 0.3, maxX = largura / 2 + 0.3 + rotuloMax;
    const minZ = -profundidade / 2 - 0.3, maxZ = profundidade / 2 + (deitado ? 0.3 : 0.9);
    const alvo = new THREE.Vector3((minX + maxX) / 2, ALT * 0.38, (minZ + maxZ) / 2);
    const azBase = deitado ? 0.42 : -0.5, giro = deitado ? 0.16 : 0.45;
    let az = azBase, el_ = deitado ? 0.62 : 0.5, zoom = 1, dist = 10;
    // Menor distancia em que os 8 cantos da cena aparecem na tela, testando os
    // angulos que a rotacao automatica percorre (busca binaria, so no resize).
    const cantos = [];
    [minX, maxX].forEach(x => [0, ALT + 0.5].forEach(y => [minZ, maxZ].forEach(z => cantos.push(new THREE.Vector3(x, y, z)))));
    const tmp = new THREE.Vector3();
    function cabe(d, a){
      camera.position.set(alvo.x + d * Math.cos(el_) * Math.sin(a), alvo.y + d * Math.sin(el_), alvo.z + d * Math.cos(el_) * Math.cos(a));
      camera.lookAt(alvo); camera.updateMatrixWorld();
      return cantos.every(c => { tmp.copy(c).project(camera); return Math.abs(tmp.x) <= 0.94 && Math.abs(tmp.y) <= 0.9 && tmp.z < 1; });
    }
    function distanciaIdeal(){
      let pior = 2;
      for (const a of [azBase - giro, azBase, azBase + giro]) {
        let lo = 1, hi = 150;
        for (let k = 0; k < 24; k++) { const m = (lo + hi) / 2; if (cabe(m, a)) hi = m; else lo = m; }
        pior = Math.max(pior, hi);
      }
      return pior;
    }
    function posicionar(){
      camera.position.set(alvo.x + dist * Math.cos(el_) * Math.sin(az), alvo.y + dist * Math.sin(el_), alvo.z + dist * Math.cos(el_) * Math.cos(az));
      camera.lookAt(alvo);
    }
    posicionar();

    function redimensionar(){
      const w = Math.max(10, el.clientWidth), h = Math.max(10, el.clientHeight);
      renderer.setSize(w, h, false);
      renderer.domElement.style.width = w + 'px';
      renderer.domElement.style.height = h + 'px';
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      dist = distanciaIdeal() * zoom;
      posicionar();
    }
    redimensionar();
    const ro = window.ResizeObserver ? new ResizeObserver(redimensionar) : null;
    if (ro) ro.observe(el);

    // Interacao: arrastar gira, roda/pinca aproxima, cursor mostra o valor.
    let arrastando = false, ultimoX = 0, ultimoY = 0, ultimaInteracao = 0;
    const toques = new Map();
    let pinca0 = 0;
    const ray = new THREE.Raycaster(), ponto = new THREE.Vector2();
    const cv = renderer.domElement;
    cv.style.touchAction = 'none';
    function mostrarValor(ev){
      const r = cv.getBoundingClientRect();
      ponto.x = ((ev.clientX - r.left) / r.width) * 2 - 1;
      ponto.y = -((ev.clientY - r.top) / r.height) * 2 + 1;
      ray.setFromCamera(ponto, camera);
      const hit = ray.intersectObjects(barrasMesh, false)[0];
      barrasMesh.forEach(m => m.material.emissive && m.material.emissive.setHex(0x000000));
      if (hit) {
        const d = hit.object.userData;
        hit.object.material.emissive.setHex(0x333333);
        dica.textContent = encurtar(d.linha, 30) + ' · ' + d.coluna + ': ' + d.valor + (dados.unidade ? ' ' + dados.unidade : '');
        dica.style.left = Math.min(r.width - 10, ev.clientX - r.left + 12) + 'px';
        dica.style.top = Math.max(0, ev.clientY - r.top - 30) + 'px';
        dica.style.display = 'block';
      } else dica.style.display = 'none';
    }
    cv.addEventListener('pointerdown', ev => {
      toques.set(ev.pointerId, ev);
      if (toques.size === 2) { const [a, b] = [...toques.values()]; pinca0 = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY); }
      arrastando = true; ultimoX = ev.clientX; ultimoY = ev.clientY; ultimaInteracao = performance.now();
      cv.setPointerCapture(ev.pointerId);
      mostrarValor(ev);
    });
    cv.addEventListener('pointermove', ev => {
      if (toques.has(ev.pointerId)) toques.set(ev.pointerId, ev);
      if (toques.size === 2) {
        const [a, b] = [...toques.values()];
        const d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
        if (pinca0) { zoom = Math.min(1.8, Math.max(0.5, zoom * pinca0 / d)); dist = distanciaIdeal() * zoom; }
        pinca0 = d; posicionar(); ultimaInteracao = performance.now(); return;
      }
      if (arrastando) {
        az -= (ev.clientX - ultimoX) * 0.008;
        el_ = Math.min(1.35, Math.max(0.12, el_ + (ev.clientY - ultimoY) * 0.006));
        ultimoX = ev.clientX; ultimoY = ev.clientY; ultimaInteracao = performance.now();
        posicionar();
      }
      mostrarValor(ev);
    });
    const soltar = ev => { toques.delete(ev.pointerId); if (!toques.size) { arrastando = false; pinca0 = 0; } };
    cv.addEventListener('pointerup', soltar);
    cv.addEventListener('pointercancel', soltar);
    cv.addEventListener('pointerleave', () => { dica.style.display = 'none'; });
    cv.addEventListener('wheel', ev => {
      ev.preventDefault();
      zoom = Math.min(1.8, Math.max(0.5, zoom * (ev.deltaY > 0 ? 1.08 : 0.93)));
      dist = distanciaIdeal() * zoom;
      ultimaInteracao = performance.now(); posicionar();
    }, { passive: false });

    // Animacao: barras crescem ao abrir; parado ha 4 s, gira devagar.
    const inicio = performance.now();
    let ativo = true, quadro = 0;
    const reduzido = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function desenhar(t){
      if (!ativo) return;
      if (!el.isConnected) { destruir(); return; }
      const p = reduzido ? 1 : Math.min(1, (t - inicio) / 700);
      const e = 1 - Math.pow(1 - p, 3);
      barrasMesh.forEach(m => { m.scale.y = Math.max(0.001, m.userData.alvo * e); });
      grupo.children.forEach(o => { if (o.userData.segue) o.position.y = o.userData.segue.scale.y + 0.25; });
      if (!reduzido && !arrastando && t - ultimaInteracao > 4000) { const alvoAz = azBase + Math.sin((t - inicio) / 6000) * giro; az += (alvoAz - az) * 0.02; posicionar(); }
      renderer.render(cena, camera);
      quadro = requestAnimationFrame(desenhar);
    }
    quadro = requestAnimationFrame(desenhar);

    function destruir(){
      if (!ativo) return;
      ativo = false;
      cancelAnimationFrame(quadro);
      if (ro) ro.disconnect();
      cena.traverse(o => {
        if (o.geometry && o.geometry !== geo) o.geometry.dispose();
        if (o.material) { if (o.material.map) o.material.map.dispose(); o.material.dispose(); }
      });
      geo.dispose();
      renderer.dispose();
    }
    return { destruir };
  }

  // Cor (CSS) usada para a linha i, para a legenda fora do grafico.
  function cor(i){ return '#' + CORES[i % CORES.length].toString(16).padStart(6, '0'); }
  window.CASA3D = { carregar, barras, temWebGL, cor };
})();
