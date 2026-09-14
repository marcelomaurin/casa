package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

object DeviceProvisionApi {
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    data class ProvisionedDevice(
        val id: Long,
        val deviceId: String,
        val name: String,
        val type: String,
        val location: String,
        val token: String
    )

    private fun request(context: Context, action: String, body: JSONObject? = null): JSONObject {
        val cfg = JarvisApi.loadConfig(context)
        require(cfg.baseUrl.startsWith("https://")) { "Configure uma URL HTTPS da CASA" }
        require(cfg.token.isNotBlank()) { "Configure o token deste celular" }
        val url = cfg.baseUrl.trimEnd('/') + "/api/v1/provision.php?acao=" + action
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

    fun createEspCam(
        context: Context,
        name: String,
        location: String,
        mac: String? = null
    ): ProvisionedDevice {
        val body = JSONObject()
            .put("type", "esp32cam")
            .put("name", name)
            .put("location", location)
            .put("capabilities", JSONArray(listOf("camera", "snapshot", "mjpeg", "flash", "telemetry", "wifi")))
        if (!mac.isNullOrBlank()) body.put("mac", mac)
        val d = request(context, "create", body).getJSONObject("device")
        return ProvisionedDevice(
            id = d.optLong("id"),
            deviceId = d.optString("device_id"),
            name = d.optString("name"),
            type = d.optString("type"),
            location = d.optString("location"),
            token = d.optString("token")
        )
    }

    fun list(context: Context): JSONArray = request(context, "list").optJSONArray("devices") ?: JSONArray()
}
