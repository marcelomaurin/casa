package br.com.maurinsoft.jarvismobile

import android.content.Context
import android.os.Handler
import android.os.Looper
import okhttp3.Call
import okhttp3.Callback
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import java.io.IOException
import java.util.concurrent.TimeUnit

class CasaEventStream(
    private val context: Context,
    private val onEvent: (event: String, data: String, id: String?) -> Unit,
    private val onState: (connected: Boolean) -> Unit = {}
) {
    private val mainHandler = Handler(Looper.getMainLooper())
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(0, TimeUnit.MILLISECONDS)
        .retryOnConnectionFailure(true)
        .build()

    @Volatile private var call: Call? = null
    @Volatile private var stopped = false
    @Volatile private var lastEventId: String? = null

    fun start() {
        stopped = false
        connect()
    }

    fun stop() {
        stopped = true
        call?.cancel()
        call = null
        mainHandler.post { onState(false) }
    }

    private fun connect() {
        if (stopped) return
        val cfg = JarvisApi.loadConfig(context)
        if (!cfg.baseUrl.startsWith("https://") || cfg.token.isBlank()) {
            mainHandler.post { onState(false) }
            return
        }

        val b = Request.Builder()
            .url(cfg.baseUrl + "/api/v1/events.php")
            .header("Authorization", "Bearer ${cfg.token}")
            .header("X-Device-Token", cfg.token)
            .header("Accept", "text/event-stream")
            .header("Cache-Control", "no-cache")
        lastEventId?.let { b.header("Last-Event-ID", it) }

        val newCall = client.newCall(b.build())
        call = newCall
        newCall.enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                if (stopped) return
                mainHandler.post { onState(false) }
                mainHandler.postDelayed({ connect() }, 3000)
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    if (!response.isSuccessful) {
                        mainHandler.post { onState(false) }
                        if (!stopped) mainHandler.postDelayed({ connect() }, 5000)
                        return
                    }
                    mainHandler.post { onState(true) }
                    val source = response.body?.source() ?: return
                    var event = "message"
                    var id: String? = null
                    val data = StringBuilder()
                    try {
                        while (!stopped && !source.exhausted()) {
                            val line = source.readUtf8Line() ?: break
                            if (line.isEmpty()) {
                                if (data.isNotEmpty()) {
                                    val payload = data.toString().removeSuffix("\n")
                                    id?.let { lastEventId = it }
                                    mainHandler.post { onEvent(event, payload, id) }
                                }
                                event = "message"
                                id = null
                                data.clear()
                            } else when {
                                line.startsWith("event:") -> event = line.substringAfter(':').trim()
                                line.startsWith("id:") -> id = line.substringAfter(':').trim()
                                line.startsWith("data:") -> data.append(line.substringAfter(':').trimStart()).append('\n')
                            }
                        }
                    } catch (_: Exception) {
                    } finally {
                        mainHandler.post { onState(false) }
                        if (!stopped) mainHandler.postDelayed({ connect() }, 3000)
                    }
                }
            }
        })
    }
}
