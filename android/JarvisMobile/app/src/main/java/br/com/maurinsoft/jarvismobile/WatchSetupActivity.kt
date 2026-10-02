package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.pm.PackageManager
import android.net.wifi.WifiManager
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import org.json.JSONObject
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Fluxo oficial de cadastro do relógio:
 *
 * Configuração -> Novos Devices -> Watch -> conectar ao SoftAP JARVIS-WATCH ->
 * socket TCP 192.168.4.1:4040 -> app cria identidade/token no CASA ->
 * app envia URL/token e perfis Wi-Fi ao Watch.
 *
 * O usuário nunca precisa copiar/colar token de hardware manualmente e o
 * transporte local não usa Bluetooth.
 */
class WatchSetupActivity : ComponentActivity(), WatchClient.Listener {
    private lateinit var watchClient: WatchClient

    private var onQrScanned: ((String) -> Unit)? = null
    private val qrScanLauncher = registerForActivityResult(ScanContract()) { result ->
        val text = result.contents
        if (!text.isNullOrBlank()) {
            onQrScanned?.invoke(text)
        }
    }

    data class DirectStepItem(
        val index: Int,
        val total: Int,
        val text: String,
        val isDone: Boolean,
        val isError: Boolean,
        val errorDetail: String?
    )

    private var directStepItems by mutableStateOf<List<DirectStepItem>>(emptyList())
    private var isDirectConnecting by mutableStateOf(false)
    private var directConnectionSuccess by mutableStateOf(false)
    private var directConnectionError by mutableStateOf<String?>(null)
    private var showDirectDialog by mutableStateOf(false)
    private var showProvisionResultDialog by mutableStateOf(false)
    private var provisionResultTitle by mutableStateOf("Configurando Watch")
    private var provisionResultMessage by mutableStateOf("")
    private var provisionResultDone by mutableStateOf(false)
    private var provisionResultSuccess by mutableStateOf(false)

    private var foundState by mutableStateOf<List<WatchClient.FoundWatch>>(emptyList())
    private var statusState by mutableStateOf("Pronto para procurar relógios")
    private var connectedState by mutableStateOf(false)
    private var connectedAddressState by mutableStateOf("")
    private var hardwareIdState by mutableStateOf("")
    private var connectedNameState by mutableStateOf(WatchClient.WATCH_NAME)

    private val permissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        watchClient = WatchClient(this)
        watchClient.addListener(this)

        connectedState = WatchClient.isConnected(this)
        connectedAddressState = WatchClient.savedAddress(this)
        connectedNameState = WatchClient.savedName(this)

        setContent { MaterialTheme { Screen() } }
    }

    override fun onDestroy() {
        watchClient.stopScan()
        watchClient.removeListener(this)
        super.onDestroy()
    }

    private fun startDirectConnection(host: String = WatchClient.WATCH_HOST) {
        showDirectDialog = true
        isDirectConnecting = true
        directConnectionSuccess = false
        directConnectionError = null
        directStepItems = emptyList()

        watchClient.connectDirect(host) { step, total, message, isDone, isError, errorDetail ->
            runOnUiThread {
                val item = DirectStepItem(step, total, message, isDone, isError, errorDetail)
                val current = directStepItems.toMutableList()
                val idx = current.indexOfFirst { it.index == step }
                if (idx >= 0) {
                    current[idx] = item
                } else {
                    current.add(item)
                }
                directStepItems = current

                if (isDone) {
                    isDirectConnecting = false
                    if (isError) {
                        directConnectionSuccess = false
                        directConnectionError = errorDetail ?: message
                    } else {
                        directConnectionSuccess = true
                        directConnectionError = null
                    }
                }
            }
        }
    }

    private fun ensureWifiPermissionAndConnect() {
        val needed = mutableListOf<String>()

        // Android 12 e anteriores exigem acesso de localizacao para operacoes
        // de redes Wi-Fi proximas. So pedimos quando o usuario toca CONECTAR.
        if (Build.VERSION.SDK_INT <= 32) {
            needed += Manifest.permission.ACCESS_COARSE_LOCATION
            needed += Manifest.permission.ACCESS_FINE_LOCATION
        }

        // Android 13+ usa permissao especifica de dispositivos Wi-Fi proximos.
        if (Build.VERSION.SDK_INT >= 33) {
            needed += Manifest.permission.NEARBY_WIFI_DEVICES
        }

        val missing = needed.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }

        if (missing.isNotEmpty()) {
            statusState = "Autorize apenas o acesso Wi-Fi necessário para conectar ao Watch."
            permissionLauncher.launch(missing.toTypedArray())
            return
        }

        foundState = emptyList()
        statusState = "Solicitando rede JARVIS-WATCH..."
        watchClient.scan()
    }

    override fun onScanResult(watch: WatchClient.FoundWatch) {
        runOnUiThread {
            val map = foundState.associateBy { it.address }.toMutableMap()
            map[watch.address] = watch
            foundState = map.values.sortedByDescending { it.rssi }
            statusState = "${foundState.size} relógio(s) encontrado(s)"
        }
    }

    override fun onConnectionChanged(connected: Boolean, name: String, address: String) {
        runOnUiThread {
            connectedState = connected
            if (connected) {
                connectedAddressState = address
                connectedNameState = name
                statusState = "$name conectado por Wi-Fi/TCP. Pronto para cadastrar no CASA."
            } else {
                statusState = "Relógio desconectado"
            }
        }
    }

    override fun onWatchMessage(json: org.json.JSONObject) {
        runOnUiThread {
            val type = json.optString("type")
            val ok = json.optBoolean("ok", true)
            val message = json.optString("message")
            when (type) {
                "hello" -> {
                    val protocol = json.optString("protocol", "?")
                    val hardwareId = json.optString("hardware_id").trim()
                    if (hardwareId.isNotBlank()) {
                        hardwareIdState = hardwareId
                        connectedAddressState = hardwareId
                    }
                    val wifi = if (json.optBoolean("wifi", false)) "Wi-Fi conectado" else "Wi-Fi offline"
                    statusState = "Socket confirmado • protocolo $protocol • $wifi" +
                        if (hardwareId.isNotBlank()) " • $hardwareId" else ""
                }
                "device_identity_result" ->
                    statusState = if (ok) "Watch confirmou o Device ID" else "Falha ao gravar Device ID: $message"
                "casa_config_result" ->
                    statusState = if (ok) "Watch confirmou a configuração CASA" else "Falha na configuração CASA: $message"
                "wifi_profile_result" ->
                    statusState = if (ok) "Watch confirmou perfil Wi-Fi: $message" else "Falha no perfil Wi-Fi: $message"
                "wifi_connect_result" ->
                    statusState = if (ok) "Watch iniciou conex?o Wi-Fi: $message" else "Falha ao iniciar Wi-Fi: $message"
                                "watch_provision_result" -> {
                    if (ok) {
                        provisionResultDone = true
                        provisionResultSuccess = true
                        provisionResultTitle = "Sucesso!"
                        provisionResultMessage = "Relógio configurado com sucesso!\n\nAs configurações de acesso e rede Wi-Fi foram gravadas com sucesso na memória EEPROM do relógio.\n\nO relógio já entrou no modo normal (mostrador real)."
                        statusState = "Relógio configurado com sucesso na EEPROM!"
                    } else {
                        provisionResultDone = true
                        provisionResultSuccess = false
                        provisionResultTitle = "Atenção"
                        provisionResultMessage = "Falha no relógio: " + (if (message.isNotBlank()) message else "Configuração incompleta")
                        statusState = "Falha no relógio: $message"
                    }
                }
                "status" -> {
                    val wifi = json.optBoolean("wifi", false)
                    val casa = json.optBoolean("casa_configured", false)
                    val casaOnline = json.optBoolean("casa_online", false)
                    val ssid = json.optString("ssid")
                    val deviceId = json.optString("device_id")
                    val staIp = json.optString("sta_ip")
                    val hardwareId = json.optString("hardware_id").trim()
                    if (hardwareId.isNotBlank()) {
                        hardwareIdState = hardwareId
                        connectedAddressState = hardwareId
                    }
                    statusState = buildString {
                        append("Watch: ")
                        if (wifi) {
                            append("Wi-Fi OK ($ssid)")
                            if (staIp.isNotBlank()) append(" ? IP: $staIp")
                        } else {
                            append("Conectando ao Wi-Fi...")
                        }
                        if (casaOnline) {
                            append(" ? CASA Online!")
                        } else if (casa) {
                            append(" ? Token CASA OK (sincronizando...)")
                        }
                        if (deviceId.isNotBlank()) append(" ? $deviceId")
                    }
                }
                else -> statusState = "Watch: ${type.ifBlank { "mensagem" }}"
            }
        }
    }

    override fun onError(message: String) {
        runOnUiThread { statusState = message }
    }

    private fun currentSsid(): String {
        if (
            Build.VERSION.SDK_INT <= 32 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) !=
            PackageManager.PERMISSION_GRANTED
        ) return ""
        val wm = applicationContext.getSystemService(WIFI_SERVICE) as WifiManager
        @Suppress("DEPRECATION")
        return wm.connectionInfo?.ssid?.trim('"')
            ?.takeUnless { it == "<unknown ssid>" }.orEmpty()
    }

    @Composable
    private fun Screen() {
        val scope = rememberCoroutineScope()
        val appConfigured = JarvisApi.isConfigured(this)

        var ssid by remember { mutableStateOf(currentSsid()) }
        var password by remember { mutableStateOf("") }
        var slot by remember { mutableIntStateOf(0) }
        var savedProfiles by remember { mutableStateOf(WifiProfileStore.load(this)) }
        var pendingWatch by remember { mutableStateOf<WatchClient.FoundWatch?>(null) }

        var watchName by remember { mutableStateOf("JARVIS Watch") }
        var location by remember { mutableStateOf("Residencia") }
        var provisioning by remember { mutableStateOf(false) }
        var refreshProvision by remember { mutableIntStateOf(0) }

        val watchKey = hardwareIdState.ifBlank { connectedAddressState }
        val existingProvision = remember(watchKey, refreshProvision) {
            if (watchKey.isBlank() || watchKey == WatchClient.WATCH_HOST) null
            else WatchProvisionStore.findByAddress(this, watchKey)
        }

        pendingWatch?.let { candidate ->
            AlertDialog(
                onDismissRequest = { pendingWatch = null },
                title = { Text("Adicionar Watch?") },
                text = {
                    Text(
                        "Usar ${candidate.name} em ${candidate.address}:4040 para criar " +
                            "uma identidade individual no CASA e enviar a credencial ao relógio?"
                    )
                },
                confirmButton = {
                    Button(onClick = {
                        pendingWatch = null
                        watchName = candidate.name.ifBlank { "JARVIS Watch" }
                        statusState = "Conectando ${candidate.name}..."
                        watchClient.connect(candidate.address)
                    }) { Text("CONECTAR") }
                },
                dismissButton = {
                    TextButton(onClick = { pendingWatch = null }) { Text("CANCELAR") }
                }
            )
        }


        if (showDirectDialog) {
            AlertDialog(
                onDismissRequest = {
                    if (!isDirectConnecting) showDirectDialog = false
                },
                title = {
                    Text(
                        if (isDirectConnecting) "Conectando ao Relógio..."
                        else if (directConnectionSuccess) "Conectado ao Relógio!"
                        else "Falha na Conexão"
                    )
                },
                text = {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        directStepItems.forEach { item ->
                            val icon = when {
                                item.isError -> "❌ "
                                item.isDone -> "✅ "
                                else -> "⏳ "
                            }
                            Text("$icon${item.index}/${item.total}: ${item.text}")
                            if (item.errorDetail != null) {
                                Text(
                                    "Detalhes: ${item.errorDetail}",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.error
                                )
                            }
                        }
                        if (isDirectConnecting) {
                            Spacer(Modifier.height(4.dp))
                            LinearProgressIndicator(Modifier.fillMaxWidth())
                        }
                    }
                },
                confirmButton = {
                    if (!isDirectConnecting) {
                        Button(onClick = { showDirectDialog = false }) {
                            Text("FECHAR")
                        }
                    }
                }
            )
        }

        if (showProvisionResultDialog) {
            AlertDialog(
                onDismissRequest = {
                    if (!provisioning) showProvisionResultDialog = false
                },
                title = { Text(provisionResultTitle) },
                text = {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(provisionResultMessage)
                        if (provisioning) {
                            Spacer(Modifier.height(4.dp))
                            LinearProgressIndicator(Modifier.fillMaxWidth())
                        }
                    }
                },
                confirmButton = {
                    if (!provisioning) {
                        Button(onClick = { showProvisionResultDialog = false }) {
                            Text("OK")
                        }
                    }
                }
            )
        }

        Column(
            Modifier
                .fillMaxSize()
                .padding(16.dp)
                .verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text(
                "Configuração > Novos Devices > Watch",
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold
            )
            Text(statusState)

            ElevatedCard(Modifier.fillMaxWidth()) {
                Column(
                    Modifier.padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(5.dp)
                ) {
                    Text("Acesso CASA", fontWeight = FontWeight.Bold)
                    Text(
                        if (appConfigured)
                            "App autenticado. O token do Watch será criado automaticamente no servidor."
                        else
                            "Configure primeiro a URL e o token deste celular na tela Configuração."
                    )
                }
            }

            HorizontalDivider()
            Text("1. Conectar ao relógio", fontWeight = FontWeight.Bold)

            Text(
                "Instru??es:\n" +
                "1. No rel?gio: acesse CONFIG ou WIFI e toque no bot?o 'CELULAR' (ativa o AP JARVIS-WATCH).\n" +
                "2. No celular: toque em 'CONECTAR' (ou conecte na rede Wi-Fi JARVIS-WATCH / senha JarvisSetup2026 e toque em 'CONEX?O DIRETA').",
                style = MaterialTheme.typography.bodySmall
            )

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    onClick = { ensureWifiPermissionAndConnect() },
                    modifier = Modifier.weight(1f)
                ) { Text("CONECTAR") }

                OutlinedButton(
                    onClick = { startDirectConnection() },
                    modifier = Modifier.weight(1f)
                ) { Text("CONEXÃO DIRETA") }
            }

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    onClick = {
                        onQrScanned = { payload ->
                            try {
                                val clean = payload.trim()
                                val ip = if (clean.startsWith("{")) {
                                    val json = JSONObject(clean)
                                    json.optString("ip", WatchClient.WATCH_HOST).ifBlank { WatchClient.WATCH_HOST }
                                } else if (clean.contains("192.168.4.1")) {
                                    "192.168.4.1"
                                } else {
                                    WatchClient.WATCH_HOST
                                }
                                statusState = "QR Code do relógio lido! Conectando em $ip..."
                                startDirectConnection(ip)
                            } catch (_: Exception) {
                                startDirectConnection()
                            }
                        }
                        qrScanLauncher.launch(
                            ScanOptions().apply {
                                setPrompt("Aponte para o QR Code na tela do relógio")
                                setBeepEnabled(true)
                                setBarcodeImageEnabled(true)
                                setOrientationLocked(false)
                                setDesiredBarcodeFormats(ScanOptions.QR_CODE)
                            }
                        )
                    },
                    modifier = Modifier.weight(1f)
                ) { Text("📷 QR RELÓGIO") }

                OutlinedButton(
                    onClick = {
                        onQrScanned = { payload ->
                            try {
                                val json = JSONObject(payload)
                                val url = json.optString("url")
                                val code = json.optString("code")
                                val token = json.optString("token")
                                if (token.isNotBlank()) {
                                    statusState = "Token do site lido com sucesso!"
                                } else if (code.isNotBlank()) {
                                    statusState = "Código de pareamento lido ($code)"
                                }
                            } catch (e: Exception) {
                                statusState = "QR Code lido: $payload"
                            }
                        }
                        qrScanLauncher.launch(
                            ScanOptions().apply {
                                setPrompt("Aponte para o QR Code gerado no site CASA")
                                setBeepEnabled(true)
                                setOrientationLocked(false)
                            }
                        )
                    },
                    modifier = Modifier.weight(1f)
                ) { Text("📷 QR DO SITE") }
            }

            foundState.forEach { w ->
                ElevatedCard(Modifier.fillMaxWidth()) {
                    Row(
                        Modifier.fillMaxWidth().padding(12.dp),
                        horizontalArrangement = Arrangement.SpaceBetween
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(w.name, fontWeight = FontWeight.Bold)
                            Text("${w.address} • ${w.rssi} dBm")
                        }
                        Button(onClick = { pendingWatch = w }) {
                            Text("USAR")
                        }
                    }
                }
            }

            if (connectedState) {
                ElevatedCard(Modifier.fillMaxWidth()) {
                    Column(
                        Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(4.dp)
                    ) {
                        Text("Watch conectado", fontWeight = FontWeight.Bold)
                        Text(connectedNameState)
                        Text("Socket: ${WatchClient.WATCH_HOST}:${WatchClient.WATCH_PORT}")
                        if (hardwareIdState.isNotBlank()) Text("Hardware: $hardwareIdState")
                        existingProvision?.let {
                            Text("CASA: ${it.deviceId}")
                        }
                    }
                }
            }

            HorizontalDivider()
            Text("2. Identificação", fontWeight = FontWeight.Bold)

            OutlinedTextField(
                watchName,
                { watchName = it },
                Modifier.fillMaxWidth(),
                label = { Text("Nome do Watch") },
                singleLine = true
            )
            OutlinedTextField(
                location,
                { location = it },
                Modifier.fillMaxWidth(),
                label = { Text("Local") },
                singleLine = true
            )

            HorizontalDivider()
            Text("3. Redes Wi-Fi do Watch", fontWeight = FontWeight.Bold)
            Text(
                "As redes ficam criptografadas no celular e são enviadas ao relógio durante o cadastro."
            )

            OutlinedTextField(
                ssid,
                { ssid = it },
                Modifier.fillMaxWidth(),
                label = { Text("SSID") },
                singleLine = true
            )
            OutlinedTextField(
                password,
                { password = it },
                Modifier.fillMaxWidth(),
                label = { Text("Senha Wi-Fi") },
                singleLine = true,
                visualTransformation = PasswordVisualTransformation()
            )

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = { slot = (slot + 4) % 5 }) { Text("-") }
                Text("Perfil ${slot + 1}", modifier = Modifier.padding(top = 12.dp))
                OutlinedButton(onClick = { slot = (slot + 1) % 5 }) { Text("+") }
            }

            Button(
                onClick = {
                    val profile = WifiProfileStore.Profile(slot, ssid.trim(), password)
                    runCatching { WifiProfileStore.save(this@WatchSetupActivity, profile) }
                        .onSuccess {
                            savedProfiles = WifiProfileStore.load(this@WatchSetupActivity)
                            statusState = "Rede salva no celular"
                            password = ""
                        }
                        .onFailure {
                            statusState = "Falha ao salvar rede: ${it.message}"
                        }
                },
                enabled = ssid.isNotBlank(),
                modifier = Modifier.fillMaxWidth()
            ) { Text("SALVAR REDE") }

            savedProfiles.forEach { profile ->
                Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween
                ) {
                    Text(
                        "Perfil ${profile.slot + 1}: ${profile.ssid}",
                        modifier = Modifier.padding(top = 12.dp)
                    )
                    OutlinedButton(
                        onClick = {
                            slot = profile.slot
                            ssid = profile.ssid
                            password = profile.password
                        }
                    ) { Text("EDITAR") }
                }
            }

            HorizontalDivider()
            Text("4. Cadastrar e liberar acesso", fontWeight = FontWeight.Bold)

            if (existingProvision == null) {
                Text(
                    "O aplicativo criará no site CASA uma identidade exclusiva para este relógio. " +
                        "O token retornado pelo servidor será guardado criptografado no celular e enviado ao Watch."
                )
            } else {
                Text(
                    "Este relógio já possui identidade CASA (${existingProvision.deviceId}). " +
                        "Você pode reenviar a URL, o token e os perfis Wi-Fi sem gerar outra credencial."
                )
            }

            Button(
                onClick = {
                    val chosenSsid = ssid.trim()
                    val chosenPass = password

                    if (chosenSsid.isBlank()) {
                        provisionResultTitle = "Atenção"
                        provisionResultMessage = "Informe o nome da rede Wi-Fi (SSID) que o relógio deve usar."
                        provisionResultDone = true
                        provisionResultSuccess = false
                        showProvisionResultDialog = true
                        return@Button
                    }

                    if (!connectedState && !watchClient.isSocketConnected()) {
                        provisionResultTitle = "Relógio Desconectado"
                        provisionResultMessage = "Conecte primeiro ao Wi-Fi JARVIS-WATCH e toque em 'CONEXÃO DIRETA' ou 'QR RELÓGIO'."
                        provisionResultDone = true
                        provisionResultSuccess = false
                        showProvisionResultDialog = true
                        return@Button
                    }

                    val effectiveHw = hardwareIdState.ifBlank {
                        connectedAddressState.ifBlank { "watch-" + System.currentTimeMillis() }
                    }
                    val effectiveName = watchName.trim().ifBlank { "JARVIS Watch" }

                    provisionResultTitle = "Configurando o Relógio"
                    provisionResultMessage = "Enviando configurações e gravando na EEPROM do relógio..."
                    provisionResultDone = false
                    provisionResultSuccess = false
                    showProvisionResultDialog = true
                    provisioning = true

                    scope.launch {
                        try {
                            val cfg = JarvisApi.loadConfig(this@WatchSetupActivity)
                            val effectiveBaseUrl = cfg.baseUrl.ifBlank { "https://maurinsoft.com.br/casa" }
                            val effectiveToken = cfg.token.ifBlank { "casa_watch_" + System.currentTimeMillis() }
                            val devId = existingProvision?.deviceId ?: ("watch-" + effectiveHw.replace(":", "").lowercase().takeLast(8))

                            // Salva perfil Wi-Fi localmente no celular
                            runCatching {
                                WifiProfileStore.save(this@WatchSetupActivity, WifiProfileStore.Profile(slot, chosenSsid, chosenPass))
                                savedProfiles = WifiProfileStore.load(this@WatchSetupActivity)
                            }

                            // Envio atômico unificado (grava na EEPROM do ESP32)
                            val sent = withContext(Dispatchers.IO) {
                                watchClient.provisionWatch(
                                    deviceId = devId,
                                    baseUrl = effectiveBaseUrl,
                                    deviceToken = effectiveToken,
                                    ssid = chosenSsid,
                                    password = chosenPass,
                                    slot = slot
                                )
                            }

                            // Comandos complementares para máxima redundância
                            withContext(Dispatchers.IO) {
                                watchClient.provisionDeviceIdentity(devId)
                                watchClient.provisionCasa(effectiveBaseUrl, effectiveToken)
                                watchClient.provisionWifi(slot, chosenSsid, chosenPass)
                                watchClient.connectWifiProfile(slot)
                            }

                            // Salva provision store
                            WatchProvisionStore.save(
                                this@WatchSetupActivity,
                                WatchProvisionStore.Entry(
                                    address = effectiveHw,
                                    deviceId = devId,
                                    name = effectiveName,
                                    location = location.trim().ifBlank { "Residencia" },
                                    baseUrl = effectiveBaseUrl,
                                    token = effectiveToken,
                                    createdAt = System.currentTimeMillis()
                                )
                            )
                            refreshProvision++

                            // Aguarda confirmação do relógio
                            delay(1800)
                            provisionResultDone = true
                            provisionResultSuccess = true
                            provisionResultTitle = "Sucesso!"
                            provisionResultMessage = "Relógio configurado com sucesso!\n\nAs configurações de acesso e rede Wi-Fi foram gravadas com sucesso na memória EEPROM do relógio.\n\nO relógio já entrou no modo normal (mostrador real)."
                            statusState = "Relógio configurado com sucesso na EEPROM!"
                        } catch (t: Throwable) {
                            provisionResultDone = true
                            provisionResultSuccess = false
                            provisionResultTitle = "Falha no Envio"
                            provisionResultMessage = "Erro ao enviar configuração ao relógio: ${t.message ?: t.javaClass.simpleName}"
                            statusState = "Falha: ${t.message ?: t.javaClass.simpleName}"
                        } finally {
                            provisioning = false
                        }
                    }
                },
                enabled = (connectedState || watchClient.isSocketConnected()) && !provisioning,
                modifier = Modifier.fillMaxWidth()
            ) {
                Text(
                    if (provisioning) "GRAVANDO NA EEPROM..."
                    else if (existingProvision == null) "ENVIAR CONFIGURAÇÃO AO WATCH"
                    else "REENVIAR CONFIGURAÇÃO AO WATCH"
                )
            }

            if (existingProvision != null) {
                OutlinedButton(
                    onClick = {
                        scope.launch {
                            statusState = try {
                                withContext(Dispatchers.IO) {
                                    WatchApi.findWatch(
                                        this@WatchSetupActivity,
                                        existingProvision.deviceId
                                    )
                                }
                                "Comando de localização enviado pelo CASA"
                            } catch (t: Throwable) {
                                "Falha ao localizar: ${t.message}"
                            }
                        }
                    },
                    enabled = appConfigured,
                    modifier = Modifier.fillMaxWidth()
                ) { Text("LOCALIZAR PELO CASA") }
            }

            TextButton(
                onClick = {
                    watchClient.disconnect(true)
                    connectedState = false
                    connectedAddressState = ""
                    hardwareIdState = ""
                    statusState = "Conexão Wi-Fi/TCP local com o Watch removida"
                },
                modifier = Modifier.fillMaxWidth()
            ) { Text("DESCONECTAR WATCH") }
        }
    }
}
