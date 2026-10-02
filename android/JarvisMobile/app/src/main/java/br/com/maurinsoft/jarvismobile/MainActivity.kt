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
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import android.net.Uri
import com.journeyapps.barcodescanner.ScanContract

class MainActivity : ComponentActivity() {
    private lateinit var speechInput: SpeechInputController
    private lateinit var audioPlayer: JarvisAudioPlayer
    private lateinit var sessionController: MobileSessionController
    private lateinit var commandController: JarvisCommandController

        private var onQrCodeScannedCallback: ((String) -> Unit)? = null

    private val qrScanLauncher = registerForActivityResult(ScanContract()) { result ->
        val contents = result.contents
        if (!contents.isNullOrBlank()) {
            onQrCodeScannedCallback?.invoke(contents)
        }
    }

    private val pickImageLauncher = registerForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) {
            decodeQrFromUri(uri)
        }
    }

    private fun decodeQrFromUri(uri: Uri) {
        lifecycleScope.launch {
            val text = withContext(Dispatchers.IO) { QrLogin.decodeImage(this@MainActivity, uri) }
            if (text != null) onQrCodeScannedCallback?.invoke(text)
        }
    }

private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        speechInput = SpeechInputController(this).also { it.initialize() }
        audioPlayer = JarvisAudioPlayer(this)
        sessionController = MobileSessionController(this)
        commandController = JarvisCommandController(this)
        UpdateManager.checkAndDownloadAsync(this, true)
        setContent { JarvisApp() }
    }

    override fun onDestroy() {
        if (::speechInput.isInitialized) speechInput.destroy()
        super.onDestroy()
    }

    private fun tr(key: String): String = AppStrings.get(this, key)

    private fun startJarvisService() = sessionController.startConnectionService()

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
                        if (MobileAuth.hasInstallationLink(this@MainActivity)) finish()
                        else {
                            appState.clearLocalSession()
                            Thread { runCatching { sessionController.logout() } }.start()
                        }
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
                if (cfg.baseUrl.startsWith("http")) "Aponte a câmera para o QR Code da CASA ou informe suas credenciais."
                else "Configure a URL da CASA ou leia o QR Code."
            )
        }
        var busy by remember { mutableStateOf(false) }

        fun processQrPayload(scannedText: String) {
            busy = true
            status = "QR Code lido! Conectando à CASA..."
            scope.launch {
                try {
                    val r = withContext(Dispatchers.IO) { QrLogin.login(this@MainActivity, scannedText, server) }
                    server = r.baseUrl
                    status = if (r.paired) "Celular pareado com sucesso!" else "Acesso autorizado via QR Code!"
                    busy = false
                    onLoggedIn(r.session)
                } catch (e: Exception) {
                    busy = false
                    status = "Falha ao conectar via QR Code: " + (e.message ?: e.toString())
                }
            }
        }

        LcarsFrame(
            title = "Acesso ao sistema",
            subtitle = "CASA / JARVIS • autenticação do operador",
            user = "",
            canBack = false,
            onBack = {},
            onHome = {},
            onLogout = {}
        ) {
            if (!MobileAuth.hasInstallationLink(this@MainActivity)) QrAccessSection(
                busy = busy,
                onScan = {
                    onQrCodeScannedCallback = { payload -> processQrPayload(payload) }
                    qrScanLauncher.launch(QrLogin.scanOptions())
                },
                onPickImage = {
                    onQrCodeScannedCallback = { payload -> processQrPayload(payload) }
                    pickImageLauncher.launch("image/*")
                }
            )

            Spacer(Modifier.height(14.dp))

            LcarsSectionLabel("LOGIN MANUAL", LcarsColors.Salmon)

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
                            runCatching { sessionController.login(user, password) }
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
            ) { Text(if (busy) "AUTENTICANDO..." else "AUTORIZAR ACESSO MANUAL") }

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
        val navigation = remember { MobileNavigationState() }
        val route = navigation.route
        val title = navigation.title()

        val subtitle = when {
            !configured -> "CASA não configurada"
            online -> "CASA online" + if (pending > 0) " • fila: $pending" else ""
            else -> "CASA offline • reconexão automática"
        }

        androidx.activity.compose.BackHandler(enabled = navigation.canBack) { navigation.back() }
        Scaffold(
            bottomBar = {
                NavigationBar {
                    listOf(MobileRoute.HOME to "Casa", MobileRoute.VOICE to "JARVIS",
                        MobileRoute.DEVICES_LIST to "Dispositivos", MobileRoute.CONFIG to "Ajustes").forEach { (target, label) ->
                        NavigationBarItem(selected = route == target, onClick = { navigation.home(); if (target != MobileRoute.HOME) navigation.open(target) },
                            icon = { Text(label.take(1)) }, label = { Text(label) })
                    }
                }
            }
        ) { padding ->
            Column(Modifier.fillMaxSize().padding(padding).padding(horizontal = 12.dp)) {
                Text(title, style = MaterialTheme.typography.titleLarge)
                Text(subtitle, style = MaterialTheme.typography.bodySmall)
            when (route) {
                MobileRoute.HOME -> {
                    Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    LcarsSectionLabel("MINHA CASA", LcarsColors.Orange)
                    LcarsMenuButton("PAINEL DA CASA", "Sensores, dispositivos e cenas", LcarsColors.Gold) {
                        startActivity(Intent(this@MainActivity, DashboardActivity::class.java))
                    }
                    LcarsMenuButton("CASA", "Operações, sensores e estado da residência", LcarsColors.Salmon) { navigation.open(MobileRoute.CASA_MENU) }
                    LcarsMenuButton("JARVIS", "Voz, comando manual e respostas", LcarsColors.Lavender) { navigation.open(MobileRoute.JARVIS_MENU) }
                    LcarsMenuButton("WATCH", "Relógio, recursos e configuração", LcarsColors.Blue) { navigation.open(MobileRoute.WATCH_MENU) }
                    LcarsMenuButton("DEVICES", "Equipamentos e novos dispositivos", LcarsColors.Gold) { navigation.open(MobileRoute.DEVICES_MENU) }
                    LcarsMenuButton("SISTEMA", "Conexão, idioma e credenciais", LcarsColors.Green) { navigation.open(MobileRoute.SYSTEM_MENU) }
                    }
                }

                MobileRoute.CASA_MENU -> {
                    LcarsSectionLabel("CASA", LcarsColors.Salmon)
                    LcarsMenuButton("OPERAÇÕES", "Controles rápidos e comando manual", LcarsColors.Salmon) { navigation.open(MobileRoute.OPERATIONS) }
                    LcarsMenuButton("STATUS E SENSORES", "Consulta de estado, temperatura e sensores", LcarsColors.Orange) { startActivity(Intent(this@MainActivity, DashboardActivity::class.java)) }
                }

                MobileRoute.JARVIS_MENU -> {
                    LcarsSectionLabel("JARVIS", LcarsColors.Lavender)
                    LcarsMenuButton("VOZ", "Falar com o JARVIS", LcarsColors.Lavender) { navigation.open(MobileRoute.VOICE) }
                    LcarsMenuButton("COMANDO MANUAL", "Digitar uma solicitação", LcarsColors.Blue) { navigation.open(MobileRoute.VOICE) }
                }

                MobileRoute.WATCH_MENU -> {
                    LcarsSectionLabel("WATCH", LcarsColors.Blue)
                    LcarsMenuButton("ESTADO DO WATCH", "Conexão local, CASA e heartbeat", LcarsColors.Blue) { navigation.open(MobileRoute.WATCH_STATUS) }
                    LcarsMenuButton("CONFIGURAR WATCH", "Wi-Fi, identidade e provisionamento", LcarsColors.Salmon) {
                        startActivity(Intent(this@MainActivity, WatchSetupActivity::class.java))
                    }
                }

                MobileRoute.DEVICES_MENU -> {
                    LcarsSectionLabel("DEVICES", LcarsColors.Gold)
                    LcarsMenuButton("LISTAR DEVICES", "Equipamentos cadastrados", LcarsColors.Gold) { navigation.open(MobileRoute.DEVICES_LIST) }
                    LcarsMenuButton("ADICIONAR DEVICE", "Watch, ESP32-CAM e novos equipamentos", LcarsColors.Orange) {
                        startActivity(Intent(this@MainActivity, NewDevicesActivity::class.java))
                    }
                }

                MobileRoute.SYSTEM_MENU -> {
                    LcarsSectionLabel("SISTEMA", LcarsColors.Green)
                    LcarsMenuButton("CONFIGURAÇÃO", "URL CASA, token e idioma", LcarsColors.Green) { navigation.open(MobileRoute.CONFIG) }
                    LcarsMenuButton("SAIR", "Encerrar sessão do operador", LcarsColors.Salmon) { onLogout() }
                }

                MobileRoute.OPERATIONS -> OperationsScreen(online, pending)
                MobileRoute.VOICE -> ConversationScreen(online, speechInput, audioPlayer) {
                    permissionLauncher.launch(arrayOf(Manifest.permission.RECORD_AUDIO))
                }
                MobileRoute.WATCH_STATUS -> WatchScreen()
                MobileRoute.DEVICES_LIST -> DevicesScreen()
                MobileRoute.CONFIG -> ConfigScreen()
            }
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
        fun send(text: String) { if (text.isBlank() || busy) return; busy = true; scope.launch { val result = withContext(Dispatchers.IO) { commandController.send(text) }; response = if (result.delivered) result.text ?: tr("executed") else result.message; if (result.delivered) playAudio(result.audioUrl); busy = false } }
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
        var devices by remember { mutableStateOf<List<ControlPlaneApi.DeviceInfo>>(emptyList()) }
        var loading by remember { mutableStateOf(false) }
        var error by remember { mutableStateOf<String?>(null) }

        fun refreshDevices() {
            if (loading) return
            loading = true
            scope.launch {
                val result = withContext(Dispatchers.IO) { runCatching { ControlPlaneApi.listDevices(this@MainActivity) } }
                result.onSuccess { devices = it; error = null }.onFailure { error = "Falha ao consultar dispositivos. Tente atualizar." }
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

            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            if (devices.isEmpty()) {
                ElevatedCard(Modifier.fillMaxWidth()) {
                    Text(if (loading) "Carregando dispositivos..." else "Nenhum dispositivo cadastrado no CASA.", modifier = Modifier.padding(16.dp))
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
            OutlinedTextField(token, { token = it }, Modifier.fillMaxWidth(), label = { Text(tr("phone_token")) }, singleLine = true, visualTransformation = PasswordVisualTransformation())
            Button(onClick = { JarvisApi.saveConfig(this@MainActivity, server, token); runCatching { startJarvisService() }; status = tr("saved") }, Modifier.fillMaxWidth()) { Text(tr("save")) }
            OutlinedButton(onClick = { JarvisApi.saveConfig(this@MainActivity, server, token); busy = true; scope.launch { status = try { withContext(Dispatchers.IO) { JarvisApi.testConnection(this@MainActivity) } } catch (e: Exception) { "${tr("offline_reconnecting")}. ${e.message ?: ""}" }; busy = false } }, Modifier.fillMaxWidth(), enabled = !busy) { Text(if (busy) tr("testing") else tr("test_now")) }

            HorizontalDivider()
            InstallationSettings { recreate() }
            Text("Sistema", fontWeight = FontWeight.Bold)
            Text("Use as abas WATCH e DEVICES para gerenciar equipamentos e integrações.")

            ElevatedCard(Modifier.fillMaxWidth()) { Text(status, modifier = Modifier.padding(16.dp)) }
        }
    }
}
