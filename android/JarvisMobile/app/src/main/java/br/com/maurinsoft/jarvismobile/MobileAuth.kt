package br.com.maurinsoft.jarvismobile

import android.content.Context
import android.os.Build
import android.provider.Settings
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
    private const val KEY_PAIRED = "installation_paired"
    private const val KEY_DEVICE_ID = "paired_device_id"

    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    fun savedSession(context: Context): Session? {
        val p = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val token = p.getString(KEY_TOKEN, "").orEmpty().ifBlank {
            if (hasInstallationLink(context)) AppTokenStore.load(context) else ""
        }
        if (token.isBlank()) return null
        val expires = p.getString(KEY_EXPIRES, "").orEmpty()
        // A expiracao do token do servidor nao bloqueia a interface local.
        // O usuario continua autenticado no aparelho e a sessao online
        // sera renovada no proximo login quando necessario.
        return Session(
            token = token,
            name = p.getString(KEY_NAME, "").orEmpty(),
            login = p.getString(KEY_LOGIN, if (hasInstallationLink(context)) "api_key" else "").orEmpty(),
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

    fun loginWithApiKey(
        context: Context,
        baseUrl: String,
        apiKey: String,
        deviceName: String = ""
    ): Session {
        val cleanUrl = baseUrl.trim().trimEnd('/')
        require(cleanUrl.startsWith("http://") || cleanUrl.startsWith("https://")) {
            "URL da CASA inválida. Utilize http:// ou https://"
        }
        require(apiKey.isNotBlank()) { "Chave de API / Token não informado" }

        // 1. Tenta validar no endpoint oficial /api/v1/auth.php com acao 'qr_login'
        var session: Session? = null
        val body = JSONObject()
            .put("acao", "qr_login")
            .put("token", apiKey.trim())
            .put("device_name", deviceName.ifBlank { "Casa Mobile (QR)" })

        val reqAuth = Request.Builder()
            .url("$cleanUrl/api/v1/auth.php")
            .header("Authorization", "Bearer ${apiKey.trim()}")
            .header("X-API-Key", apiKey.trim())
            .header("Accept", "application/json")
            .post(body.toString().toRequestBody(jsonType))
            .build()

        runCatching {
            client.newCall(reqAuth).execute().use { response ->
                val raw = response.body?.string().orEmpty()
                val json = runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
                if (response.isSuccessful && json.optString("status") == "ok") {
                    val user = json.optJSONObject("user") ?: JSONObject()
                    session = Session(
                        token = json.optString("session_token", apiKey.trim()),
                        name = user.optString("nome", deviceName.ifBlank { "Casa Mobile" }),
                        login = user.optString("login", "api_key"),
                        profile = user.optString("perfil", "operador"),
                        expiresAt = json.optString("expires_at", "permanente")
                    )
                }
            }
        }

        // 2. Fallback: Se auth.php não respondeu, valida direto em /api/v1/status
        if (session == null) {
            val reqStatus = Request.Builder()
                .url("$cleanUrl/api/v1/status")
                .header("Authorization", "Bearer ${apiKey.trim()}")
                .header("X-Device-Token", apiKey.trim())
                .header("Accept", "application/json")
                .get()
                .build()

            client.newCall(reqStatus).execute().use { response ->
                val raw = response.body?.string().orEmpty()
                if (!response.isSuccessful) {
                    throw IllegalStateException("Chave de API recusada pela CASA (HTTP ${response.code})")
                }
                val json = runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
                val clientLabel = json.optString("sistema", "CASA")
                session = Session(
                    token = apiKey.trim(),
                    name = if (deviceName.isNotBlank()) deviceName else "Chave API: $clientLabel",
                    login = "api_key",
                    profile = "operador",
                    expiresAt = "permanente"
                )
            }
        }

        val resolved = session ?: throw IllegalStateException("Não foi possível autenticar a chave de API.")
        JarvisApi.saveConfig(context, cleanUrl, resolved.token)
        save(context, resolved)
        markInstallationLinked(context)
        return resolved
    }

    /** Conteúdo do QR de pareamento gerado em Segurança › Acessos pessoais. */
    data class PairingQr(val baseUrl: String, val code: String)

    /** Reconhece {"t":"casa-pair","v":1,"url":...,"code":...}; null se o QR for de outro tipo. */
    fun parsePairingQr(raw: String): PairingQr? {
        val text = raw.trim()
        if (!text.startsWith("{")) return null
        val json = runCatching { JSONObject(text) }.getOrNull() ?: return null
        if (json.optString("t") != "casa-pair") return null
        val url = json.optString("url").trim().trimEnd('/')
        val code = json.optString("code").trim()
        if (code.isBlank() || !(url.startsWith("https://") || url.startsWith("http://"))) return null
        return PairingQr(url, code)
    }

    private fun deviceDisplayName(context: Context): String {
        val custom = runCatching { Settings.Global.getString(context.contentResolver, "device_name") }.getOrNull().orEmpty().trim()
        if (custom.isNotBlank()) return custom
        val maker = Build.MANUFACTURER.orEmpty().replaceFirstChar { it.uppercase() }
        val model = Build.MODEL.orEmpty()
        return if (model.startsWith(maker, ignoreCase = true)) model else "$maker $model".trim()
    }

    /**
     * Pareia este celular usando o código de uso único do QR. O servidor cria a
     * identidade do celular e devolve uma credencial própria, guardada no Keystore.
     */
    fun pairWithCode(context: Context, qr: PairingQr): Session {
        val appVersion = runCatching { context.packageManager.getPackageInfo(context.packageName, 0).versionName }.getOrNull().orEmpty()
        val body = JSONObject()
            .put("acao", "pair")
            .put("code", qr.code)
            .put("device_name", deviceDisplayName(context))
            .put("device_model", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
            .put("os_version", "Android ${Build.VERSION.RELEASE}")
            .put("app_version", appVersion)
        val req = Request.Builder()
            .url(qr.baseUrl + "/api/v1/auth.php")
            .header("Accept", "application/json")
            .post(body.toString().toRequestBody(jsonType))
            .build()
        client.newCall(req).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            val json = runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
            if (!response.isSuccessful || json.optString("status") != "ok") {
                throw IllegalStateException(json.optString("mensagem").ifBlank { "Pareamento recusado (HTTP ${response.code})" })
            }
            val token = json.optString("device_token", json.optString("session_token"))
            require(token.isNotBlank()) { "Servidor não retornou a credencial do celular" }
            val user = json.optJSONObject("user") ?: JSONObject()
            val session = Session(
                token = token,
                name = user.optString("nome", "Casa Mobile"),
                login = "api_key",
                profile = user.optString("perfil", "operador"),
                expiresAt = json.optString("expires_at", "permanente")
            )
            JarvisApi.saveConfig(context, qr.baseUrl, token)
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .putString(KEY_DEVICE_ID, json.optString("device_id"))
                .apply()
            save(context, session)
            markInstallationLinked(context)
            return session
        }
    }

    fun hasInstallationLink(context: Context): Boolean {
        val p = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        return p.getBoolean(KEY_PAIRED, false) ||
            p.getString(KEY_DEVICE_ID, "").orEmpty().isNotBlank() ||
            (p.getString(KEY_LOGIN, "") == "api_key" && AppTokenStore.load(context).isNotBlank())
    }

    private fun markInstallationLinked(context: Context) {
        check(context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putBoolean(KEY_PAIRED, true).commit()) { "Não foi possível salvar o vínculo do celular" }
    }

    // Somente uma ação explícita de desvinculação permite novo QR Code.
    fun forgetInstallation(context: Context) {
        context.stopService(android.content.Intent(context, JarvisConnectionService::class.java))
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().commit()
        AppTokenStore.save(context, "")
        JarvisApi.clearPending(context)
    }

    fun pairedDeviceId(context: Context): String =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_DEVICE_ID, "").orEmpty()

    fun validate(context: Context): Session? {
        val current = savedSession(context) ?: return null
        val cfg = JarvisApi.loadConfig(context)
        if (!cfg.baseUrl.startsWith("http://") && !cfg.baseUrl.startsWith("https://")) return null

        // Se a sessão for de chave de API, valida via /api/v1/status
        if (current.login == "api_key") {
            return runCatching {
                val req = Request.Builder()
                    .url(cfg.baseUrl.trimEnd('/') + "/api/v1/status")
                    .header("Authorization", "Bearer ${current.token}")
                    .header("X-Device-Token", current.token)
                    .header("Accept", "application/json")
                    .get()
                    .build()
                client.newCall(req).execute().use { response ->
                    if (response.isSuccessful) current else null
                }
            }.getOrNull()
        }

        val req = Request.Builder()
            .url(cfg.baseUrl.trimEnd('/') + "/api/v1/auth.php")
            .header("Accept", "application/json")
            .header("Authorization", "Bearer ${current.token}")
            .post(JSONObject().put("acao", "validate").toString().toRequestBody(jsonType))
            .build()
        return runCatching {
            client.newCall(req).execute().use { response ->
                if (!response.isSuccessful) return@use null
                val json = JSONObject(response.body?.string().orEmpty())
                if (json.optString("status") != "ok") return@use null
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
        if (hasInstallationLink(context)) return // Fechar a tela não desfaz o provisionamento.
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
        check(context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_TOKEN, session.token)
            .putString(KEY_NAME, session.name)
            .putString(KEY_LOGIN, session.login)
            .putString(KEY_PROFILE, session.profile)
            .putString(KEY_EXPIRES, session.expiresAt)
            .commit()) { "Não foi possível salvar a sessão no aparelho" }
    }

    fun clear(context: Context) {
        val editor = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
        listOf(KEY_TOKEN, KEY_NAME, KEY_LOGIN, KEY_PROFILE, KEY_EXPIRES).forEach { editor.remove(it) }
        editor.commit()
    }
}