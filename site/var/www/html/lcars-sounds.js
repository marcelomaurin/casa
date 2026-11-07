/* CASA / COMPUTER — sons de interface.
 * tng_viewscreen_on e somente fallback: outros audios disparados pelo clique tem prioridade.
 */
(function (root) {
  'use strict';
  const PREF='casa.ui.sounds.enabled';
  const FALLBACK='/casa/sounds/tng_viewscreen_on.mp3';
  const sounds=new Map([['fallback',FALLBACK]]);
  let muted=false, pending=null, fallbackAudio=null;
  try { muted=root.localStorage.getItem(PREF)==='false'; } catch (_) {}

  function updateToggle() {
    const button=root.document && root.document.getElementById('casa-sound-toggle');
    if (!button) return;
    button.textContent=muted ? '🔇' : '🔊';
    button.title=muted ? 'Ativar sons do Casa' : 'Silenciar sons do Casa';
    button.setAttribute('aria-label',button.title);
    button.setAttribute('aria-pressed',String(!muted));
  }

  function setEnabled(enabled) {
    muted=!Boolean(enabled);
    try { root.localStorage.setItem(PREF,muted ? 'false':'true'); } catch (_) {}
    if (muted && fallbackAudio) {
      fallbackAudio.pause();
      fallbackAudio.currentTime=0;
    }
    updateToggle();
  }

  function claim() {
    if (!pending) return;
    pending.claimed=true;
    root.clearTimeout(pending.timer);
    pending=null;
  }

  function register(name,url) {
    if (typeof name!=='string' || !name || name==='fallback') return false;
    if (typeof url!=='string' || !url.startsWith('/casa/sounds/') ||
        !/\.(?:mp3|ogg|wav)$/i.test(url)) return false;
    sounds.set(name,url);
    return true;
  }

  function play(name) {
    const chosen=(typeof name==='string' && name) ? name : 'fallback';
    const src=sounds.get(chosen);
    if (!src) return false;
    // Chamar um som especifico cancela o som generico do mesmo clique.
    if (chosen!=='fallback') claim();
    if (muted || typeof root.Audio!=='function') return false;
    try {
      const audio=chosen==='fallback'
        ? (fallbackAudio || (fallbackAudio=new root.Audio(src)))
        : new root.Audio(src);
      audio.volume=0.65;
      if (chosen==='fallback') {
        audio.pause();
        audio.currentTime=0;
      }
      const promise=audio.play();
      if (promise && typeof promise.catch==='function') promise.catch(()=>{});
      return true;
    } catch (_) {
      return false;
    }
  }

  function onClick(event) {
    if (muted || !event || !event.target || typeof event.target.closest!=='function') return;
    const button=event.target.closest('button, [role="button"], input[type="button"], input[type="submit"]');
    if (!button || button.disabled || button.getAttribute('aria-disabled')==='true' ||
        button.id==='casa-sound-toggle' || button.getAttribute('data-casa-sound')==='none') return;
    if (pending) claim(); // Um novo clique invalida o fallback anterior.
    const ticket={claimed:false,timer:null};
    pending=ticket;
    // Da tempo ao manipulador da acao para tocar um som especifico.
    ticket.timer=root.setTimeout(()=>{
      if (pending!==ticket || ticket.claimed) return;
      pending=null;
      play('fallback');
    },75);
  }

  function mount() {
    if (!root.document) return;
    const button=root.document.getElementById('casa-sound-toggle');
    if (button) button.addEventListener('click',()=>setEnabled(muted));
    updateToggle();
    root.document.addEventListener('click',onClick,true);
    // Sons HTMLAudioElement externos tambem se sobrepoem ao fallback.
    root.document.addEventListener('play',e=>{
      if (e.target!==fallbackAudio) claim();
    },true);
    // Outros modulos podem disparar: document.dispatchEvent(new CustomEvent('casa:sound-playing'))
    root.document.addEventListener('casa:sound-playing',claim,true);
  }
  root.CASASounds=Object.freeze({
    register,play,claim,
    enabled:()=>!muted,
    setEnabled,
    fallback:FALLBACK
  });
  if (root.document) {
    if (root.document.readyState==='loading')
      root.document.addEventListener('DOMContentLoaded',mount,{once:true});
    else mount();
  }
})(typeof window!=='undefined'?window:globalThis);
