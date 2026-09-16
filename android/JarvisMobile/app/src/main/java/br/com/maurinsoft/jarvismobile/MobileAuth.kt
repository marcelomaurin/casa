package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.time.OffsetDateTime
import java.util.concurrent.TimeUnit

object MobileAuth {
    data class Session(
        val token: String,
        val name: String,
        val login: String,
        val profile: String,
        val expiresAt: String
    )

    private const val PREFS = "jarvis_mobile_auth"
    private const val KEY_TOKEN = "session_token"
    private const val KEY_NAME = "user_name"
    private const val KEY_LOGIN = "user_login"
    private const val KEY_PROFILE = "user_profile"
    private const val KEY_EXPIRES = "expires_at"

    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    fun savedSession(context: Context): Session? {
        val p = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val token = p.getString(KEY_TOKEN, "").orEmpty()
        if (token.isBlank()) return null
        val expires = p.getString(KEY_EXPIRES, "").orEmpty()
        if (expires.isNotBlank()) {
            val valid = runCatching {
                OffsetDateTime.parse(expires).toInstant().toEpochMilli() > System.currentTimeMillis()
            }.getOrDefault(false)
            if (!valid) { clear(context); return null }
        }
        return Session(
            token = token,
            name = p.getString(KEY_NAME, "").orEmpty(),
            login = p.getString(KEY_LOGIN, "").orEmpty(),
            profile = p.getString(KEY_PROFILE, "").orEmpty(),
            expiresAt = expires
        )
    }

    fun isLoggedIn(context: Context): Boolean = savedSession(context) != null

    fun login(context: Context, user: String, password: String): Session {
        val cfg = JarvisApi.loadConfig(context)
        require(cfg.baseUrl.startsWith("https://")) { "Configure primeiro a URL HTTPS da CASA" }
        val body = JSONObject().put("acao", "login").put("usuario", user.trim()).put("senha", password)
        val req = Request.Builder()
            .url(cfg.baseUrl.trimEnd('/') + "/api/v1/auth.php")
            .header("Accept", "application/json")
            .post(body.toString().toRequestBody(jsonType))
            .build()
        client.newCall(req).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            val json = runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
            if (!response.isSuccessful || json.optString("status") != "ok") {
                throw IllegalStateException(json.optString("mensagem").ifBlank { "Falha no login: HTTP ${response.code}" })
            }
            val userJson = json.optJSONObject("user") ?: JSONObject()
            val session = Session(
                token = json.optString("session_token"),
                name = userJson.optString("nome"),
                login = userJson.optString("login"),
                profile = userJson.optString("perfil"),
                expiresAt = json.optString("expires_at")
            )
            require(session.token.isNotBlank()) { "Servidor não retornou sessão" }
            save(context, session)
            return session
        }
    }

    fun validate(context: Context): Session? {
        val current = savedSession(context) ?: return null
        val cfg = JarvisApi.loadConfig(context)
        if (!cfg.baseUrl.startsWith("https://")) return null
        val req = Request.Builder()
            .url(cfg.baseUrl.trimEnd('/') + "/api/v1/auth.php")
            .header("Accept", "application/json")
            .header("Authorization", "Bearer ${current.token}")
            .post(JSONObject().put("acao", "validate").toString().toRequestBody(jsonType))
            .build()
        return runCatching {
            client.newCall(req).execute().use { response ->
                if (!response.isSuccessful) { clear(context); return@use null }
                val json = JSONObject(response.body?.string().orEmpty())
                if (json.optString("status") != "ok") { clear(context); return@use null }
                val user = json.optJSONObject("user") ?: JSONObject()
                val refreshed = current.copy(
                    name = user.optString("nome", current.name),
                    login = user.optString("login", current.login),
                    profile = user.optString("perfil", current.profile)
                )
                save(context, refreshed)
                refreshed
            }
        }.getOrNull()
    }

    fun logout(context: Context) {
        val current = savedSession(context)
        val cfg = JarvisApi.loadConfig(context)
        if (current != null && cfg.baseUrl.startsWith("https://")) {
            runCatching {
                val req = Request.Builder()
                    .url(cfg.baseUrl.trimEnd('/') + "/api/v1/auth.php")
                    .header("Accept", "application/json")
                    .header("Authorization", "Bearer ${current.token}")
                    .post(JSONObject().put("acao", "logout").toString().toRequestBody(jsonType))
                    .build()
                client.newCall(req).execute().close()
            }
        }
        clear(context)
    }

    private fun save(context: Context, session: Session) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_TOKEN, session.token)
            .putString(KEY_NAME, session.name)
            .putString(KEY_LOGIN, session.login)
            .putString(KEY_PROFILE, session.profile)
            .putString(KEY_EXPIRES, session.expiresAt)
            .apply()
    }

    fun clear(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().apply()
    }
}