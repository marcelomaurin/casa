package br.com.maurinsoft.jarvismobile

import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.platform.LocalContext

@Composable
fun InstallationSettings(onForgotten: () -> Unit) {
    val context = LocalContext.current
    var confirm by remember { mutableStateOf(false) }
    var updateStatus by remember { mutableStateOf(UpdateManager.status(context)) }
    Text("Atualizações • versão ${BuildConfig.VERSION_NAME}")
    Text(updateStatus)
    OutlinedButton(onClick = { UpdateManager.checkAndDownloadAsync(context, true); updateStatus = "Consulta solicitada ao GitHub." }) { Text("Buscar atualização") }
    OutlinedButton(onClick = { UpdateManager.openPendingInstaller(context) }) { Text("Instalar atualização baixada") }
    if (MobileAuth.hasInstallationLink(context)) {
        Text("Celular vinculado. Não é necessário ler o QR Code novamente.")
        OutlinedButton(onClick = { confirm = true }) { Text("Desvincular este celular") }
    }
    if (confirm) AlertDialog(onDismissRequest = { confirm = false },
        title = { Text("Desvincular da CASA?") },
        text = { Text("Remove a credencial local e os comandos pendentes. Um novo QR Code será necessário. O cadastro no servidor não será excluído.") },
        confirmButton = { TextButton(onClick = { MobileAuth.forgetInstallation(context); confirm = false; onForgotten() }) { Text("Desvincular") } },
        dismissButton = { TextButton(onClick = { confirm = false }) { Text("Cancelar") } })
}
