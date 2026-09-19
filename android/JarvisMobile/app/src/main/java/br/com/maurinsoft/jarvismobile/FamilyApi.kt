package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

object FamilyApi {
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    data class FamilyMessage(
        val id: Long,
        val sender: String,
        val origin: String,
        val type: String,
        val message: String,
        val data: JSONObject,
        val createdAt: String
    )

    private fun request(context: Context, action: String, body: JSONObject? = null, query: String = ""): JSONObject {
        val cfg = JarvisApi.loadConfig(context)
        require(cfg.baseUrl.startsWith("https://")) { "Use uma URL HTTPS do JARVIS" }
        require(cfg.token.isNotBlank()) { "Token do dispositivo Android não configurado" }
        val url = cfg.baseUrl + "/api/v1/family.php?acao=" + action + query
        val b = Request.Builder().url(url)
            .header("Authorization", "Bearer ${cfg.token}")
            .header("X-Device-Token", cfg.token)
            .header("Accept", "application/json")
        if (body != null) b.post(body.toString().toRequestBody(jsonType)) else b.get()
        client.newCall(b.build()).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw IllegalStateException("HTTP ${response.code}: ${raw.take(300)}")
            return JSONObject(raw)
        }
    }

    fun presence(context: Context, device: String = "JARVIS Mobile", metadata: JSONObject = JSONObject()): JSONObject =
        request(context, "presence", JSONObject()
            .put("platform", "mobile")
            .put("device", device)
            .put("metadata", metadata))

    fun sendMessage(context: Context, message: String, type: String = "texto", data: JSONObject = JSONObject()): Long {
        val j = request(context, "send", JSONObject()
            .put("origin", "mobile")
            .put("type", type)
            .put("message", message)
            .put("data", data))
        return j.optLong("id")
    }

    fun poll(context: Context, after: Long): List<FamilyMessage> {
        val arr = request(context, "poll", null, "&after=$after").optJSONArray("messages") ?: JSONArray()
        val out = ArrayList<FamilyMessage>()
        for (i in 0 until arr.length()) {
            val m = arr.optJSONObject(i) ?: continue
            out += FamilyMessage(
                id = m.optLong("id"), sender = m.optString("remetente"), origin = m.optString("origem"),
                type = m.optString("tipo"), message = m.optString("mensagem"),
                data = runCatching { JSONObject(m.optString("dados", "{}")) }.getOrDefault(JSONObject()),
                createdAt = m.optString("criado_em")
            )
        }
        return out
    }

    fun assistEvent(
        context: Context,
        type: String,
        severity: String,
        message: String,
        data: JSONObject = JSONObject(),
        person: String? = null,
        device: String = "JARVIS Watch"
    ): Long {
        val b = JSONObject().put("type", type).put("severity", severity).put("message", message)
            .put("data", data).put("device", device)
        if (!person.isNullOrBlank()) b.put("person", person)
        return request(context, "assist_event", b).optLong("event_id")
    }

    fun startCall(
        context: Context,
        mode: String = "video",
        origin: String = "mobile",
        targetPlatform: String? = null,
        targetClient: String? = null
    ): Long {
        val body = JSONObject().put("mode", mode).put("origin", origin)
        if (!targetPlatform.isNullOrBlank()) body.put("target_platform", targetPlatform)
        if (!targetClient.isNullOrBlank()) body.put("target_client", targetClient)
        return request(context, "call_start", body).optLong("call_id")
    }

    fun joinCall(context: Context, callId: Long, device: String = "JARVIS Mobile") {
        request(context, "call_join", JSONObject().put("call_id", callId).put("platform", "mobile").put("device", device))
    }

    fun currentCall(context: Context, platform: String = "mobile"): JSONObject? {
        val c = request(context, "call_current", null, "&platform=$platform").optJSONObject("call")
        return if (c == null || c.length() == 0) null else c
    }

    fun sendSignal(context: Context, callId: Long, type: String, payload: JSONObject, target: String? = null): Long {
        val b = JSONObject().put("call_id", callId).put("type", type).put("payload", payload)
        if (!target.isNullOrBlank()) b.put("target", target)
        return request(context, "call_signal", b).optLong("id")
    }

    fun pollSignals(context: Context, callId: Long, after: Long): JSONArray =
        request(context, "call_poll", null, "&call_id=$callId&after=$after").optJSONArray("signals") ?: JSONArray()

    fun endCall(context: Context, callId: Long) {
        request(context, "call_end", JSONObject().put("call_id", callId).put("origin", "mobile"))
    }
}
