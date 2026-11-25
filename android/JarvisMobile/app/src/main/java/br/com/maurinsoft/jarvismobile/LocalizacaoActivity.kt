package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.Intent
import android.net.Uri
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
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.Locale

/** Compartilhamento opcional da localizacao do celular e posicao da familia. */
class LocalizacaoActivity : ComponentActivity() {
    private var aposPermissao: ((Boolean) -> Unit)? = null

    private val pedirPermissoes = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { r ->
        val ok = r[Manifest.permission.ACCESS_FINE_LOCATION] == true || r[Manifest.permission.ACCESS_COARSE_LOCATION] == true
        aposPermissao?.invoke(ok || LocalizacaoPrefs.temPermissao(this))
        aposPermissao = null
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (MobileAuth.savedSession(this) == null) {
            startActivity(Intent(this, MainActivity::class.java)); finish(); return
        }
        setContent { MaterialTheme { Tela() } }
    }

    private fun solicitarPermissao(callback: (Boolean) -> Unit) {
        if (LocalizacaoPrefs.temPermissao(this)) { callback(true); return }
        aposPermissao = callback
        val lista = mutableListOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)
        if (Build.VERSION.SDK_INT >= 33) lista += Manifest.permission.POST_NOTIFICATIONS
        pedirPermissoes.launch(lista.toTypedArray())
    }

    @OptIn(ExperimentalMaterial3Api::class)
    @Composable
    private fun Tela() {
        val scope = rememberCoroutineScope()
        var ativo by remember { mutableStateOf(LocalizacaoPrefs.ativo(this@LocalizacaoActivity)) }
        var intervalo by remember { mutableStateOf(LocalizacaoPrefs.intervaloMin(this@LocalizacaoActivity)) }
        var status by remember { mutableStateOf(LocalizacaoPrefs.status(this@LocalizacaoActivity)) }
        var familia by remember { mutableStateOf<List<LocalizacaoApi.Posicao>>(emptyList()) }
        var erro by remember { mutableStateOf<String?>(null) }
        var confirmarApagar by remember { mutableStateOf(false) }
        var aviso by remember { mutableStateOf<String?>(null) }

        suspend fun atualizarFamilia() {
            val r = withContext(Dispatchers.IO) { runCatching { LocalizacaoApi.ultimas(this@LocalizacaoActivity) } }
            r.onSuccess { familia = it; erro = null }.onFailure { erro = it.message }
            status = LocalizacaoPrefs.status(this@LocalizacaoActivity)
        }
        LaunchedEffect(Unit) { while (true) { atualizarFamilia(); delay(30_000) } }

        fun ligar() {
            solicitarPermissao { ok ->
                if (!ok) { aviso = "Sem permissão de localização o compartilhamento não pode ser ligado."; return@solicitarPermissao }
                LocalizacaoPrefs.definirAtivo(this@LocalizacaoActivity, true)
                LocalizacaoService.iniciar(this@LocalizacaoActivity)
                ativo = true; aviso = null
            }
        }
        fun desligar() {
            LocalizacaoPrefs.definirAtivo(this@LocalizacaoActivity, false)
            LocalizacaoService.parar(this@LocalizacaoActivity)
            LocalizacaoPrefs.registrarStatus(this@LocalizacaoActivity, "Compartilhamento desligado")
            ativo = false; status = LocalizacaoPrefs.status(this@LocalizacaoActivity)
        }

        LcarsFrame(
            title = "Localização",
            subtitle = "Compartilhamento com a CASA e posição da família",
            user = "", canBack = true, onBack = { finish() }, onHome = { finish() }, onLogout = {}
        ) {
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                LcarsSectionLabel("ESTE CELULAR", LcarsColors.Blue)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Compartilhar minha localização", fontWeight = FontWeight.Bold)
                        Text("A posição fica visível para os aparelhos da casa e é apagada após 30 dias.",
                            style = MaterialTheme.typography.bodySmall)
                    }
                    Switch(checked = ativo, onCheckedChange = { if (it) ligar() else desligar() })
                }
                Text("Enviar a cada:", style = MaterialTheme.typography.bodySmall)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    LocalizacaoPrefs.INTERVALOS_MIN.forEach { min ->
                        FilterChip(selected = intervalo == min, label = { Text("$min min") }, onClick = {
                            intervalo = min
                            LocalizacaoPrefs.definirIntervalo(this@LocalizacaoActivity, min)
                            if (ativo) LocalizacaoService.iniciar(this@LocalizacaoActivity)
                        })
                    }
                }
                if (status.isNotBlank()) Text(status, style = MaterialTheme.typography.bodySmall)
                aviso?.let { Text(it, color = LcarsColors.Salmon) }
                OutlinedButton(onClick = { confirmarApagar = true }, modifier = Modifier.fillMaxWidth()) {
                    Text("APAGAR MEU HISTÓRICO NA CASA")
                }

                LcarsSectionLabel("FAMÍLIA", LcarsColors.Lavender)
                erro?.let { Text("Erro: $it", color = LcarsColors.Salmon) }
                if (familia.isEmpty() && erro == null) Text("Nenhum aparelho compartilhou a localização nos últimos 7 dias.")
                familia.forEach { pos -> CartaoPosicao(pos) }
            }
        }

        if (confirmarApagar) AlertDialog(
            onDismissRequest = { confirmarApagar = false },
            title = { Text("Apagar histórico?") },
            text = { Text("Todas as posições deste celular guardadas na CASA serão removidas. Não é possível desfazer.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmarApagar = false
                    scope.launch {
                        val r = withContext(Dispatchers.IO) { runCatching { LocalizacaoApi.apagarMeuHistorico(this@LocalizacaoActivity) } }
                        aviso = r.fold({ "Histórico apagado ($it posições)." }, { "Falha ao apagar: ${it.message}" })
                        atualizarFamilia()
                    }
                }) { Text("APAGAR") }
            },
            dismissButton = { TextButton(onClick = { confirmarApagar = false }) { Text("CANCELAR") } }
        )
    }

    @Composable
    private fun CartaoPosicao(pos: LocalizacaoApi.Posicao) {
        ElevatedCard(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(pos.nome + if (pos.proprio) " (este celular)" else "", fontWeight = FontWeight.Black)
                val extras = listOfNotNull(
                    "há " + tempo(pos.segundos),
                    pos.precisaoM?.let { "precisão ${it.toInt()} m" },
                    pos.bateria?.let { "bateria $it%" }
                ).joinToString(" • ")
                Text(extras, style = MaterialTheme.typography.bodySmall)
                Text(String.format(Locale.US, "%.5f, %.5f", pos.latitude, pos.longitude), style = MaterialTheme.typography.bodySmall)
                TextButton(onClick = {
                    val geo = String.format(Locale.US, "geo:%.6f,%.6f?q=%.6f,%.6f", pos.latitude, pos.longitude, pos.latitude, pos.longitude)
                    runCatching { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(geo))) }
                }) { Text("ABRIR NO MAPA") }
            }
        }
    }

    private fun tempo(s: Long): String = when {
        s < 60 -> "${s}s"
        s < 3600 -> "${s / 60} min"
        s < 86400 -> "${s / 3600} h"
        else -> "${s / 86400} dias"
    }
}
