package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
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

class MainActivity : ComponentActivity() {
    private lateinit var speechInput: SpeechInputController
    private lateinit var audioPlayer: JarvisAudioPlayer

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        speechInput = SpeechInputController(this).also { it.initialize() }
        audioPlayer = JarvisAudioPlayer(this)
        setContent { JarvisApp() }
    }

    override fun onDestroy() {
        if (::speechInput.isInitialized) speechInput.destroy()
        super.onDestroy()
    }

    private fun tr(key: String): String = AppStrings.get(this, key)

    private fun startJarvisService() {
        ContextCompat.startForegroundService(this, Intent(this, JarvisConnectionService::class.java))
    }

    private fun listen(callback: (String) -> Unit) = speechInput.listen(callback)

    private fun playAudio(path: String?) = audioPlayer.play(path)

    @Composable
    private fun JarvisApp() {
        val appState = remember { MobileAppState(this@MainActivity) }

        LaunchedEffect(Unit) {
            delay(300)
            appState.finishSplash()
            if (appState.session != null) {
                launch { appState.validateSession() }
            }
        }

        LaunchedEffect(appState.session) {
            if (appState.session != null) runCatching { startJarvisService() }
        }

        LaunchedEffect(appState.splash, appState.session) {
            if (!appState.splash && appState.session != null) while (true) {
                appState.refreshConnection()
                delay(if (appState.online) 10_000 else 5_000)
            }
        }

        MaterialTheme {
            when {
                appState.splash -> SplashScreen()
                appState.session == null -> LoginScreen { appState.onLoggedIn(it) }
                else -> MainShell(
                    online = appState.online,
                    configured = appState.configured,
                    pending = appState.pending,
                    session = appState.session!!,
                    onLogout = {
                        appState.clearLocalSession()
                        runCatching { stopService(Intent(this@MainActivity, JarvisConnectionService::class.java)) }
                        Thread { runCatching { MobileAuth.logout(this@MainActivity) } }.start()
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
        var server by remember { mutableStateOf(cfg.baseUrl) }
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
                value = server,
                onValueChange = { server = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Servidor CASA") },
                singleLine = true,
                enabled = !busy
            )

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
                    JarvisApi.saveConfig(this@MainActivity, server, cfg.token)
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
        var stack by remember { mutableStateOf(listOf(MobileRoute.HOME)) }
        val route = stack.last()

        fun open(next: MobileRoute) {
            stack = stack + next
        }

        fun back() {
            if (stack.size > 1) stack = stack.dropLast(1)
        }

        fun home() {
            stack = listOf(MobileRoute.HOME)
        }

        val title = when (route) {
            MobileRoute.HOME -> "JARVIS Mobile"
            MobileRoute.CASA_MENU -> "CASA"
            MobileRoute.JARVIS_MENU -> "JARVIS"
            MobileRoute.WATCH_MENU -> "Watch"
            MobileRoute.DEVICES_MENU -> "Devices"
            MobileRoute.SYSTEM_MENU -> "Sistema"
            MobileRoute.OPERATIONS -> "Operações"
            MobileRoute.VOICE -> "Voz"
            MobileRoute.WATCH_STATUS -> "Watch / Estado"
            MobileRoute.DEVICES_LIST -> "Devices / Lista"
            MobileRoute.CONFIG -> "Sistema / Configuração"
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
                MobileRoute.HOME -> {
                    LcarsSectionLabel("MENU PRINCIPAL", LcarsColors.Orange)
                    LcarsMenuButton("CASA", "Operações, sensores e estado da residência", LcarsColors.Salmon) { open(MobileRoute.CASA_MENU) }
                    LcarsMenuButton("JARVIS", "Voz, comando manual e respostas", LcarsColors.Lavender) { open(MobileRoute.JARVIS_MENU) }
                    LcarsMenuButton("WATCH", "Relógio, recursos e configuração", LcarsColors.Blue) { open(MobileRoute.WATCH_MENU) }
                    LcarsMenuButton("DEVICES", "Equipamentos e novos dispositivos", LcarsColors.Gold) { open(MobileRoute.DEVICES_MENU) }
                    LcarsMenuButton("SISTEMA", "Conexão, idioma e credenciais", LcarsColors.Green) { open(MobileRoute.SYSTEM_MENU) }
                }

                MobileRoute.CASA_MENU -> {
                    LcarsSectionLabel("CASA", LcarsColors.Salmon)
                    LcarsMenuButton("OPERAÇÕES", "Controles rápidos e comando manual", LcarsColors.Salmon) { open(MobileRoute.OPERATIONS) }
                    LcarsMenuButton("STATUS E SENSORES", "Consulta de estado, temperatura e sensores", LcarsColors.Orange) { open(MobileRoute.OPERATIONS) }
                }

                MobileRoute.JARVIS_MENU -> {
                    LcarsSectionLabel("JARVIS", LcarsColors.Lavender)
                    LcarsMenuButton("VOZ", "Falar com o JARVIS", LcarsColors.Lavender) { open(MobileRoute.VOICE) }
                    LcarsMenuButton("COMANDO MANUAL", "Digitar uma solicitação", LcarsColors.Blue) { open(MobileRoute.OPERATIONS) }
                }

                MobileRoute.WATCH_MENU -> {
                    LcarsSectionLabel("WATCH", LcarsColors.Blue)
                    LcarsMenuButton("ESTADO DO WATCH", "Conexão local, CASA e heartbeat", LcarsColors.Blue) { open(MobileRoute.WATCH_STATUS) }
                    LcarsMenuButton("CONFIGURAR WATCH", "Wi-Fi, identidade e provisionamento", LcarsColors.Salmon) {
                        startActivity(Intent(this@MainActivity, WatchSetupActivity::class.java))
                    }
                }

                MobileRoute.DEVICES_MENU -> {
                    LcarsSectionLabel("DEVICES", LcarsColors.Gold)
                    LcarsMenuButton("LISTAR DEVICES", "Equipamentos cadastrados", LcarsColors.Gold) { open(MobileRoute.DEVICES_LIST) }
                    LcarsMenuButton("ADICIONAR DEVICE", "Watch, ESP32-CAM e novos equipamentos", LcarsColors.Orange) {
                        startActivity(Intent(this@MainActivity, NewDevicesActivity::class.java))
                    }
                }

                MobileRoute.SYSTEM_MENU -> {
                    LcarsSectionLabel("SISTEMA", LcarsColors.Green)
                    LcarsMenuButton("CONFIGURAÇÃO", "URL CASA, token e idioma", LcarsColors.Green) { open(MobileRoute.CONFIG) }
                    LcarsMenuButton("SAIR", "Encerrar sessão do operador", LcarsColors.Salmon) { onLogout() }
                }

                MobileRoute.OPERATIONS -> OperationsScreen(online, pending)
                MobileRoute.VOICE -> VoiceScreen(online)
                MobileRoute.WATCH_STATUS -> WatchScreen()
                MobileRoute.DEVICES_LIST -> DevicesScreen()
                MobileRoute.CONFIG -> ConfigScreen()
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
            Button(
                onClick = {
                    if (ContextCompat.checkSelfPermission(
                            this@MainActivity,
                            Manifest.permission.RECORD_AUDIO
                        ) != PackageManager.PERMISSION_GRANTED
                    ) {
                        answer = "O microfone é necessário somente para usar o comando por voz. Autorize e toque novamente em FALAR."
                        permissionLauncher.launch(arrayOf(Manifest.permission.RECORD_AUDIO))
                    } else {
                        listening = true
                        answer = tr("listening")
                        listen { text ->
                            listening = false
                            if (text.isBlank()) answer = tr("not_recognized")
                            else {
                                heard = text
                                submit(text)
                            }
                        }
                    }
                },
                enabled = !busy && !listening,
                modifier = Modifier.fillMaxWidth().height(64.dp)
            ) {
                Text(if (listening) tr("listening") else if (busy) tr("processing") else tr("speak"))
            }
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
                    Text("Somente alertas gerados pelo próprio JARVIS/CASA. O app não lê notificações de outros aplicativos.")
                    Text("Voz / JARVIS", fontWeight = FontWeight.Bold)
                    Text("Comandos do Watch podem ser processados pelo celular e devolvidos por TCP/CASA.")
                }
            }

            Button(
                onClick = { startActivity(Intent(this@MainActivity, WatchSetupActivity::class.java)) },
                modifier = Modifier.fillMaxWidth()
            ) { Text("CONFIGURAR WATCH") }

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
