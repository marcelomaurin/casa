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
        OPERATIONS("Operações"),
        VOICE("Voz"),
        CONFIG("Configuração")
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
        val intent = Intent(this, JarvisConnectionService::class.java)
        ContextCompat.startForegroundService(this, intent)
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
        val url = JarvisApi.absoluteUrl(this, path)

        Thread {
            try {
                val req = Request.Builder().url(url).header("X-Device-Token", cfg.token).build()
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
            } catch (_: Exception) {
            }
        }.start()
    }

    private fun openTetherSettings() {
        try {
            startActivity(Intent("android.settings.TETHER_SETTINGS"))
        } catch (_: Exception) {
            try {
                startActivity(Intent(Settings.ACTION_WIRELESS_SETTINGS))
            } catch (_: Exception) {
                startActivity(Intent(Settings.ACTION_SETTINGS))
            }
        }
    }

    @Composable
    private fun JarvisApp() {
        var splash by remember { mutableStateOf(true) }
        LaunchedEffect(Unit) {
            delay(1200)
            splash = false
        }

        MaterialTheme {
            if (splash) {
                SplashScreen()
            } else {
                MainShell()
            }
        }
    }

    @Composable
    private fun SplashScreen() {
        Surface(modifier = Modifier.fillMaxSize()) {
            Column(
                modifier = Modifier.fillMaxSize().padding(32.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center
            ) {
                Text("JARVIS", style = MaterialTheme.typography.displayMedium, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(12.dp))
                Text("Assistente residencial inteligente", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(28.dp))
                CircularProgressIndicator()
                Spacer(Modifier.height(18.dp))
                Text("Inicializando serviços, voz e conexão...", textAlign = TextAlign.Center)
            }
        }
    }

    @OptIn(ExperimentalMaterial3Api::class)
    @Composable
    private fun MainShell() {
        var screen by remember { mutableStateOf(Screen.OPERATIONS) }

        Scaffold(
            topBar = {
                TopAppBar(
                    title = {
                        Column {
                            Text("JARVIS Mobile", fontWeight = FontWeight.Bold)
                            Text(screen.title, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                )
            },
            bottomBar = {
                NavigationBar {
                    Screen.values().forEach { item ->
                        NavigationBarItem(
                            selected = screen == item,
                            onClick = { screen = item },
                            icon = { Text(when (item) {
                                Screen.OPERATIONS -> "⌂"
                                Screen.VOICE -> "●"
                                Screen.CONFIG -> "⚙"
                            }) },
                            label = { Text(item.title) }
                        )
                    }
                }
            }
        ) { padding ->
            Box(modifier = Modifier.padding(padding).fillMaxSize()) {
                when (screen) {
                    Screen.OPERATIONS -> OperationsScreen()
                    Screen.VOICE -> VoiceScreen()
                    Screen.CONFIG -> ConfigScreen()
                }
            }
        }
    }

    @Composable
    private fun OperationsScreen() {
        val scope = rememberCoroutineScope()
        var command by remember { mutableStateOf("") }
        var response by remember { mutableStateOf("Selecione uma operação ou envie um comando manual.") }
        var busy by remember { mutableStateOf(false) }
        var connected by remember { mutableStateOf<Boolean?>(null) }

        fun send(text: String) {
            if (text.isBlank() || busy) return
            busy = true
            response = "Executando: $text"
            scope.launch {
                try {
                    val answer = withContext(Dispatchers.IO) { JarvisApi.askJarvis(this@MainActivity, text) }
                    response = answer.text.ifBlank { "Comando enviado." }
                    playAudio(answer.audioUrl)
                    connected = true
                } catch (e: Exception) {
                    connected = false
                    response = "Falha ao comunicar com o JARVIS: ${e.message ?: "erro desconhecido"}"
                } finally {
                    busy = false
                }
            }
        }

        Column(
            modifier = Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            ElevatedCard(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Estado da conexão", fontWeight = FontWeight.Bold)
                    Text(when (connected) {
                        true -> "JARVIS acessível"
                        false -> "Sem comunicação com o JARVIS"
                        null -> "Ainda não testado"
                    })
                    Button(onClick = {
                        scope.launch {
                            busy = true
                            try {
                                response = withContext(Dispatchers.IO) { JarvisApi.testConnection(this@MainActivity) }
                                connected = true
                            } catch (e: Exception) {
                                connected = false
                                response = "Teste falhou: ${e.message}"
                            } finally { busy = false }
                        }
                    }, enabled = !busy) { Text("Testar conexão") }
                }
            }

            Text("Operações rápidas", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { send("Ligue a luz da sala") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Ligar luz") }
                OutlinedButton(onClick = { send("Desligue a luz da sala") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Desligar luz") }
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { send("Ligue a irrigação") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Irrigação ON") }
                OutlinedButton(onClick = { send("Desligue a irrigação") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Irrigação OFF") }
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { send("Informe o status da casa") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Status") }
                Button(onClick = { send("Qual a temperatura atual dos sensores?") }, modifier = Modifier.weight(1f), enabled = !busy) { Text("Temperatura") }
            }

            HorizontalDivider()
            Text("Comando manual", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            OutlinedTextField(
                value = command,
                onValueChange = { command = it },
                label = { Text("Digite uma ordem para o JARVIS") },
                modifier = Modifier.fillMaxWidth(),
                minLines = 2
            )
            Button(
                onClick = {
                    val text = command.trim()
                    command = ""
                    send(text)
                },
                enabled = command.isNotBlank() && !busy,
                modifier = Modifier.fillMaxWidth()
            ) { Text(if (busy) "Executando..." else "Executar") }

            ElevatedCard(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp)) {
                    Text("Resposta", fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(6.dp))
                    Text(response)
                }
            }
        }
    }

    @Composable
    private fun VoiceScreen() {
        val scope = rememberCoroutineScope()
        var heard by remember { mutableStateOf("") }
        var answer by remember { mutableStateOf("Toque em Falar e faça seu pedido.") }
        var listening by remember { mutableStateOf(false) }
        var busy by remember { mutableStateOf(false) }

        fun submit(text: String) {
            if (text.isBlank()) return
            busy = true
            scope.launch {
                try {
                    val result = withContext(Dispatchers.IO) { JarvisApi.askJarvis(this@MainActivity, text) }
                    answer = result.text.ifBlank { "O JARVIS respondeu sem texto." }
                    playAudio(result.audioUrl)
                } catch (e: Exception) {
                    answer = "Erro de comunicação: ${e.message ?: "falha desconhecida"}"
                } finally {
                    busy = false
                }
            }
        }

        Column(
            modifier = Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            Text("Conversa por voz", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            Text("Use o microfone do celular para falar com o JARVIS. A resposta pode ser reproduzida em áudio pela API.", textAlign = TextAlign.Center)

            ElevatedCard(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Você", fontWeight = FontWeight.Bold)
                    Text(if (heard.isBlank()) "Nenhuma frase reconhecida ainda." else heard)
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
                        if (text.isBlank()) {
                            answer = "Não consegui reconhecer a fala. Verifique a permissão do microfone e tente novamente."
                        } else {
                            heard = text
                            answer = "Enviando para o JARVIS..."
                            submit(text)
                        }
                    }
                },
                enabled = !busy && !listening,
                modifier = Modifier.fillMaxWidth().height(64.dp)
            ) {
                Text(when {
                    listening -> "Ouvindo..."
                    busy -> "Processando..."
                    else -> "FALAR COM O JARVIS"
                })
            }

            OutlinedButton(
                onClick = { playAudio(JarvisApi.lastAudioUrl) },
                enabled = !JarvisApi.lastAudioUrl.isNullOrBlank(),
                modifier = Modifier.fillMaxWidth()
            ) { Text("Repetir último áudio") }
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
            modifier = Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text("Servidor JARVIS", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            OutlinedTextField(
                value = server,
                onValueChange = { server = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("URL do servidor") },
                placeholder = { Text("http://192.168.2.12") },
                singleLine = true
            )
            OutlinedTextField(
                value = token,
                onValueChange = { token = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Token do dispositivo Android") },
                singleLine = true
            )

            Button(
                onClick = {
                    JarvisApi.saveConfig(this@MainActivity, server, token)
                    runCatching { startJarvisService() }
                    status = "Configuração salva."
                },
                modifier = Modifier.fillMaxWidth()
            ) { Text("Salvar configuração") }

            OutlinedButton(
                onClick = {
                    JarvisApi.saveConfig(this@MainActivity, server, token)
                    busy = true
                    scope.launch {
                        try {
                            status = withContext(Dispatchers.IO) { JarvisApi.testConnection(this@MainActivity) }
                        } catch (e: Exception) {
                            status = "Falha: ${e.message ?: "sem resposta"}"
                        } finally { busy = false }
                    }
                },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth()
            ) { Text(if (busy) "Testando..." else "Testar servidor") }

            ElevatedCard(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Estado", fontWeight = FontWeight.Bold)
                    Text(status)
                }
            }

            HorizontalDivider()
            Text("Relógio e conectividade", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Text("O serviço mantém a ponte BLE para o relógio e consulta notificações do JARVIS em segundo plano.")
            Button(onClick = { runCatching { startJarvisService() }; status = "Serviço JARVIS iniciado." }, modifier = Modifier.fillMaxWidth()) {
                Text("Iniciar serviço JARVIS")
            }
            OutlinedButton(onClick = { openTetherSettings() }, modifier = Modifier.fillMaxWidth()) {
                Text("Abrir compartilhamento de internet")
            }

            Text(
                "Se estiver fora da rede local, configure aqui o endereço HTTPS público do JARVIS. Não use um token administrativo; use um token próprio do dispositivo Android.",
                style = MaterialTheme.typography.bodySmall
            )
        }
    }
}
