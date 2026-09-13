package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

object JarvisApi {
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(45, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .build()

    data class Config(val baseUrl: String, val token: String)
    data class JarvisAnswer(val text: String, val audioUrl: String?, val action: String?)
    data class MobileNotification(
        val id: Long,
        val title: String,
        val message: String,
        val audioUrl: String?,
        val priority: String
    )

    fun loadConfig(context: Context): Config {
        val p = context.getSharedPreferences("jarvis", Context.MODE_PRIVATE)
        return Config(
            p.getString("base_url", "http://192.168.2.12")!!.trimEnd('/'),
            p.getString("device_token", "") ?: ""
        )
    }

    fun saveConfig(context: Context, baseUrl: String, token: String) {
        context.getSharedPreferences("jarvis", Context.MODE_PRIVATE)
            .edit()
            .putString("base_url", baseUrl.trimEnd('/'))
            .putString("device_token", token.trim())
            .apply()
    }

    private fun req(context: Context, path: String, body: JSONObject? = null): String {
        val cfg = loadConfig(context)
        require(cfg.baseUrl.isNotBlank()) { "URL do JARVIS não configurada" }
        require(cfg.token.isNotBlank()) { "Token do dispositivo não configurado" }

        val b = Request.Builder()
            .url(cfg.baseUrl + path)
            .header("X-Device-Token", cfg.token)

        if (body != null) b.post(body.toString().toRequestBody(jsonType)) else b.get()

        client.newCall(b.build()).execute().use { r ->
            val text = r.body?.string().orEmpty()
            if (!r.isSuccessful) throw IllegalStateException("HTTP ${r.code}: $text")
            return text
        }
    }

    fun askJarvis(context: Context, text: String): JarvisAnswer {
        val cfg = loadConfig(context)
        val payload = JSONObject().put("comando", text).put("origem", "ANDROID_APP")
        val request = Request.Builder()
            .url(cfg.baseUrl + "/api/jarvis.php")
            .header("X-Device-Token", cfg.token)
            .post(payload.toString().toRequestBody(jsonType))
            .build()

        client.newCall(request).execute().use { r ->
            val raw = r.body?.string().orEmpty()
            if (!r.isSuccessful) throw IllegalStateException("HTTP ${r.code}: $raw")
            val j = JSONObject(raw)
            return JarvisAnswer(
                text = j.optString("resposta"),
                audioUrl = j.optString("audio_url").takeIf { it.isNotBlank() },
                action = j.optString("acao").takeIf { it.isNotBlank() }
            )
        }
    }

    fun getNotifications(context: Context): List<MobileNotification> {
        val raw = req(context, "/api/mobile.php?acao=notificacoes")
        val arr: JSONArray = JSONObject(raw).optJSONArray("notificacoes") ?: JSONArray()
        val out = ArrayList<MobileNotification>()
        for (i in 0 until arr.length()) {
            val n = arr.getJSONObject(i)
            out += MobileNotification(
                n.optLong("id"),
                n.optString("titulo", "JARVIS"),
                n.optString("mensagem"),
                n.optString("audio_url").takeIf { it.isNotBlank() },
                n.optString("prioridade", "normal")
            )
        }
        return out
    }

    fun ackNotification(context: Context, id: Long) {
        req(context, "/api/mobile.php?acao=ack", JSONObject().put("id", id))
    }

    fun sendNetworkEvent(context: Context, type: String, description: String, details: JSONObject = JSONObject()) {
        req(
            context,
            "/api/mobile.php?acao=network_event",
            JSONObject().put("tipo", type).put("descricao", description).put("dados", details)
        )
    }

    fun absoluteUrl(context: Context, path: String): String {
        if (path.startsWith("http://") || path.startsWith("https://")) return path
        return loadConfig(context).baseUrl + path
    }

    fun executeBleBridgeRequest(context: Context, input: JSONObject): JSONObject {
        return when (input.optString("type")) {
            "jarvis" -> {
                val a = askJarvis(context, input.optString("text"))
                JSONObject()
                    .put("ok", true)
                    .put("type", "jarvis_result")
                    .put("text", a.text)
                    .put("audio_url", a.audioUrl ?: JSONObject.NULL)
                    .put("action", a.action ?: JSONObject.NULL)
            }
            "status" -> JSONObject().put("ok", true).put("type", "status").put("internet", true)
            "ping" -> JSONObject().put("ok", true).put("type", "pong")
            else -> JSONObject().put("ok", false).put("error", "Tipo BLE desconhecido")
        }
    }
}
