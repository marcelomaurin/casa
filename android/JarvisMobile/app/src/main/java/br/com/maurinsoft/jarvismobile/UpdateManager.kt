package br.com.maurinsoft.jarvismobile

import android.app.DownloadManager
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.core.app.NotificationCompat
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.util.concurrent.TimeUnit

object UpdateManager {
    // A versão indicada no manifesto é a única autorizada; não seguimos a última release.
    private const val MANIFEST_URL = "https://raw.githubusercontent.com/marcelomaurin/casa/master/bin/versions.json"
    const val PREFS = "jarvis_updates"
    private const val CHANNEL = "jarvis_updates"
    private val client = OkHttpClient.Builder().connectTimeout(8, TimeUnit.SECONDS).readTimeout(15, TimeUnit.SECONDS).build()
    private var checking = false
    private var lastCheck = 0L

    data class Release(val version: String, val versionCode: Long, val apkUrl: String, val sha256: String)

    fun status(context: Context): String = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        .getString("status", "Nenhuma atualização consultada.").orEmpty()

    fun setStatus(context: Context, message: String) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString("status", message).apply()
    }

    @Synchronized
    fun checkAndDownloadAsync(context: Context, force: Boolean = false) {
        if (checking || (!force && System.currentTimeMillis() - lastCheck < TimeUnit.HOURS.toMillis(1))) return
        checking = true
        lastCheck = System.currentTimeMillis()
        val app = context.applicationContext
        Thread {
            try {
                val release = indicatedRelease() ?: run { setStatus(app, "Nenhuma versão autorizada para este pacote."); return@Thread }
                if (release.versionCode <= BuildConfig.VERSION_CODE) {
                    setStatus(app, "Aplicativo atualizado para a versão autorizada.")
                    val prefs = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                    if (prefs.getLong("pending_code", 0) <= BuildConfig.VERSION_CODE) {
                        val oldId = prefs.getLong("download_id", -1)
                        if (oldId > 0) (app.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).remove(oldId)
                        prefs.edit().remove("download_id").remove("pending_version").remove("pending_code").remove("sha256").apply()
                    }
                    return@Thread
                }
                val prefs = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                val manager = app.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
                val oldId = prefs.getLong("download_id", -1)
                if (oldId > 0 && prefs.getLong("pending_code", 0) == release.versionCode && prefs.getString("sha256", "") == release.sha256) {
                    manager.query(DownloadManager.Query().setFilterById(oldId))?.use { cursor ->
                        if (cursor.moveToFirst() && cursor.getInt(cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS)) != DownloadManager.STATUS_FAILED) return@Thread
                    }
                }
                if (oldId > 0) manager.remove(oldId)
                val request = DownloadManager.Request(Uri.parse(release.apkUrl))
                    .setTitle("Casa Mobile ${release.version}")
                    .setDescription("Versão autorizada em bin/versions.json")
                    .setMimeType("application/vnd.android.package-archive")
                    .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                    .setDestinationInExternalFilesDir(app, "updates", "CasaMobile-${release.versionCode}.apk")
                    .setAllowedOverMetered(true).setAllowedOverRoaming(false)
                val id = manager.enqueue(request)
                prefs.edit().putLong("download_id", id).putLong("pending_code", release.versionCode)
                    .putString("pending_version", release.version).putString("sha256", release.sha256).commit()
                setStatus(app, "Baixando versão autorizada ${release.version}.")
            } catch (e: Exception) {
                setStatus(app, "Falha ao buscar atualização. Nova tentativa na próxima consulta.")
            } finally { synchronized(this) { checking = false } }
        }.start()
    }

    private fun indicatedRelease(): MobileReleaseManifest.Release? {
        val request = Request.Builder().url(MANIFEST_URL).header("Cache-Control", "no-cache").build()
        client.newCall(request).execute().use { response ->
            check(response.isSuccessful) { "Manifesto indisponível" }
            val channel = if (BuildConfig.DEBUG) "casa-mobile-debug" else "casa-mobile"
            return MobileReleaseManifest.parse(response.body?.string().orEmpty(), channel, BuildConfig.APPLICATION_ID)
        }
    }

    fun openPendingInstaller(context: Context) {
        val id = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getLong("download_id", -1)
        if (id <= 0) { setStatus(context, "Nenhuma atualização baixada para instalar."); return }
        context.startActivity(Intent(context, UpdateInstallActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    fun notifyReady(context: Context) {
        setStatus(context, "Download concluído. Toque para validar e instalar a atualização.")
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        nm.createNotificationChannel(NotificationChannel(CHANNEL, "Atualizações Casa Mobile", NotificationManager.IMPORTANCE_DEFAULT))
        if (android.os.Build.VERSION.SDK_INT >= 33 && context.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) != android.content.pm.PackageManager.PERMISSION_GRANTED) return
        val pending = PendingIntent.getActivity(context, 2800, Intent(context, UpdateInstallActivity::class.java), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        nm.notify(2800, NotificationCompat.Builder(context, CHANNEL).setSmallIcon(android.R.drawable.stat_sys_download_done)
            .setContentTitle("Atualização Casa Mobile disponível").setContentText("Toque para validar e instalar sem apagar o vínculo.")
            .setContentIntent(pending).setAutoCancel(true).build())
    }
}
