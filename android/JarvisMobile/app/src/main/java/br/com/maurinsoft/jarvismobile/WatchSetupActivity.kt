package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.pm.PackageManager
import android.net.wifi.WifiManager
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
                    statusState = if (ok) "Watch iniciou conexão Wi-Fi: $message" else "Falha ao iniciar Wi-Fi: $message"
                "status" -> {
                    val wifi = json.optBoolean("wifi", false)
                    val casa = json.optBoolean("casa_configured", false)
                    val ssid = json.optString("ssid")
                    val deviceId = json.optString("device_id")
                    val hardwareId = json.optString("hardware_id").trim()
                    if (hardwareId.isNotBlank()) {
                        hardwareIdState = hardwareId
                        connectedAddressState = hardwareId
                    }
                    statusState = buildString {
                        append("Watch confirmado")
                        append(if (wifi) " • Wi-Fi OK" else " • Wi-Fi offline")
                        if (ssid.isNotBlank()) append(" ($ssid)")
                        append(if (casa) " • CASA configurada" else " • CASA não configurada")
                        if (deviceId.isNotBlank()) append(" • $deviceId")
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

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    onClick = { ensureWifiPermissionAndConnect() },
                    modifier = Modifier.weight(1f)
                ) { Text("CONECTAR") }

                OutlinedButton(
                    onClick = { watchClient.connectSaved() },
                    modifier = Modifier.weight(1f)
                ) { Text("RECONECTAR") }
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
                    if (!appConfigured) {
                        statusState = "Configure primeiro o acesso do celular ao CASA"
                        return@Button
                    }
                    if (!connectedState) {
                        statusState = "Conecte um Watch antes de cadastrar"
                        return@Button
                    }
                    if (hardwareIdState.isBlank()) {
                        statusState = "Aguardando identificação do Watch pelo socket"
                        watchClient.requestStatus()
                        return@Button
                    }
                    if (watchName.isBlank()) {
                        statusState = "Informe o nome do Watch"
                        return@Button
                    }

                    provisioning = true
                    statusState = if (existingProvision == null)
                        "Criando identidade e token do Watch no CASA..."
                    else
                        "Reenviando credenciais do Watch..."

                    scope.launch {
                        try {
                            val entry = if (existingProvision != null) {
                                existingProvision
                            } else {
                                val created = withContext(Dispatchers.IO) {
                                    DeviceProvisionApi.createWatch(
                                        this@WatchSetupActivity,
                                        watchName.trim(),
                                        location.trim().ifBlank { "Residencia" },
                                        hardwareIdState
                                    )
                                }
                                val cfg = JarvisApi.loadConfig(this@WatchSetupActivity)
                                WatchProvisionStore.Entry(
                                    address = hardwareIdState,
                                    deviceId = created.deviceId,
                                    name = created.name,
                                    location = created.location,
                                    baseUrl = cfg.baseUrl,
                                    token = created.token,
                                    createdAt = System.currentTimeMillis()
                                ).also {
                                    WatchProvisionStore.save(this@WatchSetupActivity, it)
                                    refreshProvision++
                                }
                            }

                            val identityQueued = watchClient.provisionDeviceIdentity(
                                entry.deviceId
                            )
                            val casaQueued = watchClient.provisionCasa(
                                entry.baseUrl,
                                entry.token
                            )
                            if (!identityQueued || !casaQueued) {
                                statusState =
                                    "Credencial criada e guardada, mas o Watch não aceitou todo o envio. Reconecte e use REENVIAR."
                                provisioning = false
                                return@launch
                            }

                            val profiles = WifiProfileStore.load(this@WatchSetupActivity)
                                .sortedBy { it.slot }

                            var wifiQueued = 0
                            profiles.forEach { profile ->
                                if (watchClient.provisionWifi(
                                        profile.slot,
                                        profile.ssid,
                                        profile.password
                                    )
                                ) wifiQueued++
                            }

                            val phoneSsid = currentSsid()
                            val preferred = profiles.firstOrNull {
                                it.ssid.equals(phoneSsid, ignoreCase = true)
                            } ?: profiles.firstOrNull()

                            val connectQueued = preferred?.let {
                                watchClient.connectWifiProfile(it.slot)
                            } ?: false

                            watchClient.requestStatus()

                            statusState =
                                "Provisionamento enviado para ${entry.deviceId}: " +
                                "$wifiQueued perfil(is) Wi-Fi" +
                                (preferred?.let { " • conexão solicitada em ${it.ssid}" } ?: "") +
                                ". Aguardando confirmação do Watch."

                            // A associacao Wi-Fi do ESP32 e assincrona. Uma segunda
                            // leitura alguns segundos depois confirma o resultado real.
                            if (connectQueued) {
                                delay(5_000)
                                watchClient.requestStatus()
                            }
                        } catch (t: Throwable) {
                            statusState =
                                "Falha no cadastro do Watch: ${t.message ?: t.javaClass.simpleName}"
                        } finally {
                            provisioning = false
                        }
                    }
                },
                enabled = connectedState && appConfigured && !provisioning,
                modifier = Modifier.fillMaxWidth()
            ) {
                Text(
                    if (provisioning) "CONFIGURANDO..."
                    else if (existingProvision == null) "CADASTRAR WATCH NO CASA"
                    else "REENVIAR ACESSO AO WATCH"
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
