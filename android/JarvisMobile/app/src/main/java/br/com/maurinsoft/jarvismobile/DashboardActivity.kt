package br.com.maurinsoft.jarvismobile

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class DashboardActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (MobileAuth.savedSession(this) == null) {
            startActivity(Intent(this, MainActivity::class.java))
            finish()
            return
        }
        setContent { MaterialTheme { DashboardScreen() } }
    }

    @Composable
    private fun DashboardScreen() {
        val scope = rememberCoroutineScope()
        val session = remember { MobileAuth.savedSession(this@DashboardActivity) }
        var devices by remember { mutableStateOf<List<ControlPlaneApi.DeviceInfo>>(emptyList()) }
        var health by remember { mutableStateOf(ControlPlaneApi.HealthInfo(false, false, false, "Carregando...")) }
        var loading by remember { mutableStateOf(false) }
        var streamConnected by remember { mutableStateOf(false) }
        var lastEvent by remember { mutableStateOf("Nenhum evento recebido") }

        fun refresh() {
            if (loading) return
            loading = true
            scope.launch {
                val pair = withContext(Dispatchers.IO) {
                    val h = ControlPlaneApi.readiness(this@DashboardActivity)
                    val d = runCatching { ControlPlaneApi.listDevices(this@DashboardActivity) }.getOrDefault(emptyList())
                    h to d
                }
                health = pair.first
                devices = pair.second
                loading = false
            }
        }

        DisposableEffect(Unit) {
            val stream = CasaEventStream(
                context = this@DashboardActivity,
                onEvent = { event, _, id ->
                    lastEvent = "$event${id?.let { " #$it" } ?: ""}"
                    refresh()
                },
                onState = { streamConnected = it }
            )
            stream.start()
            onDispose { stream.stop() }
        }

        LaunchedEffect(Unit) { refresh() }

        val onlineCount = devices.count { it.online }
        val offlineCount = devices.size - onlineCount

        LcarsFrame(
            title = "CASA / Dashboard",
            subtitle = if (health.ready) "Control Plane pronto" else "Control Plane indisponível ou incompleto",
            user = session?.name?.ifBlank { session.login } ?: "—",
            canBack = true,
            onBack = { finish() },
            onHome = { finish() },
            onLogout = {
                runCatching { MobileAuth.logout(this@DashboardActivity) }
                startActivity(Intent(this@DashboardActivity, MainActivity::class.java))
                finish()
            }
        ) {
            Column(
                Modifier.fillMaxSize().verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                LcarsSectionLabel("ESTADO GERAL", LcarsColors.Green)

                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatusCard("API", if (health.ready) "PRONTA" else "FALHA", Modifier.weight(1f))
                    StatusCard("SSE", if (streamConnected) "ONLINE" else "RECONECTANDO", Modifier.weight(1f))
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatusCard("ONLINE", onlineCount.toString(), Modifier.weight(1f))
                    StatusCard("OFFLINE", offlineCount.toString(), Modifier.weight(1f))
                }

                ElevatedCard(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Saúde do servidor", fontWeight = FontWeight.Bold)
                        Text("Banco: ${if (health.database) "OK" else "FALHA"}")
                        Text("Control Plane: ${if (health.controlPlane) "OK" else "FALHA"}")
                        Text("Último evento: $lastEvent")
                    }
                }

                LcarsSectionLabel("DEVICES", LcarsColors.Gold)
                if (devices.isEmpty()) {
                    ElevatedCard(Modifier.fillMaxWidth()) {
                        Text(if (loading) "Carregando dispositivos..." else "Nenhum dispositivo disponível no Registry.", Modifier.padding(16.dp))
                    }
                } else {
                    devices.forEach { d -> DeviceCard(d) }
                }

                Button(onClick = { refresh() }, modifier = Modifier.fillMaxWidth(), enabled = !loading) {
                    Text(if (loading) "ATUALIZANDO..." else "ATUALIZAR AGORA")
                }
            }
        }
    }

    @Composable
    private fun StatusCard(title: String, value: String, modifier: Modifier = Modifier) {
        ElevatedCard(modifier) {
            Column(Modifier.padding(14.dp)) {
                Text(title, style = MaterialTheme.typography.labelMedium)
                Text(value, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
            }
        }
    }

    @Composable
    private fun DeviceCard(d: ControlPlaneApi.DeviceInfo) {
        ElevatedCard(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text((if (d.online) "● " else "○ ") + d.name, fontWeight = FontWeight.Bold)
                Text("${d.type} • ${d.deviceId}")
                Text("Estado: ${if (d.online) "online" else "offline"} • saúde: ${d.health}")
                if (d.location.isNotBlank()) Text("Local: ${d.location}")
                if (d.transport.isNotBlank()) Text("Transporte: ${d.transport}")
                d.battery?.let { Text("Bateria: $it%") }
                d.rssi?.let { Text("RSSI: $it dBm") }
                if (d.firmwareVersion.isNotBlank()) Text("Firmware: ${d.firmwareVersion}")
                if (d.capabilities.isNotEmpty()) Text("Recursos: ${d.capabilities.joinToString(", ")}")
                if (d.lastHeartbeat.isNotBlank()) Text("Heartbeat: ${d.lastHeartbeat}")
            }
        }
    }
}
