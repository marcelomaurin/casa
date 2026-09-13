package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.app.Activity
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
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.Locale

class MainActivity : ComponentActivity() {
    private var recognizer: SpeechRecognizer? = null
    private var onSpeechResult: ((String) -> Unit)? = null
    private val http = OkHttpClient()

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        requestPermissionsIfNeeded()
        startJarvisService()
        setupSpeechRecognizer()
        setContent { JarvisScreen() }
    }

    override fun onDestroy() {
        recognizer?.destroy()
        super.onDestroy()
    }

    private fun requestPermissionsIfNeeded() {
        val p = mutableListOf(Manifest.permission.RECORD_AUDIO)
        if (Build.VERSION.SDK_INT >= 33) p += Manifest.permission.POST_NOTIFICATIONS
        if (Build.VERSION.SDK_INT >= 31) {
            p += Manifest.permission.BLUETOOTH_CONNECT
            p += Manifest.permission.BLUETOOTH_SCAN
            p += Manifest.permission.BLUETOOTH_ADVERTISE
        }
        permissionLauncher.launch(p.toTypedArray())
    }

    private fun startJarvisService() {
        val i = Intent(this, JarvisConnectionService::class.java)
        ContextCompat.startForegroundService(this, i)
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
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) return
        onSpeechResult = callback
        val i = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, "pt-BR")
            putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, false)
            putExtra(RecognizerIntent.EXTRA_PROMPT, "Fale com o JARVIS")
        }
        recognizer?.startListening(i)
    }

    private fun playAudio(path: String?) {
        if (path.isNullOrBlank()) return
        val cfg = JarvisApi.loadConfig(this)
        val url = JarvisApi.absoluteUrl(this, path)

        Thread {
            try {
                val req = Request.Builder().url(url).header("X-Device-Token", cfg.token).build()
                val response = http.newCall(req).execute()
                val bytes = response.body?.bytes() ?: return@Thread
                response.close()
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
            } catch (_: Exception) { }
        }.start()
    }

    @Composable
    private fun JarvisScreen() {
        val scope = rememberCoroutineScope()
        var cfg by remember { mutableStateOf(JarvisApi.loadConfig(this)) }
        var server by remember { mutableStateOf(cfg.baseUrl) }
        var token by remember { mutableStateOf(cfg.token) }
        var typed by remember { mutableStateOf("") }
        var heard by remember { mutableStateOf("") }
        var answer by remember { mutableStateOf("Pronto para conversar.") }
        var busy by remember { mutableStateOf(false) }

        MaterialTheme {
            Scaffold(
                topBar = { TopAppBar(title = { Text("JARVIS Mobile") }) }
            ) { pad ->
                Column(
                    modifier = Modifier
                        .padding(pad)
                        .padding(16.dp)
                        .fillMaxSize()
                        .verticalScroll(rememberScrollState()),
                    verticalArrangement = Arrangement.spacedBy(12.dp)
                ) {
                    Text("Conversa com IA", style = MaterialTheme.typography.titleLarge)
                    if (heard.isNotBlank()) Text("Você: $heard")
                    Text("JARVIS: $answer")

                    OutlinedTextField(
                        value = typed,
                        onValueChange = { typed = it },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Mensagem") }
                    )

                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(
                            enabled = !busy && typed.isNotBlank(),
                            onClick = {
                                val q = typed.trim()
                                typed = ""
                                heard = q
                                busy = true
                                scope.launch {
                                    try {
                                        val a = withContext(Dispatchers.IO) { JarvisApi.askJarvis(this@MainActivity, q) }
                                        answer = a.text
                                        playAudio(a.audioUrl)
                                    } catch (e: Exception) {
                                        answer = "Erro: ${e.message}"
                                    } finally { busy = false }
                                }
                            }
                        ) { Text("Enviar") }

                        Button(
                            enabled = !busy,
                            onClick = {
                                listen { text ->
                                    if (text.isBlank()) {
                                        answer = "Não consegui entender o áudio."
                                        return@listen
                                    }
                                    heard = text
                                    busy = true
                                    scope.launch {
                                        try {
                                            val a = withContext(Dispatchers.IO) { JarvisApi.askJarvis(this@MainActivity, text) }
                                            answer = a.text
                                            playAudio(a.audioUrl)
                                        } catch (e: Exception) {
                                            answer = "Erro: ${e.message}"
                                        } finally { busy = false }
                                    }
                                }
                            }
                        ) { Text(if (busy) "Aguarde" else "🎤 Falar") }
                    }

                    Divider()
                    Text("Relógio e internet", style = MaterialTheme.typography.titleMedium)
                    Text("O app mantém uma ponte BLE para o JARVIS Watch. O relógio pode enviar comandos ao celular; o celular usa sua conexão com a internet e devolve a resposta pelo BLE.")
                    Button(onClick = {
                        try { startActivity(Intent(Settings.ACTION_TETHER_SETTINGS)) }
                        catch (_: Exception) { startActivity(Intent(Settings.ACTION_WIRELESS_SETTINGS)) }
                    }) { Text("Abrir compartilhamento de internet") }

                    Divider()
                    Text("Configuração", style = MaterialTheme.typography.titleMedium)
                    OutlinedTextField(
                        value = server,
                        onValueChange = { server = it },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Servidor JARVIS") },
                        placeholder = { Text("http://192.168.2.12") }
                    )
                    OutlinedTextField(
                        value = token,
                        onValueChange = { token = it },
                        modifier = Modifier.fillMaxWidth(),
                        label = { Text("Token do dispositivo Android") }
                    )
                    Button(onClick = {
                        JarvisApi.saveConfig(this@MainActivity, server, token)
                        cfg = JarvisApi.loadConfig(this@MainActivity)
                        startJarvisService()
                        answer = "Configuração salva."
                    }) { Text("Salvar") }

                    Spacer(Modifier.height(20.dp))
                    Text(
                        "O serviço em segundo plano informa ao JARVIS quando o celular entra/sai do Wi‑Fi, consulta notificações e mantém o serviço BLE ativo.",
                        style = MaterialTheme.typography.bodySmall
                    )
                }
            }
        }
    }
}
