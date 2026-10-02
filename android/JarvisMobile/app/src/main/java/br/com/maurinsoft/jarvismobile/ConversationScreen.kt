package br.com.maurinsoft.jarvismobile

import android.content.Context
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject

private data class ChatEntry(val role: String, val text: String)

@Composable
fun ConversationScreen(online: Boolean, speech: SpeechInputController, audio: JarvisAudioPlayer, requestMicrophone: () -> Unit) {
    val context = LocalContext.current
    val prefs = remember { context.getSharedPreferences("mobile_chat", Context.MODE_PRIVATE) }
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    var entries by remember {
        mutableStateOf(runCatching {
            val arr = JSONArray(prefs.getString("entries", "[]"))
            (0 until arr.length()).map { arr.getJSONObject(it).let { item -> ChatEntry(item.getString("role"), item.getString("text")) } }
        }.getOrDefault(emptyList()))
    }
    var text by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var listening by remember { mutableStateOf(false) }
    var status by remember { mutableStateOf("") }
    var pending by remember { mutableIntStateOf(JarvisApi.pendingCount(context)) }
    var confirmPending by remember { mutableStateOf(false) }
    fun add(role: String, value: String) {
        entries = (entries + ChatEntry(role, value)).takeLast(200)
        val arr = JSONArray()
        entries.forEach { arr.put(JSONObject().put("role", it.role).put("text", it.text)) }
        prefs.edit().putString("entries", arr.toString()).apply()
    }
    fun send(value: String) {
        if (value.isBlank() || busy) return
        busy = true
        add("Você", value)
        scope.launch {
            try {
                val result = withContext(Dispatchers.IO) { JarvisCommandController(context).send(value) }
                add("JARVIS", result.text ?: result.message)
                if (result.delivered) audio.play(result.audioUrl)
                pending = JarvisApi.pendingCount(context)
            } finally { busy = false }
        }
    }
    LaunchedEffect(entries.size) { if (entries.isNotEmpty()) listState.animateScrollToItem(entries.lastIndex) }
    DisposableEffect(Unit) { onDispose { speech.cancel(); audio.stop() } }
    if (confirmPending) AlertDialog(
        onDismissRequest = { confirmPending = false }, title = { Text("Reenviar solicitações?") },
        text = { Text("Um envio anterior pode ter sido executado mesmo sem resposta. Revise a lista abaixo antes de reenviar. Solicitações com mais de cinco minutos serão descartadas.\n\n" + JarvisApi.pendingTexts(context).joinToString("\n")) },
        confirmButton = { TextButton(onClick = {
            confirmPending = false; busy = true
            scope.launch {
                try {
                    val count = withContext(Dispatchers.IO) { JarvisApi.flushPending(context) }
                    status = "$count solicitação(ões) enviada(s) ao servidor; isso não confirma execução física."
                    pending = JarvisApi.pendingCount(context)
                } finally { busy = false }
            }
        }) { Text("Reenviar") } },
        dismissButton = { TextButton(onClick = { confirmPending = false }) { Text("Cancelar") } }
    )
    Column(Modifier.fillMaxSize().imePadding(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(if (online) "Conectado à CASA" else "Sem conexão • vínculo preservado")
        if (pending > 0) {
            Text("$pending solicitação(ões) aguardando revisão")
            Row {
                TextButton(onClick = { confirmPending = true }, enabled = online && !busy) { Text("Revisar") }
                TextButton(onClick = { JarvisApi.clearPending(context); pending = 0 }, enabled = !busy) { Text("Descartar fila") }
            }
        }
        LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = listState, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (entries.isEmpty()) item { Text("Como posso ajudar?") }
            items(entries) { entry ->
                ElevatedCard(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp)) { Text(entry.role, style = MaterialTheme.typography.labelLarge); Text(entry.text) }
                }
            }
        }
        if (status.isNotBlank()) Text(status)
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        OutlinedTextField(text, { text = it }, Modifier.fillMaxWidth(), label = { Text("Mensagem para o JARVIS") }, maxLines = 4, enabled = !busy)
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            TextButton(onClick = {
                if (context.checkSelfPermission(android.Manifest.permission.RECORD_AUDIO) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
                    requestMicrophone(); status = "Autorize o microfone e toque em Falar novamente."
                } else {
                    audio.stop(); listening = true; status = "Ouvindo…"
                    speech.listen { heard ->
                        listening = false
                        if (heard.isBlank()) status = speech.lastError ?: "Nenhuma fala reconhecida."
                        else { status = ""; send(heard) }
                    }
                }
            }, enabled = !busy && !listening) { Text(if (listening) "Ouvindo…" else "Falar") }
            TextButton(onClick = { speech.cancel(); listening = false; audio.stop(); status = "Interrompido" }) { Text("Interromper") }
            Button(onClick = { val value = text.trim(); text = ""; send(value) }, enabled = text.isNotBlank() && !busy && !listening) { Text("Enviar") }
        }
    }
}
