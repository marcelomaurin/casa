package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.os.Build
import android.os.Bundle
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request

class MainActivity : ComponentActivity() {
    private var recognizer: SpeechRecognizer? = null
    private var onSpeechResult: ((String) -> Unit)? = null
    private val http = OkHttpClient()

    private enum class Screen { OPERATIONS, VOICE, CONFIG }

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        requestPermissionsIfNeeded()
        setupSpeechRecognizer()
        runCatching { startJarvisService() }
        setContent { JarvisApp() }
    }

    override fun onDestroy() {
        recognizer?.destroy()
        super.onDestroy()
    }

    private fun tr(key: String): String {
        val lang = LanguageManager.currentLanguage(this)
        val pt = mapOf(
            "operations" to "Operações", "voice" to "Voz", "config" to "Configuração",
            "assistant" to "Assistente residencial inteligente", "initializing" to "Inicializando modo local e conexão...",
            "not_configured" to "Não configurado", "online" to "Online", "offline_reconnecting" to "Offline — reconectando",
            "queue" to "Fila", "connection" to "Conexão", "connected_house" to "Online — conectado à casa",
            "offline_detail" to "Offline — o app continua disponível e tenta reconectar automaticamente",
            "waiting" to "comando(s) aguardando envio", "quick_ops" to "Operações rápidas", "turn_on" to "Ligar luz",
            "turn_off" to "Desligar luz", "status" to "Status", "sensors" to "Sensores", "manual_command" to "Comando manual",
            "processing" to "Processando...", "execute" to "Executar", "save_queue" to "Salvar na fila", "result" to "Resultado",
            "manual_ready" to "Pronto para operações manuais.", "executed" to "Comando executado.", "connected_jarvis" to "Conectado ao JARVIS",
            "offline_voice" to "Modo offline — reconexão automática ativa", "you" to "Você", "none" to "Nenhuma frase ainda.",
            "touch_speak" to "Toque em Falar.", "offline_speech" to "Offline. A fala reconhecida será enfileirada para envio quando a conexão voltar.",
            "listening" to "OUVINDO...", "speak" to "FALAR COM O JARVIS", "not_recognized" to "Não consegui reconhecer a fala.",
            "config_loaded" to "Configuração carregada.", "config_help" to "Use a URL HTTPS externa da API da casa. O aplicativo funciona offline e continuará tentando a conexão após salvar.",
            "public_url" to "URL pública da casa", "phone_token" to "Token deste celular", "save" to "Salvar", "test_now" to "Testar agora",
            "testing" to "Testando...", "saved" to "Configuração salva. Reconexão automática ativa.", "language" to "Idioma",
            "automatic" to "Automático (idioma do aparelho)", "language_help" to "A interface, o reconhecimento de voz e as respostas do JARVIS seguem este idioma."
        )
        val en = mapOf(
            "operations" to "Operations", "voice" to "Voice", "config" to "Settings",
            "assistant" to "Smart home assistant", "initializing" to "Starting local mode and connection...",
            "not_configured" to "Not configured", "online" to "Online", "offline_reconnecting" to "Offline — reconnecting",
            "queue" to "Queue", "connection" to "Connection", "connected_house" to "Online — connected to home",
            "offline_detail" to "Offline — the app remains available and keeps reconnecting automatically",
            "waiting" to "command(s) waiting to send", "quick_ops" to "Quick operations", "turn_on" to "Turn light on",
            "turn_off" to "Turn light off", "status" to "Status", "sensors" to "Sensors", "manual_command" to "Manual command",
            "processing" to "Processing...", "execute" to "Execute", "save_queue" to "Save to queue", "result" to "Result",
            "manual_ready" to "Ready for manual operations.", "executed" to "Command executed.", "connected_jarvis" to "Connected to JARVIS",
            "offline_voice" to "Offline mode — automatic reconnection active", "you" to "You", "none" to "No phrase yet.",
            "touch_speak" to "Tap Speak.", "offline_speech" to "Offline. Recognized speech will be queued until the connection returns.",
            "listening" to "LISTENING...", "speak" to "TALK TO JARVIS", "not_recognized" to "I could not recognize the speech.",
            "config_loaded" to "Settings loaded.", "config_help" to "Use the external HTTPS URL for the home API. The app works offline and keeps trying after saving.",
            "public_url" to "Public home URL", "phone_token" to "This phone token", "save" to "Save", "test_now" to "Test now",
            "testing" to "Testing...", "saved" to "Settings saved. Automatic reconnection active.", "language" to "Language",
            "automatic" to "Automatic (device language)", "language_help" to "The interface, speech recognition and JARVIS responses follow this language."
        )
        val es = mapOf(
            "operations" to "Operaciones", "voice" to "Voz", "config" to "Configuración",
            "assistant" to "Asistente residencial inteligente", "initializing" to "Iniciando modo local y conexión...",
            "not_configured" to "No configurado", "online" to "En línea", "offline_reconnecting" to "Sin conexión — reconectando",
            "queue" to "Cola", "connection" to "Conexión", "connected_house" to "En línea — conectado a la casa",
            "offline_detail" to "Sin conexión — la aplicación sigue disponible e intenta reconectarse automáticamente",
            "waiting" to "comando(s) pendientes", "quick_ops" to "Operaciones rápidas", "turn_on" to "Encender luz",
            "turn_off" to "Apagar luz", "status" to "Estado", "sensors" to "Sensores", "manual_command" to "Comando manual",
            "processing" to "Procesando...", "execute" to "Ejecutar", "save_queue" to "Guardar en cola", "result" to "Resultado",
            "manual_ready" to "Listo para operaciones manuales.", "executed" to "Comando ejecutado.", "connected_jarvis" to "Conectado a JARVIS",
            "offline_voice" to "Modo sin conexión — reconexión automática activa", "you" to "Tú", "none" to "Aún no hay frase.",
            "touch_speak" to "Toca Hablar.", "offline_speech" to "Sin conexión. La voz reconocida se guardará hasta que vuelva la conexión.",
            "listening" to "ESCUCHANDO...", "speak" to "HABLAR CON JARVIS", "not_recognized" to "No pude reconocer la voz.",
            "config_loaded" to "Configuración cargada.", "config_help" to "Usa la URL HTTPS externa de la API de la casa. La aplicación funciona sin conexión y seguirá intentando conectarse.",
            "public_url" to "URL pública de la casa", "phone_token" to "Token de este teléfono", "save" to "Guardar", "test_now" to "Probar ahora",
            "testing" to "Probando...", "saved" to "Configuración guardada. Reconexión automática activa.", "language" to "Idioma",
            "automatic" to "Automático (idioma del dispositivo)", "language_help" to "La interfaz, el reconocimiento de voz y las respuestas de JARVIS siguen este idioma."
        )
        val table = when (lang) { "en-US" -> en; "es-ES" -> es; else -> pt }
        return table[key] ?: pt[key] ?: key
    }

    private fun requestPermissionsIfNeeded() {
        val permissions = mutableListOf(Manifest.permission.RECORD_AUDIO)
        if (Build.VERSION.SDK_INT >= 33) permissions += Manifest.permission.POST_NOTIFICATIONS
        if (Build.VERSION.SDK_INT >= 31) {
            permissions += Manifest.permission.BLUETOOTH_CONNECT
            permissions += Manifest.permission.BLUETOOTH_SCAN
            permissions += Manifest.permission.BLUETOOTH_ADVERTISE
        }
        permissionLauncher.launch(permissions.toTypedArray())
    }

    private fun startJarvisService() {
        ContextCompat.startForegroundService(this, Intent(this, JarvisConnectionService::class.java))
    }

    private fun setupSpeechRecognizer() {
        if (!SpeechRecognizer.isRecognitionAvailable(this)) return
        recognizer = SpeechRecognizer.createSpeechRecognizer(this).apply {
            setRecognitionListener(object : RecognitionListener {
                override fun onReadyForSpeech(params: Bundle?) {}
                override fun onBeginningOfSpeech() {}
                override fun onRmsChanged(rmsdB: Float) {}
                override fun onBufferReceived(buffer: ByteArray?) {}
                override fun onEndOfSpeech() {}
                override fun onError(error: Int) { onSpeechResult?.invoke("") }
                override fun onResults(results: Bundle?) {
                    onSpeechResult?.invoke(results?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)?.firstOrNull().orEmpty())
                }
                override fun onPartialResults(partialResults: Bundle?) {}
                override fun onEvent(eventType: Int, params: Bundle?) {}
            })
        }
    }

    private fun listen(callback: (String) -> Unit) {
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) { callback(""); return }
        onSpeechResult = callback
        recognizer?.startListening(Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, LanguageManager.recognitionTag(this@MainActivity))
            putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, false)
            putExtra(RecognizerIntent.EXTRA_PROMPT, "JARVIS")
        })
    }

    private fun playAudio(path: String?) {
        if (path.isNullOrBlank()) return
        val cfg = JarvisApi.loadConfig(this)
        val url = runCatching { JarvisApi.absoluteUrl(this, path) }.getOrNull() ?: return
        Thread {
            try {
                http.newCall(Request.Builder().url(url).header("Authorization", "Bearer ${cfg.token}").header("X-Device-Token", cfg.token).build()).execute().use { response ->
                    if (!response.isSuccessful) return@use
                    val file = createTempFile("jarvis_", ".wav", cacheDir)
                    file.writeBytes(response.body?.bytes() ?: return@use)
                    runOnUiThread {
                        MediaPlayer().apply {
                            setAudioAttributes(AudioAttributes.Builder().setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).setUsage(AudioAttributes.USAGE_ASSISTANCE_ACCESSIBILITY).build())
                            setDataSource(file.absolutePath)
                            setOnPreparedListener { it.start() }
                            setOnCompletionListener { it.release(); file.delete() }
                            prepareAsync()
                        }
                    }
                }
            } catch (_: Exception) {}
        }.start()
    }

    @Composable
    private fun JarvisApp() {
        var splash by remember { mutableStateOf(true) }
        var online by remember { mutableStateOf(false) }
        var configured by remember { mutableStateOf(JarvisApi.isConfigured(this)) }
        var pending by remember { mutableIntStateOf(JarvisApi.pendingCount(this)) }
        LaunchedEffect(Unit) { delay(1000); splash = false }
        LaunchedEffect(splash) {
            if (!splash) while (true) {
                configured = JarvisApi.isConfigured(this@MainActivity)
                online = if (configured) withContext(Dispatchers.IO) { JarvisApi.isOnline(this@MainActivity) } else false
                pending = JarvisApi.pendingCount(this@MainActivity)
                delay(if (online) 10_000 else 5_000)
            }
        }
        MaterialTheme { if (splash) SplashScreen() else MainShell(online, configured, pending) }
    }

    @Composable
    private fun SplashScreen() {
        Surface(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                Text("J", style = MaterialTheme.typography.displayLarge, fontWeight = FontWeight.Black)
                Text("JARVIS", style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp)); Text(tr("assistant")); Spacer(Modifier.height(24.dp)); CircularProgressIndicator(); Spacer(Modifier.height(12.dp)); Text(tr("initializing"), textAlign = TextAlign.Center)
            }
        }
    }

    @OptIn(ExperimentalMaterial3Api::class)
    @Composable
    private fun MainShell(online: Boolean, configured: Boolean, pending: Int) {
        var screen by remember { mutableStateOf(Screen.OPERATIONS) }
        Scaffold(topBar = {
            TopAppBar(title = { Column { Text("JARVIS Mobile", fontWeight = FontWeight.Bold); Text(if (!configured) tr("not_configured") else if (online) tr("online") else tr("offline_reconnecting"), style = MaterialTheme.typography.bodySmall) } },
                actions = { if (pending > 0) { AssistChip(onClick = {}, label = { Text("${tr("queue")}: $pending") }); Spacer(Modifier.width(8.dp)) } })
        }, bottomBar = {
            NavigationBar {
                listOf(Screen.OPERATIONS, Screen.VOICE, Screen.CONFIG).forEach { item ->
                    val label = when(item) { Screen.OPERATIONS -> tr("operations"); Screen.VOICE -> tr("voice"); Screen.CONFIG -> tr("config") }
                    NavigationBarItem(selected = screen == item, onClick = { screen = item }, icon = { Text(if (item == Screen.OPERATIONS) "⌂" else if (item == Screen.VOICE) "●" else "⚙") }, label = { Text(label) })
                }
            }
        }) { pad -> Box(Modifier.padding(pad).fillMaxSize()) { when(screen) { Screen.OPERATIONS -> OperationsScreen(online, pending); Screen.VOICE -> VoiceScreen(online); Screen.CONFIG -> ConfigScreen() } } }
    }

    @Composable
    private fun ConnectionCard(online: Boolean, pending: Int) {
        ElevatedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(tr("connection"), fontWeight = FontWeight.Bold); Text(if (online) tr("connected_house") else tr("offline_detail")); if (pending > 0) Text("$pending ${tr("waiting")}.")
        } }
    }

    @Composable
    private fun OperationsScreen(online: Boolean, pending: Int) {
        val scope = rememberCoroutineScope(); var command by remember { mutableStateOf("") }; var response by remember { mutableStateOf(tr("manual_ready")) }; var busy by remember { mutableStateOf(false) }
        fun send(text: String) { if (text.isBlank() || busy) return; busy = true; scope.launch { val result = withContext(Dispatchers.IO) { JarvisApi.sendOrQueue(this@MainActivity, text) }; response = if (result.delivered) result.answer?.text ?: tr("executed") else result.message; if (result.delivered) playAudio(result.answer?.audioUrl); busy = false } }
        Column(Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            ConnectionCard(online, pending); Text(tr("quick_ops"), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) { Button(onClick = { send("Ligue a luz da sala") }, Modifier.weight(1f), enabled = !busy) { Text(tr("turn_on")) }; OutlinedButton(onClick = { send("Desligue a luz da sala") }, Modifier.weight(1f), enabled = !busy) { Text(tr("turn_off")) } }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) { Button(onClick = { send("Informe o status da casa") }, Modifier.weight(1f), enabled = !busy) { Text(tr("status")) }; Button(onClick = { send("Qual a temperatura atual dos sensores?") }, Modifier.weight(1f), enabled = !busy) { Text(tr("sensors")) } }
            OutlinedTextField(command, { command = it }, Modifier.fillMaxWidth(), label = { Text(tr("manual_command")) }, minLines = 2)
            Button(onClick = { val t = command.trim(); command = ""; send(t) }, Modifier.fillMaxWidth(), enabled = command.isNotBlank() && !busy) { Text(if (busy) tr("processing") else if (online) tr("execute") else tr("save_queue")) }
            ElevatedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp)) { Text(tr("result"), fontWeight = FontWeight.Bold); Spacer(Modifier.height(6.dp)); Text(response) } }
        }
    }

    @Composable
    private fun VoiceScreen(online: Boolean) {
        val scope = rememberCoroutineScope(); var heard by remember { mutableStateOf("") }; var answer by remember { mutableStateOf(if (online) tr("touch_speak") else tr("offline_speech")) }; var busy by remember { mutableStateOf(false) }; var listening by remember { mutableStateOf(false) }
        fun submit(text: String) { busy = true; scope.launch { val result = withContext(Dispatchers.IO) { JarvisApi.sendOrQueue(this@MainActivity, text) }; answer = if (result.delivered) result.answer?.text ?: tr("executed") else result.message; if (result.delivered) playAudio(result.answer?.audioUrl); busy = false } }
        Column(Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Text(tr("voice"), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold); Text(if (online) tr("connected_jarvis") else tr("offline_voice"))
            ElevatedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) { Text(tr("you"), fontWeight = FontWeight.Bold); Text(if (heard.isBlank()) tr("none") else heard); HorizontalDivider(); Text("JARVIS", fontWeight = FontWeight.Bold); Text(answer) } }
            Button(onClick = { listening = true; answer = tr("listening"); listen { text -> listening = false; if (text.isBlank()) answer = tr("not_recognized") else { heard = text; submit(text) } } }, enabled = !busy && !listening, modifier = Modifier.fillMaxWidth().height(64.dp)) { Text(if (listening) tr("listening") else if (busy) tr("processing") else tr("speak")) }
        }
    }

    @Composable
    private fun ConfigScreen() {
        val scope = rememberCoroutineScope(); val current = remember { JarvisApi.loadConfig(this) }; var server by remember { mutableStateOf(current.baseUrl) }; var token by remember { mutableStateOf(current.token) }; var status by remember { mutableStateOf(tr("config_loaded")) }; var busy by remember { mutableStateOf(false) }; var automatic by remember { mutableStateOf(LanguageManager.isAutomatic(this)) }; var selectedLanguage by remember { mutableStateOf(LanguageManager.currentLanguage(this)) }
        Column(Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(tr("config"), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold); Text(tr("config_help"))
            Text(tr("language"), fontWeight = FontWeight.Bold); Text(tr("language_help"))
            Row(verticalAlignment = Alignment.CenterVertically) { Switch(checked = automatic, onCheckedChange = { automatic = it; LanguageManager.setAutomatic(this@MainActivity, it); if (it) selectedLanguage = LanguageManager.currentLanguage(this@MainActivity) }); Spacer(Modifier.width(8.dp)); Text(tr("automatic")) }
            if (!automatic) LanguageManager.supported.forEach { lang -> Row(verticalAlignment = Alignment.CenterVertically) { RadioButton(selected = selectedLanguage == lang.tag, onClick = { selectedLanguage = lang.tag; LanguageManager.setLanguage(this@MainActivity, lang.tag); recreate() }); Text(lang.label) } }
            Text("${tr("language")}: ${LanguageManager.languageLabel(this@MainActivity)}")
            OutlinedTextField(server, { server = it }, Modifier.fillMaxWidth(), label = { Text(tr("public_url")) }, placeholder = { Text("https://seu-host") }, singleLine = true)
            OutlinedTextField(token, { token = it }, Modifier.fillMaxWidth(), label = { Text(tr("phone_token")) }, singleLine = true)
            Button(onClick = { JarvisApi.saveConfig(this@MainActivity, server, token); runCatching { startJarvisService() }; status = tr("saved") }, Modifier.fillMaxWidth()) { Text(tr("save")) }
            OutlinedButton(onClick = { JarvisApi.saveConfig(this@MainActivity, server, token); busy = true; scope.launch { status = try { withContext(Dispatchers.IO) { JarvisApi.testConnection(this@MainActivity) } } catch (e: Exception) { "${tr("offline_reconnecting")}. ${e.message ?: ""}" }; busy = false } }, Modifier.fillMaxWidth(), enabled = !busy) { Text(if (busy) tr("testing") else tr("test_now")) }

            HorizontalDivider()
            Text("Dispositivos", fontWeight = FontWeight.Bold)
            Text("Cadastre e autorize novos hardwares pelo aplicativo. O JARVIS Mobile cria a identidade no CASA e entrega a credencial individual ao dispositivo.")
            Button(
                onClick = {
                    JarvisApi.saveConfig(this@MainActivity, server, token)
                    startActivity(Intent(this@MainActivity, NewDevicesActivity::class.java))
                },
                modifier = Modifier.fillMaxWidth()
            ) { Text("NOVOS DEVICES") }

            ElevatedCard(Modifier.fillMaxWidth()) { Text(status, modifier = Modifier.padding(16.dp)) }
        }
    }
}
