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
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
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

    @Volatile
    var lastAudioUrl: String? = null
        private set

    fun loadConfig(context: Context): Config {
        val p = context.getSharedPreferences("jarvis", Context.MODE_PRIVATE)
        return Config(
            p.getString("base_url", "")?.trim()?.trimEnd('/') ?: "",
            p.getString("device_token", "")?.trim() ?: ""
        )
    }

    fun saveConfig(context: Context, baseUrl: String, token: String) {
        context.getSharedPreferences("jarvis", Context.MODE_PRIVATE)
            .edit()
            .putString("base_url", baseUrl.trim().trimEnd('/'))
            .putString("device_token", token.trim())
            .apply()
    }

    private fun ensureConfigured(context: Context): Config {
        val cfg = loadConfig(context)
        require(cfg.baseUrl.startsWith("http://") || cfg.baseUrl.startsWith("https://")) {
            "Informe a URL externa completa do JARVIS, por exemplo https://casa.exemplo.com"
        }
        require(cfg.token.isNotBlank()) { "Token do dispositivo Android não configurado" }
        return cfg
    }

    private fun builder(cfg: Config, url: String): Request.Builder =
        Request.Builder()
            .url(url)
            .header("Authorization", "Bearer ${cfg.token}")
            .header("X-Device-Token", cfg.token)
            .header("Accept", "application/json")

    private fun request(context: Context, path: String, body: JSONObject? = null): String {
        val cfg = ensureConfigured(context)
        val b = builder(cfg, cfg.baseUrl + path)
        if (body != null) {
            b.post(body.toString().toRequestBody(jsonType))
        } else {
            b.get()
        }

        client.newCall(b.build()).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                throw IllegalStateException("HTTP ${response.code}: ${raw.take(300)}")
            }
            return raw
        }
    }

    fun testConnection(context: Context): String {
        val raw = request(context, "/api/v1/status")
        val j = JSONObject(raw)
        val system = j.optString("sistema", "JARVIS")
        val version = j.optString("versao_api", "v1")
        val clientName = j.optString("cliente", "dispositivo Android")
        return "Conectado a $system ($version) como $clientName"
    }

    fun askJarvis(context: Context, text: String): JarvisAnswer {
        val raw = request(
            context,
            "/api/v1/comando",
            JSONObject()
                .put("comando", text)
                .put("ia_mode", "auto")
                .put("origem", "ANDROID_APP")
        )
        val j = JSONObject(raw)
        val audio = j.optString("audio_url").takeIf { it.isNotBlank() && it != "null" }
        lastAudioUrl = audio
        return JarvisAnswer(
            text = j.optString("resposta", j.optString("mensagem", "Sem resposta do JARVIS")),
            audioUrl = audio,
            action = j.optString("acao_executada").takeIf { it.isNotBlank() && it != "null" }
        )
    }

    fun getNotifications(context: Context): List<MobileNotification> {
        val raw = request(context, "/api/v1/mobile.php?acao=notificacoes")
        val arr: JSONArray = JSONObject(raw).optJSONArray("notificacoes") ?: JSONArray()
        val out = ArrayList<MobileNotification>()
        for (i in 0 until arr.length()) {
            val n = arr.getJSONObject(i)
            out += MobileNotification(
                n.optLong("id"),
                n.optString("titulo", "JARVIS"),
                n.optString("mensagem"),
                n.optString("audio_url").takeIf { it.isNotBlank() && it != "null" },
                n.optString("prioridade", "normal")
            )
        }
        return out
    }

    fun ackNotification(context: Context, id: Long) {
        request(
            context,
            "/api/v1/mobile.php?acao=ack",
            JSONObject().put("id", id)
        )
    }

    fun sendNetworkEvent(
        context: Context,
        type: String,
        description: String,
        details: JSONObject = JSONObject()
    ) {
        request(
            context,
            "/api/v1/mobile.php?acao=network_event",
            JSONObject()
                .put("tipo", type)
                .put("descricao", description)
                .put("dados", details)
        )
    }

    fun absoluteUrl(context: Context, path: String): String {
        if (path.startsWith("http://") || path.startsWith("https://")) return path
        return ensureConfigured(context).baseUrl + path
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
            "status" -> {
                val result = runCatching { testConnection(context) }
                JSONObject()
                    .put("ok", result.isSuccess)
                    .put("type", "status")
                    .put("internet", result.isSuccess)
                    .put("message", result.getOrElse { it.message ?: "offline" })
            }
            "ping" -> JSONObject().put("ok", true).put("type", "pong")
            else -> JSONObject().put("ok", false).put("error", "Tipo BLE desconhecido")
        }
    }
}
