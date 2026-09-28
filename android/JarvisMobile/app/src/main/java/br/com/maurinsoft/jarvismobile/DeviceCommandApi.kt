package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/** Cliente do Control Plane para o proprio JARVIS Mobile. */
object DeviceCommandApi {
    data class Command(
        val id: Long,
        val name: String,
        val payload: JSONObject,
        val priority: String,
        val correlationId: String?
    )

    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    private fun request(context: Context, action: String, body: JSONObject? = null): JSONObject {
        val cfg = JarvisApi.loadConfig(context)
        require(cfg.baseUrl.startsWith("https://")) { "Use uma URL HTTPS do JARVIS" }
        require(cfg.token.isNotBlank()) { "Token do JARVIS Mobile nao configurado" }
        val builder = Request.Builder()
            .url(cfg.baseUrl.trimEnd('/') + "/api/v1/device.php?acao=$action")
            .header("Authorization", "Bearer ${cfg.token}")
            .header("X-Device-Token", cfg.token)
            .header("Accept", "application/json")
        if (body == null) builder.get()
        else builder.post(body.toString().toRequestBody(jsonType))
        client.newCall(builder.build()).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw IllegalStateException("HTTP ${response.code}: ${raw.take(300)}")
            return JSONObject(raw)
        }
    }

    fun poll(context: Context, limit: Int = 10): List<Command> {
        val root = request(context, "commands&limit=${limit.coerceIn(1, 20)}")
        val arr = root.optJSONArray("commands") ?: JSONArray()
        val out = ArrayList<Command>()
        for (i in 0 until arr.length()) {
            val item = arr.optJSONObject(i) ?: continue
            val id = item.optLong("id")
            val name = item.optString("comando").trim()
            if (id <= 0L || name.isBlank()) continue
            val payload = when (val raw = item.opt("payload")) {
                is JSONObject -> raw
                is String -> runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
                else -> JSONObject()
            }
            out += Command(
                id = id,
                name = name,
                payload = payload,
                priority = item.optString("prioridade", "normal"),
                correlationId = item.optString("correlation_id").takeIf { it.isNotBlank() && it != "null" }
            )
        }
        return out
    }

    fun ack(context: Context, id: Long) {
        request(context, "command_ack", JSONObject().put("id", id))
    }

    fun start(context: Context, id: Long) {
        request(context, "command_start", JSONObject().put("id", id))
    }

    fun result(context: Context, id: Long, ok: Boolean, result: JSONObject = JSONObject(), error: String? = null) {
        request(
            context,
            "command_result",
            JSONObject()
                .put("id", id)
                .put("status", if (ok) "success" else "error")
                .put("result", result)
                .apply { if (!ok) put("error", error ?: "Falha no JARVIS Mobile") }
        )
    }
}
