package br.com.maurinsoft.jarvismobile

import android.app.DownloadManager
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build
import android.os.Environment
import androidx.core.app.NotificationCompat
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONArray
import java.util.concurrent.TimeUnit

object UpdateManager {
    private const val RELEASES_URL = "https://api.github.com/repos/marcelomaurin/casa/releases?per_page=20"
    private const val PREFS = "jarvis_updates"
    private const val CHANNEL = "jarvis_updates"
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    data class Release(val version: String, val apkUrl: String, val fileName: String)

    fun checkAndDownloadAsync(context: Context) {
        Thread {
            runCatching {
                val release = latestRelease() ?: return@runCatching
                val current = BuildConfig.VERSION_NAME.substringBefore('-')
                if (compareVersions(release.version, current) <= 0) return@runCatching
                val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                val pendingVersion = prefs.getString("pending_version", "").orEmpty()
                val pendingId = prefs.getLong("download_id", -1L)
                if (pendingVersion == release.version && pendingId > 0) return@runCatching
                val manager = context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
                val req = DownloadManager.Request(android.net.Uri.parse(release.apkUrl))
                    .setTitle("JARVIS Mobile ${release.version}")
                    .setDescription("Atualização do JARVIS Mobile")
                    .setMimeType("application/vnd.android.package-archive")
                    .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                    .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, release.fileName)
                    .setAllowedOverMetered(true)
                    .setAllowedOverRoaming(false)
                val id = manager.enqueue(req)
                prefs.edit().putLong("download_id", id).putString("pending_version", release.version).apply()
                notify(context, "Atualização ${release.version}", "Download iniciado automaticamente.")
            }
        }.start()
    }

    private fun latestRelease(): Release? {
        val req = Request.Builder().url(RELEASES_URL)
            .header("Accept", "application/vnd.github+json")
            .header("User-Agent", "JARVIS-Mobile/${BuildConfig.VERSION_NAME}")
            .build()
        client.newCall(req).execute().use { response ->
            if (!response.isSuccessful) return null
            val arr = JSONArray(response.body?.string().orEmpty())
            for (i in 0 until arr.length()) {
                val r = arr.optJSONObject(i) ?: continue
                val tag = r.optString("tag_name")
                if (!tag.startsWith("jarvis-mobile-v") || !tag.endsWith("-debug")) continue
                val version = tag.removePrefix("jarvis-mobile-v").removeSuffix("-debug")
                val assets = r.optJSONArray("assets") ?: continue
                for (j in 0 until assets.length()) {
                    val a = assets.optJSONObject(j) ?: continue
                    val name = a.optString("name")
                    val url = a.optString("browser_download_url")
                    if (name.endsWith(".apk", true) && url.startsWith("https://")) return Release(version, url, name)
                }
            }
        }
        return null
    }

    private fun compareVersions(a: String, b: String): Int {
        val aa = a.split('.').map { it.toIntOrNull() ?: 0 }
        val bb = b.split('.').map { it.toIntOrNull() ?: 0 }
        for (i in 0 until maxOf(aa.size, bb.size)) {
            val x = aa.getOrElse(i) { 0 }; val y = bb.getOrElse(i) { 0 }
            if (x != y) return x.compareTo(y)
        }
        return 0
    }

    private fun notify(context: Context, title: String, text: String) {
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "Atualizações JARVIS", NotificationManager.IMPORTANCE_DEFAULT))
        }
        if (Build.VERSION.SDK_INT >= 33 && context.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) != android.content.pm.PackageManager.PERMISSION_GRANTED) return
        nm.notify(2606, NotificationCompat.Builder(context, CHANNEL)
            .setSmallIcon(android.R.drawable.stat_sys_download)
            .setContentTitle(title).setContentText(text).setAutoCancel(true).build())
    }
}
