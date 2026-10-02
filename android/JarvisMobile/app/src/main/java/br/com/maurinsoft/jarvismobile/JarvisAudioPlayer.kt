package br.com.maurinsoft.jarvismobile

import android.app.Activity
import android.media.AudioAttributes
import android.media.MediaPlayer
import okhttp3.OkHttpClient
import okhttp3.Request

class JarvisAudioPlayer(
    private val activity: Activity,
    private val http: OkHttpClient = OkHttpClient()
) {
    private var active: MediaPlayer? = null
    private var activeFile: java.io.File? = null
    fun stop() {
        runCatching { active?.stop(); active?.release() }
        active = null
        activeFile?.delete(); activeFile = null
    }
    fun play(path: String?) {
        if (path.isNullOrBlank()) return
        stop()
        val cfg = JarvisApi.loadConfig(activity)
        val url = runCatching { JarvisApi.absoluteUrl(activity, path) }.getOrNull() ?: return
        Thread {
            try {
                http.newCall(
                    Request.Builder()
                        .url(url)
                        .header("Authorization", "Bearer ${cfg.token}")
                        .header("X-Device-Token", cfg.token)
                        .build()
                ).execute().use { response ->
                    if (!response.isSuccessful) return@use
                    val bytes = response.body?.bytes() ?: return@use
                    val file = java.io.File.createTempFile("jarvis_", ".wav", activity.cacheDir)
                    file.writeBytes(bytes)
                    activity.runOnUiThread {
                        stop()
                        activeFile = file
                        active = MediaPlayer().apply {
                            setAudioAttributes(
                                AudioAttributes.Builder()
                                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                                    .setUsage(AudioAttributes.USAGE_ASSISTANCE_ACCESSIBILITY)
                                    .build()
                            )
                            setDataSource(file.absolutePath)
                            setOnPreparedListener { it.start() }
                            setOnCompletionListener {
                                it.release()
                                file.delete()
                            }
                            setOnErrorListener { player, _, _ ->
                                player.release()
                                file.delete()
                                true
                            }
                            prepareAsync()
                        }
                    }
                }
            } catch (_: Exception) {
            }
        }.start()
    }
}
