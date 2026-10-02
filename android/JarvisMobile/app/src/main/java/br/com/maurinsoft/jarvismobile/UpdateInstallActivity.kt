package br.com.maurinsoft.jarvismobile

import android.app.DownloadManager
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.ComponentActivity
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.security.MessageDigest

/** A instalação passa pelo instalador do Android e nunca desinstala o app anterior. */
class UpdateInstallActivity : ComponentActivity() {
    private lateinit var message: TextView
    private lateinit var button: Button

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        message = TextView(this).apply { text = "Atualização Casa Mobile"; setPadding(24, 24, 24, 24) }
        button = Button(this).apply { text = "Validar e instalar"; setOnClickListener { install() } }
        setContentView(LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; addView(message); addView(button) })
        install()
    }

    @Suppress("DEPRECATION")
    private fun install() {
        if (!packageManager.canRequestPackageInstalls()) {
            message.text = "Autorize o Casa Mobile a instalar atualizações nesta tela do Android. Depois retorne e toque em Validar e instalar."
            startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:$packageName")))
            return
        }
        button.isEnabled = false
        lifecycleScope.launch {
            val result = withContext(Dispatchers.IO) { runCatching {
                val prefs = getSharedPreferences(UpdateManager.PREFS, MODE_PRIVATE)
                val id = prefs.getLong("download_id", -1)
                require(id > 0) { "Nenhum APK aguardando instalação." }
                val manager = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
                val ready = manager.query(DownloadManager.Query().setFilterById(id))?.use { c ->
                    c.moveToFirst() && c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS)) == DownloadManager.STATUS_SUCCESSFUL
                } ?: false
                require(ready) { "Download ainda não concluído." }
                val uri = manager.getUriForDownloadedFile(id) ?: error("APK indisponível.")
                val temp = File.createTempFile("update_verify", ".apk", cacheDir)
                try {
                    val digest = MessageDigest.getInstance("SHA-256")
                    contentResolver.openInputStream(uri)?.use { input ->
                        temp.outputStream().use { output ->
                            val buffer = ByteArray(8192)
                            var total = 0L
                            while (true) {
                                val n = input.read(buffer); if (n < 0) break
                                total += n; require(total <= 200L * 1024 * 1024) { "APK excede o limite de tamanho." }
                                digest.update(buffer, 0, n); output.write(buffer, 0, n)
                            }
                        }
                    } ?: error("Não foi possível ler o APK.")
                    val hash = digest.digest().joinToString("") { "%02x".format(it) }
                    require(hash == prefs.getString("sha256", "")) { "Hash do APK não confere. Instalação bloqueada." }
                    val flags = if (Build.VERSION.SDK_INT >= 28) PackageManager.GET_SIGNING_CERTIFICATES else PackageManager.GET_SIGNATURES
                    val candidate = packageManager.getPackageArchiveInfo(temp.path, flags) ?: error("APK inválido.")
                    val current = packageManager.getPackageInfo(packageName, flags)
                    require(candidate.packageName == packageName) { "APK pertence a outro aplicativo." }
                    val code = if (Build.VERSION.SDK_INT >= 28) candidate.longVersionCode else candidate.versionCode.toLong()
                    require(code > BuildConfig.VERSION_CODE && code == prefs.getLong("pending_code", 0)) { "Versão do APK não corresponde à autorizada." }
                    val expected = if (Build.VERSION.SDK_INT >= 28) current.signingInfo?.apkContentsSigners else current.signatures
                    val actual = if (Build.VERSION.SDK_INT >= 28) candidate.signingInfo?.apkContentsSigners else candidate.signatures
                    require(!expected.isNullOrEmpty() && !actual.isNullOrEmpty() && expected.toSet() == actual.toSet()) {
                        "Assinatura diferente da instalação atual. Não desinstale o app; publique um APK com a mesma chave."
                    }
                    uri
                } finally { temp.delete() }
            } }
            result.onSuccess { uri ->
                runCatching {
                    startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(uri, "application/vnd.android.package-archive")
                        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION))
                    message.text = "Instalador aberto. Confirme a atualização no Android."
                }.onFailure { message.text = "Não foi possível abrir o instalador: ${it.message}" }
            }.onFailure { message.text = it.message ?: "Falha ao validar atualização." }
            UpdateManager.setStatus(this@UpdateInstallActivity, message.text.toString())
            button.isEnabled = true
        }
    }
}
