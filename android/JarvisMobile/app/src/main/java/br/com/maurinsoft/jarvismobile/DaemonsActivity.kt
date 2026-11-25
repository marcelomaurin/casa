package br.com.maurinsoft.jarvismobile

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Acompanhamento em tempo real dos nos do cluster e controle dos daemons. */
class DaemonsActivity : ComponentActivity() {
    private data class Pedido(val no: DaemonsApi.No, val servico: DaemonsApi.Servico, val operacao: String)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (MobileAuth.savedSession(this) == null) {
            startActivity(Intent(this, MainActivity::class.java)); finish(); return
        }
        setContent { MaterialTheme { Tela() } }
    }

    @Composable
    private fun Tela() {
        val scope = rememberCoroutineScope()
        var painel by remember { mutableStateOf<DaemonsApi.Painel?>(null) }
        var erro by remember { mutableStateOf<String?>(null) }
        var aviso by remember { mutableStateOf<String?>(null) }
        var carregando by remember { mutableStateOf(false) }
        var pedido by remember { mutableStateOf<Pedido?>(null) }
        var executando by remember { mutableStateOf(false) }
        var atualizadoEm by remember { mutableStateOf("") }

        suspend fun atualizar() {
            carregando = true
            val r = withContext(Dispatchers.IO) { runCatching { DaemonsApi.listar(this@DaemonsActivity) } }
            r.onSuccess {
                painel = it; erro = null
                atualizadoEm = java.text.SimpleDateFormat("HH:mm:ss", java.util.Locale("pt", "BR")).format(java.util.Date())
            }.onFailure { erro = it.message ?: "Falha ao consultar a CASA" }
            carregando = false
        }

        // Acompanhamento continuo enquanto a tela estiver aberta.
        LaunchedEffect(Unit) { while (true) { atualizar(); delay(15_000) } }

        LcarsFrame(
            title = "Daemons do cluster",
            subtitle = if (atualizadoEm.isBlank()) "Consultando a CASA..." else "Atualizado às $atualizadoEm • a cada 15 s",
            user = "", canBack = true, onBack = { finish() }, onHome = { finish() }, onLogout = {}
        ) {
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                val p = painel
                if (p != null) {
                    LcarsSectionLabel("RESUMO", LcarsColors.Orange)
                    Text("Nós online: ${p.nosOnline} de ${p.totalNos}")
                    Text("Serviços ativos: ${p.servicosAtivos} • parados: ${p.servicosParados}")
                    if (!p.podeControlar) Text(
                        "Somente acompanhamento: este celular não tem permissão para controlar daemons.",
                        style = MaterialTheme.typography.bodySmall, color = LcarsColors.Muted
                    )
                }
                erro?.let { Text("Erro: $it", color = LcarsColors.Salmon, fontWeight = FontWeight.Bold) }
                aviso?.let { ElevatedCard(Modifier.fillMaxWidth()) { Text(it, Modifier.padding(12.dp)) } }
                OutlinedButton(onClick = { scope.launch { atualizar() } }, enabled = !carregando, modifier = Modifier.fillMaxWidth()) {
                    Text(if (carregando) "ATUALIZANDO..." else "ATUALIZAR AGORA")
                }

                p?.nos?.forEach { no -> CartaoNo(no, p.podeControlar && !executando) { s, op -> pedido = Pedido(no, s, op) } }
                if (p != null && p.nos.isEmpty()) Text("Nenhum nó registrado no cluster.")
            }
        }

        pedido?.let { ped ->
            val verbo = when (ped.operacao) { "start" -> "Iniciar"; "stop" -> "Parar"; else -> "Reiniciar" }
            AlertDialog(
                onDismissRequest = { if (!executando) pedido = null },
                title = { Text("$verbo ${ped.servico.nome}?") },
                text = {
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Nó: ${ped.no.nome}")
                        if (ped.operacao != "start" && ped.servico.impactoAoParar.isNotBlank())
                            Text("Impacto: ${ped.servico.impactoAoParar}", style = MaterialTheme.typography.bodySmall)
                    }
                },
                confirmButton = {
                    TextButton(enabled = !executando, onClick = {
                        executando = true
                        scope.launch {
                            val r = withContext(Dispatchers.IO) {
                                runCatching { DaemonsApi.controlar(this@DaemonsActivity, ped.no.id, ped.servico.id, ped.operacao) }
                            }
                            aviso = r.fold({ it }, { "Falha: ${it.message}" })
                            executando = false; pedido = null
                            atualizar()
                        }
                    }) { Text(if (executando) "ENVIANDO..." else verbo.uppercase()) }
                },
                dismissButton = { TextButton(enabled = !executando, onClick = { pedido = null }) { Text("CANCELAR") } }
            )
        }
    }

    @Composable
    private fun CartaoNo(no: DaemonsApi.No, podeControlar: Boolean, onAcao: (DaemonsApi.Servico, String) -> Unit) {
        val cor = if (no.online) LcarsColors.Green else LcarsColors.Salmon
        ElevatedCard(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(12.dp).background(cor, RoundedCornerShape(6.dp)))
                    Spacer(Modifier.width(8.dp))
                    Text(no.nome, fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
                    Text(if (no.online) "ONLINE" else no.status.uppercase(), color = cor, fontWeight = FontWeight.Bold)
                }
                val detalhes = listOf(no.ip, no.papel, no.versao).filter { it.isNotBlank() }.joinToString(" • ")
                if (detalhes.isNotBlank()) Text(detalhes, style = MaterialTheme.typography.bodySmall)
                Text("CPU ${no.cpu.ifBlank { "—" }} • RAM ${no.ram.ifBlank { "—" }} • último sinal: ${tempo(no.segundosSemSinal)}",
                    style = MaterialTheme.typography.bodySmall)
                HorizontalDivider()
                if (no.servicos.isEmpty()) Text("Nenhum serviço atribuído.", style = MaterialTheme.typography.bodySmall)
                no.servicos.forEach { s -> LinhaServico(s, podeControlar && no.online) { op -> onAcao(s, op) } }
            }
        }
    }

    @Composable
    private fun LinhaServico(s: DaemonsApi.Servico, podeControlar: Boolean, onAcao: (String) -> Unit) {
        Column(Modifier.fillMaxWidth()) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(if (s.ativo) "●" else "○", color = if (s.ativo) LcarsColors.Green else LcarsColors.Muted)
                Spacer(Modifier.width(6.dp))
                Text(s.nome, modifier = Modifier.weight(1f), fontWeight = FontWeight.Bold)
                Text(if (s.ativo) "ativo" else "parado", style = MaterialTheme.typography.labelSmall,
                    color = if (s.ativo) LcarsColors.Green else LcarsColors.Muted)
            }
            if (s.protegido) Text("Protegido: não pode ser parado", style = MaterialTheme.typography.labelSmall, color = LcarsColors.Muted)
            if (podeControlar) Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                if (!s.ativo) BotaoAcao("Iniciar", LcarsColors.Green) { onAcao("start") }
                if (s.ativo) BotaoAcao("Reiniciar", LcarsColors.Gold) { onAcao("restart") }
                if (s.ativo && !s.protegido) BotaoAcao("Parar", LcarsColors.Salmon) { onAcao("stop") }
            }
        }
    }

    @Composable
    private fun BotaoAcao(texto: String, cor: Color, onClick: () -> Unit) {
        Button(onClick = onClick, colors = ButtonDefaults.buttonColors(containerColor = cor, contentColor = LcarsColors.Ink),
            contentPadding = PaddingValues(horizontal = 12.dp, vertical = 4.dp)) { Text(texto) }
    }

    private fun tempo(segundos: Long?): String = when {
        segundos == null -> "nunca"
        segundos < 60 -> "${segundos}s atrás"
        segundos < 3600 -> "${segundos / 60} min atrás"
        segundos < 86400 -> "${segundos / 3600} h atrás"
        else -> "${segundos / 86400} dias atrás"
    }
}
