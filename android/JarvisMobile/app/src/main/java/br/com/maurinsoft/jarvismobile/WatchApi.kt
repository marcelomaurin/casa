package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

object WatchApi {
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    data class WatchDevice(
        val deviceId: String,
        val name: String,
        val status: String,
        val health: String,
        val transport: String,
        val rssi: Int,
        val location: String,
        val lastHeartbeat: String,
        val capabilities: JSONArray
    ) {
        val online: Boolean get() = status.equals("online", true)
    }

    data class WatchTelemetry(
        val client: String,
        val battery: Int?,
        val steps: Long?,
        val rssiWifi: Int?,
        val wifiSsid: String?,
        val transport: String?,
        val powerMode: String?,
        val alertActive: Boolean,
        val timestamp: String?
    )

    data class WatchEvent(
        val id: Long,
        val deviceId: String,
        val type: String,
        val priority: String,
        val correlationId: String?,
        val data: JSONObject,
        val createdAt: String
    )

    data class EnqueueResult(val id: Long, val correlationId: String)

    private fun config(context: Context): JarvisApi.Config {
        val cfg = JarvisApi.loadConfig(context)
        require(cfg.baseUrl.startsWith("https://")) { "Configure uma URL HTTPS da CASA" }
        require(cfg.token.isNotBlank()) { "Configure o token deste celular" }
        return cfg
    }

    private fun request(context: Context, path: String, body: JSONObject? = null): JSONObject {
        val cfg = config(context)
        val builder = Request.Builder()
            .url(cfg.baseUrl.trimEnd('/') + path)
            .header("Authorization", "Bearer ${cfg.token}")
            .header("X-Device-Token", cfg.token)
            .header("Accept", "application/json")
        if (body == null) builder.get()
        else builder.post(body.toString().toRequestBody(jsonType))

        client.newCall(builder.build()).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                throw IllegalStateException("HTTP ${response.code}: ${raw.take(300)}")
            }
            return JSONObject(raw)
        }
    }

    fun listWatches(context: Context): List<WatchDevice> {
        val arr = request(context, "/api/v1/control.php?acao=devices")
            .optJSONArray("devices") ?: JSONArray()
        val out = ArrayList<WatchDevice>()
        for (i in 0 until arr.length()) {
            val d = arr.optJSONObject(i) ?: continue
            if (!d.optString("tipo").equals("watch", true)) continue
            val caps = when (val raw = d.opt("capabilities")) {
                is JSONArray -> raw
                is String -> runCatching { JSONArray(raw) }.getOrDefault(JSONArray())
                else -> JSONArray()
            }
            out += WatchDevice(
                deviceId = d.optString("device_id"),
                name = d.optString("nome", "JARVIS Watch"),
                status = d.optString("status", "offline"),
                health = d.optString("health", "unknown"),
                transport = d.optString("transport"),
                rssi = d.optInt("sinal_rssi", -127),
                location = d.optString("localizacao"),
                lastHeartbeat = d.optString("ultimo_heartbeat"),
                capabilities = caps
            )
        }
        return out
    }

    fun latestTelemetry(context: Context): WatchTelemetry? {
        val w = request(context, "/api/v1/mobile.php?acao=watch_status")
            .optJSONObject("watch") ?: return null
        return WatchTelemetry(
            client = w.optString("cliente"),
            battery = w.opt("bateria_pct")?.let { if (it == JSONObject.NULL) null else w.optInt("bateria_pct") },
            steps = w.opt("passos")?.let { if (it == JSONObject.NULL) null else w.optLong("passos") },
            rssiWifi = w.opt("rssi_wifi")?.let { if (it == JSONObject.NULL) null else w.optInt("rssi_wifi") },
            wifiSsid = w.optString("wifi_ssid").takeIf { it.isNotBlank() && it != "null" },
            transport = w.optString("transporte").takeIf { it.isNotBlank() && it != "null" },
            powerMode = w.optString("modo_energia").takeIf { it.isNotBlank() && it != "null" },
            alertActive = w.optInt("alerta_ativo", 0) != 0,
            timestamp = w.optString("data_hora").takeIf { it.isNotBlank() }
        )
    }

    fun enqueue(
        context: Context,
        deviceId: String,
        command: String,
        payload: JSONObject = JSONObject(),
        priority: String = "normal",
        ttlSeconds: Int = 300
    ): EnqueueResult {
        require(deviceId.isNotBlank())
        require(command.isNotBlank())
        val result = request(
            context,
            "/api/v1/control.php?acao=enqueue",
            JSONObject()
                .put("device_id", deviceId)
                .put("command", command)
                .put("payload", payload)
                .put("priority", priority)
                .put("ttl_seconds", ttlSeconds.coerceIn(10, 86400))
        )
        return EnqueueResult(result.optLong("id"), result.optString("correlation_id"))
    }

    fun enqueueToOnlineWatches(
        context: Context,
        command: String,
        payload: JSONObject = JSONObject(),
        priority: String = "normal",
        ttlSeconds: Int = 300
    ): Int {
        var sent = 0
        listWatches(context).filter { it.online }.forEach { watch ->
            if (runCatching {
                    enqueue(context, watch.deviceId, command, payload, priority, ttlSeconds)
                }.isSuccess) sent++
        }
        return sent
    }

    fun forwardNotification(
        context: Context,
        packageName: String,
        title: String,
        text: String,
        priority: String = "normal"
    ): Int {
        if (text.isBlank() && title.isBlank()) return 0
        return enqueueToOnlineWatches(
            context,
            "notification",
            JSONObject()
                .put("type", "phone_notification")
                .put("package", packageName)
                .put("title", title.take(120))
                .put("text", text.take(500)),
            priority,
            180
        )
    }

    fun findWatch(context: Context, deviceId: String): EnqueueResult =
        enqueue(context, deviceId, "find_watch", JSONObject(), "high", 60)

    fun sendIrCommand(
        context: Context,
        deviceId: String,
        protocol: String,
        address: Long,
        command: Int,
        repeats: Int = 0
    ): EnqueueResult = enqueue(
        context,
        deviceId,
        "ir_send",
        JSONObject()
            .put("protocol", protocol)
            .put("address", address)
            .put("command", command)
            .put("repeats", repeats.coerceIn(0, 10)),
        "normal",
        60
    )

    fun pollEvents(context: Context, after: Long, limit: Int = 50): List<WatchEvent> {
        val arr = request(
            context,
            "/api/v1/control.php?acao=events&after=${after.coerceAtLeast(0)}&limit=${limit.coerceIn(1, 100)}"
        ).optJSONArray("events") ?: JSONArray()

        val watchIds = listWatches(context).map { it.deviceId }.toHashSet()
        val out = ArrayList<WatchEvent>()
        for (i in 0 until arr.length()) {
            val e = arr.optJSONObject(i) ?: continue
            val deviceId = e.optString("device_id")
            if (deviceId !in watchIds) continue
            val data = when (val raw = e.opt("dados")) {
                is JSONObject -> raw
                is String -> runCatching { JSONObject(raw) }.getOrDefault(JSONObject())
                else -> JSONObject()
            }
            out += WatchEvent(
                id = e.optLong("id"),
                deviceId = deviceId,
                type = e.optString("tipo"),
                priority = e.optString("prioridade", "normal"),
                correlationId = e.optString("correlation_id").takeIf { it.isNotBlank() && it != "null" },
                data = data,
                createdAt = e.optString("criado_em")
            )
        }
        return out
    }
}
