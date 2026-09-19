package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.pm.PackageManager
import android.os.Bundle
import android.webkit.PermissionRequest
import android.webkit.WebChromeClient
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import org.json.JSONObject

/**
 * Terminal WebRTC do JARVIS Mobile.
 *
 * O Watch apenas inicia/controla a chamada. O fluxo de mídia usa câmera,
 * microfone e WebRTC do Android através do WebView, compartilhando a mesma
 * sinalização /api/v1/family.php usada pelo navegador.
 */
class FamilyCallActivity : ComponentActivity() {
    companion object {
        const val EXTRA_CALL_ID = "family_call_id"
        const val EXTRA_CALL_MODE = "family_call_mode"
        const val EXTRA_AUTO_JOIN = "family_call_auto_join"
    }

    private var webView: WebView? = null
    private var started = false

    private val permissions = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { startWebRtcIfAllowed() }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        startWebRtcIfAllowed()
    }

    private fun startWebRtcIfAllowed() {
        if (started) return
        val mode = intent.getStringExtra(EXTRA_CALL_MODE).orEmpty().ifBlank { "video" }
        val needed = buildList {
            if (ContextCompat.checkSelfPermission(this@FamilyCallActivity, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
                add(Manifest.permission.RECORD_AUDIO)
            }
            if (mode == "video" && ContextCompat.checkSelfPermission(this@FamilyCallActivity, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
                add(Manifest.permission.CAMERA)
            }
        }
        if (needed.isNotEmpty()) {
            permissions.launch(needed.toTypedArray())
            return
        }

        started = true
        val callId = intent.getLongExtra(EXTRA_CALL_ID, 0L)
        if (callId <= 0L) {
            finish()
            return
        }

        val cfg = JarvisApi.loadConfig(this)
        if (!cfg.baseUrl.startsWith("https://") || cfg.token.isBlank()) {
            finish()
            return
        }

        val view = WebView(this)
        webView = view
        setContentView(view)

        view.settings.javaScriptEnabled = true
        view.settings.domStorageEnabled = true
        view.settings.mediaPlaybackRequiresUserGesture = false
        view.webViewClient = WebViewClient()
        view.webChromeClient = object : WebChromeClient() {
            override fun onPermissionRequest(request: PermissionRequest) {
                runOnUiThread {
                    val allowed = request.resources.filter {
                        it == PermissionRequest.RESOURCE_AUDIO_CAPTURE ||
                            it == PermissionRequest.RESOURCE_VIDEO_CAPTURE
                    }.toTypedArray()
                    if (allowed.isNotEmpty()) request.grant(allowed) else request.deny()
                }
            }
        }

        val html = buildHtml(cfg.baseUrl.trimEnd('/'), cfg.token, callId, mode)
        view.loadDataWithBaseURL(
            cfg.baseUrl.trimEnd('/') + "/",
            html,
            "text/html",
            "UTF-8",
            null
        )
    }

    private fun buildHtml(baseUrl: String, token: String, callId: Long, mode: String): String {
        val base = JSONObject.quote(baseUrl)
        val tok = JSONObject.quote(token)
        val callMode = JSONObject.quote(if (mode == "audio") "audio" else "video")
        return """
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<style>
body{margin:0;background:#fff8e8;color:#241f25;font-family:Arial,sans-serif}
header{background:#f4a261;padding:18px;font-weight:800;font-size:20px}
main{padding:14px}.status{font-weight:700;padding:8px 0 14px}
video{width:100%;background:#111;border-radius:14px;min-height:180px;margin-bottom:10px}
button{width:100%;border:0;border-radius:18px;padding:14px;font-weight:800;background:#ee8f83;color:#241f25}
</style>
</head>
<body>
<header>JARVIS • VIDEOCHAMADA</header>
<main>
<div id="status" class="status">Conectando...</div>
<video id="local" autoplay muted playsinline></video>
<div id="remotes"></div>
<button onclick="endCall()">ENCERRAR</button>
</main>
<script>
const BASE=$base;
const TOKEN=$tok;
const CALL=$callId;
const MODE=$callMode;
let lastSignal=0,localStream=null;
const peers={};

async function api(action,data=null,query=''){
  const opt={headers:{
    'Content-Type':'application/json',
    'Accept':'application/json',
    'Authorization':'Bearer '+TOKEN,
    'X-Device-Token':TOKEN
  }};
  if(data!==null){opt.method='POST';opt.body=JSON.stringify(data)}
  const r=await fetch(BASE+'/api/v1/family.php?acao='+action+query,opt);
  const j=await r.json().catch(()=>({}));
  if(!r.ok && r.status!==409) throw new Error(j.mensagem||('HTTP '+r.status));
  return j;
}
async function media(){
  if(localStream)return localStream;
  localStream=await navigator.mediaDevices.getUserMedia({audio:true,video:MODE==='video'});
  document.getElementById('local').srcObject=localStream;
  return localStream;
}
async function signal(type,payload,target=null){
  await api('call_signal',{call_id:CALL,type,payload,target});
}
async function peer(name,initiator=false){
  if(peers[name])return peers[name];
  const pc=new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'}]});
  peers[name]=pc;
  if(localStream)localStream.getTracks().forEach(t=>pc.addTrack(t,localStream));
  pc.onicecandidate=e=>{if(e.candidate)signal('ice',e.candidate,name)};
  pc.ontrack=e=>{
    let v=document.getElementById('p_'+btoa(unescape(encodeURIComponent(name))).replace(/=/g,''));
    if(!v){v=document.createElement('video');v.id='p_'+btoa(unescape(encodeURIComponent(name))).replace(/=/g,'');v.autoplay=true;v.playsInline=true;document.getElementById('remotes').appendChild(v)}
    v.srcObject=e.streams[0];
  };
  if(initiator){const o=await pc.createOffer();await pc.setLocalDescription(o);await signal('offer',o,name)}
  return pc;
}
async function pollSignals(){
  const r=await api('call_poll',null,'&call_id='+CALL+'&after='+lastSignal);
  for(const s of r.signals||[]){
    lastSignal=Math.max(lastSignal,+s.id);
    const p=JSON.parse(s.payload||'{}');
    if(s.tipo==='join'){await peer(s.remetente,true)}
    else if(s.tipo==='offer'){const pc=await peer(s.remetente,false);await pc.setRemoteDescription(p);const a=await pc.createAnswer();await pc.setLocalDescription(a);await signal('answer',a,s.remetente)}
    else if(s.tipo==='answer'){const pc=await peer(s.remetente,false);await pc.setRemoteDescription(p)}
    else if(s.tipo==='ice'){const pc=await peer(s.remetente,false);try{await pc.addIceCandidate(p)}catch(e){}}
  }
}
async function boot(){
  try{
    await media();
    await api('call_join',{call_id:CALL,platform:'mobile',device:'JARVIS Mobile'});
    await signal('join',{mode:MODE});
    document.getElementById('status').textContent='Chamada '+MODE+' #'+CALL;
    setInterval(()=>pollSignals().catch(()=>{}),1000);
  }catch(e){document.getElementById('status').textContent='Falha: '+e.message}
}
async function endCall(){
  try{await api('call_end',{call_id:CALL,origin:'mobile'})}catch(e){}
  Object.values(peers).forEach(p=>p.close());
  if(localStream)localStream.getTracks().forEach(t=>t.stop());
  document.getElementById('status').textContent='Chamada encerrada';
}
boot();
</script>
</body>
</html>
        """.trimIndent()
    }

    override fun onDestroy() {
        webView?.apply {
            loadUrl("about:blank")
            destroy()
        }
        webView = null
        super.onDestroy()
    }
}
