package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.os.Build
import android.os.Bundle
import android.provider.Settings
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
import androidx.compose.ui.text.input.PasswordVisualTransformation
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

    private enum class Route {
        HOME,
        CASA_MENU,
        JARVIS_MENU,
        WATCH_MENU,
        DEVICES_MENU,
        SYSTEM_MENU,
        OPERATIONS,
        VOICE,
        WATCH_STATUS,
        DEVICES_LIST,
        CONFIG
    }

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
            "operations" to "Operações", "voice" to "Voz", "watch" to "Watch", "devices" to "Devices", "config" to "Configuração",
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
            "operations" to "Operations", "voice" to "Voice", "watch" to "Watch", "devices" to "Devices", "config" to "Settings",
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
            "operations" to "Operaciones", "voice" to "Voz", "watch" to "Watch", "devices" to "Devices", "config" to "Configuración",
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
        var session by remember { mutableStateOf(MobileAuth.savedSession(this)) }
        var online by remember { mutableStateOf(false) }
        var configured by remember { mutableStateOf(JarvisApi.isConfigured(this)) }
        var pending by remember { mutableIntStateOf(JarvisApi.pendingCount(this)) }

        LaunchedEffect(Unit) {
            delay(700)
            session = withContext(Dispatchers.IO) {
                MobileAuth.validate(this@MainActivity) ?: MobileAuth.savedSession(this@MainActivity)
            }
            splash = false
        }

        LaunchedEffect(splash, session) {
            if (!splash && session != null) while (true) {
                configured = JarvisApi.isConfigured(this@MainActivity)
                online = if (configured) withContext(Dispatchers.IO) {
                    JarvisApi.isOnline(this@MainActivity)
                } else false
                pending = JarvisApi.pendingCount(this@MainActivity)
                delay(if (online) 10_000 else 5_000)
            }
        }

        MaterialTheme {
            when {
                splash -> SplashScreen()
                session == null -> LoginScreen { session = it }
                else -> MainShell(
                    online = online,
                    configured = configured,
                    pending = pending,
                    session = session!!,
                    onLogout = {
                        val current = session
                        session = null
                        Thread {
                            runCatching { MobileAuth.logout(this@MainActivity) }
                        }.start()
                    }
                )
            }
        }
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

    @Composable
    private fun LoginScreen(onLoggedIn: (MobileAuth.Session) -> Unit) {
        val scope = rememberCoroutineScope()
        val cfg = remember { JarvisApi.loadConfig(this) }
        var user by remember { mutableStateOf("") }
        var password by remember { mutableStateOf("") }
        var status by remember {
            mutableStateOf(
                if (cfg.baseUrl.startsWith("https://")) "Informe suas credenciais."
                else "Configure a URL HTTPS da CASA antes do primeiro login."
            )
        }
        var busy by remember { mutableStateOf(false) }

        LcarsFrame(
            title = "Acesso ao sistema",
            subtitle = "CASA / JARVIS • autenticação do operador",
            user = "",
            canBack = false,
            onBack = {},
            onHome = {},
            onLogout = {}
        ) {
            LcarsSectionLabel("LOGIN", LcarsColors.Salmon)

            OutlinedTextField(
                value = user,
                onValueChange = { user = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Operador / e-mail") },
                singleLine = true,
                enabled = !busy
            )

            OutlinedTextField(
                value = password,
                onValueChange = { password = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Senha") },
                singleLine = true,
                enabled = !busy,
                visualTransformation = PasswordVisualTransformation()
            )

            Button(
                onClick = {
                    if (user.isBlank() || password.isBlank()) {
                        status = "Informe operador e senha."
                        return@Button
                    }
                    busy = true
                    status = "Autenticando..."
                    scope.launch {
                        val result = withContext(Dispatchers.IO) {
                            runCatching { MobileAuth.login(this@MainActivity, user, password) }
                        }
                        busy = false
                        result.onSuccess {
                            password = ""
                            status = "Acesso autorizado."
                            onLoggedIn(it)
                        }.onFailure {
                            status = it.message ?: "Falha no login."
                        }
                    }
                },
                modifier = Modifier.fillMaxWidth(),
                enabled = !busy
            ) { Text(if (busy) "AUTENTICANDO..." else "AUTORIZAR ACESSO") }

            if (!cfg.baseUrl.startsWith("https://")) {
                OutlinedButton(
                    onClick = { startActivity(Intent(this@MainActivity, MainActivity::class.java)) },
                    modifier = Modifier.fillMaxWidth()
                ) { Text("CONFIGURAR CASA") }
            }

            ElevatedCard(Modifier.fillMaxWidth()) {
                Text(status, modifier = Modifier.padding(16.dp))
            }
        }
    }

    @Composable
    private fun MainShell(
        online: Boolean,
        configured: Boolean,
        pending: Int,
        session: MobileAuth.Session,
        onLogout: () -> Unit
    ) {
        var stack by remember { mutableStateOf(listOf(Route.HOME)) }
        val route = stack.last()

        fun open(next: Route) {
            stack = stack + next
        }

        fun back() {
            if (stack.size > 1) stack = stack.dropLast(1)
        }

        fun home() {
            stack = listOf(Route.HOME)
        }

        val title = when (route) {
            Route.HOME -> "JARVIS Mobile"
            Route.CASA_MENU -> "CASA"
            Route.JARVIS_MENU -> "JARVIS"
            Route.WATCH_MENU -> "Watch"
            Route.DEVICES_MENU -> "Devices"
            Route.SYSTEM_MENU -> "Sistema"
            Route.OPERATIONS -> "Operações"
            Route.VOICE -> "Voz"
            Route.WATCH_STATUS -> "Watch / Estado"
            Route.DEVICES_LIST -> "Devices / Lista"
            Route.CONFIG -> "Sistema / Configuração"
        }

        val subtitle = when {
            !configured -> "CASA não configurada"
            online -> "CASA online" + if (pending > 0) " • fila: $pending" else ""
            else -> "CASA offline • reconexão automática"
        }

        LcarsFrame(
            title = title,
            subtitle = subtitle,
            user = session.name.ifBlank { session.login },
            canBack = stack.size > 1,
            onBack = { back() },
            onHome = { home() },
            onLogout = onLogout
        ) {
            when (route) {
                Route.HOME -> {
                    LcarsSectionLabel("MENU PRINCIPAL", LcarsColors.Orange)
                    LcarsMenuButton("CASA", "Operações, sensores e estado da residência", LcarsColors.Salmon) { open(Route.CASA_MENU) }
                    LcarsMenuButton("JARVIS", "Voz, comando manual e respostas", LcarsColors.Lavender) { open(Route.JARVIS_MENU) }
                    LcarsMenuButton("WATCH", "Relógio, recursos e configuração", LcarsColors.Blue) { open(Route.WATCH_MENU) }
                    LcarsMenuButton("DEVICES", "Equipamentos e novos dispositivos", LcarsColors.Gold) { open(Route.DEVICES_MENU) }
                    LcarsMenuButton("SISTEMA", "Conexão, idioma e credenciais", LcarsColors.Green) { open(Route.SYSTEM_MENU) }
                }

                Route.CASA_MENU -> {
                    LcarsSectionLabel("CASA", LcarsColors.Salmon)
                    LcarsMenuButton("OPERAÇÕES", "Controles rápidos e comando manual", LcarsColors.Salmon) { open(Route.OPERATIONS) }
                    LcarsMenuButton("STATUS E SENSORES", "Consulta de estado, temperatura e sensores", LcarsColors.Orange) { open(Route.OPERATIONS) }
                }

                Route.JARVIS_MENU -> {
                    LcarsSectionLabel("JARVIS", LcarsColors.Lavender)
                    LcarsMenuButton("VOZ", "Falar com o JARVIS", LcarsColors.Lavender) { open(Route.VOICE) }
                    LcarsMenuButton("COMANDO MANUAL", "Digitar uma solicitação", LcarsColors.Blue) { open(Route.OPERATIONS) }
                }

                Route.WATCH_MENU -> {
                    LcarsSectionLabel("WATCH", LcarsColors.Blue)
                    LcarsMenuButton("ESTADO DO WATCH", "Conexão local, CASA e heartbeat", LcarsColors.Blue) { open(Route.WATCH_STATUS) }
                    LcarsMenuButton("CONFIGURAR WATCH", "Wi-Fi, identidade e provisionamento", LcarsColors.Salmon) {
                        startActivity(Intent(this@MainActivity, WatchSetupActivity::class.java))
                    }
                    LcarsMenuButton("NOTIFICAÇÕES", "Permitir e encaminhar notificações", LcarsColors.Lavender) {
                        startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))
                    }
                }

                Route.DEVICES_MENU -> {
                    LcarsSectionLabel("DEVICES", LcarsColors.Gold)
                    LcarsMenuButton("LISTAR DEVICES", "Equipamentos cadastrados", LcarsColors.Gold) { open(Route.DEVICES_LIST) }
                    LcarsMenuButton("ADICIONAR DEVICE", "Watch, ESP32-CAM e novos equipamentos", LcarsColors.Orange) {
                        startActivity(Intent(this@MainActivity, NewDevicesActivity::class.java))
                    }
                }

                Route.SYSTEM_MENU -> {
                    LcarsSectionLabel("SISTEMA", LcarsColors.Green)
                    LcarsMenuButton("CONFIGURAÇÃO", "URL CASA, token e idioma", LcarsColors.Green) { open(Route.CONFIG) }
                    LcarsMenuButton("SAIR", "Encerrar sessão do operador", LcarsColors.Salmon) { onLogout() }
                }

                Route.OPERATIONS -> OperationsScreen(online, pending)
                Route.VOICE -> VoiceScreen(online)
                Route.WATCH_STATUS -> WatchScreen()
                Route.DEVICES_LIST -> DevicesScreen()
                Route.CONFIG -> ConfigScreen()
            }
        }
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
    private fun WatchScreen() {
        val scope = rememberCoroutineScope()
        var status by remember { mutableStateOf("Carregando status do Watch...") }
        var devices by remember { mutableStateOf<List<WatchApi.WatchDevice>>(emptyList()) }
        var localConnected by remember { mutableStateOf(WatchClient.isConnected(this@MainActivity)) }
        var lanHost by remember { mutableStateOf(WatchClient.savedLanHost(this@MainActivity)) }
        var deviceId by remember { mutableStateOf(WatchClient.savedDeviceId(this@MainActivity)) }
        var busy by remember { mutableStateOf(false) }

        suspend fun refreshWatch() {
            localConnected = WatchClient.isConnected(this@MainActivity)
            lanHost = WatchClient.savedLanHost(this@MainActivity)
            deviceId = WatchClient.savedDeviceId(this@MainActivity)
            devices = withContext(Dispatchers.IO) {
                runCatching { WatchApi.listWatches(this@MainActivity) }.getOrDefault(emptyList())
            }
            status = when {
                localConnected && lanHost.isNotBlank() -> "Conectado localmente em $lanHost:${WatchClient.WATCH_PORT}"
                devices.any { it.online } -> "Watch online pelo CASA"
                else -> "Watch offline"
            }
        }

        LaunchedEffect(Unit) {
            refreshWatch()
            while (true) {
                delay(5_000)
                refreshWatch()
            }
        }

        Column(
            Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text("WATCH", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)

            ElevatedCard(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(if (localConnected || devices.any { it.online }) "● JARVIS Watch" else "○ JARVIS Watch", fontWeight = FontWeight.Bold)
                    Text(status)
                    if (deviceId.isNotBlank()) Text("Device ID: $deviceId")
                    if (lanHost.isNotBlank()) Text("IP local: $lanHost")
                    devices.firstOrNull()?.let { w ->
                        if (w.location.isNotBlank()) Text("Local: ${w.location}")
                        if (w.transport.isNotBlank()) Text("Transporte: ${w.transport}")
                        if (w.lastHeartbeat.isNotBlank()) Text("Último heartbeat: ${w.lastHeartbeat}")
                    }
                }
            }

            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    onClick = {
                        busy = true
                        scope.launch {
                            status = withContext(Dispatchers.IO) {
                                val id = deviceId.ifBlank { devices.firstOrNull()?.deviceId.orEmpty() }
                                if (id.isBlank()) "Watch ainda não cadastrado"
                                else runCatching {
                                    WatchApi.findWatch(this@MainActivity, id)
                                    "Comando LOCALIZAR enviado"
                                }.getOrElse { "Falha ao localizar: ${it.message}" }
                            }
                            busy = false
                        }
                    },
                    modifier = Modifier.weight(1f),
                    enabled = !busy
                ) { Text("LOCALIZAR") }

                OutlinedButton(
                    onClick = { scope.launch { refreshWatch() } },
                    modifier = Modifier.weight(1f),
                    enabled = !busy
                ) { Text("ATUALIZAR") }
            }

            HorizontalDivider()
            Text("Recursos do celular", fontWeight = FontWeight.Bold)

            ElevatedCard(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("GPS", fontWeight = FontWeight.Bold)
                    Text("O Watch pode solicitar a localização do celular.")
                    Text("Câmera", fontWeight = FontWeight.Bold)
                    Text("O Watch pode solicitar uma foto pelo celular.")
                    Text("Notificações", fontWeight = FontWeight.Bold)
                    var forwardNotifications by remember {
                        mutableStateOf(WatchNotificationListener.isEnabled(this@MainActivity))
                    }
                    val notificationAccess = WatchNotificationListener.hasSystemAccess(this@MainActivity)
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Switch(
                            checked = forwardNotifications,
                            onCheckedChange = {
                                forwardNotifications = it
                                WatchNotificationListener.setEnabled(this@MainActivity, it)
                            },
                            enabled = notificationAccess
                        )
                        Spacer(Modifier.width(8.dp))
                        Text(if (forwardNotifications) "Encaminhamento ativado" else "Encaminhamento desativado")
                    }
                    Text("Voz / JARVIS", fontWeight = FontWeight.Bold)
                    Text("Comandos do Watch podem ser processados pelo celular e devolvidos por TCP/CASA.")
                }
            }

            Button(
                onClick = { startActivity(Intent(this@MainActivity, WatchSetupActivity::class.java)) },
                modifier = Modifier.fillMaxWidth()
            ) { Text("CONFIGURAR WATCH") }

            OutlinedButton(
                onClick = { startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS)) },
                modifier = Modifier.fillMaxWidth()
            ) { Text("PERMISSÕES DE NOTIFICAÇÃO") }
        }
    }

    @Composable
    private fun DevicesScreen() {
        val scope = rememberCoroutineScope()
        var devices by remember { mutableStateOf<List<WatchApi.WatchDevice>>(emptyList()) }
        var loading by remember { mutableStateOf(false) }

        fun refreshDevices() {
            if (loading) return
            loading = true
            scope.launch {
                devices = withContext(Dispatchers.IO) {
                    runCatching { WatchApi.listWatches(this@MainActivity) }.getOrDefault(emptyList())
                }
                loading = false
            }
        }

        LaunchedEffect(Unit) { refreshDevices() }

        Column(
            Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text("DEVICES", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            Text("Equipamentos cadastrados no ecossistema CASA.")

            if (devices.isEmpty()) {
                ElevatedCard(Modifier.fillMaxWidth()) {
                    Text(if (loading) "Carregando dispositivos..." else "Nenhum Watch encontrado no CASA.", modifier = Modifier.padding(16.dp))
                }
            } else {
                devices.forEach { d ->
                    ElevatedCard(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text((if (d.online) "● " else "○ ") + d.name, fontWeight = FontWeight.Bold)
                            Text(d.deviceId)
                            if (d.location.isNotBlank()) Text("Local: ${d.location}")
                            Text("Status: ${d.status}")
                            if (d.transport.isNotBlank()) Text("Transporte: ${d.transport}")
                        }
                    }
                }
            }

            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    onClick = { startActivity(Intent(this@MainActivity, NewDevicesActivity::class.java)) },
                    modifier = Modifier.weight(1f)
                ) { Text("+ ADICIONAR") }

                OutlinedButton(
                    onClick = { refreshDevices() },
                    modifier = Modifier.weight(1f),
                    enabled = !loading
                ) { Text("ATUALIZAR") }
            }

            HorizontalDivider()
            Text("Atalhos", fontWeight = FontWeight.Bold)

            Button(
                onClick = { startActivity(Intent(this@MainActivity, WatchSetupActivity::class.java)) },
                modifier = Modifier.fillMaxWidth()
            ) { Text("WATCH") }

            OutlinedButton(
                onClick = { startActivity(Intent(this@MainActivity, NewDevicesActivity::class.java)) },
                modifier = Modifier.fillMaxWidth()
            ) { Text("ESP32-CAM / OUTROS DEVICES") }
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
            Text("Sistema", fontWeight = FontWeight.Bold)
            Text("Use as abas WATCH e DEVICES para gerenciar equipamentos e integrações.")

            ElevatedCard(Modifier.fillMaxWidth()) { Text(status, modifier = Modifier.padding(16.dp)) }
        }
    }
}
