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

    data class ProvisionedDevice(val id: Long, val deviceId: String, val name: String, val type: String, val location: String, val token: String)
    data class PairingRequest(
        val requestId: String,
        val mac: String,
        val type: String,
        val model: String,
        val firmwareVersion: String,
        val capabilities: List<String>,
        val pairingCode: String,
        val createdAt: String,
        val expiresAt: String
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

    fun pendingPairingRequests(context: Context): List<PairingRequest> {
        val rows = request(context, "solicitacoes_pendentes").optJSONArray("solicitacoes") ?: JSONArray()
        return buildList {
            for (i in 0 until rows.length()) {
                val r = rows.optJSONObject(i) ?: continue
                val capsJson = r.optJSONArray("capabilities") ?: JSONArray()
                val caps = buildList { for (j in 0 until capsJson.length()) add(capsJson.optString(j)) }.filter { it.isNotBlank() }
                add(PairingRequest(
                    requestId = r.optString("request_id"),
                    mac = r.optString("mac_address"),
                    type = r.optString("device_type", "device"),
                    model = r.optString("model", r.optString("device_type", "device")),
                    firmwareVersion = r.optString("firmware_version"),
                    capabilities = caps,
                    pairingCode = r.optString("pairing_code"),
                    createdAt = r.optString("criado_em"),
                    expiresAt = r.optString("expira_em")
                ))
            }
        }
    }

    fun authorizePairing(context: Context, request: PairingRequest, name: String, location: String): ProvisionedDevice {
        require(request.requestId.isNotBlank()) { "Solicitação de pareamento inválida" }
        val body = JSONObject()
            .put("request_id", request.requestId)
            .put("pairing_code", request.pairingCode)
            .put("name", name.ifBlank { request.model.ifBlank { request.type } })
            .put("location", location.ifBlank { "Residencia" })
        val response = request(context, "autorizar_pareamento", body)
        val d = response.optJSONObject("device") ?: response
        return ProvisionedDevice(
            id = d.optLong("id"),
            deviceId = d.optString("device_id"),
            name = d.optString("name", name),
            type = d.optString("type", request.type),
            location = d.optString("location", location),
            token = d.optString("token", d.optString("device_token"))
        )
    }

    // Compatibilidade com telas antigas. Novos devices devem usar solicitar_pareamento -> autorizar_pareamento.
    fun createEspCam(context: Context, name: String, location: String, mac: String? = null): ProvisionedDevice {
        val body = JSONObject().put("type", "esp32cam").put("name", name).put("location", location)
            .put("capabilities", JSONArray(listOf("camera", "snapshot", "mjpeg", "flash", "telemetry", "wifi")))
        if (!mac.isNullOrBlank()) body.put("mac", mac)
        val d = request(context, "create", body).getJSONObject("device")
        return ProvisionedDevice(d.optLong("id"), d.optString("device_id"), d.optString("name"), d.optString("type"), d.optString("location"), d.optString("token"))
    }

    fun createWatch(context: Context, name: String, location: String, mac: String? = null): ProvisionedDevice {
        val body = JSONObject().put("type", "watch").put("name", name).put("location", location)
            .put("capabilities", JSONArray(listOf("watch", "display", "wifi", "microphone", "speaker", "vibration", "accelerometer", "steps", "infrared", "notifications", "telemetry")))
        if (!mac.isNullOrBlank()) body.put("mac", mac)
        val d = request(context, "create", body).getJSONObject("device")
        return ProvisionedDevice(d.optLong("id"), d.optString("device_id"), d.optString("name"), d.optString("type"), d.optString("location"), d.optString("token"))
    }

    fun list(context: Context): JSONArray = request(context, "list").optJSONArray("devices") ?: JSONArray()
}
