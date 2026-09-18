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
        .retryOnConnectionFailure(true)
        .build()

    private const val DEFAULT_BASE_URL = "https://maurinsoft.com.br/casa"
    private const val LEGACY_BASE_URL = "https://casa.maurinsoft.com.br"
    private const val PREFS = "jarvis"
    private const val PENDING_KEY = "pending_commands"

    data class Config(val baseUrl: String, val token: String)
    data class JarvisAnswer(val text: String, val audioUrl: String?, val action: String?)
    data class MobileNotification(val id: Long, val title: String, val message: String, val audioUrl: String?, val priority: String)
    data class SendResult(val delivered: Boolean, val queued: Boolean, val answer: JarvisAnswer?, val message: String)

    @Volatile var lastAudioUrl: String? = null
        private set

    private fun normalizeBaseUrl(value: String?): String {
        val raw = value.orEmpty().trim().trimEnd('/')
        if (raw.isBlank()) return DEFAULT_BASE_URL

        val normalized = when {
            raw.equals(LEGACY_BASE_URL, ignoreCase = true) -> DEFAULT_BASE_URL
            raw.equals("https://maurinsoft.com.br", ignoreCase = true) -> DEFAULT_BASE_URL
            raw.equals("https://maurinsoft.com.br/casa", ignoreCase = true) -> DEFAULT_BASE_URL
            raw.equals("https://maurinsoft.com.br/casa/", ignoreCase = true) -> DEFAULT_BASE_URL
            else -> raw
        }
        return normalized
    }

    fun loadConfig(context: Context): Config {
        val p = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val stored = p.getString("base_url", DEFAULT_BASE_URL)
        val baseUrl = normalizeBaseUrl(stored)

        // Persiste a migração para que versões futuras já usem o endereço canônico.
        if (stored?.trim()?.trimEnd('/') != baseUrl) {
            p.edit().putString("base_url", baseUrl).apply()
        }

        return Config(baseUrl, AppTokenStore.load(context))
    }

    fun saveConfig(context: Context, baseUrl: String, token: String) {
        val normalized = normalizeBaseUrl(baseUrl)
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString("base_url", normalized)
            .apply()
        AppTokenStore.save(context, token)
    }

    fun isConfigured(context: Context): Boolean {
        val cfg = loadConfig(context)
        return cfg.baseUrl.startsWith("https://") && cfg.token.isNotBlank()
    }

    private fun ensureConfigured(context: Context): Config {
        val cfg = loadConfig(context)
        require(cfg.baseUrl.startsWith("https://")) { "Use uma URL HTTPS do JARVIS" }
        require(cfg.token.isNotBlank()) { "Token do dispositivo Android não configurado" }
        return cfg
    }

    private fun request(context: Context, path: String, body: JSONObject? = null): String {
        val cfg = ensureConfigured(context)
        val language = LanguageManager.currentLanguage(context)
        val b = Request.Builder().url(cfg.baseUrl + path)
            .header("Authorization", "Bearer ${cfg.token}")
            .header("X-Device-Token", cfg.token)
            .header("Accept", "application/json")
            .header("Accept-Language", language)
            .header("X-Jarvis-Language", language)
        if (body != null) b.post(body.toString().toRequestBody(jsonType)) else b.get()
        client.newCall(b.build()).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw IllegalStateException("HTTP ${response.code}: ${raw.take(300)}")
            return raw
        }
    }

    fun testConnection(context: Context): String {
        val j = JSONObject(request(context, "/api/v1/status"))
        return "${j.optString("sistema", "JARVIS")} ${j.optString("versao_api", "v1")} — ${j.optString("cliente", "Android")}" 
    }

    fun isOnline(context: Context): Boolean = runCatching { testConnection(context) }.isSuccess

    fun askJarvis(context: Context, text: String): JarvisAnswer {
        val language = LanguageManager.currentLanguage(context)
        val raw = request(context, "/api/v1/comando", JSONObject()
            .put("comando", text)
            .put("ia_mode", "auto")
            .put("origem", "ANDROID_APP")
            .put("idioma", language)
            .put("locale", language))
        val j = JSONObject(raw)
        val audio = j.optString("audio_url").takeIf { it.isNotBlank() && it != "null" }
        lastAudioUrl = audio
        return JarvisAnswer(
            text = j.optString("resposta", j.optString("mensagem", "JARVIS")),
            audioUrl = audio,
            action = j.optString("acao_executada").takeIf { it.isNotBlank() && it != "null" }
        )
    }

    @Synchronized
    fun queueCommand(context: Context, text: String) {
        if (text.isBlank()) return
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val arr = runCatching { JSONArray(prefs.getString(PENDING_KEY, "[]")) }.getOrElse { JSONArray() }
        arr.put(JSONObject().put("text", text.trim()).put("language", LanguageManager.currentLanguage(context)).put("created_at", System.currentTimeMillis()).put("attempts", 0))
        while (arr.length() > 100) arr.remove(0)
        prefs.edit().putString(PENDING_KEY, arr.toString()).apply()
    }

    @Synchronized
    fun pendingCount(context: Context): Int = runCatching {
        JSONArray(context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(PENDING_KEY, "[]")).length()
    }.getOrDefault(0)

    fun sendOrQueue(context: Context, text: String): SendResult = try {
        val answer = askJarvis(context, text)
        SendResult(true, false, answer, answer.text)
    } catch (e: Exception) {
        queueCommand(context, text)
        SendResult(false, true, null, when (LanguageManager.currentLanguage(context)) {
            "en-US" -> "Offline. Command saved and will be sent automatically."
            "es-ES" -> "Sin conexión. Comando guardado para envío automático."
            else -> "Sem conexão. Comando salvo na fila para envio automático."
        })
    }

    @Synchronized
    fun flushPending(context: Context): Int {
        if (!isConfigured(context)) return 0
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val source = runCatching { JSONArray(prefs.getString(PENDING_KEY, "[]")) }.getOrElse { JSONArray() }
        if (source.length() == 0) return 0
        val remaining = JSONArray(); var sent = 0
        for (i in 0 until source.length()) {
            val item = source.optJSONObject(i) ?: continue
            val text = item.optString("text")
            if (text.isBlank()) continue
            if (sent == 0 || isOnline(context)) {
                if (runCatching { askJarvis(context, text) }.isSuccess) { sent++; continue }
            }
            item.put("attempts", item.optInt("attempts", 0) + 1); remaining.put(item)
            for (j in (i + 1) until source.length()) source.optJSONObject(j)?.let { remaining.put(it) }
            break
        }
        prefs.edit().putString(PENDING_KEY, remaining.toString()).apply()
        return sent
    }

    fun getNotifications(context: Context): List<MobileNotification> {
        val arr = JSONObject(request(context, "/api/v1/mobile.php?acao=notificacoes")).optJSONArray("notificacoes") ?: JSONArray()
        val out = ArrayList<MobileNotification>()
        for (i in 0 until arr.length()) {
            val n = arr.getJSONObject(i)
            out += MobileNotification(n.optLong("id"), n.optString("titulo", "JARVIS"), n.optString("mensagem"), n.optString("audio_url").takeIf { it.isNotBlank() && it != "null" }, n.optString("prioridade", "normal"))
        }
        return out
    }

    fun ackNotification(context: Context, id: Long) { request(context, "/api/v1/mobile.php?acao=ack", JSONObject().put("id", id)) }

    fun sendNetworkEvent(context: Context, type: String, description: String, details: JSONObject = JSONObject()) {
        request(context, "/api/v1/mobile.php?acao=network_event", JSONObject().put("tipo", type).put("descricao", description).put("dados", details).put("idioma", LanguageManager.currentLanguage(context)))
    }

    fun absoluteUrl(context: Context, path: String): String = if (path.startsWith("http://") || path.startsWith("https://")) path else ensureConfigured(context).baseUrl + path

    fun executeBleBridgeRequest(context: Context, input: JSONObject): JSONObject = when (input.optString("type")) {
        "jarvis" -> {
            val result = sendOrQueue(context, input.optString("text"))
            JSONObject().put("ok", result.delivered).put("queued", result.queued).put("type", "jarvis_result")
                .put("text", result.answer?.text ?: result.message).put("audio_url", result.answer?.audioUrl ?: JSONObject.NULL)
                .put("action", result.answer?.action ?: JSONObject.NULL).put("language", LanguageManager.currentLanguage(context))
        }
        "status" -> JSONObject().put("ok", isOnline(context)).put("type", "status").put("internet", isOnline(context)).put("pending", pendingCount(context)).put("language", LanguageManager.currentLanguage(context))
        "ping" -> JSONObject().put("ok", true).put("type", "pong").put("language", LanguageManager.currentLanguage(context))
        else -> JSONObject().put("ok", false).put("error", "unknown_ble_type")
    }
}
