package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.Intent
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

class WatchSetupActivity : ComponentActivity(), WatchClient.Listener {
    private lateinit var watchClient: WatchClient
    private var foundState by mutableStateOf<List<WatchClient.FoundWatch>>(emptyList())
    private var statusState by mutableStateOf("Pronto para procurar o relógio")
    private var connectedState by mutableStateOf(false)

    private val permissionLauncher = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        watchClient = WatchClient(this)
        watchClient.addListener(this)
        requestPermissions()
        connectedState = WatchClient.isConnected(this)
        setContent { MaterialTheme { Screen() } }
    }

    override fun onDestroy() {
        watchClient.removeListener(this)
        super.onDestroy()
    }

    private fun requestPermissions() {
        val p = mutableListOf(Manifest.permission.ACCESS_FINE_LOCATION)
        if (Build.VERSION.SDK_INT >= 31) {
            p += Manifest.permission.BLUETOOTH_SCAN
            p += Manifest.permission.BLUETOOTH_CONNECT
        }
        permissionLauncher.launch(p.distinct().toTypedArray())
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
            statusState = if (connected) "$name conectado" else "Relógio desconectado"
        }
    }

    override fun onWatchMessage(json: org.json.JSONObject) {
        runOnUiThread { statusState = "Watch: ${json.optString("type", "mensagem")}" }
    }

    override fun onError(message: String) { runOnUiThread { statusState = message } }

    private fun currentSsid(): String {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED && Build.VERSION.SDK_INT < 31) return ""
        val wm = applicationContext.getSystemService(WIFI_SERVICE) as WifiManager
        @Suppress("DEPRECATION")
        return wm.connectionInfo?.ssid?.trim('"')?.takeUnless { it == "<unknown ssid>" }.orEmpty()
    }

    @Composable
    private fun Screen() {
        var ssid by remember { mutableStateOf(currentSsid()) }
        var password by remember { mutableStateOf("") }
        var slot by remember { mutableIntStateOf(0) }
        var watchToken by remember { mutableStateOf("") }
        var savedProfiles by remember { mutableStateOf(WifiProfileStore.load(this)) }
        var pendingWatch by remember { mutableStateOf<WatchClient.FoundWatch?>(null) }
        val casa = remember { JarvisApi.loadConfig(this).baseUrl }

        pendingWatch?.let { candidate ->
            AlertDialog(
                onDismissRequest = { pendingWatch = null },
                title = { Text("Adicionar ao JARVIS?") },
                text = {
                    Text(
                        "Autorizar ${candidate.name} (${candidate.address}) a fazer parte do JARVIS? " +
                            "O relógio poderá trocar comandos, notificações e configurações com este celular."
                    )
                },
                confirmButton = {
                    Button(onClick = {
                        pendingWatch = null
                        statusState = "Conectando ${candidate.name}..."
                        watchClient.connect(candidate.address)
                    }) { Text("AUTORIZAR") }
                },
                dismissButton = {
                    TextButton(onClick = { pendingWatch = null }) { Text("CANCELAR") }
                }
            )
        }

        Column(
            Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text("JARVIS Watch", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
            Text(statusState)
            Button(
                onClick = { startActivity(Intent(this@WatchSetupActivity, NewDevicesActivity::class.java)) },
                modifier = Modifier.fillMaxWidth()
            ) { Text("NOVOS DEVICES / CONFIGURAR EQUIPAMENTOS") }

            ElevatedCard(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Bluetooth", fontWeight = FontWeight.Bold)
                    Text(if (connectedState) "CONECTADO" else "DESCONECTADO")
                    Text("Salvo: ${WatchClient.savedName(this@WatchSetupActivity)}")
                    Text("RSSI: ${WatchClient.lastRssi(this@WatchSetupActivity)} dBm")
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { foundState = emptyList(); statusState = "Procurando JARVIS Watch..."; watchClient.scan() }, Modifier.weight(1f)) { Text("PROCURAR") }
                OutlinedButton(onClick = { watchClient.connectSaved() }, Modifier.weight(1f)) { Text("RECONECTAR") }
            }
            foundState.forEach { w ->
                ElevatedCard(Modifier.fillMaxWidth()) {
                    Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column { Text(w.name, fontWeight = FontWeight.Bold); Text("${w.address} • ${w.rssi} dBm") }
                        Button(onClick = { pendingWatch = w }) { Text("ADICIONAR") }
                    }
                }
            }

            HorizontalDivider()
            Text("Wi‑Fi de contingência do relógio", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text("Cadastre aqui as redes de casa, trabalho e outros locais. O Android permite identificar o SSID atual, mas não entrega a senha salva a aplicativos; informe a senha uma vez e o JARVIS Mobile a guarda criptografada.")
            OutlinedTextField(ssid, { ssid = it }, Modifier.fillMaxWidth(), label = { Text("SSID") }, singleLine = true)
            OutlinedTextField(password, { password = it }, Modifier.fillMaxWidth(), label = { Text("Senha Wi‑Fi") }, singleLine = true, visualTransformation = PasswordVisualTransformation())
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = { slot = (slot + 4) % 5 }) { Text("-") }
                Text("Perfil ${slot + 1}", modifier = Modifier.padding(top = 12.dp))
                OutlinedButton(onClick = { slot = (slot + 1) % 5 }) { Text("+") }
            }
            Button(
                onClick = {
                    val profile = WifiProfileStore.Profile(slot, ssid.trim(), password)
                    WifiProfileStore.save(this@WatchSetupActivity, profile)
                    savedProfiles = WifiProfileStore.load(this@WatchSetupActivity)
                    statusState = if (watchClient.provisionWifi(slot, profile.ssid, profile.password)) {
                        "Rede salva no celular e enviada ao relógio"
                    } else {
                        "Rede salva no celular; conecte o relógio para reenviar"
                    }
                    password = ""
                },
                enabled = connectedState && ssid.isNotBlank(), modifier = Modifier.fillMaxWidth()
            ) { Text("SALVAR E ENVIAR REDE") }

            savedProfiles.forEach { profile ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Perfil ${profile.slot + 1}: ${profile.ssid}", modifier = Modifier.padding(top = 12.dp))
                    OutlinedButton(
                        onClick = {
                            slot = profile.slot
                            ssid = profile.ssid
                            password = profile.password
                        }
                    ) { Text("USAR") }
                }
            }

            HorizontalDivider()
            Text("Acesso CASA direto", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text("Use um token exclusivo do relógio com permissões mínimas; nunca use token mestre.")
            OutlinedTextField(watchToken, { watchToken = it }, Modifier.fillMaxWidth(), label = { Text("Token individual do Watch") }, singleLine = true)
            Button(
                onClick = {
                    statusState = if (watchClient.provisionCasa(casa, watchToken.trim())) "CASA configurada no Watch" else "Falha ao configurar CASA"
                    watchToken = ""
                }, enabled = connectedState && watchToken.isNotBlank(), modifier = Modifier.fillMaxWidth()
            ) { Text("PROVISIONAR FALLBACK CASA") }

            OutlinedButton(onClick = { watchClient.findWatch() }, enabled = connectedState, modifier = Modifier.fillMaxWidth()) { Text("LOCALIZAR / VIBRAR RELÓGIO") }
            TextButton(onClick = { watchClient.disconnect(true); connectedState = false; statusState = "Pareamento lógico removido" }, modifier = Modifier.fillMaxWidth()) { Text("ESQUECER RELÓGIO") }
        }
    }
}
