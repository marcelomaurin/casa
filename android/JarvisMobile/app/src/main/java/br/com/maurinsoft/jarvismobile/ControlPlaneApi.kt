package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

object ControlPlaneApi {
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    data class DeviceInfo(
        val deviceId: String,
        val name: String,
        val type: String,
        val status: String,
        val health: String,
        val transport: String,
        val location: String,
        val firmwareVersion: String,
        val protocolVersion: String,
        val lastHeartbeat: String,
        val battery: Int?,
        val rssi: Int?,
        val capabilities: List<String>,
        val online: Boolean
    )

    data class HealthInfo(
        val ready: Boolean,
        val database: Boolean,
        val controlPlane: Boolean,
        val message: String
    )

    private fun cfg(context: Context): JarvisApi.Config {
        val c = JarvisApi.loadConfig(context)
        require(c.baseUrl.startsWith("https://")) { "Use uma URL HTTPS do JARVIS" }
        require(c.token.isNotBlank()) { "Token do Android não configurado" }
        return c
    }

    private fun get(context: Context, path: String): String {
        val c = cfg(context)
        val request = Request.Builder()
            .url(c.baseUrl + path)
            .header("Authorization", "Bearer ${c.token}")
            .header("X-Device-Token", c.token)
            .header("Accept", "application/json")
            .build()
        client.newCall(request).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            if (!response.isSuccessful) throw IllegalStateException("HTTP ${response.code}: ${raw.take(300)}")
            return raw
        }
    }

    private fun parseCapabilities(rawCaps: Any?): List<String> {
        val caps = ArrayList<String>()
        fun addArray(arr: JSONArray) {
            for (k in 0 until arr.length()) {
                when (val item = arr.opt(k)) {
                    is JSONObject -> if (item.optBoolean("enabled", true)) {
                        item.optString("name").takeIf { it.isNotBlank() }?.let { caps += it }
                    }
                    is String -> if (item.isNotBlank()) caps += item
                }
            }
        }
        when (rawCaps) {
            is JSONArray -> addArray(rawCaps)
            is JSONObject -> rawCaps.keys().forEach { key -> if (rawCaps.optBoolean(key, true)) caps += key }
            is String -> runCatching { addArray(JSONArray(rawCaps)) }
        }
        return caps.distinct()
    }

    fun listDevices(context: Context): List<DeviceInfo> {
        val root = JSONObject(get(context, "/api/v1/control.php?acao=devices"))
        val arr = root.optJSONArray("devices") ?: JSONArray()
        val result = ArrayList<DeviceInfo>()
        for (i in 0 until arr.length()) {
            val j = arr.optJSONObject(i) ?: continue
            result += DeviceInfo(
                deviceId = j.optString("device_id"),
                name = j.optString("nome", j.optString("device_id", "Device")),
                type = j.optString("tipo", "generic"),
                status = j.optString("status", "unknown"),
                health = j.optString("health", "unknown"),
                transport = j.optString("transport"),
                location = j.optString("localizacao"),
                firmwareVersion = j.optString("firmware_version"),
                protocolVersion = j.optString("protocol_version"),
                lastHeartbeat = j.optString("ultimo_heartbeat"),
                battery = if (j.has("battery_pct") && !j.isNull("battery_pct")) j.optInt("battery_pct") else null,
                rssi = if (j.has("sinal_rssi") && !j.isNull("sinal_rssi")) j.optInt("sinal_rssi") else null,
                capabilities = parseCapabilities(j.opt("capabilities")),
                online = j.optBoolean("online", j.optString("status").equals("online", true))
            )
        }
        return result
    }

    fun readiness(context: Context): HealthInfo {
        return try {
            val j = JSONObject(get(context, "/api/v1/ready.php"))
            val checks = j.optJSONObject("checks") ?: JSONObject()
            HealthInfo(
                ready = j.optString("status").equals("ready", true) || j.optBoolean("ready", false),
                database = checks.optBoolean("database", true),
                controlPlane = checks.optBoolean("control_plane", true),
                message = j.optString("message", "API pronta")
            )
        } catch (e: Exception) {
            HealthInfo(false, false, false, e.message ?: "API indisponível")
        }
    }
}
