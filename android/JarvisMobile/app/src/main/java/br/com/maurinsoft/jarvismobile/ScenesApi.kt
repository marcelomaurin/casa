package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

object ScenesApi {
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    data class SceneInfo(
        val id: Long,
        val slug: String,
        val name: String,
        val description: String,
        val active: Boolean,
        val actions: Int,
        val riskLevel: Int
    )

    data class SceneRun(
        val runId: Long,
        val status: String,
        val message: String,
        val requiresConfirmation: Boolean = false,
        val riskLevel: Int = 0
    )

    private fun cfg(context: Context): JarvisApi.Config {
        val c = JarvisApi.loadConfig(context)
        require(c.baseUrl.startsWith("https://")) { "Use uma URL HTTPS do JARVIS" }
        require(c.token.isNotBlank()) { "Token do Android não configurado" }
        return c
    }

    private fun call(context: Context, path: String, body: JSONObject? = null): Pair<Int, String> {
        val c = cfg(context)
        val builder = Request.Builder()
            .url(c.baseUrl + path)
            .header("Authorization", "Bearer ${c.token}")
            .header("X-Device-Token", c.token)
            .header("Accept", "application/json")
        if (body == null) builder.get()
        else builder.post(body.toString().toRequestBody(jsonType))
        client.newCall(builder.build()).execute().use { response ->
            return response.code to response.body?.string().orEmpty()
        }
    }

    fun list(context: Context): List<SceneInfo> {
        val (code, raw) = call(context, "/api/v1/scenes.php?acao=list")
        if (code !in 200..299) throw IllegalStateException("HTTP $code: ${raw.take(300)}")
        val arr = JSONObject(raw).optJSONArray("scenes") ?: JSONArray()
        val out = ArrayList<SceneInfo>()
        for (i in 0 until arr.length()) {
            val j = arr.optJSONObject(i) ?: continue
            out += SceneInfo(
                id = j.optLong("id"),
                slug = j.optString("slug"),
                name = j.optString("nome", j.optString("slug", "Cena")),
                description = j.optString("descricao"),
                active = j.optBoolean("ativo", true),
                actions = j.optInt("actions_total", 0),
                riskLevel = j.optInt("risk_level", 1)
            )
        }
        return out
    }

    fun execute(context: Context, scene: SceneInfo, confirm: Boolean = false): SceneRun {
        val body = JSONObject().put("id", scene.id)
        if (confirm) body.put("confirm", true)
        val (code, raw) = call(context, "/api/v1/scenes.php?acao=execute", body)
        val j = runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
        if (code == 409 && j.optString("status") == "confirmacao_necessaria") {
            return SceneRun(0, "CONFIRMATION_REQUIRED", j.optString("mensagem", "Confirmação necessária"), true, j.optInt("risk_level", scene.riskLevel))
        }
        if (code !in 200..299) throw IllegalStateException("HTTP $code: ${raw.take(300)}")
        return SceneRun(
            runId = j.optLong("run_id"),
            status = j.optString("run_status", "QUEUED"),
            message = "Cena ${scene.name} enfileirada"
        )
    }
}
