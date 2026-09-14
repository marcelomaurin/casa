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

    private enum class Screen(val title: String) {
        OPERATIONS("Operações"), VOICE("Voz"), CONFIG("Configuração")
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
                    val list = results?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)
                    onSpeechResult?.invoke(list?.firstOrNull().orEmpty())
                }
                override fun onPartialResults(partialResults: Bundle?) {}
                override fun onEvent(eventType: Int, params: Bundle?) {}
            })
        }
    }

    private fun listen(callback: (String) -> Unit) {
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            callback("")
            return
        }
        onSpeechResult = callback
        val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, "pt-BR")
            putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, false)
            putExtra(RecognizerIntent.EXTRA_PROMPT, "Fale com o JARVIS")
        }
        recognizer?.startListening(intent)
    }

    private fun playAudio(path: String?) {
        if (path.isNullOrBlank()) return
        val cfg = JarvisApi.loadConfig(this)
        val url = runCatching { JarvisApi.absoluteUrl(this, path) }.getOrNull() ?: return
        Thread {
            try {
                val req = Request.Builder()
                    .url(url)
                    .header("Authorization", "Bearer ${cfg.token}")
                    .header("X-Device-Token", cfg.token)
                    .build()
                http.newCall(req).execute().use { response ->
                    if (!response.isSuccessful) return@use
                    val bytes = response.body?.bytes() ?: return@use
                    val file = createTempFile("jarvis_", ".wav", cacheDir)
                    file.writeBytes(bytes)
                    runOnUiThread {
                        MediaPlayer().apply {
                            setAudioAttributes(
                                AudioAttributes.Builder()
                                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                                    .setUsage(AudioAttributes.USAGE_ASSISTANCE_ACCESSIBILITY)
                                    .build()
                            )
                            setDataSource(file.absolutePath)
                            setOnPreparedListener { it.start() }
                            setOnCompletionListener {
                                it.release()
                                file.delete()
                            }
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

        LaunchedEffect(Unit) {
            delay(1000)
            splash = false
        }

        LaunchedEffect(splash) {
            if (!splash) {
                while (true) {
                    configured = JarvisApi.isConfigured(this@MainActivity)
                    online = if (configured) withContext(Dispatchers.IO) {
                        JarvisApi.isOnline(this@MainActivity)
                    } else false
                    pending = JarvisApi.pendingCount(this@MainActivity)
                    delay(if (online) 10_000 else 5_000)
                }
            }
        }

        MaterialTheme {
            if (splash) SplashScreen() else MainShell(online, configured, pending)
        }
    }

    @Composable
    private fun SplashScreen() {
        Surface(Modifier.fillMaxSize()) {
            Column(
                modifier = Modifier.fillMaxSize().padding(32.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center
            ) {
                Text("J", style = MaterialTheme.typography.displayLarge, fontWeight = FontWeight.Black)
                Text("JARVIS", style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                Text("Assistente residencial inteligente")
                Spacer(Modifier.height(24.dp))
                CircularProgressIndicator()
                Spacer(Modifier.height(12.dp))
                Text("Inicializando modo local e conexão...", textAlign = TextAlign.Center)
            }
        }
    }

    @OptIn(ExperimentalMaterial3Api::class)
    @Composable
    private fun MainShell(online: Boolean, configured: Boolean, pending: Int) {
        var screen by remember { mutableStateOf(Screen.OPERATIONS) }
        Scaffold(
            topBar = {
                TopAppBar(
                    title = {
                        Column {
                            Text("JARVIS Mobile", fontWeight = FontWeight.Bold)
                            Text(
                                when {
                                    !configured -> "Não configurado"
                                    online -> "Online"
                                    else -> "Offline — reconectando"
                                },
                                style = MaterialTheme.typography.bodySmall
                            )
                        }
                    },
                    actions = {
                        if (pending > 0) {
                            AssistChip(onClick = {}, label = { Text("Fila: $pending") })
                            Spacer(Modifier.width(8.dp))
                        }
                    }
                )
            },
            bottomBar = {
                NavigationBar {
                    Screen.entries.forEach { item ->
                        NavigationBarItem(
                            selected = screen == item,
                            onClick = { screen = item },
                            icon = {
                                Text(
                                    when (item) {
                                        Screen.OPERATIONS -> "⌂"
                                        Screen.VOICE -> "●"
                                        Screen.CONFIG -> "⚙"
                                    }
                                )
                            },
                            label = { Text(item.title) }
                        )
                    }
                }
            }
        ) { pad ->
            Box(Modifier.padding(pad).fillMaxSize()) {
                when (screen) {
                    Screen.OPERATIONS -> OperationsScreen(online, pending)
                    Screen.VOICE -> VoiceScreen(online)
                    Screen.CONFIG -> ConfigScreen()
                }
            }
        }
    }

    @Composable
    private fun ConnectionCard(online: Boolean, pending: Int) {
        ElevatedCard(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text("Conexão", fontWeight = FontWeight.Bold)
                Text(if (online) "Online — conectado à casa" else "Offline — o app continua disponível e tenta reconectar automaticamente")
                if (pending > 0) Text("$pending comando(s) aguardando envio.")
            }
        }
    }

    @Composable
    private fun OperationsScreen(online: Boolean, pending: Int) {
        val scope = rememberCoroutineScope()
        var command by remember { mutableStateOf("") }
        var response by remember { mutableStateOf("Pronto para operações manuais.") }
        var busy by remember { mutableStateOf(false) }

        fun send(text: String) {
            if (text.isBlank() || busy) return
            busy = true
            scope.launch {
                val result = withContext(Dispatchers.IO) {
                    JarvisApi.sendOrQueue(this@MainActivity, text)
                }
                if (result.delivered) {
                    response = result.answer?.text ?: "Comando executado."
                    playAudio(result.answer?.audioUrl)
                } else {
                    response = result.message
                }
                busy = false
            }
        }

        Column(
            Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            ConnectionCard(online, pending)
            Text("Operações rápidas", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { send("Ligue a luz da sala") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Ligar luz") }
                OutlinedButton(onClick = { send("Desligue a luz da sala") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Desligar luz") }
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { send("Informe o status da casa") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Status") }
                Button(onClick = { send("Qual a temperatura atual dos sensores?") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Sensores") }
            }
            HorizontalDivider()
            OutlinedTextField(
                value = command,
                onValueChange = { command = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Comando manual") },
                minLines = 2
            )
            Button(
                onClick = {
                    val text = command.trim()
                    command = ""
                    send(text)
                },
                modifier = Modifier.fillMaxWidth(),
                enabled = command.isNotBlank() && !busy
            ) { Text(if (busy) "Processando..." else if (online) "Executar" else "Salvar na fila") }
            ElevatedCard(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp)) {
                    Text("Resultado", fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(6.dp))
                    Text(response)
                }
            }
        }
    }

    @Composable
    private fun VoiceScreen(online: Boolean) {
        val scope = rememberCoroutineScope()
        var heard by remember { mutableStateOf("") }
        var answer by remember { mutableStateOf(if (online) "Toque em Falar." else "Offline. A fala reconhecida será enfileirada para envio quando a conexão voltar.") }
        var busy by remember { mutableStateOf(false) }
        var listening by remember { mutableStateOf(false) }

        fun submit(text: String) {
            busy = true
            scope.launch {
                val result = withContext(Dispatchers.IO) {
                    JarvisApi.sendOrQueue(this@MainActivity, text)
                }
                if (result.delivered) {
                    answer = result.answer?.text ?: "Comando executado."
                    playAudio(result.answer?.audioUrl)
                } else answer = result.message
                busy = false
            }
        }

        Column(
            Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            Text("Voz", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            Text(if (online) "Conectado ao JARVIS" else "Modo offline — reconexão automática ativa")
            ElevatedCard(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Você", fontWeight = FontWeight.Bold)
                    Text(if (heard.isBlank()) "Nenhuma frase ainda." else heard)
                    HorizontalDivider()
                    Text("JARVIS", fontWeight = FontWeight.Bold)
                    Text(answer)
                }
            }
            Button(
                onClick = {
                    listening = true
                    answer = "Ouvindo..."
                    listen { text ->
                        listening = false
                        if (text.isBlank()) answer = "Não consegui reconhecer a fala."
                        else {
                            heard = text
                            submit(text)
                        }
                    }
                },
                enabled = !busy && !listening,
                modifier = Modifier.fillMaxWidth().height(64.dp)
            ) {
                Text(when {
                    listening -> "OUVINDO..."
                    busy -> "PROCESSANDO..."
                    else -> "FALAR COM O JARVIS"
                })
            }
        }
    }

    @Composable
    private fun ConfigScreen() {
        val scope = rememberCoroutineScope()
        val current = remember { JarvisApi.loadConfig(this) }
        var server by remember { mutableStateOf(current.baseUrl) }
        var token by remember { mutableStateOf(current.token) }
        var status by remember { mutableStateOf("Configuração carregada.") }
        var busy by remember { mutableStateOf(false) }

        Column(
            Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text("Configuração", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            Text("Use a URL HTTPS externa da API da casa. O aplicativo funciona offline e continuará tentando a conexão após salvar.")
            OutlinedTextField(
                value = server,
                onValueChange = { server = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("URL pública da casa") },
                placeholder = { Text("https://seu-host") },
                singleLine = true
            )
            OutlinedTextField(
                value = token,
                onValueChange = { token = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Token deste celular") },
                singleLine = true
            )
            Button(
                onClick = {
                    JarvisApi.saveConfig(this@MainActivity, server, token)
                    runCatching { startJarvisService() }
                    status = "Configuração salva. Reconexão automática ativa."
                },
                modifier = Modifier.fillMaxWidth()
            ) { Text("Salvar") }
            OutlinedButton(
                onClick = {
                    JarvisApi.saveConfig(this@MainActivity, server, token)
                    busy = true
                    scope.launch {
                        status = try {
                            withContext(Dispatchers.IO) { JarvisApi.testConnection(this@MainActivity) }
                        } catch (e: Exception) {
                            "Sem conexão agora. O serviço continuará tentando automaticamente. ${e.message ?: ""}"
                        }
                        busy = false
                    }
                },
                modifier = Modifier.fillMaxWidth(),
                enabled = !busy
            ) { Text(if (busy) "Testando..." else "Testar agora") }
            ElevatedCard(Modifier.fillMaxWidth()) {
                Text(status, modifier = Modifier.padding(16.dp))
            }
        }
    }
}
